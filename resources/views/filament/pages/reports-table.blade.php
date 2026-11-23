{{-- The report table: one row per group, then the period's totals. Every value is escaped. --}}
<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="overflow-x-auto">
        <table class="w-full divide-y divide-gray-200 text-start text-sm dark:divide-white/5">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    @foreach ($columns as $key => $heading)
                        <th scope="col" @class(['px-3 py-3 font-semibold text-gray-950 dark:text-white', 'text-start' => $key === 'label', 'text-end' => $key !== 'label'])>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $key => $heading)
                            <td @class(['px-3 py-2 text-gray-950 dark:text-white', 'text-start font-medium' => $key === 'label', 'text-end tabular-nums' => $key !== 'label'])>
                                {{ is_float($row[$key] ?? null) ? number_format($row[$key], 1) : (is_int($row[$key] ?? null) ? number_format($row[$key]) : ($row[$key] ?? '')) }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">No emails were sent in this period.</td>
                    </tr>
                @endforelse
            </tbody>
            @if (count($rows) > 0)
                <tfoot class="bg-gray-50 font-semibold dark:bg-white/5">
                    <tr>
                        @foreach ($columns as $key => $heading)
                            <td @class(['px-3 py-2 text-gray-950 dark:text-white', 'text-start' => $key === 'label', 'text-end tabular-nums' => $key !== 'label'])>
                                {{ $key === 'label' ? 'Total' : (is_float($totals[$key] ?? null) ? number_format($totals[$key], 1) : number_format((int) ($totals[$key] ?? 0))) }}
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <p class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
        Emails accepted by providers in the period. Opens and clicks are approximate. Positive replies and conversions are conversations marked Interested or Converted in the inbox.
    </p>
</div>
