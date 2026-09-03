<?php

namespace App\Services\Report;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

class PdfReportGenerator
{
    /**
     * Generate and download a formatted PDF access report.
     */
    public function generateAccessReportPdf(
        Builder $query,
        ?User $actor = null,
        int $limit = 500
    ): Response {
        // PDF generation is capped at a sensible limit (e.g. 500 rows) to prevent PDF engine timeout
        $accessRecords = $query->limit($limit)->get();

        $data = [
            'accessRecords' => $accessRecords,
            'generatedAt' => now()->toDateTimeString(),
            'generatedBy' => $actor ? "{$actor->name} ({$actor->username})" : 'System Administrator',
        ];

        $pdf = Pdf::loadView('reports.access_report_pdf', $data)
            ->setPaper('a4', 'landscape')
            ->setWarnings(false);

        $filename = 'access_report_' . now()->format('Ymd_His') . '.pdf';

        return $pdf->download($filename);
    }
}
