<?php

namespace App\Traits;

use App\Exceptions\OptimisticLockException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait providing true atomic, statement-level optimistic concurrency locking.
 *
 * Enforces atomic version checking in MySQL:
 * UPDATE {table} SET {dirty_fields}, version = expected + 1 WHERE {primary_key} = ? AND version = expected;
 * Throws OptimisticLockException if affected rows is 0.
 */
trait HasOptimisticLocking
{
    /**
     * Optional explicit expected version passed from API client.
     */
    protected ?int $explicitExpectedVersion = null;

    /**
     * Set an explicit expected version from an API request or service.
     */
    public function setExpectedVersion(?int $version): static
    {
        $this->explicitExpectedVersion = $version;

        return $this;
    }

    /**
     * Get the explicit expected version if set.
     */
    public function getExplicitExpectedVersion(): ?int
    {
        return $this->explicitExpectedVersion;
    }

    /**
     * Get the column name for the optimistic lock version.
     */
    public function getOptimisticLockColumn(): string
    {
        return property_exists($this, 'optimisticLockColumn') ? $this->optimisticLockColumn : 'version';
    }

    /**
     * Set the keys for a save update query, appending the atomic version check.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        $lockColumn = $this->getOptimisticLockColumn();
        if (array_key_exists($lockColumn, $this->getOriginal())) {
            $expectedVersion = $this->explicitExpectedVersion ?? (int) $this->getOriginal($lockColumn);
            $query->where($lockColumn, '=', $expectedVersion);
        }

        return $query;
    }

    /**
     * Perform a model update operation with atomic optimistic version checking.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     *
     * @throws \App\Exceptions\OptimisticLockException
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();
        $lockColumn = $this->getOptimisticLockColumn();
        $hasLock = array_key_exists($lockColumn, $this->getOriginal());

        $expectedVersion = null;
        if ($hasLock) {
            $expectedVersion = $this->explicitExpectedVersion ?? (int) $this->getOriginal($lockColumn);

            // Pre-flight check if an explicit expectedVersion was provided and differs from loaded model
            if ($this->explicitExpectedVersion !== null && (int) $this->getOriginal($lockColumn) !== $this->explicitExpectedVersion) {
                throw new OptimisticLockException(
                    "Concurrency conflict on {$this->getTable()} ID {$this->getKey()}: expected version {$this->explicitExpectedVersion}, but found {$this->getOriginal($lockColumn)}."
                );
            }

            // Increment version in the atomic update payload
            $dirty[$lockColumn] = $expectedVersion + 1;
            $this->{$lockColumn} = $expectedVersion + 1;
        }

        if (count($dirty) > 0) {
            $affected = $this->setKeysForSaveQuery($query)->update($dirty);

            if ($hasLock && $affected === 0) {
                // Revert in-memory version on conflict
                $this->{$lockColumn} = $expectedVersion;

                throw new OptimisticLockException(
                    "Concurrency conflict on {$this->getTable()} ID {$this->getKey()}: expected version {$expectedVersion}."
                );
            }

            $this->syncChanges();
            $this->fireModelEvent('updated', false);
        }

        return true;
    }
}
