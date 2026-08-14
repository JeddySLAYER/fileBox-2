<?php

namespace App\Services\Search;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\SpaceVisibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SearchService
{
    public function __construct(
        private readonly SpaceVisibility $spaceVisibility,
    ) {}

    /**
     * Recherche multicritère sur documents (et dossiers si demandé).
     *
     * @param  array{
     *   q?: string,
     *   status?: string,
     *   folder_id?: int,
     *   project_id?: int,
     *   department_id?: int,
     *   document_type_id?: int,
     *   tag?: string,
     *   tag_ids?: array<int>,
     *   confidentiality?: string,
     *   author_id?: int,
     *   is_editable?: bool|string,
     *   include_ocr?: bool|string
     * }  $filters
     */
    public function searchDocuments(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Document::query()
            ->with(['folder', 'author', 'owner', 'currentVersion', 'documentType', 'tags', 'project'])
            ->latest();

        if (! empty($filters['q'])) {
            $q = mb_strtolower($filters['q']);
            // OCR = extraction de contenu scanné, pas le moteur de recherche (opt-in via include_ocr)
            $includeOcr = filter_var($filters['include_ocr'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $query->where(function ($builder) use ($q, $includeOcr) {
                $builder->whereRaw('LOWER(title) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(reference) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(COALESCE(summary, \'\')) LIKE ?', ["%{$q}%"])
                    ->orWhereHas('tags', fn ($t) => $t->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                        ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$q}%"]));

                if ($includeOcr) {
                    $builder->orWhereHas('versions', fn ($v) => $v->whereRaw('LOWER(COALESCE(ocr_text, \'\')) LIKE ?', ["%{$q}%"]));
                }
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', DocumentStatus::Archived);
        }

        if (! empty($filters['folder_id'])) {
            $query->where('folder_id', $filters['folder_id']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['document_type_id'])) {
            $query->where('document_type_id', $filters['document_type_id']);
        }

        if (! empty($filters['confidentiality'])) {
            $query->where('confidentiality', $filters['confidentiality']);
        }

        if (! empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        if (array_key_exists('is_editable', $filters) && $filters['is_editable'] !== null && $filters['is_editable'] !== '') {
            $query->where('is_editable', filter_var($filters['is_editable'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['tag'])) {
            $tag = mb_strtolower($filters['tag']);
            $query->whereHas('tags', fn ($t) => $t->whereRaw('LOWER(slug) = ?', [$tag])
                ->orWhereRaw('LOWER(name) = ?', [$tag]));
        }

        if (! empty($filters['tag_ids']) && is_array($filters['tag_ids'])) {
            $query->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $filters['tag_ids']));
        }

        $this->spaceVisibility->applyDocumentScope($query, $actor);

        return $query->paginate($perPage);
    }

    /**
     * @param  array{q?: string, project_id?: int, department_id?: int}  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, Folder>
     */
    public function searchFolders(User $actor, array $filters = [])
    {
        $query = Folder::query()
            ->with(['project', 'department', 'creator'])
            ->withCount(['children', 'documents'])
            ->orderBy('name');

        if (! empty($filters['q'])) {
            $q = mb_strtolower($filters['q']);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        $this->spaceVisibility->applyFolderScope($query, $actor);

        return $query->limit(50)->get();
    }
}
