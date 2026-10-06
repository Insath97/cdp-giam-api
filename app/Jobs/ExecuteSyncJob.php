<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Services\Sync\ProvisioningSyncWorker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteSyncJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of queue worker execution attempts.
     * Business retry logic and exponential backoff are durably managed in the sync_jobs outbox table.
     */
    public int $tries = 1;

    /**
     * Maximum execution duration in seconds.
     */
    public int $timeout = 60;

    /**
     * Delete the job if the referenced model no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public SyncJob $syncJob
    ) {
        $this->afterCommit = true;
    }

    public function handle(ProvisioningSyncWorker $worker): void
    {
        $worker->process($this->syncJob);
    }
}
