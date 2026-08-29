@props(['active' => null])
{{--
    Section nav shared by every /dashboard/* page, so the pages stop
    being islands you can only reach from the overview. Rendered as a
    horizontally scrollable pill row — no letter-spacing anywhere, the
    labels are Gujarati/Devanagari on two of the three locales.
--}}
@php
    $items = [
        ['key' => 'index', 'route' => 'dashboard.index', 'label' => __('dashboard.overview'), 'icon' => 'M4 6a2 2 0 012-2h4v6H4V6zm0 8h6v6H6a2 2 0 01-2-2v-4zm10-10h4a2 2 0 012 2v4h-6V4zm0 8h6v6a2 2 0 01-2 2h-4v-8z'],
        ['key' => 'donations', 'route' => 'dashboard.donations', 'label' => __('dashboard.my_donations'), 'icon' => 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z'],
        ['key' => 'bookings', 'route' => 'dashboard.bookings', 'label' => __('dashboard.my_bookings'), 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ['key' => 'orders', 'route' => 'dashboard.orders', 'label' => __('dashboard.my_orders'), 'icon' => 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
        ['key' => 'receipts', 'route' => 'dashboard.receipts', 'label' => __('dashboard.receipts_80g'), 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['key' => 'messages', 'route' => 'dashboard.messages', 'label' => __('contact.my_messages'), 'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.9-3.8A7.96 7.96 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
        ['key' => 'profile', 'route' => 'dashboard.profile', 'label' => __('dashboard.profile'), 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
    ];
@endphp

<nav aria-label="{{ __('dashboard.account_menu') }}" class="-mx-4 mb-8 overflow-x-auto px-4 sm:mx-0 sm:px-0">
    <ul class="flex items-center gap-2 pb-1">
        @foreach($items as $item)
            <li>
                <a href="{{ route($item['route']) }}"
                   class="dash-nav-link"
                   @if($active === $item['key']) aria-current="page" @endif>
                    <x-dashboard.icon :path="$item['icon']" class="w-4 h-4" />
                    {{ $item['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
