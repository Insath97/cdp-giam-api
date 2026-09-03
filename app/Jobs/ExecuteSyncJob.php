<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Services\Sync\ProvisioningSyncWorker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public SyncJob $syncJob
    ) {}

    public function handle(ProvisioningSyncWorker $worker): void
    {
        $worker->process($this->syncJob);
    }
}
