<?php

namespace App\Models;

use App\Enums\CongestionLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 試合ごとのブース。混雑（レベル・待ち時間）の現在値を持つ */
class GameBooth extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id', 'booth_id', 'is_open', 'congestion_level', 'wait_minutes',
        'congestion_updated_at', 'congestion_updated_by',
    ];

    protected $casts = [
        'is_open' => 'boolean',
        'congestion_level' => CongestionLevel::class,
        'wait_minutes' => 'integer',
        'congestion_updated_at' => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function booth(): BelongsTo
    {
        return $this->belongsTo(Booth::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'congestion_updated_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CongestionLog::class);
    }
}
