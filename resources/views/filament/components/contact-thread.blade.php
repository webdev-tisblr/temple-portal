{{-- Read-only transcript of one contact conversation, newest last.

     The opening message is not a ContactMessage row — it lives on the
     submission itself (see the create_temple_contact_messages migration) — so
     it is rendered here as the first bubble rather than fetched with the rest. --}}
@php
    $messages = $record?->messages ?? collect();
@endphp

<div class="space-y-3">
    <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900/40">
        <div class="mb-1 flex items-center justify-between gap-3">
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                {{ $record?->name ?: 'Devotee' }}
            </span>
            <span class="text-xs text-gray-400">{{ $record?->created_at?->format('d M Y, h:i A') }}</span>
        </div>
        <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $record?->message }}</p>
    </div>

    @forelse($messages as $message)
        <div @class([
            'rounded-lg border p-3',
            'border-primary-200 bg-primary-50 dark:border-primary-900 dark:bg-primary-950/30' => $message->isFromAdmin(),
            'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900/40' => ! $message->isFromAdmin(),
        ])>
            <div class="mb-1 flex items-center justify-between gap-3">
                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                    @if($message->isFromAdmin())
                        {{ $message->adminUser?->name ?? 'The trust' }}
                    @else
                        {{ $record?->name ?: 'Devotee' }}
                    @endif
                </span>
                <span class="text-xs text-gray-400">
                    {{ $message->created_at?->format('d M Y, h:i A') }}
                    @if($message->isFromAdmin())
                        {{-- Whether the devotee has actually opened it. This is
                             the whole reason the reply exists, so it is worth
                             saying out loud rather than assuming delivery. --}}
                        · {{ $message->read_at ? 'seen' : 'not opened yet' }}
                    @endif
                </span>
            </div>
            <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $message->body }}</p>
        </div>
    @empty
        <p class="text-xs italic text-gray-400">No reply has been sent yet.</p>
    @endforelse
</div>
