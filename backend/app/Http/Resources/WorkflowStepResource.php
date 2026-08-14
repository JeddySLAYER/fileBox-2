<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkflowStep */
class WorkflowStepResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'step_order' => $this->step_order,
            'is_mandatory' => $this->is_mandatory,
            'duration_hours' => $this->duration_hours,
            'reminder_hours_before' => $this->reminder_hours_before,
            'remind_on_overdue' => (bool) $this->remind_on_overdue,
            'description' => $this->description,
            'responsible_role' => $this->whenLoaded('responsibleRole', fn () => $this->responsibleRole ? [
                'id' => $this->responsibleRole->id,
                'name' => $this->responsibleRole->name,
                'slug' => $this->responsibleRole->slug,
            ] : null),
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser ? [
                'id' => $this->responsibleUser->id,
                'name' => $this->responsibleUser->name,
                'email' => $this->responsibleUser->email,
            ] : null),
        ];
    }
}
