@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('contact.my_messages')],
    ]"
    title="{{ __('contact.my_messages') }}"
    subtitle="{{ __('contact.messages_sub') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="messages" />

    <x-dashboard.panel
        :title="__('contact.my_messages')"
        icon="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.9-3.8A7.96 7.96 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z">

        @if($threads->isNotEmpty())
            <ul class="divide-y" style="border-color: #E4D5BC;">
                @foreach($threads as $thread)
                    <li>
                        <a href="{{ route('dashboard.messages.show', $thread) }}"
                           class="dash-row-link flex items-start justify-between gap-4 px-4 py-4 transition">
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold" style="color: #4A3728;">{{ $thread->subject }}</span>
                                    @if($thread->unread_count > 0)
                                        <span class="dash-chip dash-chip-info">{{ __('contact.new_reply') }}</span>
                                    @endif
                                </span>
                                <span class="mt-1 block truncate text-sm" style="color: #8A7860;">{{ $thread->message }}</span>
                            </span>
                            <span class="shrink-0 text-right text-xs" style="color: #8A7860;">
                                <span class="block">{{ ($thread->last_message_at ?? $thread->created_at)->format('d M Y') }}</span>
                                <span class="block">
                                    {{ $thread->reply_count > 0 ? __('contact.replied') : __('contact.awaiting_reply') }}
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if($threads->hasPages())
                <div class="dash-panel-foot">{{ $threads->links() }}</div>
            @endif
        @else
            <div class="px-4 py-10 text-center">
                <p class="mb-4" style="color: #8A7860;">{{ __('contact.no_messages') }}</p>
                <a href="{{ route('contact') }}" class="btn-divine inline-flex px-6 py-2.5">{{ __('contact.start_new_message') }}</a>
            </div>
        @endif
    </x-dashboard.panel>
</div>
@endsection
