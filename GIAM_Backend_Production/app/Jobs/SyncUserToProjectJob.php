<?php
namespace App\Jobs;
use App\Models\Application; use App\Models\User; use App\Services\ProjectSyncService;
use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\InteractsWithQueue; use Illuminate\Queue\SerializesModels;
class SyncUserToProjectJob implements ShouldQueue { use Dispatchable,InteractsWithQueue,Queueable,SerializesModels; public int $tries=5; public array $backoff=[5,15,60,180,300]; public function __construct(public int $userId,public int $applicationId){} public function handle(ProjectSyncService $service):void{$u=User::find($this->userId);$a=Application::find($this->applicationId);if($u&&$a&&$a->is_active)$service->syncUserToProject($u,$a);}}
