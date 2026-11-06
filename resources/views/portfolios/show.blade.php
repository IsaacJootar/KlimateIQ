<x-app-layout :title="$portfolio->name">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-primary">Your Portfolio</p>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ $portfolio->name }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($portfolio->agency->portfolio_type)
                        {{ $portfolio->agency->portfolio_type->label() }} &middot;
                    @endif
                    {{ $portfolio->isCorridor() ? 'Corridor — read in order' : 'Point list — ranked worst to best' }}
                    &middot; {{ $rows->count() }} asset{{ $rows->count() === 1 ? '' : 's' }} under watch
                </p>
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('portfolios.stress-test', $portfolio) }}" class="link-nav text-xs">Stress test &rarr;</a>
                <form method="POST" action="{{ route('portfolios.destroy', $portfolio) }}"
                      onsubmit="return confirm('Delete this portfolio? This cannot be undone.');">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs text-red-600 hover:underline">Delete portfolio</button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($valueSummary->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach ($valueSummary as $summary)
                        <div class="metric-card metric-teal">
                            <div class="metric-card-label">Protected{{ $valueSummary->count() > 1 ? " ({$summary['currency']})" : '' }}</div>
                            <div class="metric-card-value"><x-money-value :symbol="$summary['symbol']" :amount="$summary['total']" /></div>
                            <div class="metric-card-sub">total value under watch</div>
                        </div>
                        <div class="metric-card metric-red">
                            <div class="metric-card-label">At Risk{{ $valueSummary->count() > 1 ? " ({$summary['currency']})" : '' }}</div>
                            <div class="metric-card-value"><x-money-value :symbol="$summary['symbol']" :amount="$summary['atRisk']" /></div>
                            <div class="metric-card-sub">in red or amber locations</div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Risk Map</h3>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Live &mdash; pulses on anything in the red band</span>
                </div>
                @if ($mapAssets->isNotEmpty())
                    <div id="portfolio-map" style="height: 360px;"></div>
                @else
                    <p class="px-5 py-10 text-center text-sm text-gray-500">Add an asset below and it'll appear here, checked against live climate signals.</p>
                @endif
            </div>

            <x-form-section title="Add an asset to this portfolio"
                description="Every asset you add is immediately checked against live climate signals for its exact location.">
                <form method="POST" action="{{ route('portfolios.locations.store', $portfolio) }}"
                      x-data="{
                          mode: 'region',
                          scanning: false,
                          scanStep: 0,
                          scanSteps: [
                              'Locating this asset…',
                              'Pulling live climate signals for this point…',
                              'Cross-referencing the nearest monitored LGA…',
                              'Calculating risk exposure…',
                          ],
                          async runScan() {
                              this.scanning = true;
                              for (let i = 0; i < this.scanSteps.length; i++) {
                                  this.scanStep = i;
                                  await new Promise(r => setTimeout(r, 600));
                              }
                              this.$el.submit();
                          },
                      }"
                      @submit.prevent="runScan()"
                      class="relative space-y-4">
                    @csrf

                    <div x-show="scanning" x-cloak
                         class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-xl bg-white/95 dark:bg-slate-900/95 backdrop-blur-sm">
                        <div class="relative h-10 w-10">
                            <span class="absolute inset-0 rounded-full bg-primary/30 animate-ping"></span>
                            <span class="absolute inset-2.5 rounded-full bg-primary"></span>
                        </div>
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200" x-text="scanSteps[scanStep]"></p>
                    </div>

                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-1.5">
                            <input type="radio" x-model="mode" value="region"> From the monitored LGA list
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input type="radio" x-model="mode" value="coordinate"> Exact coordinates (GPS pin)
                        </label>
                    </div>

                    <div x-show="mode === 'region'">
                        <x-input-label for="region_id" value="Asset location" />
                        <x-select-input id="region_id" name="region_id" class="block mt-1 w-full">
                            <option value="">Select an LGA</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->region_id }}">{{ $region->name }} ({{ $region->state }})</option>
                            @endforeach
                        </x-select-input>
                    </div>

                    <div x-show="mode === 'coordinate'" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <x-input-label for="latitude" value="Latitude" />
                            <x-text-input id="latitude" name="latitude" type="text" class="block mt-1 w-full" placeholder="6.5244" />
                        </div>
                        <div>
                            <x-input-label for="longitude" value="Longitude" />
                            <x-text-input id="longitude" name="longitude" type="text" class="block mt-1 w-full" placeholder="3.3792" />
                        </div>
                        <div>
                            <x-input-label for="label" value="Asset name" />
                            <x-text-input id="label" name="label" type="text" class="block mt-1 w-full" placeholder="Tower #42, Ikeja axis" />
                        </div>
                        <p class="sm:col-span-3 text-xs text-amber-600 dark:text-amber-400">
                            There's no scoring data for this exact point — a raw coordinate is scored against the nearest LGA instead, clearly marked below with the distance. If nothing seeded is within {{ \App\Support\NearestRegion::MAX_USEFUL_DISTANCE_KM }} km, it shows "not scorable" rather than guessing from something too far away.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <x-input-label for="value" value="Value at this location (optional)" />
                            <x-text-input id="value" name="value" type="number" step="0.01" min="0" class="block mt-1 w-full" placeholder="e.g. 2000000" />
                        </div>
                        <div>
                            <x-input-label for="currency" value="Currency" />
                            <x-select-input id="currency" name="currency" class="block mt-1 w-full">
                                <option value="NGN">NGN (₦)</option>
                                <option value="USD">USD ($)</option>
                            </x-select-input>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 -mt-2">What's exposed at this point — a loan amount, sum insured, asset value. Shown next to its risk score so exposure and risk read together.</p>

                    <x-input-error :messages="$errors->get('region_id')" class="mt-2" />
                    <x-input-error :messages="$errors->get('latitude')" class="mt-2" />

                    <x-loading-button class="btn-secondary" loading-text="Adding…">Add to portfolio</x-loading-button>
                </form>
            </x-form-section>

            <div class="section-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Location</th>
                                <th class="px-4 py-3">Value</th>
                                <th class="px-4 py-3">Worst index</th>
                                <th class="px-4 py-3">Score</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">
                                        @if ($row['location']->region_id)
                                            <a href="{{ route('regions.show', array_filter(['region' => $row['location']->region_id, 'index' => $row['worst_index_code'], 'portfolio' => $portfolio->portfolio_id])) }}" class="hover:text-primary hover:underline">{{ $row['display_name'] }}</a>
                                        @else
                                            {{ $row['display_name'] }}
                                        @endif
                                        @if ($row['approximated'])
                                            <div class="text-xs font-normal text-amber-600 dark:text-amber-400 mt-0.5">
                                                &asymp; nearest LGA: {{ $row['nearest_region_name'] }} ({{ $row['distance_km'] }} km away)
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                        {{ $row['location']->formattedValue() ?? '—' }}
                                    </td>
                                    @if ($row['scorable'])
                                        <td class="px-4 py-3 text-sm text-gray-500">
                                            {{ $row['worst_index_name'] ?? 'No score yet' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($row['worst_score'] !== null)
                                                <span class="risk-badge risk-badge-{{ $row['worst_band'] }}">{{ round($row['worst_score']) }}</span>
                                            @else
                                                <span class="text-xs text-gray-400">No score yet</span>
                                            @endif
                                        </td>
                                    @else
                                        <td colspan="2" class="px-4 py-3 text-xs text-amber-600 dark:text-amber-400">
                                            {{ $row['reason'] }}
                                        </td>
                                    @endif
                                    <td class="px-4 py-3 text-right">
                                        <form method="POST" action="{{ route('portfolios.locations.destroy', [$portfolio, $row['location']]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs text-red-600 hover:underline">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                                        No locations yet — add one above.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

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

            <x-form-section title="API access" last="true"
                description="A token issued here can only read this portfolio's own feed — GET /api/v1/portfolios/{{ $portfolio->portfolio_id }}/feed with an Authorization: Bearer header — never any other portfolio or agency's data. See docs/INGESTION_GUIDE.md for the full contract this backs a data-licensing agreement with.">
                <form method="POST" action="{{ route('portfolios.tokens.store', $portfolio) }}" class="flex flex-col sm:flex-row gap-3">
                    @csrf
                    <x-text-input name="name" type="text" class="flex-1" placeholder="e.g. Acme Re payout system" required />
                    <x-loading-button class="btn-secondary" loading-text="Issuing…">Issue token</x-loading-button>
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
                                <th class="px-4 py-3 whitespace-nowrap">Issued by</th>
                                <th class="px-4 py-3 whitespace-nowrap">Last used</th>
                                <th class="px-4 py-3 whitespace-nowrap"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($apiTokens as $token)
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
                                    <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No tokens issued yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    @if ($mapAssets->isNotEmpty())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    window.initRegionMap('portfolio-map', @json($mapAssets));
                });
            </script>
        @endpush
    @endif
</x-app-layout>
