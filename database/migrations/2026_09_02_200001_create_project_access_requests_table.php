<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Normalized entity representing HR project access business requests.
     */
    public function up(): void
    {
        Schema::create('project_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('requested_project_name');
            $table->text('nature_of_role');
            $table->enum('status', ['PENDING', 'FULFILLED', 'REJECTED'])->default('PENDING');
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('resolved_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('user_project_access_id')->nullable()->constrained('user_project_access')->nullOnDelete();
            $table->timestamps();

            // Indexes for performance and lookup
            $table->index(['employee_id', 'status'], 'proj_req_emp_status_idx');
            $table->index('status', 'proj_req_status_idx');
            $table->index('submitted_by_user_id', 'proj_req_submitted_by_idx');
            $table->index('resolved_project_id', 'proj_req_resolved_project_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_access_requests');
    }
};
