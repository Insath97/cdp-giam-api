<?php

namespace App\Traits;

use App\Exceptions\OptimisticLockException;
use Illuminate\Database\Eloquent\Model;

trait HasOptimisticLocking
{
    public static function bootHasOptimisticLocking(): void
    {
        static::updating(function (Model $model) {
            $lockColumn = $model->getOptimisticLockColumn();

            // Only check if version was loaded and exists
            if (isset($model->getOriginal()[$lockColumn])) {
                $expectedVersion = $model->getOriginal($lockColumn);

                // Fetch current version from database directly
                $currentVersion = $model->newQuery()
                    ->where($model->getKeyName(), $model->getKey())
                    ->value($lockColumn);

                if ($currentVersion !== null && (int) $currentVersion !== (int) $expectedVersion) {
                    throw new OptimisticLockException(
                        "Concurrency conflict on {$model->getTable()} ID {$model->getKey()}: expected version {$expectedVersion}, but found {$currentVersion}."
                    );
                }

                // Increment version for next state
                $model->{$lockColumn} = (int) $expectedVersion + 1;
            }
        });
    }

    public function getOptimisticLockColumn(): string
    {
        return property_exists($this, 'optimisticLockColumn') ? $this->optimisticLockColumn : 'version';
    }
}
