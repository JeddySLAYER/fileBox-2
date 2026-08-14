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
     * Projet public = éligible au workflow.
     * Le circuit ne démarre que si le document est proposé, sauf si le type exige une validation.
     */
    public static function subjectToWorkflow(Document $document): bool
    {
        return self::isProjectPublic($document);
    }

    public static function requiresWorkflow(Document $document): bool
    {
        $document->loadMissing('documentType');

        return (bool) $document->documentType?->requires_workflow;
    }

    public static function recommendsWorkflow(Document $document): bool
    {
        if (! self::isProjectPublic($document)) {
            return false;
        }

        $document->loadMissing('documentType');

        return self::requiresWorkflow($document) || (bool) $document->documentType?->default_workflow_id;
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

        if ($document->status === DocumentStatus::Proposed) {
            return true;
        }

        // Type qui exige une validation : un responsable peut démarrer sans passer par « proposé ».
        return self::requiresWorkflow($document)
            && in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Rejected], true);
    }

    public static function resolveWorkflowId(?int $explicitWorkflowId, ?DocumentType $type): ?int
    {
        return $explicitWorkflowId ?? $type?->default_workflow_id;
    }
}
