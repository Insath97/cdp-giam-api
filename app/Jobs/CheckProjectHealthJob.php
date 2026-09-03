<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Integration\ProjectHealthCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckProjectHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Project $project
    ) {}

    public function handle(ProjectHealthCheckService $healthCheckService): void
    {
        $healthCheckService->check($this->project);
    }
}
