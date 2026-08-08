<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->with('agency')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
            ))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function toggleAdmin(User $user): RedirectResponse
    {
        abort_if($user->id === Auth::id(), 403, "You can't change your own admin status — ask another admin.");

        $user->forceFill([
            'platform_role' => $user->isPlatformAdmin() ? null : 'PLATFORM_ADMIN',
        ])->save();

        return back()->with('status', $user->isPlatformAdmin()
            ? "{$user->name} is now a platform admin."
            : "{$user->name} is no longer a platform admin.");
    }

    public function toggleActive(User $user): RedirectResponse
    {
        abort_if($user->id === Auth::id(), 403, "You can't deactivate your own account — ask another admin.");

        $user->forceFill([
            'disabled_at' => $user->isDisabled() ? null : now(),
        ])->save();

        return back()->with('status', $user->isDisabled()
            ? "{$user->name}'s account has been deactivated."
            : "{$user->name}'s account has been reactivated.");
    }
}
