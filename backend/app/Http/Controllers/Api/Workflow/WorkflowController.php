<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\StoreWorkflowRequest;
use App\Http\Requests\Workflow\UpdateWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Services\Workflow\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowService $workflowService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Workflow::class);

        $workflows = $this->workflowService->list(
            filters: $request->only(['search', 'is_active']),
            perPage: (int) $request->integer('per_page', 15),
        );

        return WorkflowResource::collection($workflows);
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $this->authorize('create', Workflow::class);

        $workflow = $this->workflowService->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'Workflow créé.',
            'workflow' => new WorkflowResource($workflow),
        ], 201);
    }

    public function show(Workflow $workflow): JsonResponse
    {
        $this->authorize('view', $workflow);

        $workflow->load(['steps.responsibleRole', 'steps.responsibleUser', 'creator'])
            ->loadCount($this->workflowService->usageCounts());

        return response()->json([
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    public function update(UpdateWorkflowRequest $request, Workflow $workflow): JsonResponse
    {
        $this->authorize('update', $workflow);

        $workflow = $this->workflowService->update($workflow, $request->validated());

        return response()->json([
            'message' => 'Workflow mis à jour.',
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    public function destroy(Workflow $workflow): JsonResponse
    {
        $this->authorize('delete', $workflow);

        $this->workflowService->delete($workflow);

        return response()->json([
            'message' => 'Workflow supprimé.',
        ]);
    }
}
