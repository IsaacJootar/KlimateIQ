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

            <div class="section-card p-6"
                 x-data="{
                    token: '{{ session('plainTextToken') }}',
                    endpoint: 'indices',
                    indexCode: '{{ $indices->first()->code ?? '' }}',
                    regionId: '{{ $regions->first()->region_id ?? '' }}',
                    loading: false,
                    status: null,
                    elapsed: null,
                    body: null,
                    url: '',
                    async send() {
                        this.loading = true;
                        this.status = null;
                        this.body = null;

                        if (this.endpoint === 'indices') this.url = '/api/v1/indices';
                        else if (this.endpoint === 'regions') this.url = '/api/v1/regions';
                        else if (this.endpoint === 'scores-by-index') this.url = `/api/v1/indices/${this.indexCode}/scores`;
                        else if (this.endpoint === 'scores-by-region') this.url = `/api/v1/regions/${this.regionId}/scores`;

                        const start = performance.now();
                        try {
                            const res = await fetch(this.url, {
                                headers: { 'Authorization': `Bearer ${this.token}`, 'Accept': 'application/json' },
                            });
                            this.status = res.status;
                            const json = await res.json();
                            this.body = JSON.stringify(json, null, 2);
                        } catch (e) {
                            this.status = 'network error';
                            this.body = String(e);
                        }
                        this.elapsed = Math.round(performance.now() - start);
                        this.loading = false;
                    },
                 }">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Try it — call the live API</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                    This sends a real request from your browser to this server's own API, exactly like a third
                    party would — nothing here is faked or pre-recorded. Paste any token you've issued (the one
                    you just created above is pre-filled), pick an endpoint, and see the actual response.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div class="sm:col-span-2">
                        <x-input-label for="try-token">Bearer token</x-input-label>
                        <x-text-input id="try-token" type="text" x-model="token" placeholder="Paste a token here" />
                    </div>
                    <div>
                        <x-input-label for="try-endpoint">Endpoint</x-input-label>
                        <x-select-input id="try-endpoint" x-model="endpoint">
                            <option value="indices">GET /api/v1/indices</option>
                            <option value="regions">GET /api/v1/regions</option>
                            <option value="scores-by-index">GET /api/v1/indices/{code}/scores</option>
                            <option value="scores-by-region">GET /api/v1/regions/{id}/scores</option>
                        </x-select-input>
                    </div>
                    <div x-show="endpoint === 'scores-by-index'" x-cloak>
                        <x-input-label for="try-index">Index</x-input-label>
                        <x-select-input id="try-index" x-model="indexCode">
                            @foreach ($indices as $idx)
                                <option value="{{ $idx->code }}">{{ $idx->name }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                    <div x-show="endpoint === 'scores-by-region'" x-cloak>
                        <x-input-label for="try-region">Region</x-input-label>
                        <x-select-input id="try-region" x-model="regionId">
                            @foreach ($regions as $region)
                                <option value="{{ $region->region_id }}">{{ $region->name }}, {{ $region->state }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                </div>

                <button type="button" class="btn-primary" @click="send()" x-bind:disabled="loading || ! token">
                    <span x-show="! loading">Send request</span>
                    <span x-show="loading" x-cloak class="inline-flex items-center gap-1.5">
                        <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Sending…
                    </span>
                </button>

                <div x-show="status !== null" x-cloak class="mt-4">
                    <div class="flex items-center gap-3 mb-2 text-xs">
                        <span class="risk-badge" x-bind:class="status === 200 ? 'risk-badge-green' : 'risk-badge-red'" x-text="'HTTP ' + status"></span>
                        <code class="text-slate-500 dark:text-slate-400" x-text="url"></code>
                        <span class="text-slate-400" x-text="elapsed + 'ms'"></span>
                    </div>
                    <pre class="text-xs bg-slate-900 text-slate-100 rounded-lg p-4 overflow-x-auto max-h-96" x-text="body"></pre>
                </div>
            </div>

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
                    <x-loading-button class="btn-primary w-full sm:w-auto sm:col-span-2" loading-text="Issuing…">Issue token</x-loading-button>
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
                                <th class="px-4 py-3 whitespace-nowrap">Name</th>
                                <th class="px-4 py-3 whitespace-nowrap">Account</th>
                                <th class="px-4 py-3 whitespace-nowrap">Last used</th>
                                <th class="px-4 py-3 whitespace-nowrap">Issued</th>
                                <th class="px-4 py-3 whitespace-nowrap"></th>
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
                                            <x-loading-button class="btn-danger" loading-text="Revoking…">Revoke</x-loading-button>
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
