<x-app-layout title="API Tokens">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('API Tokens') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Issue and revoke read-access tokens for the third-party API. See
            <code class="text-xs">docs/INGESTION_GUIDE.md</code> for the endpoints a token can call.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('plainTextToken'))
                <div x-data="{ show: true }" x-show="show" class="rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">
                            Copy this token now &mdash; it won't be shown again.
                        </p>
                        <button type="button" @click="show = false" class="flex-shrink-0 text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-200">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <code id="new-token-value" class="flex-1 text-xs bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2 overflow-x-auto whitespace-nowrap">{{ session('plainTextToken') }}</code>
                        <button type="button" class="btn-secondary flex-shrink-0"
                            onclick="navigator.clipboard.writeText(document.getElementById('new-token-value').textContent); this.textContent = 'Copied!';">
                            Copy
                        </button>
                    </div>
                </div>
            @endif

            <x-form-section title="Issue a new token" last="true"
                description="Pick the account the token authenticates as. That account's data isn't scoped by the token — every token can read every region/index/score, the same as the dashboard.">
                <form method="POST" action="{{ route('admin.api-tokens.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <x-input-label for="user_id">Account</x-input-label>
                        <x-select-input id="user_id" name="user_id" required>
                            <option value="">Select an account</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                            @endforeach
                        </x-select-input>
                    </div>
                    <div>
                        <x-input-label for="name">Token name</x-input-label>
                        <x-text-input id="name" type="text" name="name" placeholder="e.g. NCDC dashboard integration" required />
                    </div>
                    <button type="submit" class="btn-primary w-full sm:w-auto sm:col-span-2">Issue token</button>
                </form>
            </x-form-section>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Issued tokens</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Account</th>
                                <th class="px-4 py-3">Last used</th>
                                <th class="px-4 py-3">Issued</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($tokens as $token)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $token->name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $token->tokenable?->name ?? 'deleted account' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $token->created_at->diffForHumans() }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <form method="POST" action="{{ route('admin.api-tokens.destroy', $token) }}"
                                              onsubmit="return confirm('Revoke this token? Anything using it will stop working immediately.');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn-danger">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No tokens issued yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
