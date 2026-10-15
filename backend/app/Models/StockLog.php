<?php

namespace App\Models;

use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 在庫更新の履歴（追記専用。created_at のみ） */
class StockLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['item_stock_id', 'status', 'quantity', 'user_id'];

    protected $casts = [
        'status' => StockStatus::class,
        'quantity' => 'integer',
    ];

    public function itemStock(): BelongsTo
    {
        return $this->belongsTo(ItemStock::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
