<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditLoggerService;
use App\Services\Report\AccessReportQueryService;
use App\Services\Report\CsvReportGenerator;
use App\Services\Report\PdfReportGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        protected AccessReportQueryService $queryService,
        protected CsvReportGenerator $csvGenerator,
        protected PdfReportGenerator $pdfGenerator,
        protected AuditLoggerService $auditLogger
    ) {}

    /**
     * View paginated access report matrix in JSON.
     */
    public function accessReport(Request $request): JsonResponse
    {
        $query = $this->queryService->buildQuery($request);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $records = $query->paginate($perPage);

        return response()->json($records);
    }

    /**
     * Export access report to CSV stream.
     */
    public function exportAccessCsv(Request $request): StreamedResponse
    {
        $query = $this->queryService->buildQuery($request);

        $this->auditLogger->log(
            action: 'REPORT_EXPORTED',
            entityType: 'AccessReport',
            entityId: 'CSV',
            beforeData: null,
            afterData: [
                'format' => 'CSV',
                'filters' => $request->all(),
            ],
            status: 'SUCCESS',
            actorUserId: $request->user()?->id
        );

        $filename = 'access_report_' . now()->format('Ymd_His') . '.csv';

        return $this->csvGenerator->streamAccessReport($query, $filename);
    }

    /**
     * Export access report to PDF.
     */
    public function exportAccessPdf(Request $request): Response
    {
        $query = $this->queryService->buildQuery($request);

        $this->auditLogger->log(
            action: 'REPORT_EXPORTED',
            entityType: 'AccessReport',
            entityId: 'PDF',
            beforeData: null,
            afterData: [
                'format' => 'PDF',
                'filters' => $request->all(),
            ],
            status: 'SUCCESS',
            actorUserId: $request->user()?->id
        );

        return $this->pdfGenerator->generateAccessReportPdf($query, $request->user());
    }

    /**
     * Export audit logs to CSV stream, strictly guarded by both REPORT_EXPORT and AUDIT_VIEW.
     */
    public function exportAuditCsv(Request $request): StreamedResponse
    {
        $query = AuditLog::with(['actor', 'project'])->orderBy('id', 'desc');

        if ($request->filled('action')) {
            $actions = is_array($request->input('action'))
                ? $request->input('action')
                : explode(',', $request->input('action'));
            $query->whereIn('action', array_map('trim', $actions));
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->integer('actor_user_id'));
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->input('status')));
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->date('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->date('to_date'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('entity_id', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $this->auditLogger->log(
            action: 'REPORT_EXPORTED',
            entityType: 'AuditLogReport',
            entityId: 'CSV',
            beforeData: null,
            afterData: [
                'format' => 'CSV',
                'filters' => $request->all(),
            ],
            status: 'SUCCESS',
            actorUserId: $request->user()?->id
        );

        $filename = 'audit_logs_report_' . now()->format('Ymd_His') . '.csv';

        return $this->csvGenerator->streamAuditReport($query, $filename);
    }
}
