<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['in_stock', 'low', 'sold_out']);
            $table->unsignedInteger('quantity')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();                              // updated_at が「最終更新」

            $table->unique(['game_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_stocks');
    }
};
