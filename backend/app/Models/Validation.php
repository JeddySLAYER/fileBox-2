<?php

namespace App\Models;

use App\Enums\ValidationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'workflow_step_id',
    'user_id',
    'status',
    'comment',
    'sla_hours',
    'due_at',
    'validated_at',
])]
class Validation extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ValidationStatus::class,
            'validated_at' => 'datetime',
            'due_at' => 'datetime',
            'sla_hours' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
