<x-app-layout title="API Access">
    <x-slot name="header">
        <p class="text-xs font-semibold uppercase tracking-widest text-primary">Your Portfolio</p>
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('API Access') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Licensed, read-only data feeds — one token per portfolio, each scoped so it can only read that
            portfolio's own feed. See <code class="text-xs">docs/INGESTION_GUIDE.md</code> for the full contract.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (! $hasAgency)
                <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                    Your account isn't part of an organization yet, so there's no portfolio to license a feed for.
                </div>
            @elseif ($portfolios->isEmpty())
                <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                    No portfolios yet — <a href="{{ route('portfolios.create') }}" class="link-nav">start one</a> to issue its first API token.
                </div>
            @else
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

                @foreach ($portfolios as $entry)
                    @php [$portfolio, $tokens] = [$entry['portfolio'], $entry['tokens']]; @endphp
                    <div class="section-card overflow-hidden">
                        <div class="section-card-header">
                            <h3 class="font-semibold text-gray-800 dark:text-gray-200">{{ $portfolio->name }}</h3>
                            <a href="{{ route('portfolios.show', $portfolio) }}" class="link-nav text-xs">Manage portfolio &rarr;</a>
                        </div>
                        <div class="p-5 border-b border-gray-100 dark:border-gray-700">
                            <p class="text-xs text-slate-400 mb-3">
                                <code>GET /api/v1/portfolios/{{ $portfolio->portfolio_id }}/feed</code> — a token issued here can only read this portfolio's own feed, never any other.
                            </p>
                            <form method="POST" action="{{ route('portfolios.tokens.store', $portfolio) }}" class="flex flex-col sm:flex-row gap-3">
                                @csrf
                                <x-text-input name="name" type="text" class="flex-1" placeholder="e.g. Acme Re payout system" required />
                                <x-loading-button class="btn-secondary" loading-text="Issuing…">Issue token</x-loading-button>
                            </form>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                        <th class="px-4 py-3 whitespace-nowrap">Name</th>
                                        <th class="px-4 py-3 whitespace-nowrap">Issued by</th>
                                        <th class="px-4 py-3 whitespace-nowrap">Last used</th>
                                        <th class="px-4 py-3 whitespace-nowrap"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @forelse ($tokens as $token)
                                        <tr>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $token->name }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-500">{{ $token->tokenable?->name ?? 'deleted account' }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-500">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                            <td class="px-4 py-3 text-right">
                                                <form method="POST" action="{{ route('portfolios.tokens.destroy', [$portfolio, $token]) }}"
                                                      onsubmit="return confirm('Revoke this token? Anything using it will stop working immediately.');">
                                                    @csrf @method('DELETE')
                                                    <x-loading-button class="btn-danger" loading-text="Revoking…">Revoke</x-loading-button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">No tokens issued yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            @endif

        </div>
    </div>
</x-app-layout>
