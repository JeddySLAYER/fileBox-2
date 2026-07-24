<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'path',
    'size',
    'status',
    'notes',
    'created_by',
    'restored_at',
])]
class Backup extends Model
{
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
