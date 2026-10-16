<?php

namespace App\Models;

use App\Enums\CongestionLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 混雑更新の履歴（追記専用。created_at のみ） */
class CongestionLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['game_booth_id', 'level', 'wait_minutes', 'user_id'];

    protected $casts = [
        'level' => CongestionLevel::class,
        'wait_minutes' => 'integer',
    ];

    public function gameBooth(): BelongsTo
    {
        return $this->belongsTo(GameBooth::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
