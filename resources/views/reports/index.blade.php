<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Reports') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Export scores for an index and date range as CSV or PDF — for a meeting, a briefing, or a record.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="section-card p-6">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Build a report</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Pick what to report on, the date range, and how you want the file.</p>

                <form method="POST" action="{{ route('reports.store') }}" class="space-y-5">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="index_id">Index</x-input-label>
                            <x-select-input id="index_id" name="index_id" required>
                                @foreach ($indices as $idx)
                                    <option value="{{ $idx->index_id }}">{{ $idx->name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div>
                            <x-input-label for="format">Format</x-input-label>
                            <x-select-input id="format" name="format" required>
                                <option value="csv">CSV</option>
                                <option value="pdf">PDF</option>
                            </x-select-input>
                        </div>
                        <div>
                            <x-input-label for="date_from">From</x-input-label>
                            <x-text-input id="date_from" type="date" name="date_from" required />
                        </div>
                        <div>
                            <x-input-label for="date_to">To</x-input-label>
                            <x-text-input id="date_to" type="date" name="date_to" required />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="region_ids">Regions</x-input-label>
                        <x-select-input id="region_ids" name="region_ids[]" multiple required size="6">
                            @foreach ($regions as $region)
                                <option value="{{ $region->region_id }}">{{ $region->name }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                    @if ($hasAgency)
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="share_with_agency" value="1" class="rounded">
                            Share with everyone in my agency
                        </label>
                    @endif
                    <button type="submit" class="btn-primary w-full sm:w-auto">Generate report</button>
                </form>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Your reports</h3>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($reportRequests as $report)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $report->index->name }} <span class="font-normal text-slate-400 uppercase">&middot; {{ $report->format }}</span>
                                    @if ($report->agency_id)
                                        <span class="risk-badge risk-badge-green ms-1">agency</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $report->date_from->toDateString() }} – {{ $report->date_to->toDateString() }} &middot; {{ $report->status }}
                                </p>
                            </div>
                            @if ($report->status === 'READY')
                                <a href="{{ route('reports.download', $report) }}" class="link-nav flex-shrink-0">Download &rarr;</a>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">No reports yet.</li>
                    @endforelse
                </ul>
            </div>

            @if ($hasAgency)
                <div class="section-card overflow-hidden">
                    <div class="section-card-header">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Shared by your agency</h3>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($sharedReports as $report)
                            <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $report->index->name }} <span class="font-normal text-slate-400 uppercase">&middot; {{ $report->format }}</span></p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        {{ $report->date_from->toDateString() }} – {{ $report->date_to->toDateString() }} &middot; generated by {{ $report->user->name }}
                                    </p>
                                </div>
                                <a href="{{ route('reports.download', $report) }}" class="link-nav flex-shrink-0">Download &rarr;</a>
                            </li>
                        @empty
                            <li class="px-5 py-8 text-center text-sm text-gray-500">Nobody in your agency has shared a report yet.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
