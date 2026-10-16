<?php

namespace App\Models;

use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 試合ごとのメニューの在庫。updated_at が「最終更新」 */
class ItemStock extends Model
{
    use HasFactory;

    protected $fillable = ['game_id', 'menu_item_id', 'status', 'quantity', 'updated_by'];

    protected $casts = [
        'status' => StockStatus::class,
        'quantity' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StockLog::class);
    }
}
