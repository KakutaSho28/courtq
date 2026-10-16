<?php

namespace App\Models;

use App\Enums\MenuItemKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = ['booth_id', 'name', 'kind', 'price', 'description', 'is_active'];

    protected $casts = [
        'kind' => MenuItemKind::class,
        'price' => 'integer',
        'is_active' => 'boolean',
    ];

    public function booth(): BelongsTo
    {
        return $this->belongsTo(Booth::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }
}
