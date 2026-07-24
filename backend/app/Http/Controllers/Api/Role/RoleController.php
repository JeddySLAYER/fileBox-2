<?php

namespace App\Http\Controllers\Api\Role;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\SyncRolePermissionsRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\Role\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Role::class);

        return RoleResource::collection($this->roleService->list());
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = $this->roleService->create($request->validated());

        return response()->json([
            'message' => 'Rôle créé.',
            'role' => new RoleResource($role),
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role->load('permissions')->loadCount('users');

        return response()->json([
            'role' => new RoleResource($role),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $role = $this->roleService->update($role, $request->validated());

        return response()->json([
            'message' => 'Rôle mis à jour.',
            'role' => new RoleResource($role),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roleService->delete($role);

        return response()->json([
            'message' => 'Rôle supprimé.',
        ]);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $role = $this->roleService->syncPermissions($role, $request->validated('permission_ids'));

        return response()->json([
            'message' => 'Permissions du rôle synchronisées.',
            'role' => new RoleResource($role),
        ]);
    }
}
