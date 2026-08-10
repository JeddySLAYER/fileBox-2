<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'description',
    'default_workflow_id',
    'requires_workflow',
])]
class DocumentType extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'requires_workflow' => 'boolean',
        ];
    }

    public function defaultWorkflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'default_workflow_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
