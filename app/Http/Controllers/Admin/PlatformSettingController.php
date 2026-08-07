<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformSettingController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.index', [
            'emailEnabled' => PlatformSetting::get('email.notifications_enabled', false),
            'resendConfigured' => ! empty(config('services.resend.key')),
            'openAiConfigured' => ! empty(config('services.openai.api_key')),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        PlatformSetting::set('email.notifications_enabled', $request->boolean('email_notifications_enabled'), 'boolean');

        return back()->with('success', 'Platform settings saved.');
    }
}
