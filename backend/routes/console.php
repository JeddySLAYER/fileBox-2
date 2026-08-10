<?php

use App\Services\Access\AccessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('accesses:revoke-expired', function (AccessService $accessService) {
    $count = $accessService->revokeExpired();
    $this->info("Accès expirés révoqués: {$count}");
})->purpose('Revoke expired resource accesses');

Artisan::command('notifications:access-deadlines', function (AccessService $accessService) {
    $count = $accessService->notifyUpcomingExpirations();
    $this->info("Rappels d'expiration envoyés: {$count}");
})->purpose('Notify users whose temporary access expires within 24 hours');

Artisan::command('projects:sync-members', function (\App\Services\Project\ProjectService $projects) {
    $count = $projects->syncAllMandatoryMembers();
    $this->info("Projets resynchronisés (créateurs + responsables): {$count}");
})->purpose('Ajoute créateurs et responsables aux projets existants');

Artisan::command('users:clear-invite-departments', function () {
    $count = \App\Models\User::query()
        ->whereNotNull('department_id')
        ->whereHas('roles', fn ($q) => $q->where('slug', 'invite'))
        ->update(['department_id' => null]);

    $this->info("Invités détachés de leur département: {$count}");
})->purpose('Retire le département de tous les comptes invités');

Schedule::command('accesses:revoke-expired')->everyMinute();
Schedule::command('notifications:access-deadlines')->hourly();
