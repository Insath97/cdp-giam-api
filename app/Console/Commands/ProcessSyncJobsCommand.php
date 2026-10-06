<?php

namespace App\Console\Commands;

use App\Models\SyncJob;
use App\Services\Sync\ProvisioningSyncWorker;
use Illuminate\Console\Command;

class ProcessSyncJobsCommand extends Command
{
    protected $signature = 'giam:process-sync-jobs {--limit=50} {--job-id=}';

    protected $description = 'Process pending and due retrying provisioning sync jobs from the transactional outbox';

    public function handle(ProvisioningSyncWorker $worker): int
    {
        $limit = (int) $this->option('limit');
        $specificJobId = $this->option('job-id');

        $query = SyncJob::with(['project.integration', 'user']);

        if ($specificJobId) {
            $query->where('id', $specificJobId);
        } else {
            $query->whereIn('status', ['PENDING', 'RETRYING'])
                ->where(function ($q) {
                    $q->whereNull('next_retry_at')
                      ->orWhere('next_retry_at', '<=', now());
                })
                ->orderBy('id', 'asc')
                ->limit($limit);
        }

        $jobs = $query->get();

        if ($jobs->isEmpty()) {
            $this->info('No pending or due sync jobs to process.');

            return self::SUCCESS;
        }

        $this->info("Processing {$jobs->count()} outbox sync job(s)...");

        $results = [];

        foreach ($jobs as $job) {
            $success = $worker->process($job);
            $job->refresh();

            $results[] = [
                'ID' => $job->id,
                'Project' => $job->project?->code ?? 'N/A',
                'User' => $job->user?->username ?? 'N/A',
                'Operation' => $job->operation,
                'Status' => $job->status,
                'HTTP Code' => $job->http_status_code ?? 'N/A',
                'Attempts' => "{$job->attempt_count}/{$job->max_attempts}",
            ];
        }

        $this->table(['ID', 'Project', 'User', 'Operation', 'Status', 'HTTP Code', 'Attempts'], $results);

        return self::SUCCESS;
    }
}
