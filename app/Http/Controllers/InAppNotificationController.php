<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InAppNotificationController extends Controller
{
    public function index(): View
    {
        return view('notifications.index', [
            'notifications' => Auth::user()->notifications()->paginate(20),
        ]);
    }

    public function markRead(string $notification): RedirectResponse
    {
        $record = Auth::user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return redirect($record->data['url'] ?? route('notifications.index'));
    }
}
