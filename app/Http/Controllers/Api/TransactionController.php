<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flights\Booking;
use App\Models\Flights\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        // Only admins can view all transactions.
        // Agents and regular users see only their own wallet_topup or booking_payment transactions.
        if (!$request->user()->hasRole('admin')) {
            $transactions = $request->user()
                ->transactions()
                ->with([
                    'booking:id,order_num,status,user_id',
                    'booking.user:id,name,email',
                    'user:id,name,email'
                ])
                ->latest()
                ->get();
        } else {
            // Admins can view all transactions across the system
            $transactions = Transaction::with([
                    'booking:id,order_num,status,user_id',
                    'booking.user:id,name,email',
                    'user:id,name,email'
                ])
                ->latest()
                ->get();
        }

        return response()->json([
            'status' => true,
            'data' => $transactions->map(function ($transaction) {
                $type = $transaction->user
                    ? 'wallet_topup'
                    : ($transaction->booking ? 'booking_payment' : null);

                $name = $type === 'wallet_topup'
                    ? optional($transaction->user)->name
                    : optional(optional($transaction->booking)->user)->name;

                $email = $type === 'wallet_topup'
                    ? optional($transaction->user)->email
                    : optional(optional($transaction->booking)->user)->email;

                return [
                    'id' => $transaction->id,
                    'trx_id'          => $transaction->payment_gateway_reference,
                    'booking_num'     => $transaction->booking->order_num ?? null,
                    'date'            => $transaction->transaction_date->toDateString(),
                    'amount'          => $transaction->amount,
                    'currency'        => $transaction->currency,
                    'payment_gateway' => $transaction->payment_gateway ?? 'bank',
                    'status'          => $transaction->status,
                    'type'            => $type,
                    'name'            => $name,
                    'email'           => $email,
                    'description'     => null,
                ];
            }),
        ]);
    }


    /**
     * Store a newly created user in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $rules = [
            'type' => 'required|string|in:wallet_topup',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'payment_gateway_reference' => 'required|string|unique:transactions,payment_gateway_reference',
            'payment_gateway' => 'required|string',
        ];

        // Only let admins pass a specific user_id
        if ($request->user()->hasRole('admin')) {
            $rules['user_id'] = 'required|exists:users,id';
        }

        $validatedData = $request->validate($rules);

        // Fallback to authenticated user ID if not provided by admin
        $userId = $request->user()->hasRole('admin') && isset($validatedData['user_id'])
            ? $validatedData['user_id']
            : $request->user()->id;

        $transaction = Transaction::create([
            'user_id' => $userId,
            'booking_id' => null,
            'amount' => $validatedData['amount'],
            'currency' => $validatedData['currency'],
            'type' => $validatedData['type'],
            'status' => 'pending',
            'payment_gateway_reference' => $validatedData['payment_gateway_reference'],
            'transaction_date' => now(),
            'payment_gateway' => $validatedData['payment_gateway'],
        ]);

        return response()->json([
            'status' => 'true',
            'data' => [
                'trx_id' => $transaction->payment_gateway_reference,
                'date' => $transaction->transaction_date->toDateString(),
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'payment_gateway' => $transaction->payment_gateway,
                'status' => $transaction->status,
                'description' => null,
            ],
            'message' => 'Deposit request submitted successfully',
        ], 201);
    }

    /**
     * Handle payment using user's wallet balance.
     *
     * Validates the request, checks the user's wallet balance,
     * creates a transaction if sufficient funds exist,
     * deducts the balance, and marks the booking as paid (by order_num).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function walletPay(Request $request)
    {
        $validated = $request->validate([
            'order_num'=> 'required|exists:bookings,order_num',
            'type'      => 'required|string|in:booking_payment',
            'amount'    => 'required|numeric|min:0.01',
            'currency'  => 'required|string|size:3',
            'payment_gateway' => 'required|string',
        ]);

        $user = $request->user();

        // Resolve the booking
        $booking = Booking::where('order_num', $validated['order_num'])->firstOrFail();

        // Check ownership: Only admins can pay for another user's booking
        if (!$user->hasRole('admin') && $booking->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized to process payment for this booking.',
            ], 403);
        }

        if ($user->wallet_balance < $validated['amount']) {
            return response()->json([
                'status' => false,
                'message' => 'Insufficient wallet balance.',
            ], 422);
        }

        $paymentReference = strtoupper(uniqid('WALLET-'));

        DB::beginTransaction();
        try {
            $user->decrement('wallet_balance', $validated['amount']);

            $transaction = Transaction::create([
                'user_id'    => $user->id,
                'booking_id' => $booking->id,
                'amount'     => $validated['amount'],
                'currency'   => strtoupper($validated['currency']),
                'type'       => $validated['type'],
                'status'     => 'completed',
                'payment_gateway_reference' => $paymentReference,
                'transaction_date' => now(),
                'description' => $validated['payment_gateway'],
            ]);

            $booking->update([
                'payment_status' => 'paid',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'data' => [
                    'trx_id'         => $transaction->payment_gateway_reference,
                    'date'           => $transaction->transaction_date->toDateString(),
                    'amount'         => $transaction->amount,
                    'currency'       => $transaction->currency,
                    'payment_gateway'=> $transaction->payment_gateway,
                    'status'         => $transaction->status,
                ],
                'message' => 'Wallet payment completed and booking updated successfully.',
            ], 201);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Payment failed. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Approve or reject a transaction.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function approveOrReject(Request $request){

        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized: Only admins can approve or reject transactions',
            ], 403);
        }

        $validatedData = $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|string|in:approved,rejected',
            'note' => 'nullable|string',
        ]);
        $transaction = Transaction::find($validatedData['transaction_id']);
        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }


        // idempotency: only pending can transition
        if ($transaction->status !== 'pending') {
            return response()->json([
                'status'  => true,
                'message' => 'No change: transaction is already '. $transaction->status,
                'data'    => [
                    'trx_id'   => $transaction->payment_gateway_reference,
                    'status'   => $transaction->status,
                    'amount'   => (float) $transaction->amount,
                    'currency' => $transaction->currency,
                ],
            ], 200);
        }

        // normalize to storage statuses used by UI
        $normalized = $validatedData['status'] === 'approved' ? 'completed' : 'failed';

        // optional override: only allow amount override when approving
        if ($normalized === 'completed') {
            $amount = isset($validatedData['amount']) ? (float) $validatedData['amount'] : (float) $transaction->amount;
            if ($amount <= 0) {
                return response()->json(['message' => 'Amount must be greater than 0'], 422);
            }

            // update tx first
            $transaction->amount      = $amount;
            $transaction->status      = 'completed';
            // $transaction->approve_note= $validatedData['note'] ?? null;
            $transaction->save();

            // lock user row then credit
            $user = User::whereKey($transaction->user_id)->lockForUpdate()->first();
            $user->wallet_balance = (float) $user->wallet_balance + $amount;
            $user->save();

        } else {
            $transaction->status         = 'failed';
            // $transaction->decline_reason = $validatedData['note'] ?? null;
            $transaction->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'Transaction updated successfully',
            'data' => [
                'trx_id' => $transaction->payment_gateway_reference,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
            ],
        ]);
    }
}
