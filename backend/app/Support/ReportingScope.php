<?php

namespace App\Support;

use App\Models\User;

/**
 * Périmètre reporting (dashboard KPIs + journal métier).
 * global = admin/direction ; department = responsable ; project = chef de projet.
 */
final class ReportingScope
{
    public const MODE_GLOBAL = 'global';

    public const MODE_DEPARTMENT = 'department';

    public const MODE_PROJECT = 'project';

    public const MODE_NONE = 'none';

    /** @var list<int> */
    private array $departmentIds;

    /** @var list<int> */
    private array $projectIds;

    private string $mode;

    public function __construct(private readonly User $user)
    {
        $roleSlugs = $user->relationLoaded('roles')
            ? $user->roles->pluck('slug')->all()
            : $user->roles()->pluck('slug')->all();

        if (in_array('administrateur', $roleSlugs, true) || in_array('direction', $roleSlugs, true)) {
            $this->mode = self::MODE_GLOBAL;
            $this->departmentIds = [];
            $this->projectIds = [];

            return;
        }

        if (in_array('responsable_departement', $roleSlugs, true)) {
            $ids = $user->managedDepartments()->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === [] && $user->department_id) {
                $ids = [(int) $user->department_id];
            }
            $this->mode = self::MODE_DEPARTMENT;
            $this->departmentIds = $ids;
            $this->projectIds = [];

            return;
        }

        if (in_array('chef_projet', $roleSlugs, true)) {
            $this->mode = self::MODE_PROJECT;
            $this->departmentIds = [];
            $this->projectIds = $user->managedProjects()->pluck('id')->map(fn ($id) => (int) $id)->all();

            return;
        }

        $this->mode = self::MODE_NONE;
        $this->departmentIds = [];
        $this->projectIds = [];
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isGlobal(): bool
    {
        return $this->mode === self::MODE_GLOBAL;
    }

    public function canAccess(): bool
    {
        return $this->mode !== self::MODE_NONE;
    }

    /**
     * @return list<int>
     */
    public function departmentIds(): array
    {
        return $this->departmentIds;
    }

    /**
     * @return list<int>
     */
    public function projectIds(): array
    {
        return $this->projectIds;
    }

    /**
     * @return array{mode: string, label: string, department_ids: list<int>, project_ids: list<int>}
     */
    public function meta(): array
    {
        $label = match ($this->mode) {
            self::MODE_GLOBAL => 'Vue globale',
            self::MODE_DEPARTMENT => 'Vue département',
            self::MODE_PROJECT => 'Vue projets',
            default => 'Aucun accès',
        };

        return [
            'mode' => $this->mode,
            'label' => $label,
            'department_ids' => $this->departmentIds,
            'project_ids' => $this->projectIds,
        ];
    }
}
