@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('contact.my_messages'), 'url' => route('dashboard.messages')],
        ['label' => $thread->subject],
    ]"
    title="{{ $thread->subject }}"
    subtitle="{{ $thread->category->label() }}" />

<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="messages" />

    <div class="space-y-4">
        {{-- The opening message is not a ContactMessage row — it lives on the
             submission itself — so it is rendered first, by hand. --}}
        <x-dashboard.chat-bubble
            :author="__('contact.you')"
            :body="$thread->message"
            :at="$thread->created_at"
            :mine="true" />

        @foreach($thread->messages as $turn)
            <x-dashboard.chat-bubble
                :author="$turn->isFromAdmin() ? ($turn->adminUser?->name ?? __('contact.the_trust')) : __('contact.you')"
                :body="$turn->body"
                :at="$turn->created_at"
                :mine="! $turn->isFromAdmin()" />
        @endforeach
    </div>

    <form method="POST" action="{{ route('dashboard.messages.reply', $thread) }}" class="mt-8">
        @csrf
        <label for="body" class="dash-label">{{ __('contact.send_reply') }}</label>
        <textarea name="body" id="body" rows="4" required maxlength="2000"
            placeholder="{{ __('contact.write_reply') }}"
            class="dash-input">{{ old('body') }}</textarea>
        @error('body')<p class="dash-error">{{ $message }}</p>@enderror
        <button type="submit" class="btn-divine mt-3">{{ __('contact.send_reply') }}</button>
    </form>
</div>
@endsection
