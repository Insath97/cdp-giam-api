<?php

namespace App\Console\Commands;

use App\Services\Integration\ProjectOnboardingService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OnboardProjectCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'giam:onboard-project 
                            {code : Canonical project code (e.g. credix, stockly, centrix)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Onboard or update an approved downstream project and its integration configuration';

    /**
     * Execute the console command.
     */
    public function handle(ProjectOnboardingService $onboardingService): int
    {
        $code = (string) $this->argument('code');

        try {
            $project = $onboardingService->onboard($code);

            $integration = $project->integration;

            $this->info('=================================================');
            $this->info('  GIAM PROJECT ONBOARDED SUCCESSFULLY');
            $this->info('=================================================');
            $this->line("Project ID:     {$project->id}");
            $this->line("Project Code:   {$project->code}");
            $this->line("Project Name:   {$project->name}");
            $this->line("Auth Method:    {$integration->auth_method}");
            $this->line('Sync Enabled:   ' . ($integration->sync_enabled ? 'Yes' : 'No'));
            $this->line('SSO Enabled:    ' . ($integration->sso_enabled ? 'Yes' : 'No'));
            $this->line("Status:         {$project->status}");
            $this->info('=================================================');

            return Command::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->error("Failed to onboard project [{$code}]: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
