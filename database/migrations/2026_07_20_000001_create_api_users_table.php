<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('api_key', 100)->unique();
            $table->enum('plan', ['free', 'basic', 'pro', 'enterprise'])->default('free');
            $table->unsignedInteger('requests_today')->default(0);
            $table->unsignedInteger('requests_limit')->default(1000);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_users');
    }
};
