<?php

namespace App\Http\Controllers\Api\Setting;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSettingResource;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Setting\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        return SystemSettingResource::collection($this->settingService->all());
    }

    public function show(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $settings = $this->settingService->all()->firstWhere('key', $key);
        abort_if(! $settings, 404, 'Paramètre introuvable.');

        return response()->json([
            'setting' => new SystemSettingResource($settings),
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'value' => ['nullable'],
            'type' => ['sometimes', Rule::in(['string', 'boolean', 'integer', 'json'])],
            'description' => ['nullable', 'string'],
        ]);

        $setting = $this->settingService->upsert($data['key'], $data);

        $this->activityLog->log(
            action: 'settings.updated',
            user: $request->user(),
            description: "Paramètre mis à jour : {$data['key']}",
            properties: ['key' => $data['key']],
        );

        return response()->json([
            'message' => 'Paramètre enregistré.',
            'setting' => new SystemSettingResource($setting),
        ]);
    }

    public function bulk(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.value' => ['nullable'],
            'settings.*.type' => ['sometimes', Rule::in(['string', 'boolean', 'integer', 'json'])],
            'settings.*.description' => ['nullable', 'string'],
        ]);

        $settings = $this->settingService->upsertMany($data['settings']);

        $this->activityLog->log(
            action: 'settings.bulk_updated',
            user: $request->user(),
            description: 'Mise à jour groupée des paramètres système',
            properties: ['keys' => array_keys($data['settings'])],
        );

        return response()->json([
            'message' => 'Paramètres mis à jour.',
            'settings' => SystemSettingResource::collection($settings),
        ]);
    }
}
