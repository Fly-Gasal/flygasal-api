<div align="center">
  <img src="public/flygasal.png" alt="FlyGasal Logo" width="160" />
  <br />
  <h1>FlyGasal — B2B Flight Booking Platform</h1>
  <p><strong>Laravel 10 REST API · React 19 + Vite SPA · PKFare GDS</strong></p>

  ![Laravel](https://img.shields.io/badge/Laravel-10-FF2D20?style=flat-square&logo=laravel&logoColor=white)
  ![React](https://img.shields.io/badge/React-19-61DAFB?style=flat-square&logo=react&logoColor=black)
  ![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)
  ![Tailwind](https://img.shields.io/badge/Tailwind-4-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)
  ![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?style=flat-square&logo=mysql&logoColor=white)
</div>

---

## Overview

FlyGasal is a B2B flight booking platform that lets travel agencies search flights, manage bookings, and handle wallet payments through a clean agent portal and admin dashboard. It sources real-time flight data from the PKFare GDS.

| Layer | Directory | Description |
|---|---|---|
| Backend API | `flygasal-api/` | Laravel 10 REST API |
| TSX Frontend | `flygasal/` | React 19 + Vite (primary) |
| JSX Frontend | `flygasal-client/` | React 18 JSX (legacy) |
| Docs | `flygasal-doc/` | Docusaurus 4 API reference |

---

## Features

### Agent Portal
- **Flight Search** — Real-time availability via PKFare with 10-minute caching
- **Booking & Ticketing** — Create PNRs, issue e-tickets, async webhook confirmations
- **Digital Wallet** — Top-up requests → admin approval → instant wallet deduction
- **Booking History** — Paginated with status tracking (pending → confirmed → ticketed)
- **E-Ticket PDF** — One-click PDF generation with passenger and segment details
- **Developer Portal** — API key management, outbound webhook endpoint registration

### Admin Dashboard
- **Analytics** — Sales chart, revenue trends, booking counts by period
- **User Management** — Approve agents, manage wallet deposits and debits
- **System Settings** — PKFare credentials, SMTP config, platform settings via GUI
- **RBAC** — Role-based access control via `arden28/guardian`

---

## Architecture

### Authentication
- **Sessions** — Laravel Sanctum `auth_token` Bearer tokens
- **API Keys** — Sanctum tokens prefixed `api-key:*`, scoped per key; managed via `DeveloperController`
- **Telegram Login** — `TelegramAuthController` verifies bot signature

### External Integrations
- **PKFare GDS** — Search, precise pricing, booking, ticketing
  - Auth: `md5(partner_id + secret + timestamp)` signature
  - Credentials: stored in `settings` table, loaded dynamically
- **Inbound PKFare Webhooks** — Async ticket results posted to `/api/pkfare/ticket-issuance-notify-v2`
- **Outbound Webhooks** — FlyGasal POSTs signed events to agency-configured URLs when bookings/transactions occur

### Request Flow
1. Search → `POST /flights/search` → PKFare → normalized `Offer[]` → cached 10 min
2. Select → `POST /flights/precise-pricing` → live PKFare price
3. Book → `POST /flights/bookings` → Booking `pending`
4. Pay → `POST /transactions/pay` → wallet deducted → Booking `confirmed`
5. Ticket → `POST /bookings/ticketing` → PKFare issues → async webhook → Booking `ticketed`

---

## API Reference

Base URL: `https://api.flygasal.net/api`

### Public Routes
| Method | Path | Description |
|--------|------|-------------|
| GET | `/status` | Health check |
| GET | `/proxy/airports?q=` | Airport search |
| GET | `/proxy/countries` | Country list |
| POST | `/login` | Agent login |
| POST | `/register` | Agent registration |
| POST | `/auth/telegram` | Telegram login |

### PKFare Inbound Webhooks (public, signature-validated)
| Method | Path | Event |
|--------|------|-------|
| POST | `/pkfare/ticket-issuance-notify-v2` | Ticketing result |
| POST | `/pkfare/refund-result` | Refund result |
| POST | `/pkfare/reimbursed-result` | Reimbursement result |
| POST | `/pkfare/schedule-change` | Schedule change |

### Authenticated Routes (`Authorization: Bearer <token>`)
| Method | Path | Description |
|--------|------|-------------|
| GET | `/user` | Current user profile |
| PUT | `/profile` | Update profile / agency logo |
| POST | `/flights/search` | Search flights |
| POST | `/flights/precise-pricing` | Real-time pricing |
| POST | `/flights/ancillary-pricing` | Ancillary services pricing |
| GET | `/bookings` | List bookings (paginated) |
| POST | `/flights/bookings` | Create booking |
| GET | `/bookings/{orderNum}` | Booking detail |
| POST | `/bookings/ticketing` | Issue e-tickets |
| POST | `/bookings/{id}/cancel` | Cancel booking |
| GET | `/transactions` | Transaction history |
| POST | `/transactions/add` | Request wallet top-up |
| POST | `/transactions/pay` | Pay via wallet |

### Developer — API Keys
| Method | Path | Description |
|--------|------|-------------|
| GET | `/developer/keys` | List API keys |
| POST | `/developer/keys` | Create API key |
| DELETE | `/developer/keys/{id}` | Revoke specific key |
| DELETE | `/developer/keys` | Revoke all keys |

**Available scopes:** `flights:search` · `flights:pricing` · `bookings:read` · `bookings:write` · `transactions:read` · `profile:read`

### Developer — Outbound Webhook Endpoints
| Method | Path | Description |
|--------|------|-------------|
| GET | `/developer/webhooks` | List webhook endpoints |
| POST | `/developer/webhooks` | Register a new endpoint |
| PATCH | `/developer/webhooks/{id}` | Toggle active / update events |
| DELETE | `/developer/webhooks/{id}` | Remove endpoint |

**Supported events:** `booking.created` · `booking.confirmed` · `booking.ticketed` · `booking.cancelled` · `transaction.credit` · `transaction.debit`

Each delivery is signed with `X-FlyGasal-Signature: sha256=<hmac-sha256>` using the endpoint's unique `whsec_*` secret.

### Admin Routes
| Method | Path | Description |
|--------|------|-------------|
| GET | `/admin/dashboard/summary` | KPI summary |
| GET | `/admin/dashboard/sales` | Sales chart data |
| GET/POST | `/admin/users` | User management |
| POST | `/admin/users/{id}/approve` | Approve agent account |
| POST | `/admin/users/{id}/deposit` | Add wallet balance |
| GET/POST | `/admin/settings` | Platform settings |
| GET/POST | `/admin/pkfare-settings` | PKFare credentials |
| GET/POST | `/admin/roles` | Role management |

---

## Getting Started

### Requirements
- PHP 8.2+, Composer
- MySQL 8+
- Node.js 20+ (for the frontends)

### Backend Setup

```bash
cd flygasal-api
composer install
cp .env.example .env
php artisan key:generate

# Configure .env (DB, APP_URL, WEBHOOK_TOKEN, SANCTUM_STATEFUL_DOMAINS)
php artisan migrate --seed
php artisan serve
```

### Frontend Setup

```bash
cd flygasal
npm install
echo "VITE_API_URL=http://localhost:8000/api" > .env
npm run dev
```

### Useful Commands

```bash
# Fresh migration + seed
php artisan migrate:fresh --seed

# Run a single migration
php artisan migrate --path=database/migrations/<file>

# Clear caches
php artisan cache:clear && php artisan config:clear

# Run tests
php artisan test
```

---

## Environment Variables

### Backend (`.env`)

```env
APP_URL=http://flygasal.test
DB_CONNECTION=mysql
DB_DATABASE=flygasal
SANCTUM_STATEFUL_DOMAINS=localhost:5173
WEBHOOK_TOKEN=your_pkfare_webhook_token
```

PKFare credentials are stored in the `settings` table via the admin panel and override any `.env` values at runtime.

### Frontend (`.env`)

```env
VITE_API_URL=https://api.flygasal.net/api
```

---

## URL Format (TSX Frontend)

```
# One-way
/flights/{origin}-{destination}/{YYYY-MM-DD}/{cabin}/{pax}

# Round-trip
/flights/{origin}-{stopover}-{destination}/{outDate}_{retDate}/{cabin}/{pax}

# Examples
/flights/NBO-DXB/2026-09-01/economy/1-0-0
/flights/NBO-DXB-NBO/2026-09-01_2026-09-08/business/2-1-0
```

---

## Booking Status Lifecycle

```
pending → confirmed → ticketed
                   → cancelled
```

---

## Developer Quick Start

```bash
# 1. Create an API key in the Agent Portal → Developer → API Keys

# 2. Authenticate
curl https://api.flygasal.net/api/user \
  -H "Authorization: Bearer fgk_your_key_here"

# 3. Search flights
curl -X POST https://api.flygasal.net/api/flights/search \
  -H "Authorization: Bearer fgk_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{"flights":[{"origin":"NBO","destination":"DXB","depart":"2026-09-01"}],"adults":1,"flightType":"Economy"}'
```

Full API reference: [docs.flygasal.com](https://docs.flygasal.com)
