<?php

namespace App\Models;

use App\Enums\BoothCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booth extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category', 'location_label', 'description'];

    protected $casts = [
        'category' => BoothCategory::class,
    ];

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function gameBooths(): HasMany
    {
        return $this->hasMany(GameBooth::class);
    }

    /** 担当スタッフ（booth_staff） */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'booth_staff')->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }
}
