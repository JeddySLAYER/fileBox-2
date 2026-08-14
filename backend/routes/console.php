<?php

use App\Services\Access\AccessService;
use App\Services\Validation\ValidationReminderService;
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

Artisan::command('notifications:validation-reminders', function (ValidationReminderService $reminders) {
    $count = $reminders->sendDueReminders();
    $this->info("Rappels de validation envoyés: {$count}");
})->purpose('Remind validators when a step is approaching or past its deadline');

Artisan::command('projects:sync-members', function (\App\Services\Project\ProjectService $projects) {
    $count = $projects->syncAllMandatoryMembers();
    $this->info("Projets resynchronisés (créateurs + responsables): {$count}");
})->purpose('Ajoute créateurs et responsables aux projets existants');

Artisan::command('users:clear-invite-departments', function () {
    $count = \App\Models\User::query()
        ->whereNotNull('department_id')
        ->whereHas('roles', fn ($q) => $q->whereIn('slug', \App\Models\User::ROLES_WITHOUT_DEPARTMENT))
        ->update(['department_id' => null]);

    $this->info("Comptes transverses détachés de leur département: {$count}");
})->purpose('Retire le département des comptes admin, direction, chef de projet et invité');

Schedule::command('accesses:revoke-expired')->everyMinute();
Schedule::command('notifications:access-deadlines')->hourly();
Schedule::command('notifications:validation-reminders')->everyFifteenMinutes();
