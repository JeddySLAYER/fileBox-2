<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Utilisateurs
            ['name' => 'Voir les utilisateurs', 'slug' => 'users.view', 'module' => 'users'],
            ['name' => 'Créer un utilisateur', 'slug' => 'users.create', 'module' => 'users'],
            ['name' => 'Modifier un utilisateur', 'slug' => 'users.update', 'module' => 'users'],
            ['name' => 'Supprimer un utilisateur', 'slug' => 'users.delete', 'module' => 'users'],

            // Rôles & permissions
            ['name' => 'Gérer les rôles', 'slug' => 'roles.manage', 'module' => 'roles'],
            ['name' => 'Gérer les permissions', 'slug' => 'permissions.manage', 'module' => 'permissions'],

            // Organisation
            ['name' => 'Gérer les départements', 'slug' => 'departments.manage', 'module' => 'departments'],
            ['name' => 'Voir les projets', 'slug' => 'projects.view', 'module' => 'projects'],
            ['name' => 'Gérer les projets', 'slug' => 'projects.manage', 'module' => 'projects'],

            // Dossiers
            ['name' => 'Voir les dossiers', 'slug' => 'folders.view', 'module' => 'folders'],
            ['name' => 'Créer un dossier', 'slug' => 'folders.create', 'module' => 'folders'],
            ['name' => 'Modifier un dossier', 'slug' => 'folders.update', 'module' => 'folders'],
            ['name' => 'Supprimer un dossier', 'slug' => 'folders.delete', 'module' => 'folders'],

            // Documents
            ['name' => 'Voir les documents', 'slug' => 'documents.view', 'module' => 'documents'],
            ['name' => 'Créer un document', 'slug' => 'documents.create', 'module' => 'documents'],
            ['name' => 'Modifier un document', 'slug' => 'documents.update', 'module' => 'documents'],
            ['name' => 'Supprimer un document', 'slug' => 'documents.delete', 'module' => 'documents'],
            ['name' => 'Archiver un document', 'slug' => 'documents.archive', 'module' => 'documents'],
            ['name' => 'Partager un document', 'slug' => 'documents.share', 'module' => 'documents'],
            ['name' => 'Télécharger un document', 'slug' => 'documents.download', 'module' => 'documents'],

            // Versions / types / tags / commentaires
            ['name' => 'Gérer les versions', 'slug' => 'versions.manage', 'module' => 'versions'],
            ['name' => 'Gérer les types de documents', 'slug' => 'document_types.manage', 'module' => 'document_types'],
            ['name' => 'Gérer les tags', 'slug' => 'tags.manage', 'module' => 'tags'],
            ['name' => 'Commenter', 'slug' => 'comments.create', 'module' => 'comments'],

            // Workflows / validations / accès
            ['name' => 'Gérer les workflows', 'slug' => 'workflows.manage', 'module' => 'workflows'],
            ['name' => 'Valider un document', 'slug' => 'validations.act', 'module' => 'validations'],
            ['name' => 'Gérer les accès', 'slug' => 'accesses.manage', 'module' => 'accesses'],

            // Système
            ['name' => 'Gérer les paramètres', 'slug' => 'settings.manage', 'module' => 'settings'],
            ['name' => 'Voir le tableau de bord', 'slug' => 'dashboard.view', 'module' => 'dashboard'],
            ['name' => 'Voir le journal d\'activité', 'slug' => 'activity.view', 'module' => 'activity'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                $permission
            );
        }

        $roles = [
            'administrateur' => [
                'name' => 'Administrateur système',
                'description' => 'Privilèges techniques complets sur la plateforme.',
                'permissions' => Permission::query()->pluck('slug')->all(),
            ],
            'direction' => [
                'name' => 'Direction',
                'description' => 'Consultation stratégique, validations finales et tableaux de bord.',
                'permissions' => [
                    'dashboard.view',
                    'activity.view',
                    'documents.view',
                    'documents.download',
                    'folders.view',
                    'validations.act',
                    'comments.create',
                    'projects.view',
                    'projects.manage',
                ],
            ],
            'responsable_departement' => [
                'name' => 'Responsable de département',
                'description' => 'Crée et pilote les projets de son département (sans gestion des départements).',
                'permissions' => [
                    'dashboard.view',
                    'activity.view',
                    'projects.view',
                    'projects.manage',
                    'folders.view',
                    'folders.create',
                    'folders.update',
                    'folders.delete',
                    'documents.view',
                    'documents.create',
                    'documents.update',
                    'documents.delete',
                    'documents.share',
                    'documents.download',
                    'documents.archive',
                    'validations.act',
                    'comments.create',
                    'accesses.manage',
                    'tags.manage',
                ],
            ],
            'chef_projet' => [
                'name' => 'Chef de projet',
                'description' => 'Coordination documentaire des projets supervisés.',
                'permissions' => [
                    'dashboard.view',
                    'activity.view',
                    'projects.view',
                    'projects.manage',
                    'folders.view',
                    'folders.create',
                    'folders.update',
                    'folders.delete',
                    'documents.view',
                    'documents.create',
                    'documents.update',
                    'documents.delete',
                    'documents.share',
                    'documents.download',
                    'validations.act',
                    'comments.create',
                    'accesses.manage',
                    'tags.manage',
                    'workflows.manage',
                ],
            ],
            'collaborateur' => [
                'name' => 'Collaborateur',
                'description' => 'Acteur principal : création, consultation et collaboration documentaire.',
                'permissions' => [
                    'folders.view',
                    'folders.create',
                    'folders.update',
                    'folders.delete',
                    'documents.view',
                    'documents.create',
                    'documents.update',
                    'documents.delete',
                    'documents.share',
                    'documents.download',
                    'comments.create',
                    'tags.manage',
                    'versions.manage',
                    'projects.view',
                ],
            ],
            'invite' => [
                'name' => 'Invité',
                'description' => 'Accès ponctuel et restreint via partage explicite.',
                // Pas de documents.view / folders.view globaux : uniquement via Access
                'permissions' => [],
            ],
        ];

        foreach ($roles as $slug => $data) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                ]
            );

            $permissionIds = Permission::query()
                ->whereIn('slug', $data['permissions'])
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
