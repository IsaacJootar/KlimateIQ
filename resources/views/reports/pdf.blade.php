<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1e293b; }
        h1 { font-size: 18px; margin-bottom: 0; }
        p.meta { color: #64748b; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
        th { background: #f0fdfa; }
    </style>
</head>
<body>
    <h1>{{ $reportRequest->index->name }} — KlimateIQ Report</h1>
    <p class="meta">{{ $reportRequest->date_from->toDateString() }} to {{ $reportRequest->date_to->toDateString() }} &middot; {{ count($regions) }} regions</p>

    <table>
        <thead>
            <tr>
                <th>Region</th>
                <th>State</th>
                <th>Period</th>
                <th>Score</th>
                <th>Strategy</th>
                <th>Recommended Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $regions[$row->region_id]->name ?? '' }}</td>
                    <td>{{ $regions[$row->region_id]->state ?? '' }}</td>
                    <td>{{ $row->period_start->toDateString() }} – {{ $row->period_end->toDateString() }}</td>
                    <td>{{ $row->score ?? '—' }}</td>
                    <td>{{ $row->scoring_strategy }}</td>
                    <td>{{ $actionsByBand[\App\Support\RiskBand::forScore($row->score)] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No scores in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
