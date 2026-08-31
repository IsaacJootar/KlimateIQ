<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserPreferenceController extends Controller
{
    public function setTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'in:light,dark'],
        ]);

        Auth::user()->update(['theme' => $validated['theme']]);

        return back();
    }

    /**
     * The nav sector switcher (Clarity Pass B4). An empty value clears the pin — back to "all
     * my sectors". A sector_id is accepted only if the user actually follows it.
     */
    public function setSector(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sector_id' => ['nullable', 'integer'],
        ]);

        $user = Auth::user();
        $sectorId = $validated['sector_id'] ?: null;

        if ($sectorId !== null && ! $user->sectorSubscriptions()->where('sector_id', $sectorId)->exists()) {
            $sectorId = null;
        }

        $user->getOrCreateDashboardPreference()->update(['current_sector_id' => $sectorId]);

        return back();
    }
}
