<div x-data="{ mobile: false }">
    <x-filament::tabs>
        <x-filament::tabs.item alpine-active="! mobile" x-on:click="mobile = false" icon="heroicon-o-computer-desktop">
            Desktop
        </x-filament::tabs.item>
        <x-filament::tabs.item alpine-active="mobile" x-on:click="mobile = true" icon="heroicon-o-device-phone-mobile">
            Mobile
        </x-filament::tabs.item>
    </x-filament::tabs>

    <p style="margin: 16px 0 8px; font-size: 0.875rem;">
        <strong>Subject:</strong> {{ $subject }}
    </p>
    <p style="margin: 0 0 16px; font-size: 0.75rem; opacity: 0.7;">
        Merge tags show sample values. Scripts and unsafe markup are removed before sending.
    </p>

    <div style="display: flex; justify-content: center;">
        {{-- The sandbox blocks scripts, so even unexpected content cannot run inside the panel. --}}
        <iframe
            srcdoc="{{ $html }}"
            sandbox=""
            title="Email preview"
            x-bind:style="{ width: mobile ? '375px' : '100%' }"
            style="width: 100%; height: 65vh; border: 1px solid rgb(127 127 127 / 0.3); border-radius: 8px; background: #ffffff;"
        ></iframe>
    </div>
</div>
