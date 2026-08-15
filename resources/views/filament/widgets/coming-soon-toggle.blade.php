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

        {{-- Launch time. Only meaningful while the site is hidden, so it is
             hidden itself once the site is live — a stale launch date under
             an already-open site reads as a pending action that is not
             pending. --}}
        @if ($enabled)
            <div class="mt-5 border-t border-gray-200 pt-5 dark:border-white/10">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex-1">
                        <label for="launch-at" class="block text-sm font-medium text-gray-950 dark:text-white">
                            Launch date &amp; time
                        </label>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            A countdown appears on the coming-soon page, and the site opens
                            <strong>by itself</strong> the moment it reaches zero — no one needs to be
                            awake for it. Indian Standard Time. Leave blank to keep opening the site by hand.
                        </p>
                        <input
                            id="launch-at"
                            type="datetime-local"
                            wire:model="launchAt"
                            class="mt-3 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white sm:max-w-xs"
                        >
                    </div>

                    <button
                        type="button"
                        wire:click="saveLaunchAt"
                        wire:loading.attr="disabled"
                        class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-gray-900"
                    >
                        <x-heroicon-m-clock class="h-4 w-4" />
                        Save launch time
                    </button>
                </div>

                @if ($this->launchIso)
                    <p class="mt-3 text-sm font-medium text-primary-600 dark:text-primary-400">
                        Opening {{ \Illuminate\Support\Carbon::parse($this->launchIso)->format('d M Y, h:i A') }}
                        ({{ \Illuminate\Support\Carbon::parse($this->launchIso)->diffForHumans() }})
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Cloudflare caches the coming-soon page, so it is purged automatically at launch.
                        If no Cloudflare credentials are set on the server, purge it by hand right after —
                        otherwise visitors keep seeing this page even though the site is open.
                    </p>
                @endif
            </div>
        @endif

        {{-- Dates booked on paper or over the phone have to be closed before
             the site offers them again, so the entry point sits with the
             launch controls rather than buried in a menu. --}}
        @if (auth('admin')->user()?->can('page_ExistingBookingsPage'))
            <p class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                Bookings taken off the platform?
                <a
                    href="{{ \App\Filament\Pages\ExistingBookingsPage::getUrl() }}"
                    class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                >Block those dates from a CSV</a>
                — download the current sheet, fill in the members, upload it back.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
