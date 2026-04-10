@php
    $record = $record ?? null;
    $extraFields = $record?->extra_fields ?? [];
    $config = $record?->greeting_card_config ?? [];
    $overlays = $config['overlays'] ?? [];
    $templatePath = $record?->greeting_card_template;
    $templateUrl = $templatePath ? asset('storage/' . $templatePath) : null;
    $statePath = $statePath ?? 'data.greeting_card_config';

    $availableVars = [
        ['key' => '_donor_name', 'label' => 'Donor Name', 'type' => 'text', 'auto' => true],
        ['key' => '_amount', 'label' => 'Amount', 'type' => 'text', 'auto' => true],
        ['key' => '_date', 'label' => 'Date', 'type' => 'text', 'auto' => true],
        ['key' => '_temple_name', 'label' => 'Temple Name', 'type' => 'text', 'auto' => true],
    ];
    if (is_array($extraFields)) {
        foreach ($extraFields as $f) {
            if (!empty($f['key'])) {
                $availableVars[] = [
                    'key' => $f['key'],
                    'label' => $f['label_en'] ?? $f['key'],
                    'type' => $f['type'] ?? 'text',
                    'auto' => false,
                ];
            }
        }
    }
@endphp

<div x-data="greetingCardEditor(@js($overlays), @js($config))" x-init="init()" class="space-y-4">

    {{-- Canvas Area --}}
    <div class="relative border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-800" style="min-height: 300px;">
        @if($templateUrl)
            <img src="{{ $templateUrl }}" alt="Template" class="w-full h-auto block" x-ref="bgImage"
                 @load="onBgLoad($event)">
        @else
            <div class="flex items-center justify-center h-64 text-gray-400 dark:text-gray-500">
                <div class="text-center">
                    <svg class="w-12 h-12 mx-auto mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p>Upload a background template image above first.</p>
                </div>
            </div>
        @endif

        {{-- Draggable overlays --}}
        <template x-for="(overlay, idx) in overlays" :key="idx">
            <div :style="getOverlayStyle(overlay)"
                 @mousedown.prevent="startDrag(idx, $event)"
                 @touchstart.prevent="startDrag(idx, $event)"
                 class="absolute cursor-move select-none"
                 :class="selectedIdx === idx ? 'ring-2 ring-blue-500 ring-offset-1' : 'hover:ring-2 hover:ring-blue-300'"
                 @click.stop="selectedIdx = idx">
                <template x-if="overlay.type === 'text'">
                    <span :style="'font-size:' + Math.max(8, (overlay.font_size || 24) * scale) + 'px; color:' + (overlay.color || '#333') + '; font-weight:bold; white-space:nowrap; text-shadow: 0 1px 3px rgba(0,0,0,0.4); line-height:1.2;'"
                          x-text="getSampleText(overlay.field_key)"></span>
                </template>
                <template x-if="overlay.type === 'image'">
                    <div :style="'width:' + ((overlay.width || 100) * scale) + 'px; height:' + ((overlay.height || 100) * scale) + 'px;'"
                         class="bg-white/30 border-2 border-dashed border-gray-400 rounded-lg flex items-center justify-center backdrop-blur-sm">
                        <span class="text-xs text-gray-600 font-medium" x-text="overlay.field_key"></span>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Add Overlay Toolbar --}}
    <div class="flex flex-wrap gap-2 items-center">
        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Add:</span>
        @foreach($availableVars as $v)
            <button type="button"
                @click="addOverlay('{{ $v['key'] }}', '{{ $v['type'] === 'image' ? 'image' : 'text' }}')"
                class="px-2.5 py-1 text-xs rounded-lg border transition font-medium
                    {{ $v['auto'] ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-400 hover:bg-blue-100' : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100' }}">
                + {{ $v['label'] }}
            </button>
        @endforeach
    </div>

    {{-- Selected Overlay Properties --}}
    <template x-if="selectedIdx !== null && overlays[selectedIdx]">
        <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 space-y-3" x-transition>
            <div class="flex items-center justify-between">
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300">
                    Editing: <span class="text-primary-600" x-text="overlays[selectedIdx]?.field_key"></span>
                    <span class="text-xs text-gray-400 ml-1" x-text="'(' + overlays[selectedIdx]?.type + ')'"></span>
                </h4>
                <button type="button" @click="removeOverlay(selectedIdx)" class="text-xs text-red-500 hover:text-red-700 font-medium">Delete</button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <label class="text-xs font-medium text-gray-500">X (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].x" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500">Y (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].y" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'text'">
                    <label class="text-xs font-medium text-gray-500">Font Size</label>
                    <input type="number" x-model.number="overlays[selectedIdx].font_size" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'text'">
                    <label class="text-xs font-medium text-gray-500">Color</label>
                    <input type="color" x-model="overlays[selectedIdx].color" @input="syncToForm()" class="w-full h-9 rounded-lg border-gray-300 dark:border-gray-600 cursor-pointer">
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'image'">
                    <label class="text-xs font-medium text-gray-500">Width (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].width" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'image'">
                    <label class="text-xs font-medium text-gray-500">Height (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].height" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                </div>
            </div>
        </div>
    </template>

    <p class="text-xs text-gray-400 dark:text-gray-500">Drag overlays to position. Click to select & edit properties. Coordinates saved relative to original image size.</p>
</div>

<script>
function greetingCardEditor(initialOverlays, initialConfig) {
    return {
        overlays: initialOverlays || [],
        selectedIdx: null,
        scale: 1,
        naturalW: 1200,
        naturalH: 800,
        dragging: false,
        dragIdx: null,
        dragStartX: 0,
        dragStartY: 0,
        dragOrigX: 0,
        dragOrigY: 0,
        _sendConfig: {
            send_via_email: initialConfig?.send_via_email ?? true,
            send_via_whatsapp: initialConfig?.send_via_whatsapp ?? true,
            show_on_thankyou: initialConfig?.show_on_thankyou ?? true,
        },

        init() {
            document.addEventListener('mousemove', (e) => this.onDrag(e));
            document.addEventListener('mouseup', () => this.stopDrag());
            document.addEventListener('touchmove', (e) => this.onDrag(e), { passive: false });
            document.addEventListener('touchend', () => this.stopDrag());
            // Initial sync
            this.$nextTick(() => this.syncToForm());
        },

        onBgLoad(event) {
            let img = event.target;
            this.naturalW = img.naturalWidth || 1200;
            this.naturalH = img.naturalHeight || 800;
            this.scale = img.clientWidth / this.naturalW;
        },

        addOverlay(fieldKey, type) {
            this.overlays.push({
                field_key: fieldKey,
                type: type,
                x: 50 + (this.overlays.length * 20),
                y: 50 + (this.overlays.length * 20),
                font_size: type === 'text' ? 32 : undefined,
                color: type === 'text' ? '#881337' : undefined,
                width: type === 'image' ? 150 : undefined,
                height: type === 'image' ? 150 : undefined,
            });
            this.selectedIdx = this.overlays.length - 1;
            this.syncToForm();
        },

        removeOverlay(idx) {
            this.overlays.splice(idx, 1);
            this.selectedIdx = null;
            this.syncToForm();
        },

        startDrag(idx, event) {
            this.dragging = true;
            this.dragIdx = idx;
            this.selectedIdx = idx;
            let pos = event.touches ? event.touches[0] : event;
            this.dragStartX = pos.clientX;
            this.dragStartY = pos.clientY;
            this.dragOrigX = this.overlays[idx].x;
            this.dragOrigY = this.overlays[idx].y;
        },

        onDrag(event) {
            if (!this.dragging || this.dragIdx === null) return;
            if (event.cancelable) event.preventDefault();
            let pos = event.touches ? event.touches[0] : event;
            let dx = (pos.clientX - this.dragStartX) / this.scale;
            let dy = (pos.clientY - this.dragStartY) / this.scale;
            this.overlays[this.dragIdx].x = Math.max(0, Math.round(this.dragOrigX + dx));
            this.overlays[this.dragIdx].y = Math.max(0, Math.round(this.dragOrigY + dy));
        },

        stopDrag() {
            if (this.dragging) {
                this.dragging = false;
                this.dragIdx = null;
                this.syncToForm();
            }
        },

        getOverlayStyle(overlay) {
            return 'left:' + (overlay.x * this.scale) + 'px; top:' + (overlay.y * this.scale) + 'px; position:absolute;';
        },

        getSampleText(key) {
            const samples = {
                '_donor_name': 'Ramesh Patel',
                '_amount': '₹5,100.00',
                '_date': '09 Apr 2026',
                '_temple_name': 'Shree Pataliya Hanumanji',
            };
            return samples[key] || key;
        },

        syncToForm() {
            let config = {
                overlays: this.overlays.map(o => {
                    let c = { field_key: o.field_key, type: o.type, x: o.x, y: o.y };
                    if (o.type === 'text') { c.font_size = o.font_size || 24; c.color = o.color || '#333'; }
                    if (o.type === 'image') { c.width = o.width || 150; c.height = o.height || 150; }
                    return c;
                }),
                send_via_email: this._sendConfig.send_via_email,
                send_via_whatsapp: this._sendConfig.send_via_whatsapp,
                show_on_thankyou: this._sendConfig.show_on_thankyou,
            };

            // Sync to Livewire via the Hidden input element
            let jsonStr = JSON.stringify(config);
            let hiddenInput = document.querySelector('input[wire\\:model\\.defer="{{ $statePath }}"], input[x-model="{{ $statePath }}"]');
            // Filament Hidden fields have a wire:model attribute — find it by iterating
            if (!hiddenInput) {
                document.querySelectorAll('input[type="hidden"]').forEach(el => {
                    let wm = el.getAttribute('wire:model') || el.getAttribute('wire:model.defer') || '';
                    if (wm.includes('greeting_card_config')) {
                        hiddenInput = el;
                    }
                });
            }
            if (hiddenInput) {
                hiddenInput.value = jsonStr;
                hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            // Also try Livewire direct
            if (typeof Livewire !== 'undefined') {
                try {
                    let component = Livewire.find(document.querySelector('[wire\\:id]')?.getAttribute('wire:id'));
                    if (component) {
                        component.set('{{ $statePath }}', jsonStr);
                    }
                } catch(e) {}
            }
        },
    };
}
</script>
