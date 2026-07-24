<?php

namespace App\Services\Setting;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    private const CACHE_KEY = 'filebox.system_settings';

    public function all(): Collection
    {
        return SystemSetting::query()->orderBy('key')->get();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->cachedMap();

        if (! array_key_exists($key, $settings)) {
            return $default;
        }

        return $this->castValue($settings[$key]['value'], $settings[$key]['type']);
    }

    /**
     * @param  array{value?: string|null, type?: string, description?: string|null}  $data
     */
    public function upsert(string $key, array $data): SystemSetting
    {
        $setting = SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => array_key_exists('value', $data) ? (string) $data['value'] : null,
                'type' => $data['type'] ?? 'string',
                'description' => $data['description'] ?? null,
            ]
        );

        Cache::forget(self::CACHE_KEY);

        return $setting;
    }

    /**
     * @param  array<string, array{value?: mixed, type?: string, description?: string|null}>  $items
     * @return Collection<int, SystemSetting>
     */
    public function upsertMany(array $items): Collection
    {
        foreach ($items as $key => $data) {
            $this->upsert($key, [
                'value' => isset($data['value']) ? (is_bool($data['value']) ? ($data['value'] ? '1' : '0') : (string) $data['value']) : null,
                'type' => $data['type'] ?? 'string',
                'description' => $data['description'] ?? null,
            ]);
        }

        return $this->all();
    }

    public function delete(string $key): void
    {
        SystemSetting::query()->where('key', $key)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{value: string|null, type: string}>
     */
    private function cachedMap(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            return SystemSetting::query()
                ->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (SystemSetting $s) => [
                    $s->key => ['value' => $s->value, 'type' => $s->type],
                ])
                ->all();
        });
    }

    private function castValue(?string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value ?? 'null', true),
            default => $value,
        };
    }
}
