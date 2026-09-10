<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Integration\ProjectApiKeyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CreateProjectApiKeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'giam:create-project-api-key 
                            {project_code : Canonical project code (e.g. centrix, hrms)}
                            {--name=Primary : Descriptive name for the API key}
                            {--expires-in-days= : Optional expiration duration in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new secure inbound Project API key for resource consumption';

    /**
     * Execute the console command.
     */
    public function handle(ProjectApiKeyService $apiKeyService): int
    {
        $projectCode = $this->argument('project_code');
        $name = (string) $this->option('name');
        $expiresInDays = $this->option('expires-in-days');

        $project = Project::where('code', $projectCode)->first();

        if (!$project) {
            $this->error("Project with code '{$projectCode}' not found.");
            return Command::FAILURE;
        }

        $expiresAt = null;
        if (!empty($expiresInDays)) {
            $expiresAt = Carbon::now()->addDays((int) $expiresInDays);
        }

        $result = $apiKeyService->generateKey(
            project: $project,
            name: $name,
            expiresAt: $expiresAt
        );

        $apiKey = $result['api_key'];
        $plainTextKey = $result['plain_text_key'];

        $this->info("=================================================");
        $this->info("  GIAM PROJECT API KEY CREATED");
        $this->info("=================================================");
        $this->line("Project ID:    {$project->id}");
        $this->line("Project Code:  {$project->code}");
        $this->line("Key Name:      {$apiKey->name}");
        $this->line("Key ID:        {$apiKey->key_id}");
        $this->line("Expires At:    " . ($apiKey->expires_at ? $apiKey->expires_at->toIso8601String() : 'Never'));
        $this->newLine();
        $this->warn("PLAINTEXT API KEY (Shown ONCE):");
        $this->alert($plainTextKey);
        $this->warn("IMPORTANT: Copy this key immediately. It cannot be retrieved again.");
        $this->info("=================================================");

        return Command::SUCCESS;
    }
}
