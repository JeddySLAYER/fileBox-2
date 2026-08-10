<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

interface LogsActivity
{
    public function activityAction(): string;

    public function activityUser(): ?User;

    public function activitySubject(): ?Model;

    public function activityDescription(): ?string;

    /** @return array<string, mixed> */
    public function activityProperties(): array;
}
