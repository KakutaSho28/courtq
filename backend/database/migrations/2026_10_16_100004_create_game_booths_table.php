<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_booths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booth_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_open')->default(true);
            // 混雑 1〜4（未更新なら null）と待ち時間（分）。範囲は FormRequest で検証する
            $table->unsignedTinyInteger('congestion_level')->nullable();
            $table->unsignedSmallInteger('wait_minutes')->nullable();
            $table->timestamp('congestion_updated_at')->nullable();
            $table->foreignId('congestion_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['game_id', 'booth_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_booths');
    }
};
