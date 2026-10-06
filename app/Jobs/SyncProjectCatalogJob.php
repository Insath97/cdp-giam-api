<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\RbacCatalog\CatalogSyncHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncProjectCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Project $project
    ) {}

    public function handle(CatalogSyncHandler $syncHandler): void
    {
        $syncHandler->sync($this->project);
    }
}
