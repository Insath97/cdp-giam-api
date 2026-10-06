<?php

namespace App\Services\Report;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming report service writing CSV exports directly to output handles with O(1) memory.
 */
class CsvReportGenerator
{
    /**
     * Stream an access report query to CSV format with constant O(1) memory usage.
     *
     * @param Builder $query
     * @param string $filename
     * @return StreamedResponse
     */
    public function streamAccessReport(Builder $query, string $filename = 'access_report.csv'): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // CSV Header Row
            fputcsv($handle, [
                'Employee Code',
                'Full Name',
                'Username',
                'Email',
                'Department',
                'Designation',
                'Province',
                'Branch',
                'Project Code',
                'Project Name',
                'Access Status',
                'Roles',
                'Direct Permissions',
                'Assigned At',
            ]);

            // Stream rows via buffered chunks to preserve eager-loaded relationships with O(1) memory
            $query->chunk(250, function ($chunk) use ($handle) {
                foreach ($chunk as $access) {
                    $user = $access->user;
                    $employee = $user?->employee;
                    $project = $access->project;

                    fputcsv($handle, [
                        $employee?->employee_code ?? $user?->employee_code ?? 'N/A',
                        $employee?->full_name ?? $user?->name ?? 'N/A',
                        $user?->username ?? 'N/A',
                        $user?->email ?? 'N/A',
                        $employee?->department?->name ?? $employee?->department_code ?? 'N/A',
                        $employee?->designation?->name ?? $employee?->designation_code ?? 'N/A',
                        $employee?->province?->name ?? $employee?->province_code ?? 'N/A',
                        $employee?->branch?->name ?? $employee?->branch_code ?? 'N/A',
                        $project?->code ?? 'N/A',
                        $project?->name ?? 'N/A',
                        $access->status,
                        $access->roles->pluck('name')->implode(', '),
                        $access->permissions->pluck('name')->implode(', '),
                        $access->created_at?->toDateTimeString() ?? 'N/A',
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Stream an audit logs query to CSV format with constant O(1) memory usage.
     */
    public function streamAuditReport(Builder $query, string $filename = 'audit_logs_report.csv'): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'ID',
                'Timestamp',
                'Actor Username',
                'Action',
                'Entity Type',
                'Entity ID',
                'Project Code',
                'Status',
                'IP Address',
                'Request ID',
            ]);

            $query->chunk(250, function ($chunk) use ($handle) {
                foreach ($chunk as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $log->created_at?->toDateTimeString(),
                        $log->actor?->username ?? 'SYSTEM',
                        $log->action,
                        $log->entity_type,
                        $log->entity_id,
                        $log->project?->code ?? 'N/A',
                        $log->status,
                        $log->ip_address,
                        $log->request_id,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
