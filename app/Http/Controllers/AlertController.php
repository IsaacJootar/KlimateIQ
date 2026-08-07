<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(): View
    {
        $alerts = Alert::query()
            ->whereHas('thresholdConfig', fn ($q) => $q->where('user_id', Auth::id()))
            ->with(['region', 'index', 'signalType'])
            ->orderByDesc('triggered_at')
            ->paginate(20);

        return view('alerts.index', ['alerts' => $alerts]);
    }

    public function acknowledge(Alert $alert): RedirectResponse
    {
        $this->authorizeOwnership($alert);
        $alert->acknowledge();

        return back()->with('status', 'Alert acknowledged.');
    }

    public function resolve(Alert $alert): RedirectResponse
    {
        $this->authorizeOwnership($alert);
        $alert->resolve();

        return back()->with('status', 'Alert resolved.');
    }

    private function authorizeOwnership(Alert $alert): void
    {
        abort_unless($alert->thresholdConfig->user_id === Auth::id(), 403);
    }
}
