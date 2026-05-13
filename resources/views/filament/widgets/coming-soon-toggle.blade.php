{{-- Coming-soon toggle. Sits at the top of the admin dashboard so the
     trust can take the public site offline (admin keeps working) without
     SSHing or editing settings. --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-4">
                {{-- Status pill --}}
                <div @class([
                    'flex h-12 w-12 shrink-0 items-center justify-center rounded-full',
                    'bg-warning-100 text-warning-600 dark:bg-warning-500/20 dark:text-warning-400' => $enabled,
                    'bg-success-100 text-success-600 dark:bg-success-500/20 dark:text-success-400' => ! $enabled,
                ])>
                    @if ($enabled)
                        <x-heroicon-o-lock-closed class="h-6 w-6" />
                    @else
                        <x-heroicon-o-globe-alt class="h-6 w-6" />
                    @endif
                </div>

                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                            Coming Soon Mode
                        </h3>
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                            'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30' => $enabled,
                            'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30' => ! $enabled,
                        ])>
                            {{ $enabled ? 'ON — site locked' : 'OFF — site live' }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($enabled)
                            The public website is currently showing the coming-soon page. The admin panel keeps working.
                        @else
                            The public website is live. Flip this on to show every visitor a coming-soon page.
                        @endif
                    </p>
                </div>
            </div>

            {{-- Compact toggle switch. The "ON — site locked" /
                 "OFF — site live" badge next to the title already names
                 the state in words, so the toggle itself just needs to:
                   • be visibly clickable
                   • show direction of state at a glance (left=off, right=on)
                   • work in both light + dark mode
                 No inline ON/OFF labels — they were redundant with the
                 badge and made the whole widget feel cluttered. --}}
            {{-- Matches the look of Filament's stock ToggleColumn (the
                 same orange you see in the "Active" column on every
                 admin list). Primary = Orange in AdminPanelProvider, so
                 reusing `primary-500` keeps the visual language
                 consistent across the dashboard. --}}
            {{-- HeadlessUI / Filament canonical toggle markup:
                 • button is h-6 w-11 with a 2px transparent border so the
                   inner content area is exactly h-5 w-9 (the knob's
                   travel lane). border-transparent keeps the visible
                   shape rounded without an extra outline.
                 • items-center vertically centers the knob in the lane.
                 • knob is h-5 w-5 — snaps neatly inside the border area
                   without the height-mismatch gap that produced the
                   misaligned look in the previous version. --}}
            <button
                type="button"
                wire:click="toggle"
                wire:loading.attr="disabled"
                role="switch"
                :aria-checked="@js($enabled)"
                @class([
                    'relative inline-flex h-6 w-11 shrink-0 items-center cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900',
                    'bg-primary-500 focus:ring-primary-400' => $enabled,
                    'bg-gray-300 dark:bg-gray-600 focus:ring-gray-400' => ! $enabled,
                ])
            >
                <span class="sr-only">Toggle coming soon mode</span>
                <span @class([
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                    'translate-x-5' => $enabled,
                    'translate-x-0' => ! $enabled,
                ])></span>
            </button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
