<?php

namespace App\Support;

use App\Enums\ConfidentialityLevel;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentType;

class DocumentWorkflow
{
    public static function isPersonal(Document $document): bool
    {
        return $document->project_id === null;
    }

    public static function isProjectPublic(Document $document): bool
    {
        if ($document->project_id === null) {
            return false;
        }

        $level = $document->confidentiality?->value ?? $document->confidentiality;

        return $level === ConfidentialityLevel::PublicInternal->value;
    }

    /**
     * Projet public = éligible au workflow (jamais obligatoire : on peut sauter).
     * requires_workflow sur le type = suggestion UI, pas une contrainte.
     */
    public static function subjectToWorkflow(Document $document): bool
    {
        return self::isProjectPublic($document);
    }

    public static function recommendsWorkflow(Document $document): bool
    {
        if (! self::isProjectPublic($document)) {
            return false;
        }

        $document->loadMissing('documentType');

        return (bool) ($document->documentType?->requires_workflow || $document->documentType?->default_workflow_id);
    }

    /** @param  array{project_id?: int|null, confidentiality?: string|null}  $data */
    public static function wouldBeProjectPublic(array $data): bool
    {
        if (empty($data['project_id'])) {
            return false;
        }

        $level = $data['confidentiality'] ?? ConfidentialityLevel::PublicInternal->value;

        return $level === ConfidentialityLevel::PublicInternal->value;
    }

    public static function canPropose(Document $document): bool
    {
        if (! self::subjectToWorkflow($document)) {
            return false;
        }

        return in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Rejected], true);
    }

    public static function canStartValidation(Document $document): bool
    {
        if (self::isPersonal($document)) {
            return false;
        }

        // Depuis "proposé" (chemin nominal) ou brouillon/rejeté (démarrage direct / skip propose)
        return in_array($document->status, [
            DocumentStatus::Proposed,
            DocumentStatus::Draft,
            DocumentStatus::Rejected,
        ], true);
    }

    public static function resolveWorkflowId(?int $explicitWorkflowId, ?DocumentType $type): ?int
    {
        return $explicitWorkflowId ?? $type?->default_workflow_id;
    }
}
