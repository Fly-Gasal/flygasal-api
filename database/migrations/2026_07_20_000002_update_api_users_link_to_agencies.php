<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_users', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->string('status', 20)->default('active')->after('is_active');
            $table->text('use_case')->nullable()->after('status');
        });

        // Make api_key nullable so pending requests can exist without a key
        DB::statement('ALTER TABLE api_users MODIFY COLUMN api_key VARCHAR(100) NULL');

        // Add unique index that ignores NULLs (MySQL ignores NULLs in unique indexes by default)
        Schema::table('api_users', function (Blueprint $table) {
            $table->dropUnique(['api_key']);
        });
        Schema::table('api_users', function (Blueprint $table) {
            $table->unique('api_key');
        });
    }

    public function down(): void
    {
        Schema::table('api_users', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'status', 'use_case']);
        });
        DB::statement('ALTER TABLE api_users MODIFY COLUMN api_key VARCHAR(100) NOT NULL');
    }
};
