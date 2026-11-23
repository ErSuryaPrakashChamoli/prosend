<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }} · {{ $grouping }} report · {{ $client->name }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; font-size: 11px; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p { margin: 0 0 12px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: right; white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; white-space: normal; }
        thead th { background: #f3f4f6; font-weight: 600; }
        tfoot td { font-weight: 600; background: #f9fafb; }
        .note { margin-top: 10px; font-size: 10px; color: #6b7280; }
        @media screen { body { margin: 24px; } }
    </style>
</head>
<body>
    <h1>{{ $grouping }} report</h1>
    <p>{{ $client->name }} · emails accepted from {{ $filters['start_date'] }} to {{ $filters['end_date'] }} · generated {{ now()->toDayDateTimeString() }}</p>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $key => $heading)
                        <td>{{ is_float($row[$key] ?? null) ? number_format($row[$key], 1) : (is_int($row[$key] ?? null) ? number_format($row[$key]) : ($row[$key] ?? '')) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">No emails were sent in this period.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                @foreach ($columns as $key => $heading)
                    <td>{{ $key === 'label' ? 'Total' : (is_float($totals[$key] ?? null) ? number_format($totals[$key], 1) : number_format((int) ($totals[$key] ?? 0))) }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>

    <p class="note">Opens and clicks are approximate. Positive replies and conversions are conversations marked Interested or Converted. To keep a PDF, choose "Save as PDF" in the print dialog.</p>

    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
