<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workflow_id',
    'name',
    'step_order',
    'responsible_role_id',
    'responsible_user_id',
    'is_mandatory',
    'duration_hours',
    'reminder_hours_before',
    'remind_on_overdue',
    'description',
])]
class WorkflowStep extends Model
{
    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'remind_on_overdue' => 'boolean',
            'duration_hours' => 'integer',
            'reminder_hours_before' => 'integer',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function responsibleRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'responsible_role_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(Validation::class);
    }
}
