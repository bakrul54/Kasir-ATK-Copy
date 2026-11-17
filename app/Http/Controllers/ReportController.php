<?php

namespace App\Http\Controllers;

use App\Actions\BuildBusinessReport;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, BuildBusinessReport $buildBusinessReport): View
    {
        $filters = $this->filters($request, $buildBusinessReport);
        $reportData = $buildBusinessReport->handle($filters['report'], $filters['from'], $filters['to']);

        return view('laporan', [
            'reports' => $buildBusinessReport->reportNames(),
            'selectedReport' => $filters['report'],
            'filters' => $filters,
            'reportData' => $reportData,
        ]);
    }

    public function print(Request $request, BuildBusinessReport $buildBusinessReport): View
    {
        $filters = $this->filters($request, $buildBusinessReport);

        return view('laporan-cetak', [
            'title' => $buildBusinessReport->reportNames()[$filters['report']],
            'report' => $filters['report'],
            'filters' => $filters,
            'reportData' => $buildBusinessReport->handle($filters['report'], $filters['from'], $filters['to']),
        ]);
    }

    public function export(Request $request, BuildBusinessReport $buildBusinessReport): StreamedResponse
    {
        $filters = $this->filters($request, $buildBusinessReport);
        $reportData = $buildBusinessReport->handle($filters['report'], $filters['from'], $filters['to']);
        $reportName = str($buildBusinessReport->reportNames()[$filters['report']])->slug();
        $filename = sprintf('laporan-%s-%s-sampai-%s.csv', $reportName, $filters['from'], $filters['to']);

        return response()->streamDownload(function () use ($reportData): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Tidak dapat membuka stream ekspor laporan.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $reportData['columns'], ',', '"', '\\');

            foreach ($reportData['rows'] as $row) {
                $safeRow = array_map(function (mixed $value): mixed {
                    if (! is_string($value)) {
                        return $value;
                    }

                    return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
                }, $row);
                fputcsv($output, $safeRow, ',', '"', '\\');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function filters(Request $request, BuildBusinessReport $buildBusinessReport): array
    {
        $validator = Validator::make($request->query(), [
            'report' => ['nullable', 'string', Rule::in(array_keys($buildBusinessReport->reportNames()))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('laporan')->withErrors($validator)->throwResponse();
        }

        $validated = $validator->validated();
        $from = Carbon::parse($validated['from'] ?? today()->startOfMonth()->toDateString())->toDateString();
        $to = Carbon::parse($validated['to'] ?? today()->toDateString())->toDateString();

        return [
            'report' => $validated['report'] ?? 'sales',
            'from' => $from,
            'to' => $to,
        ];
    }
}
