<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'user_id',
    'accessible_type',
    'accessible_id',
    'abilities',
    'starts_at',
    'ends_at',
    'expiry_notified_at',
    'granted_by',
])]
class Access extends Model
{
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessible(): MorphTo
    {
        return $this->morphTo();
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function isActive(): bool
    {
        $started = $this->starts_at === null || $this->starts_at->lte(now());
        $notEnded = $this->ends_at === null || $this->ends_at->gte(now());

        return $started && $notEnded;
    }
}
