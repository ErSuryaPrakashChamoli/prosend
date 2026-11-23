<div x-data="{ mobile: false }" class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-filament::tabs>
            <x-filament::tabs.item alpine-active="! mobile" x-on:click="mobile = false" icon="heroicon-o-computer-desktop">
                Desktop
            </x-filament::tabs.item>
            <x-filament::tabs.item alpine-active="mobile" x-on:click="mobile = true" icon="heroicon-o-device-phone-mobile">
                Mobile
            </x-filament::tabs.item>
        </x-filament::tabs>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Merge tags show sample values. Scripts and unsafe markup are removed before sending.
        </p>
    </div>

    <div class="overflow-hidden rounded-2xl bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <div class="flex items-center gap-3 border-b border-gray-950/5 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900">
            <span class="flex gap-1.5" aria-hidden="true">
                <span class="size-2.5 rounded-full bg-gray-200 dark:bg-white/15"></span>
                <span class="size-2.5 rounded-full bg-gray-200 dark:bg-white/15"></span>
                <span class="size-2.5 rounded-full bg-gray-200 dark:bg-white/15"></span>
            </span>
            <p class="min-w-0 truncate text-sm">
                <span class="text-gray-500 dark:text-gray-400">Subject:</span>
                <span class="font-medium text-gray-950 dark:text-white">{{ $subject }}</span>
            </p>
        </div>

        <div class="flex justify-center p-4">
            {{-- The sandbox blocks scripts, so even unexpected content cannot run inside the panel. --}}
            <iframe
                srcdoc="{{ $html }}"
                sandbox=""
                title="Email preview"
                x-bind:class="mobile ? 'w-[375px]' : 'w-full'"
                class="h-[65vh] rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 transition-[width] duration-200"
            ></iframe>
        </div>
    </div>
</div>
