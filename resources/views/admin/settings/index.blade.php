<x-app-layout title="Platform Settings">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Platform Settings') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Platform-wide switches — these override every user's individual preferences.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="section-card p-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Email Notifications</p>
                            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 max-w-sm">
                                When ON, threshold breaches also email the owning user via Resend. When OFF, alerts
                                still record and show on the Alerts page — only the emails stop. Default is OFF.
                            </p>
                            @unless ($resendConfigured)
                                <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                    RESEND_API_KEY is not set in .env yet — turning this on will fall back to the log
                                    driver until it is. Safe to enable now and flip live once the key is added.
                                </p>
                            @endunless
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0 mt-0.5">
                            <input type="checkbox" name="email_notifications_enabled" value="1"
                                   @checked($emailEnabled)
                                   class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 dark:bg-slate-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-gano-500 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gano-700"></div>
                        </label>
                    </div>

                    <div class="pt-2 border-t border-gray-100 dark:border-gray-700">
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">Integration status</p>
                        <ul class="text-xs space-y-1">
                            <li class="flex items-center gap-2">
                                <span class="risk-badge {{ $resendConfigured ? 'risk-badge-green' : 'risk-badge-none' }}">
                                    {{ $resendConfigured ? 'configured' : 'not configured' }}
                                </span>
                                Resend (RESEND_API_KEY)
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="risk-badge {{ $openAiConfigured ? 'risk-badge-green' : 'risk-badge-none' }}">
                                    {{ $openAiConfigured ? 'configured' : 'not configured' }}
                                </span>
                                OpenAI (OPENAI_API_KEY) — AI report summaries
                            </li>
                        </ul>
                    </div>

                    <button type="submit" class="btn-primary">Save settings</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
