<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'must_change_password' => $this->must_change_password,
            'temporary_password_expires_at' => $this->temporary_password_expires_at,
            'is_active' => $this->is_active,
            'department_id' => $this->department_id,
            'email_verified_at' => $this->email_verified_at,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department?->id,
                'name' => $this->department?->name,
                'code' => $this->department?->code,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'permissions' => $role->relationLoaded('permissions')
                    ? $role->permissions->pluck('slug')
                    : [],
            ])),
            'permissions' => $this->whenLoaded('roles', function () {
                return $this->roles
                    ->flatMap(fn ($role) => $role->relationLoaded('permissions')
                        ? $role->permissions->pluck('slug')
                        : collect())
                    ->unique()
                    ->values();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
