<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'version_number',
    'file_path',
    'file_name',
    'mime_type',
    'extension',
    'size',
    'page_count',
    'checksum',
    'created_by',
    'change_summary',
    'ocr_text',
])]
class Version extends Model
{
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'page_count' => 'integer',
            'version_number' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
