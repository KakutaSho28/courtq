<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users に役割と外部 ID プロバイダ用の列を足す。
 * 作成済みの users テーブルを書き換えると、すでに migrate 済みの DB に列が入らないため、別のマイグレーションにしている。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 役割。サーバー側でだけ付与する（admin は app:create-admin とデモ Seeder）
            $table->enum('role', ['user', 'staff', 'admin'])->default('user')->after('password');
            // 外部 ID プロバイダ（SSO / OAuth）を後から足すための列。モックログインでは未使用
            $table->string('provider')->nullable()->after('role');
            $table->string('provider_user_id')->nullable()->after('provider');

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_user_id']);
            $table->dropColumn(['role', 'provider', 'provider_user_id']);
        });
    }
};
