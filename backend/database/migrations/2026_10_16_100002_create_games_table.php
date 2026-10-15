<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('season', 20);                       // 例: 2026-27
            $table->unsignedSmallInteger('section')->nullable(); // 節
            $table->string('home_team');
            $table->string('away_team');
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->dateTime('tipoff_at');
            $table->enum('status', ['scheduled', 'live', 'finished'])->default('scheduled');
            $table->timestamps();

            $table->index('tipoff_at');
            // CSV の再インポートで同じ試合を重複登録しないためのキー
            $table->unique(['season', 'section', 'home_team', 'away_team']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
