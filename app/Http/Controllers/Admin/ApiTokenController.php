<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issuing a Sanctum token was previously tinker-only (see docs/INGESTION_GUIDE.md's original
 * "Third-party API" section) — this gives a platform admin a UI for the same operation instead
 * of requiring server access. The plaintext token is only ever available at creation time
 * (Sanctum stores just its hash), so it's flashed to the session once and shown exactly once.
 */
class ApiTokenController extends Controller
{
    public function index(): View
    {
        return view('admin.api-tokens.index', [
            'tokens' => PersonalAccessToken::query()
                ->with('tokenable')
                ->orderByDesc('created_at')
                ->get(),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            // For the "Try it" panel — lets a picked endpoint's {code}/{id} be a dropdown
            // instead of asking whoever's testing to already know a valid index code or
            // region_id by heart.
            'indices' => ScoringIndex::query()->orderBy('name')->get(['index_id', 'code', 'name']),
            'regions' => Region::query()->active()->orderBy('name')->get(['region_id', 'name', 'state']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->findOrFail($validated['user_id']);
        $token = $user->createToken($validated['name']);

        return back()
            ->with('status', "Token \"{$validated['name']}\" issued for {$user->name}.")
            ->with('plainTextToken', $token->plainTextToken);
    }

    public function destroy(PersonalAccessToken $token): RedirectResponse
    {
        $name = $token->name;
        $token->delete();

        return back()->with('status', "Revoked token \"{$name}\".");
    }
}
