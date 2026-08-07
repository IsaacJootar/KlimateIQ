<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\ReportRequest;
use App\Models\ScoringIndex;
use App\Services\Reporting\ReportGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportRequestController extends Controller
{
    public function index(): View
    {
        $agencyId = Auth::user()->agency_id;

        return view('reports.index', [
            'reportRequests' => ReportRequest::query()->where('user_id', Auth::id())->with('index')->orderByDesc('created_at')->get(),
            'sharedReports' => $agencyId
                ? ReportRequest::query()->where('agency_id', $agencyId)->where('user_id', '!=', Auth::id())->where('status', 'READY')->with(['index', 'user'])->orderByDesc('created_at')->get()
                : collect(),
            'regions' => Region::query()->orderBy('name')->get(),
            'indices' => ScoringIndex::all(),
            'hasAgency' => $agencyId !== null,
        ]);
    }

    public function store(Request $request, ReportGenerationService $generator): RedirectResponse
    {
        $validated = $request->validate([
            'index_id' => ['required', 'exists:indices,index_id'],
            'region_ids' => ['required', 'array', 'min:1'],
            'region_ids.*' => ['exists:regions,region_id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'format' => ['required', 'in:csv,pdf'],
        ]);

        $shareWithAgency = $request->boolean('share_with_agency') && Auth::user()->agency_id !== null;

        $reportRequest = ReportRequest::query()->create([
            'user_id' => Auth::id(),
            'agency_id' => $shareWithAgency ? Auth::user()->agency_id : null,
            'index_id' => $validated['index_id'],
            'region_ids' => $validated['region_ids'],
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'format' => $validated['format'],
            'status' => 'PENDING',
        ]);

        // Generated inline — a hackathon-scale report over a handful of regions takes well
        // under a request timeout. A queued job is the natural next step if report volume or
        // size grows past that.
        $generator->generate($reportRequest);

        return back()->with('status', $shareWithAgency ? 'Report generated and shared with your agency.' : 'Report generated.');
    }

    public function download(ReportRequest $reportRequest): StreamedResponse
    {
        $owns = $reportRequest->user_id === Auth::id();
        $sharedWithMyAgency = $reportRequest->agency_id !== null && $reportRequest->agency_id === Auth::user()->agency_id;

        abort_unless($owns || $sharedWithMyAgency, 403);
        abort_unless($reportRequest->status === 'READY' && $reportRequest->file_path, 404);

        return Storage::download($reportRequest->file_path, "gano-ai-report.{$reportRequest->format}");
    }
}
