{{-- One row per check. Every value is escaped. --}}
<div class="fi-section divide-y divide-gray-200 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10">
    @foreach ($checks as $check)
        <div class="flex items-start gap-4 px-4 py-3">
            <span @class([
                'mt-0.5 inline-flex shrink-0 rounded-md px-2 py-0.5 text-xs font-semibold',
                'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $check['status'] === 'ok',
                'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400' => $check['status'] === 'warning',
                'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $check['status'] === 'danger',
            ])>{{ ['ok' => 'OK', 'warning' => 'Check', 'danger' => 'Problem'][$check['status']] }}</span>
            <div>
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $check['name'] }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $check['detail'] }}</p>
            </div>
        </div>
    @endforeach
</div>
