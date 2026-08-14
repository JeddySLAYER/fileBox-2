<?php

namespace Database\Seeders;

use App\Enums\ConfidentialityLevel;
use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Models\Access;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Comment;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Favorite;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Models\Validation;
use App\Models\Version;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Notifications\CommentPostedNotification;
use App\Services\Storage\FileStorageService;
use App\Support\DocumentEditability;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::query()->orderBy('id')->get();
        if ($users->isEmpty()) {
            $this->command?->warn('Aucun utilisateur en base. Créez un compte, puis relancez : php artisan db:seed --class=DemoDataSeeder');

            return;
        }

        $actor = $users->first();
        $other = $users->skip(1)->first() ?? $actor;
        $files = app(FileStorageService::class);

        $dsi = $this->department('DSI', 'DSI', 'Direction des systèmes d’information');
        $rh = $this->department('Ressources humaines', 'RH', 'Gestion du personnel et des formations');
        $fin = $this->department('Finance', 'FIN', 'Comptabilité et contrôle de gestion');

        $tagUrgent = $this->tag('Urgent', 'urgent');
        $tagLegal = $this->tag('Juridique', 'juridique');
        $tagInterne = $this->tag('Interne', 'interne');

        $wf = Workflow::query()->updateOrCreate(
            ['code' => 'WF-VALID-STD'],
            [
                'name' => 'Validation standard',
                'description' => 'Deux étapes : relecture puis validation.',
                'is_active' => true,
                'created_by' => $actor->id,
            ]
        );
        WorkflowStep::query()->updateOrCreate(
            ['workflow_id' => $wf->id, 'step_order' => 1],
            ['name' => 'Validation 1', 'responsible_user_id' => $actor->id, 'is_mandatory' => true, 'duration_hours' => 48, 'reminder_hours_before' => 12, 'remind_on_overdue' => true]
        );
        WorkflowStep::query()->updateOrCreate(
            ['workflow_id' => $wf->id, 'step_order' => 2],
            ['name' => 'Validation 2', 'responsible_user_id' => $other->id, 'is_mandatory' => true, 'duration_hours' => 24, 'reminder_hours_before' => 4, 'remind_on_overdue' => true]
        );
        $step1 = WorkflowStep::query()->where('workflow_id', $wf->id)->where('step_order', 1)->first();

        $typeNote = $this->documentType('Note interne', 'note-interne', 'Notes de service et comptes rendus', null, false);
        $typeContrat = $this->documentType('Contrat', 'contrat', 'Contrats et avenants', $wf->id, true);
        $typeRapport = $this->documentType('Rapport', 'rapport', 'Rapports d’activité et de stage', $wf->id, false);

        $projetGed = Project::query()->updateOrCreate(
            ['code' => 'PRJ-GED'],
            [
                'name' => 'Déploiement FileBox',
                'description' => 'Mise en place de la GED interne.',
                'department_id' => $dsi->id,
                'manager_id' => $actor->id,
                'created_by' => $actor->id,
                'status' => 'actif',
                'starts_at' => now()->subMonths(2)->toDateString(),
                'ends_at' => now()->addMonths(4)->toDateString(),
            ]
        );
        $projetGed->departments()->syncWithoutDetaching([$dsi->id, $rh->id]);
        $projetGed->members()->syncWithoutDetaching([$actor->id, $other->id]);

        $projetRh = Project::query()->updateOrCreate(
            ['code' => 'PRJ-RH-2026'],
            [
                'name' => 'Campagne formations 2026',
                'description' => 'Plan de formation des collaborateurs.',
                'department_id' => $rh->id,
                'manager_id' => $actor->id,
                'created_by' => $actor->id,
                'status' => 'en_pause',
                'starts_at' => now()->startOfYear()->toDateString(),
            ]
        );
        $projetRh->departments()->syncWithoutDetaching([$rh->id]);
        $projetRh->members()->syncWithoutDetaching([$actor->id]);

        $rootGed = $this->folder('FileBox GED', $actor, $projetGed->id, $dsi->id, null, projectRoot: true);
        $projetGed->update(['root_folder_id' => $rootGed->id]);
        $folderContrats = $this->folder('Contrats', $actor, $projetGed->id, $dsi->id, $rootGed->id);
        $folderNotes = $this->folder('Notes internes', $actor, $projetGed->id, $dsi->id, $rootGed->id);
        $folderRh = $this->folder('RH', $actor, $projetRh->id, $rh->id, null, projectRoot: true);
        $projetRh->update(['root_folder_id' => $folderRh->id]);
        $folderPerso = $this->folder('Documents personnels', $actor, null, null, null);

        $folderContrats->tags()->syncWithoutDetaching([$tagLegal->id]);
        $folderNotes->tags()->syncWithoutDetaching([$tagInterne->id]);

        $note = $this->document($files, $actor, [
            'reference' => 'DEMO-NOTE-001',
            'title' => 'Compte rendu comité GED',
            'description' => 'Points validés lors du comité de pilotage.',
            'summary' => "Le comité a validé le calendrier de déploiement FileBox et la formation des référents.",
            'folder_id' => $folderNotes->id,
            'project_id' => $projetGed->id,
            'department_id' => $dsi->id,
            'document_type_id' => $typeNote->id,
            'status' => DocumentStatus::Published,
            'confidentiality' => ConfidentialityLevel::PublicInternal,
        ], "Comité GED — ".now()->format('d/m/Y')."\n\n- Calendrier : go live fin de trimestre\n- Formation des référents DSI / RH\n- Prochaine réunion : suivi des imports", 'compte-rendu.txt');

        $contrat = $this->document($files, $actor, [
            'reference' => 'DEMO-CTR-001',
            'title' => 'Contrat prestataire hébergement',
            'description' => 'Contrat-cadre d’hébergement des documents.',
            'folder_id' => $folderContrats->id,
            'project_id' => $projetGed->id,
            'department_id' => $dsi->id,
            'document_type_id' => $typeContrat->id,
            'workflow_id' => $wf->id,
            'status' => DocumentStatus::InValidation,
            'confidentiality' => ConfidentialityLevel::Confidential,
        ], "Contrat d’hébergement\n\nObjet : stockage des documents FileBox.\nDurée : 24 mois.\nClauses de réversibilité et de confidentialité.", 'contrat-hebergement.txt');

        $rapport = $this->document($files, $actor, [
            'reference' => 'DEMO-RPT-001',
            'title' => 'Rapport de stage L3',
            'description' => 'Rapport d’activité du stagiaire.',
            'folder_id' => $folderPerso->id,
            'document_type_id' => $typeRapport->id,
            'status' => DocumentStatus::Draft,
            'confidentiality' => ConfidentialityLevel::Restricted,
        ], "Rapport de stage\n\nIntroduction\nCe document présente le travail réalisé sur FileBox.\n\nConclusion\nLa GED couvre explorateur, workflows et outils IA.", 'rapport-stage.txt');

        $archive = $this->document($files, $actor, [
            'reference' => 'DEMO-ARC-001',
            'title' => 'Ancienne procédure papier',
            'description' => 'Procédure remplacée, conservée pour historique.',
            'folder_id' => $folderNotes->id,
            'project_id' => $projetGed->id,
            'department_id' => $dsi->id,
            'document_type_id' => $typeNote->id,
            'status' => DocumentStatus::Archived,
            'archived_at' => Carbon::now()->subDays(12),
            'confidentiality' => ConfidentialityLevel::PublicInternal,
        ], "Procédure d’archivage papier (obsolète).\nRemplacée par FileBox.", 'procedure-papier.txt');

        $planRh = $this->document($files, $actor, [
            'reference' => 'DEMO-RH-001',
            'title' => 'Plan de formation 2026',
            'description' => 'Calendrier des sessions internes.',
            'folder_id' => $folderRh->id,
            'project_id' => $projetRh->id,
            'department_id' => $rh->id,
            'document_type_id' => $typeNote->id,
            'status' => DocumentStatus::Validated,
            'confidentiality' => ConfidentialityLevel::PublicInternal,
        ], "Plan de formation 2026\n\n- GED FileBox : 2 sessions\n- Sécurité de l’information : 1 session", 'plan-formation.txt');

        $note->tags()->syncWithoutDetaching([$tagInterne->id]);
        $contrat->tags()->syncWithoutDetaching([$tagLegal->id, $tagUrgent->id]);
        $archive->tags()->syncWithoutDetaching([$tagInterne->id]);
        $planRh->tags()->syncWithoutDetaching([$tagInterne->id]);

        Comment::query()->updateOrCreate(
            ['document_id' => $note->id, 'user_id' => $other->id, 'parent_id' => null],
            ['content' => 'Merci pour le CR, le calendrier me convient.']
        );
        $comment = Comment::query()->updateOrCreate(
            ['document_id' => $contrat->id, 'user_id' => $actor->id, 'parent_id' => null],
            ['content' => 'À relire la clause de réversibilité avant validation.']
        );

        if ($step1) {
            Validation::query()->updateOrCreate(
                ['document_id' => $contrat->id, 'workflow_step_id' => $step1->id],
                [
                    'user_id' => $actor->id,
                    'status' => ValidationStatus::Pending,
                    'sla_hours' => 48,
                    'due_at' => now()->addDay(),
                ]
            );
        }

        Access::query()->updateOrCreate(
            [
                'user_id' => $other->id,
                'accessible_type' => 'document',
                'accessible_id' => $contrat->id,
            ],
            [
                'abilities' => ['view', 'download'],
                'granted_by' => $actor->id,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(2),
            ]
        );

        Favorite::query()->firstOrCreate([
            'user_id' => $actor->id,
            'favoritable_type' => 'document',
            'favoritable_id' => $note->id,
        ]);
        Favorite::query()->firstOrCreate([
            'user_id' => $actor->id,
            'favoritable_type' => 'folder',
            'favoritable_id' => $folderContrats->id,
        ]);

        ActivityLog::query()->updateOrCreate(
            ['action' => 'demo.seeded', 'subject_type' => Document::class, 'subject_id' => $note->id],
            [
                'user_id' => $actor->id,
                'description' => 'Jeu de données de démonstration chargé.',
                'properties' => ['source' => 'DemoDataSeeder'],
            ]
        );
        ActivityLog::query()->updateOrCreate(
            ['action' => 'document.archived', 'subject_type' => Document::class, 'subject_id' => $archive->id],
            [
                'user_id' => $actor->id,
                'description' => 'Document archivé : '.$archive->reference,
            ]
        );

        if ($actor->id !== $other->id) {
            $other->notify(new CommentPostedNotification($comment, $contrat));
        } else {
            $actor->notify(new CommentPostedNotification($comment, $contrat));
        }

        Backup::query()->updateOrCreate(
            ['name' => 'Démo — sauvegarde initiale'],
            [
                'path' => 'backups/demo-initiale.zip',
                'size' => 2048,
                'status' => 'completed',
                'notes' => 'Entrée de démonstration (fichier fictif).',
                'created_by' => $actor->id,
            ]
        );

        $this->command?->info('Données de démo chargées (départements, projets, dossiers, documents, workflows, etc.). Utilisateurs inchangés.');
    }

    private function department(string $name, string $code, string $description, ?int $managerId = null): Department
    {
        return Department::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'description' => $description, 'manager_id' => $managerId]
        );
    }

    private function tag(string $name, string $slug): Tag
    {
        return Tag::query()->updateOrCreate(['slug' => $slug], ['name' => $name]);
    }

    private function documentType(
        string $name,
        string $slug,
        string $description,
        ?int $workflowId,
        bool $requiresWorkflow,
    ): DocumentType {
        return DocumentType::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'default_workflow_id' => $workflowId,
                'requires_workflow' => $requiresWorkflow,
            ]
        );
    }

    private function folder(string $name, User $actor, ?int $projectId, ?int $departmentId, ?int $parentId, bool $projectRoot = false): Folder
    {
        return Folder::query()->updateOrCreate(
            [
                'name' => $name,
                'parent_id' => $parentId,
                'project_id' => $projectId,
            ],
            [
                'department_id' => $departmentId,
                'created_by' => $actor->id,
                'is_project_root' => $projectRoot,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function document(
        FileStorageService $files,
        User $actor,
        array $meta,
        string $content,
        string $fileName,
    ): Document {
        $existing = Document::query()->where('reference', $meta['reference'])->first();
        if ($existing) {
            return $existing->load('currentVersion');
        }

        $document = Document::query()->create([
            'reference' => $meta['reference'],
            'title' => $meta['title'],
            'description' => $meta['description'] ?? null,
            'summary' => $meta['summary'] ?? null,
            'folder_id' => $meta['folder_id'],
            'project_id' => $meta['project_id'] ?? null,
            'department_id' => $meta['department_id'] ?? null,
            'document_type_id' => $meta['document_type_id'] ?? null,
            'workflow_id' => $meta['workflow_id'] ?? null,
            'author_id' => $actor->id,
            'owner_id' => $actor->id,
            'status' => $meta['status'] ?? DocumentStatus::Draft,
            'confidentiality' => $meta['confidentiality'] ?? ConfidentialityLevel::PublicInternal,
            'is_editable' => DocumentEditability::fromFileName($fileName),
            'language' => 'fr',
            'archived_at' => $meta['archived_at'] ?? null,
        ]);

        $stored = $files->storeDocumentContent(
            content: $content,
            documentId: $document->id,
            versionNumber: 1,
            fileName: $fileName,
            mimeType: 'text/plain',
        );

        $version = Version::query()->create([
            'document_id' => $document->id,
            'version_number' => 1,
            'file_path' => $stored['file_path'],
            'file_name' => $stored['file_name'],
            'mime_type' => $stored['mime_type'],
            'extension' => $stored['extension'],
            'size' => $stored['size'],
            'checksum' => $stored['checksum'],
            'created_by' => $actor->id,
            'change_summary' => 'Version initiale (démo)',
            'is_locked' => false,
        ]);

        $document->current_version_id = $version->id;
        $document->save();

        return $document->fresh('currentVersion');
    }
}
