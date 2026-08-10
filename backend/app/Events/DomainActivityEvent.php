<?php

namespace App\Events;

use App\Contracts\LogsActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class DomainActivityEvent implements LogsActivity
{
    public function __construct(
        protected string $action,
        protected ?User $user = null,
        protected ?Model $subject = null,
        protected ?string $description = null,
        protected array $properties = [],
    ) {}

    public function activityAction(): string
    {
        return $this->action;
    }

    public function activityUser(): ?User
    {
        return $this->user;
    }

    public function activitySubject(): ?Model
    {
        return $this->subject;
    }

    public function activityDescription(): ?string
    {
        return $this->description;
    }

    public function activityProperties(): array
    {
        return $this->properties;
    }
}
