<?php

namespace App\Models;

use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Game extends Model
{
    use HasFactory;

    protected $fillable = ['season', 'section', 'home_team', 'away_team', 'venue_id', 'tipoff_at', 'status'];

    protected $casts = [
        'section' => 'integer',
        'tipoff_at' => 'datetime',
        'status' => GameStatus::class,
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function gameBooths(): HasMany
    {
        return $this->hasMany(GameBooth::class);
    }

    /** この試合に出店しているブース */
    public function booths(): BelongsToMany
    {
        return $this->belongsToMany(Booth::class, 'game_booths')->withTimestamps();
    }

    public function itemStocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }

    /** 指定日（アプリのタイムゾーン）に開催される試合 */
    public function scopeOnDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereBetween('tipoff_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
    }
}
