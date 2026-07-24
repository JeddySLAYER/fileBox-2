<?php

namespace App\Http\Controllers\Api\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\SyncProjectMembersRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Project\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $projects = $this->projectService->list(
            filters: $request->only(['search', 'department_id', 'status', 'trashed']),
            perPage: (int) $request->integer('per_page', 15),
        );

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $project = $this->projectService->create($request->validated());

        return response()->json([
            'message' => 'Projet créé.',
            'project' => new ProjectResource($project),
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load(['department', 'manager', 'members'])->loadCount('members');

        return response()->json([
            'project' => new ProjectResource($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project = $this->projectService->update($project, $request->validated());

        return response()->json([
            'message' => 'Projet mis à jour.',
            'project' => new ProjectResource($project),
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $this->projectService->delete($project);

        return response()->json([
            'message' => 'Projet placé dans la corbeille.',
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $project = Project::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $project);

        $project = $this->projectService->restore($project);

        return response()->json([
            'message' => 'Projet restauré.',
            'project' => new ProjectResource($project),
        ]);
    }

    public function syncMembers(SyncProjectMembersRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project = $this->projectService->syncMembers($project, $request->validated('member_ids'));

        return response()->json([
            'message' => 'Membres du projet synchronisés.',
            'project' => new ProjectResource($project),
        ]);
    }
}
