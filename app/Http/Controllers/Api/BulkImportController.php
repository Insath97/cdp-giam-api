<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Import\BulkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkImportController extends Controller
{
    public function __construct(
        protected BulkImportService $importService
    ) {}

    /**
     * Download plain Employee CSV template.
     */
    public function downloadEmployeeTemplate(): StreamedResponse
    {
        $headers = $this->importService->getEmployeeTemplateHeaders();
        $filename = 'giam_employee_import_template.csv';

        return response()->streamDownload(function () use ($headers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Bulk import Employees from uploaded CSV.
     */
    public function importEmployees(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        try {
            $result = $this->importService->importEmployees(
                file: $request->file('file'),
                actor: $request->user()
            );

            return response()->json($result, 200);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download plain User / Principal CSV template (no plaintext password column).
     */
    public function downloadUserTemplate(): StreamedResponse
    {
        $headers = $this->importService->getUserTemplateHeaders();
        $filename = 'giam_principal_import_template.csv';

        return response()->streamDownload(function () use ($headers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Bulk import Users / Principals from uploaded CSV.
     */
    public function importUsers(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        try {
            $result = $this->importService->importUsers(
                file: $request->file('file'),
                actor: $request->user()
            );

            return response()->json($result, 200);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
