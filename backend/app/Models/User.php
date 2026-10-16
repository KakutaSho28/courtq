<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // role / provider / provider_user_id はクライアントから受け取らないため fillable に入れない。
    // 付与はサーバー側の処理（app:create-admin、招待コードの承認、デモ Seeder）だけが行う
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** DB の既定値と同じ。作成直後（DB から読み直す前）でも role が null にならないように */
    protected $attributes = [
        'role' => 'user',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
    ];

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    /** 担当しているブース（booth_staff） */
    public function booths(): BelongsToMany
    {
        return $this->belongsToMany(Booth::class, 'booth_staff')->withTimestamps();
    }

    /** お気に入りのブース（favorites） */
    public function favoriteBooths(): BelongsToMany
    {
        return $this->belongsToMany(Booth::class, 'favorites')->withTimestamps();
    }
}
