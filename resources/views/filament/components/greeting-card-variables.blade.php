<div class="text-sm">
    <p class="font-semibold text-gray-700 dark:text-gray-300 mb-2">Available variables for overlays:</p>
    <div class="flex flex-wrap gap-2">
        <span class="px-2 py-1 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 rounded text-xs font-mono">_donor_name</span>
        <span class="px-2 py-1 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 rounded text-xs font-mono">_amount</span>
        <span class="px-2 py-1 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 rounded text-xs font-mono">_date</span>
        <span class="px-2 py-1 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 rounded text-xs font-mono">_temple_name</span>
        @if(is_array($extraFields))
            @foreach($extraFields as $field)
                @if(!empty($field['key']))
                    <span class="px-2 py-1 bg-success-50 dark:bg-success-900/20 text-success-700 dark:text-success-400 rounded text-xs font-mono">{{ $field['key'] }}</span>
                @endif
            @endforeach
        @endif
    </div>
    <p class="text-xs text-gray-500 mt-2">Blue = auto-filled from donation data. Green = from the devotee's form submission.</p>
</div>
