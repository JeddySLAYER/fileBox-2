<?php

namespace App\Models;

use App\Enums\ConfidentialityLevel;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'reference',
    'title',
    'description',
    'summary',
    'folder_id',
    'project_id',
    'department_id',
    'document_type_id',
    'workflow_id',
    'author_id',
    'owner_id',
    'current_version_id',
    'status',
    'confidentiality',
    'is_editable',
    'language',
    'archived_at',
])]
class Document extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'confidentiality' => ConfidentialityLevel::class,
            'is_editable' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class)->orderBy('version_number');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function validations(): HasMany
    {
        return $this->hasMany(Validation::class);
    }

    public function accesses(): MorphMany
    {
        return $this->morphMany(Access::class, 'accessible');
    }
}
