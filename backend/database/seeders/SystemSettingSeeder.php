<?php

namespace Database\Seeders;

use App\Services\Setting\SettingService;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'app.name' => [
                'value' => 'FileBox',
                'type' => 'string',
                'description' => 'Nom de la plateforme',
            ],
            'app.locale' => [
                'value' => 'fr',
                'type' => 'string',
                'description' => 'Langue par défaut',
            ],
            'documents.max_upload_mb' => [
                'value' => '50',
                'type' => 'integer',
                'description' => 'Taille max d\'upload (Mo)',
            ],
            'documents.allow_public_share' => [
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Autoriser le partage public externe',
            ],
            'security.session_lifetime_minutes' => [
                'value' => '120',
                'type' => 'integer',
                'description' => 'Durée de session (minutes)',
            ],
            'backup.retention_days' => [
                'value' => '30',
                'type' => 'integer',
                'description' => 'Rétention des sauvegardes (jours)',
            ],
        ];

        /** @var SettingService $settings */
        $settings = app(SettingService::class);
        $settings->upsertMany($defaults);
    }
}
