<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // 役割。サーバー側でだけ付与する（admin は app:create-admin とデモ Seeder）
            $table->enum('role', ['user', 'staff', 'admin'])->default('user');
            // 外部 ID プロバイダ（SSO / OAuth）を後から足すための列。モックログインでは未使用
            $table->string('provider')->nullable();
            $table->string('provider_user_id')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
