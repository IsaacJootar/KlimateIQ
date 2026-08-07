<?php

namespace App\Services\Reporting;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ReportRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

/**
 * Turns a ReportRequest into a downloadable file — CSV via PhpSpreadsheet, PDF via dompdf.
 */
class ReportGenerationService
{
    public function generate(ReportRequest $reportRequest): void
    {
        $rows = RegionScore::query()
            ->with('region')
            ->where('index_id', $reportRequest->index_id)
            ->whereIn('region_id', $reportRequest->region_ids)
            ->whereBetween('period_start', [$reportRequest->date_from, $reportRequest->date_to])
            ->orderBy('region_id')
            ->orderBy('period_start')
            ->get();

        $relativePath = $reportRequest->format === 'pdf'
            ? $this->generatePdf($reportRequest, $rows)
            : $this->generateCsv($reportRequest, $rows);

        $reportRequest->update([
            'status' => 'READY',
            'file_path' => $relativePath,
            'generated_at' => now(),
        ]);
    }

    private function generateCsv(ReportRequest $reportRequest, $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Region', 'State', 'Period Start', 'Period End', 'Score', 'Scoring Strategy'], null, 'A1');

        $rowNum = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row->region->name,
                $row->region->state,
                $row->period_start->toDateString(),
                $row->period_end->toDateString(),
                $row->score,
                $row->scoring_strategy,
            ], null, "A{$rowNum}");
            $rowNum++;
        }

        $relativePath = "reports/{$reportRequest->report_request_id}.csv";
        Storage::makeDirectory('reports');

        (new Csv($spreadsheet))->save(Storage::path($relativePath));

        return $relativePath;
    }

    private function generatePdf(ReportRequest $reportRequest, $rows): string
    {
        $reportRequest->loadMissing('index');

        $relativePath = "reports/{$reportRequest->report_request_id}.pdf";
        Storage::makeDirectory('reports');

        // Keyed by band (3 rows max) rather than queried per report row.
        $actionsByBand = IndexActionRecommendation::query()
            ->where('index_id', $reportRequest->index_id)
            ->pluck('action_text', 'risk_band');

        Pdf::loadView('reports.pdf', [
            'reportRequest' => $reportRequest,
            'rows' => $rows,
            'regions' => Region::query()->whereIn('region_id', $reportRequest->region_ids)->get()->keyBy('region_id'),
            'actionsByBand' => $actionsByBand,
        ])->save(Storage::path($relativePath));

        return $relativePath;
    }
}
