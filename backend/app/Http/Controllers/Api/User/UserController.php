<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = $this->userService->list(
            filters: $request->only(['search', 'department_id', 'role', 'is_active']),
            perPage: (int) $request->integer('per_page', 15),
        );

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $result = $this->userService->create($request->validated());

        return response()->json([
            'message' => $result['mail_sent']
                ? 'Utilisateur créé. Un mot de passe temporaire a été envoyé par e-mail.'
                : 'Utilisateur créé, mais l\'e-mail n\'a pas pu être envoyé. Utilisez le mot de passe temporaire affiché.',
            'temporary_password' => $result['temporary_password'],
            'mail_sent' => $result['mail_sent'],
            'user' => new UserResource($result['user']),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['roles.permissions', 'department']);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user = $this->userService->update($user, $request->validated());

        return response()->json([
            'message' => 'Utilisateur mis à jour.',
            'user' => new UserResource($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $this->userService->delete($request->user(), $user);

        return response()->json([
            'message' => 'Utilisateur supprimé.',
        ]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $result = $this->userService->resetTemporaryPassword($user);

        return response()->json([
            'message' => $result['mail_sent']
                ? 'Mot de passe temporaire régénéré et envoyé par e-mail.'
                : 'Mot de passe temporaire régénéré, mais l\'e-mail n\'a pas pu être envoyé.',
            'temporary_password' => $result['temporary_password'],
            'mail_sent' => $result['mail_sent'],
            'user' => new UserResource($result['user']),
        ]);
    }
}
