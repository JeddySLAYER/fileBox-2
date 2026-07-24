<?php

namespace App\Http\Controllers\Api\Permission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Services\Permission\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Permission::class);

        $permissions = $this->permissionService->list($request->string('module')->toString() ?: null);

        return PermissionResource::collection($permissions);
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $this->authorize('create', Permission::class);

        $permission = $this->permissionService->create($request->validated());

        return response()->json([
            'message' => 'Permission créée.',
            'permission' => new PermissionResource($permission),
        ], 201);
    }

    public function show(Permission $permission): JsonResponse
    {
        $this->authorize('view', $permission);

        return response()->json([
            'permission' => new PermissionResource($permission),
        ]);
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $this->authorize('update', $permission);

        $permission = $this->permissionService->update($permission, $request->validated());

        return response()->json([
            'message' => 'Permission mise à jour.',
            'permission' => new PermissionResource($permission),
        ]);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $this->authorize('delete', $permission);

        $this->permissionService->delete($permission);

        return response()->json([
            'message' => 'Permission supprimée.',
        ]);
    }
}
