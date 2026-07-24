<?php

namespace App\Http\Controllers\Api\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\ActOnValidationRequest;
use App\Http\Requests\Validation\StartWorkflowRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\ValidationResource;
use App\Models\Document;
use App\Models\Validation;
use App\Models\Workflow;
use App\Services\Validation\ValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ValidationController extends Controller
{
    public function __construct(
        private readonly ValidationService $validationService,
    ) {}

    public function index(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        return ValidationResource::collection(
            $this->validationService->listForDocument($document)
        );
    }

    public function start(StartWorkflowRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $workflow = Workflow::query()->findOrFail($request->integer('workflow_id'));
        $document = $this->validationService->start($document, $workflow);

        $current = $this->validationService->currentPending($document);
        $current?->load([
            'workflowStep.responsibleRole',
            'workflowStep.responsibleUser',
            'user',
        ]);

        return response()->json([
            'message' => 'Workflow démarré. Document en validation.',
            'document' => new DocumentResource($document),
            'current_validation' => $current ? new ValidationResource($current) : null,
        ]);
    }

    public function restart(Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $document = $this->validationService->restart($document);

        return response()->json([
            'message' => 'Workflow relancé après correction.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function approve(ActOnValidationRequest $request, Validation $validation): JsonResponse
    {
        $this->authorize('act', $validation);

        $document = $this->validationService->approve(
            $validation,
            $request->user(),
            $request->validated('comment'),
        );

        return response()->json([
            'message' => 'Étape approuvée.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function reject(ActOnValidationRequest $request, Validation $validation): JsonResponse
    {
        $this->authorize('act', $validation);

        $document = $this->validationService->reject(
            $validation,
            $request->user(),
            $request->validated('comment'),
        );

        return response()->json([
            'message' => 'Document rejeté.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function requestCorrection(ActOnValidationRequest $request, Validation $validation): JsonResponse
    {
        $this->authorize('act', $validation);

        $document = $this->validationService->requestCorrection(
            $validation,
            $request->user(),
            $request->validated('comment'),
        );

        return response()->json([
            'message' => 'Correction demandée. Document repassé en brouillon.',
            'document' => new DocumentResource($document),
        ]);
    }
}
