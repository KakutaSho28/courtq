<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 更新のたびに 1 行追加する履歴（推移グラフ用）。更新しない追記専用のため created_at のみ
        Schema::create('congestion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_booth_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->unsignedSmallInteger('wait_minutes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['game_booth_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('congestion_logs');
    }
};
