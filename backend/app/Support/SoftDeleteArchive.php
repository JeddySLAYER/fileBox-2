<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Soft-delete non-resource models while freeing unique fields for reuse.
 * State is tracked by deleted_at (and is_active=false when present).
 */
final class SoftDeleteArchive
{
    /**
     * @param  list<string>  $uniqueFields
     */
    public static function archive(Model $model, array $uniqueFields): void
    {
        $token = Str::lower(Str::random(8));
        $id = $model->getKey();

        foreach ($uniqueFields as $field) {
            if ($field === 'email') {
                $model->setAttribute($field, "deleted+{$id}+{$token}@deleted.local");
            } else {
                $model->setAttribute($field, "deleted_{$id}_{$token}");
            }
        }

        if (array_key_exists('is_active', $model->getAttributes())
            || in_array('is_active', $model->getFillable(), true)) {
            $model->setAttribute('is_active', false);
        }

        $model->save();
        $model->delete();
    }
}
