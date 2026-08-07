<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="section-card p-6">
                <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-4">Build a report</h3>
                <form method="POST" action="{{ route('reports.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium mb-1">Index</label>
                        <select name="index_id" required class="w-full rounded-lg">
                            @foreach ($indices as $idx)
                                <option value="{{ $idx->index_id }}">{{ $idx->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Format</label>
                        <select name="format" required class="w-full rounded-lg">
                            <option value="csv">CSV</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">From</label>
                        <input type="date" name="date_from" required class="w-full rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">To</label>
                        <input type="date" name="date_to" required class="w-full rounded-lg">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium mb-1">Regions</label>
                        <select name="region_ids[]" multiple required class="w-full rounded-lg" size="6">
                            @foreach ($regions as $region)
                                <option value="{{ $region->region_id }}">{{ $region->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">Generate report</button>
                    </div>
                </form>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Your reports</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">Index</th>
                            <th class="px-4 py-3">Range</th>
                            <th class="px-4 py-3">Format</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($reportRequests as $report)
                            <tr>
                                <td class="px-4 py-3 text-sm">{{ $report->index->name }}</td>
                                <td class="px-4 py-3 text-sm">{{ $report->date_from->toDateString() }} – {{ $report->date_to->toDateString() }}</td>
                                <td class="px-4 py-3 text-sm uppercase">{{ $report->format }}</td>
                                <td class="px-4 py-3 text-sm">{{ $report->status }}</td>
                                <td class="px-4 py-3 text-sm text-right">
                                    @if ($report->status === 'READY')
                                        <a href="{{ route('reports.download', $report) }}" class="link-nav">Download &rarr;</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No reports yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
