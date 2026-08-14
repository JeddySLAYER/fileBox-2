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
    'reminder_hours_before',
    'remind_on_overdue',
    'approaching_notified_at',
    'overdue_notified_at',
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
            'reminder_hours_before' => 'integer',
            'remind_on_overdue' => 'boolean',
            'approaching_notified_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
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

    /** Étape courante : aucune autre attente avec un step_order inférieur. */
    public function scopeCurrentStep(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $pending = ValidationStatus::Pending->value;

        $query->whereNotExists(function ($q) use ($pending) {
            $q->selectRaw('1')
                ->from('validations as prior')
                ->join('workflow_steps as prior_step', 'prior_step.id', '=', 'prior.workflow_step_id')
                ->join('workflow_steps as mine', 'mine.id', '=', 'validations.workflow_step_id')
                ->whereColumn('prior.document_id', 'validations.document_id')
                ->where('prior.status', $pending)
                ->whereColumn('prior_step.step_order', '<', 'mine.step_order');
        });
    }
}
