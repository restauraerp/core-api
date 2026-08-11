<?php

namespace App\Models\Concerns;

use App\Support\Assets\ManagedAssets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deletes the file behind an upload column once nothing points at it.
 *
 * Uploads are stored under a fresh random name every time, so replacing an
 * image used to leave the old file on disk for ever, referenced by nothing.
 * Those orphans are invisible to `tenants:remove`, which resolves a tenant's
 * files row by row - meaning a restaurant that had ever changed a photo could
 * not be fully erased.
 *
 * Rules:
 *  - only on a real update, and only for a column that actually changed;
 *  - never on a soft delete, because the row can still come back;
 *  - never when another row still points at the same path, since seeded and
 *    demo imagery is deliberately shared between restaurants.
 *
 * Mass deletes (`$model->relation()->delete()`) bypass Eloquent events, so
 * callers that need the files cleaned must delete the models themselves.
 */
trait TracksUploadedAssets
{
    /**
     * Columns on this model holding a path on the `public` disk.
     *
     * @return list<string>
     */
    abstract public function uploadedAssetColumns(): array;

    protected static function bootTracksUploadedAssets(): void
    {
        static::updated(function (Model $model) {
            foreach ($model->uploadedAssetColumns() as $column) {
                if ($model->wasChanged($column)) {
                    // The row already holds the new path, so asking whether the
                    // old one is still referenced gives the right answer.
                    $model->managedAssets()->release($model->getOriginal($column));
                }
            }
        });

        static::deleted(function (Model $model) {
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)
                && ! $model->isForceDeleting()) {
                return;
            }

            foreach ($model->uploadedAssetColumns() as $column) {
                $model->managedAssets()->release($model->getAttribute($column));
            }
        });
    }

    protected function managedAssets(): ManagedAssets
    {
        return app(ManagedAssets::class);
    }
}
