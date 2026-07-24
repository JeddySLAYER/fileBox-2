<?php

namespace App\Policies;

use App\Models\DocumentType;
use App\Models\User;

class DocumentTypePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('document_types.manage')
            || $actor->hasPermission('documents.view');
    }

    public function view(User $actor, DocumentType $type): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('document_types.manage');
    }

    public function update(User $actor, DocumentType $type): bool
    {
        return $actor->hasPermission('document_types.manage');
    }

    public function delete(User $actor, DocumentType $type): bool
    {
        return $actor->hasPermission('document_types.manage');
    }

    public function restore(User $actor, DocumentType $type): bool
    {
        return $actor->hasPermission('document_types.manage');
    }
}
