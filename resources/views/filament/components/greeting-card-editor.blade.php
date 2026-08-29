@php
    $record = $record ?? null;
    $extraFields = $record?->extra_fields ?? [];
    $config = $record?->greeting_card_config ?? [];
    $overlays = $config['overlays'] ?? [];
    $templatePath = $record?->greeting_card_template;
    $templateUrl = $templatePath ? image_url($templatePath) : null;
    $statePath = $statePath ?? 'data.greeting_card_config';

    // Families offered to a text block. Indic-capable ones are listed first
    // and labelled, because a Latin-only face cannot draw Gujarati at all —
    // fontconfig substitutes per glyph and the card comes out in a face the
    // admin never chose. @see App\Services\GoogleFontService
    $fontFamilies = app(\App\Services\GoogleFontService::class)->families();
    $indicFonts = array_values(array_filter($fontFamilies, fn ($f) => $f['indic']));
    $latinFonts = array_values(array_filter($fontFamilies, fn ($f) => ! $f['indic']));

    // Callers (Seva / Darshan templates) may inject their own variable
    // buttons; the default set below is the donation-type one.
    if (! isset($availableVars) || ! is_array($availableVars)) {
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
    }
@endphp

<div wire:ignore x-data="greetingCardEditor(@js($overlays), @js($config))" x-init="init()" class="space-y-4">

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
        <template x-for="(overlay, idx) in overlays" :key="overlay._uid">
            <div :style="getOverlayStyle(overlay)"
                 @mousedown.prevent="startDrag(idx, $event)"
                 @touchstart.prevent="startDrag(idx, $event)"
                 class="absolute cursor-move select-none"
                 :class="selectedIdx === idx ? 'ring-2 ring-blue-500 ring-offset-1' : 'hover:ring-2 hover:ring-blue-300'"
                 @click.stop="selectedIdx = idx">
                <template x-if="overlay.type === 'text'">
                    {{-- font-weight follows the overlay's own bold flag. It
                         used to be hardcoded 'bold', so the preview lied:
                         every overlay looked bold here and rendered normal
                         on the real card (2026-08-17). --}}
                    <div :style="'width:' + ((overlay.width || 300) * scale) + 'px; font-size:' + Math.max(8, (overlay.font_size || 24) * scale) + 'px; color:' + (overlay.color || '#333') + '; font-weight:' + (overlay.bold ? '700' : '400') + '; text-align:center; white-space:normal; overflow-wrap:break-word; word-break:break-word; text-shadow: 0 1px 3px rgba(0,0,0,0.4); line-height:1.4;'"
                         x-text="getSampleText(overlay.field_key)"></div>
                </template>
                <template x-if="overlay.type === 'rich_text'">
                    {{-- WYSIWYG preview. Variables show their sample values so
                         the admin sees the real line length, which is the whole
                         reason this block type exists. --}}
                    <div :style="richPreviewStyle(overlay)" x-html="richPreviewHtml(overlay)"></div>
                </template>
                <template x-if="overlay.type === 'image'">
                    <div :style="'width:' + ((overlay.width || 100) * scale) + 'px; height:' + ((overlay.height || 100) * scale) + 'px;'"
                         class="bg-white/30 border-2 border-dashed border-gray-400 flex items-center justify-center backdrop-blur-sm"
                         :class="overlay.shape === 'circle' ? 'rounded-full' : 'rounded-lg'">
                        <span class="text-xs text-gray-600 font-medium" x-text="overlay.field_key"></span>
                    </div>
                </template>

                {{-- Resize handle (bottom-right). Drag to grow/shrink: image
                     overlays resize width+height, text overlays scale font. --}}
                <div x-show="selectedIdx === idx"
                     @mousedown.stop.prevent="startResize(idx, $event)"
                     @touchstart.stop.prevent="startResize(idx, $event)"
                     class="absolute -bottom-1.5 -right-1.5 w-3.5 h-3.5 bg-blue-500 border-2 border-white rounded-sm shadow cursor-se-resize"
                     title="Drag to resize"></div>
            </div>
        </template>
    </div>

    {{-- Add Overlay Toolbar --}}
    <div class="flex flex-wrap gap-2 items-center">
        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Add:</span>
        {{-- The preferred way to put words on a card (2026-08-29): one block
             holding the whole sentence, variables included, instead of a
             single-variable overlay parked on wording painted into the
             artwork. Listed first because it is what an admin should reach
             for. --}}
        <button type="button"
            @click="addTextBlock()"
            class="px-2.5 py-1 text-xs rounded-lg border transition font-semibold bg-primary-50 dark:bg-primary-900/20 border-primary-300 dark:border-primary-700 text-primary-700 dark:text-primary-300 hover:bg-primary-100">
            + Text block
        </button>
        <span class="text-gray-300 dark:text-gray-600">|</span>
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
                <button type="button" @click="removeOverlay(selectedIdx)"
                    style="background-color:#dc2626;color:#ffffff;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;border:none;cursor:pointer;line-height:1;"
                    onmouseover="this.style.backgroundColor='#b91c1c'" onmouseout="this.style.backgroundColor='#dc2626'">
                    <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete
                </button>
            </div>

            {{-- ── Rich text block editor ──────────────────────────────
                 Everything the sentence needs, in one place: the words, the
                 variables inside them, and the typography. Bold/italic/
                 underline apply to the SELECTION; family, size, colour and
                 alignment apply to the block. --}}
            <template x-if="overlays[selectedIdx]?.type === 'rich_text'">
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-1.5">
                        <button type="button" @click="fmt('bold')" title="Bold" class="rte-btn" style="font-weight:800;">B</button>
                        <button type="button" @click="fmt('italic')" title="Italic" class="rte-btn" style="font-style:italic;">I</button>
                        <button type="button" @click="fmt('underline')" title="Underline" class="rte-btn" style="text-decoration:underline;">U</button>
                        <span class="mx-1 h-4 w-px bg-gray-200 dark:bg-gray-600"></span>
                        <label class="rte-btn cursor-pointer" title="Colour for the selected words">
                            <span style="text-decoration:underline; text-decoration-thickness:3px;" :style="'text-decoration-color:' + inlineColor">A</span>
                            <input type="color" x-model="inlineColor" @input="applyInlineColor()" class="sr-only">
                        </label>
                        <select @change="applyInlineSize($event.target.value); $event.target.value = ''" class="rte-select" title="Size for the selected words">
                            <option value="">Size…</option>
                            <template x-for="px in [16, 20, 24, 28, 32, 40, 48, 56, 64, 80]" :key="px">
                                <option :value="px" x-text="px + ' px'"></option>
                            </template>
                        </select>
                        <span class="mx-1 h-4 w-px bg-gray-200 dark:bg-gray-600"></span>
                        <button type="button" @click="fmt('removeFormat')" title="Clear formatting on the selection" class="rte-btn text-xs">Clear</button>
                    </div>

                    <div contenteditable="true"
                         x-ref="rte"
                         x-effect="loadRte(selectedIdx)"
                         @input="onRteInput()"
                         @blur="onRteInput()"
                         class="min-h-[90px] w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3 py-2 text-sm leading-relaxed focus:outline-none focus:ring-2 focus:ring-primary-500"
                         style="white-space: pre-wrap;"></div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs font-medium text-gray-500">Insert:</span>
                        @foreach($availableVars as $v)
                            @if(($v['type'] ?? 'text') !== 'image')
                                <button type="button" @click="insertVariable('{{ $v['key'] }}')"
                                    class="px-2 py-0.5 text-[11px] rounded-md border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100">
                                    {{ $v['label'] }}
                                </button>
                            @endif
                        @endforeach
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="col-span-2">
                            <label class="text-xs font-medium text-gray-500">Font (Google Fonts)</label>
                            <select x-model="overlays[selectedIdx].font_family" @change="loadPreviewFont(overlays[selectedIdx].font_family); syncToForm()"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                                <optgroup label="Covers Gujarati / Hindi">
                                    @foreach($indicFonts as $f)
                                        <option value="{{ $f['family'] }}">{{ $f['family'] }}</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="Latin only — Gujarati text falls back to another face">
                                    @foreach($latinFonts as $f)
                                        <option value="{{ $f['family'] }}">{{ $f['family'] }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500">Alignment</label>
                            <select x-model="overlays[selectedIdx].align" @change="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500">Base size (px)</label>
                            <input type="number" min="8" x-model.number="overlays[selectedIdx].font_size" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                        </div>
                    </div>
                </div>
            </template>

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
                <div x-show="isTextish(overlays[selectedIdx])">
                    <label class="text-xs font-medium text-gray-500">Color</label>
                    <input type="color" x-model="overlays[selectedIdx].color" @input="syncToForm()" class="w-full h-9 rounded-lg border-gray-300 dark:border-gray-600 cursor-pointer">
                </div>
                <div x-show="isTextish(overlays[selectedIdx])">
                    <label class="text-xs font-medium text-gray-500">Weight</label>
                    <button type="button"
                        @click="overlays[selectedIdx].bold = !overlays[selectedIdx].bold; syncToForm()"
                        class="w-full h-9 rounded-lg border text-sm transition flex items-center justify-center gap-1.5"
                        :class="overlays[selectedIdx].bold
                            ? 'bg-primary-600 border-primary-600 text-white font-bold'
                            : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300'">
                        <span style="font-weight:800;">B</span>
                        <span x-text="overlays[selectedIdx].bold ? 'Bold' : 'Normal'"></span>
                    </button>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500">Width (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].width" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                    <span x-show="isTextish(overlays[selectedIdx])" class="text-[10px] text-gray-400">Text wraps & aligns inside this width</span>
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'image'">
                    <label class="text-xs font-medium text-gray-500">Height (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].height" @input="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'image'">
                    <label class="text-xs font-medium text-gray-500">Shape</label>
                    <select x-model="overlays[selectedIdx].shape" @change="syncToForm()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm px-2 py-1.5">
                        <option value="square">Square</option>
                        <option value="circle">Circle</option>
                    </select>
                </div>
            </div>
        </div>
    </template>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        Drag an overlay to position it. Click to select, then drag the blue corner handle to resize (photo size / text size). Coordinates saved relative to original image size.
    </p>
    <p class="text-xs text-gray-400 dark:text-gray-500">
        <strong>Prefer a text block</strong> for anything with words in it. Leave the background artwork blank where the words go and write the whole sentence here, variables included &mdash; that way the wording, its weight and its alignment are one thing, instead of a variable balanced on top of text painted into the picture.
    </p>
</div>

<style>
    /* Toolbar buttons for the text-block editor. Plain CSS rather than
       Tailwind classes: this partial is rendered inside the Filament panel,
       whose build does not scan resources/views/filament for utilities. */
    .rte-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.9rem;
        height: 1.9rem;
        padding: 0 .4rem;
        border-radius: .375rem;
        border: 1px solid rgb(209 213 219);
        background: #fff;
        font-size: .8rem;
        line-height: 1;
        color: rgb(55 65 81);
        cursor: pointer;
    }
    .rte-btn:hover { background: rgb(243 244 246); }
    .rte-select {
        height: 1.9rem;
        border-radius: .375rem;
        border: 1px solid rgb(209 213 219);
        background: #fff;
        font-size: .75rem;
        padding: 0 .4rem;
        color: rgb(55 65 81);
    }
    .dark .rte-btn, .dark .rte-select {
        background: rgb(31 41 55);
        border-color: rgb(75 85 99);
        color: rgb(229 231 235);
    }
    .dark .rte-btn:hover { background: rgb(55 65 81); }
</style>

<script>
function greetingCardEditor(initialOverlays, initialConfig) {
    return {
        // Stamp a stable _uid on every overlay up-front (before first render)
        // so the x-for :key is always unique — undefined keys collapse rows.
        overlays: (Array.isArray(initialOverlays) ? initialOverlays : []).map((o, i) => ({
            ...o,
            // Text overlays wrap+center within a width; backfill a default for
            // templates saved before widths existed.
            width: (o.type === 'text' && !o.width) ? 300 : o.width,
            // Templates saved before the bold toggle carry no flag. Normalise
            // to FALSE, not true: the renderer has always drawn them at normal
            // weight, so false is what they actually look like today — the old
            // always-bold preview was the thing that was wrong.
            bold: o.type === 'text' ? (o.bold ?? false) : o.bold,
            // Rich blocks saved before a property existed still need one, or
            // the bound <select>/<input> renders blank and a save writes the
            // blank back.
            align: o.type === 'rich_text' ? (o.align || 'center') : o.align,
            font_family: o.type === 'rich_text' ? (o.font_family || 'Noto Sans Gujarati') : o.font_family,
            // Image slots gained a shape (square|circle); default older
            // templates to square so the <select> shows a value.
            shape: o.type === 'image' ? (o.shape || 'square') : o.shape,
            _uid: 'ov_init_' + i,
        })),
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
        resizing: false,
        resizeIdx: null,
        resizeStartX: 0,
        resizeStartY: 0,
        resizeOrigW: 0,
        resizeOrigH: 0,
        resizeOrigFont: 0,
        _sendConfig: {
            send_via_email: initialConfig?.send_via_email ?? true,
            send_via_whatsapp: initialConfig?.send_via_whatsapp ?? true,
            show_on_thankyou: initialConfig?.show_on_thankyou ?? true,
        },

        _uidSeq: 0,

        // ── Rich text block state ────────────────────────────────────
        // Which overlay the contenteditable currently holds. Reloading its
        // innerHTML on every Alpine tick would move the caret to the start
        // mid-typing, so it is only rewritten when the selection changes.
        rteLoadedIdx: null,
        inlineColor: '#881337',
        // Families whose CSS has already been injected, so switching back
        // and forth doesn't add a <link> per change.
        loadedFonts: {},

        nextUid() {
            return 'ov_' + Date.now().toString(36) + '_' + (this._uidSeq++);
        },

        init() {
            // Give every overlay a STABLE unique id. The x-for keys on this
            // (not the array index) so add/delete/reorder update the correct
            // DOM node — keying on the index made deletes hit the wrong row.
            this.overlays.forEach((o) => {
                if (!o._uid) o._uid = this.nextUid();
                if (o.type === 'rich_text') this.loadPreviewFont(o.font_family);
            });
            document.addEventListener('mousemove', (e) => { this.onDrag(e); this.onResize(e); });
            document.addEventListener('mouseup', () => { this.stopDrag(); this.stopResize(); });
            document.addEventListener('touchmove', (e) => { this.onDrag(e); this.onResize(e); }, { passive: false });
            document.addEventListener('touchend', () => { this.stopDrag(); this.stopResize(); });
            // Recompute scale on window resize so drag math stays correct.
            window.addEventListener('resize', () => this.recomputeScale());
            this.$nextTick(() => {
                // If the template image is already cached, @load may have
                // fired before Alpine mounted — compute scale now.
                this.recomputeScale();
                this.syncToForm();
            });
        },

        recomputeScale() {
            let img = this.$refs.bgImage;
            if (img && img.complete && img.naturalWidth) {
                this.naturalW = img.naturalWidth;
                this.naturalH = img.naturalHeight;
                this.scale = img.clientWidth / this.naturalW;
            }
        },

        onBgLoad(event) {
            let img = event.target;
            this.naturalW = img.naturalWidth || 1200;
            this.naturalH = img.naturalHeight || 800;
            this.scale = img.clientWidth / this.naturalW;
        },

        addOverlay(fieldKey, type) {
            this.overlays.push({
                _uid: this.nextUid(),
                field_key: fieldKey,
                type: type,
                x: 50 + (this.overlays.length * 20),
                y: 50 + (this.overlays.length * 20),
                font_size: type === 'text' ? 32 : undefined,
                // Bold by default for NEW overlays: that is what the preview
                // has always shown, so it keeps freshly placed text looking
                // the way admins already expect.
                bold: type === 'text' ? true : undefined,
                color: type === 'text' ? '#881337' : undefined,
                width: type === 'image' ? 150 : 300,
                height: type === 'image' ? 150 : undefined,
                shape: type === 'image' ? 'square' : undefined,
            });
            this.selectedIdx = this.overlays.length - 1;
            this.syncToForm();
        },

        // A whole sentence, authored here rather than painted into the
        // artwork with a gap left in it. Seeded with a real example so a new
        // block shows what it is for instead of being an empty rectangle.
        addTextBlock() {
            const firstVar = @js($availableVars[0]['key'] ?? '_donor_name');
            // Braces are assembled rather than written literally: a literal
            // double-brace pair anywhere in this file is compiled by Blade as
            // an echo, comments inside a <script> block included.
            const token = (key) => '{' + '{ ' + key + ' }' + '}';
            this.overlays.push({
                _uid: this.nextUid(),
                type: 'rich_text',
                html: '<p>Jay Siyaram, <b>' + token(firstVar) + '</b></p>',
                x: 60 + (this.overlays.length * 20),
                y: 60 + (this.overlays.length * 20),
                width: Math.max(200, Math.round(this.naturalW * 0.7)),
                font_size: 32,
                font_family: 'Noto Sans Gujarati',
                color: '#881337',
                align: 'center',
                bold: false,
            });
            this.selectedIdx = this.overlays.length - 1;
            this.rteLoadedIdx = null;
            this.loadPreviewFont('Noto Sans Gujarati');
            this.syncToForm();
        },

        /** Both overlay kinds that draw words — used by the shared controls. */
        isTextish(overlay) {
            return overlay && (overlay.type === 'text' || overlay.type === 'rich_text');
        },

        /** Put the selected block's HTML into the editable div, once. */
        loadRte(idx) {
            const overlay = this.overlays[idx];
            if (!overlay || overlay.type !== 'rich_text') { this.rteLoadedIdx = null; return; }
            if (this.rteLoadedIdx === idx) return;
            const el = this.$refs.rte;
            if (!el) return;
            el.innerHTML = overlay.html || '';
            this.rteLoadedIdx = idx;
            this.loadPreviewFont(overlay.font_family);
        },

        onRteInput() {
            const overlay = this.overlays[this.selectedIdx];
            if (!overlay || overlay.type !== 'rich_text' || !this.$refs.rte) return;
            overlay.html = this.$refs.rte.innerHTML;
            this.syncToForm();
        },

        /**
         * styleWithCSS makes execCommand emit <span style="…"> rather than
         * the legacy <font> element — the renderer reads inline styles, so
         * without it colours would be silently dropped on the card.
         */
        fmt(command) {
            const el = this.$refs.rte;
            if (!el) return;
            el.focus();
            try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
            document.execCommand(command, false, null);
            this.onRteInput();
        },

        applyInlineColor() {
            const el = this.$refs.rte;
            if (!el) return;
            el.focus();
            try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
            document.execCommand('foreColor', false, this.inlineColor);
            this.onRteInput();
        },

        /**
         * execCommand has no px sizes — only the 1-7 legacy scale. Apply the
         * largest of those as a marker, then rewrite those elements to the
         * real px value. Crude, but it is the only cross-browser way to get
         * an exact size out of contenteditable, and the renderer needs px.
         */
        applyInlineSize(px) {
            if (!px) return;
            const el = this.$refs.rte;
            if (!el) return;
            el.focus();
            try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
            document.execCommand('fontSize', false, '7');
            el.querySelectorAll('font[size="7"]').forEach((node) => {
                const span = document.createElement('span');
                span.style.fontSize = px + 'px';
                span.innerHTML = node.innerHTML;
                node.replaceWith(span);
            });
            // Also catch the CSS-mode output some browsers produce.
            el.querySelectorAll('span[style*="xxx-large"], span[style*="x-large"]').forEach((node) => {
                node.style.fontSize = px + 'px';
            });
            this.onRteInput();
        },

        insertVariable(key) {
            const el = this.$refs.rte;
            if (!el) return;
            el.focus();
            // Assembled, not literal — see addTextBlock().
            document.execCommand('insertText', false, '{' + '{ ' + key + ' }' + '}');
            this.onRteInput();
        },

        /**
         * Pull the chosen family from Google so the CANVAS preview is set in
         * the same face the server will render with. Admin-only page, so an
         * external stylesheet here is fine.
         */
        loadPreviewFont(family) {
            if (!family || this.loadedFonts[family]) return;
            this.loadedFonts[family] = true;
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://fonts.googleapis.com/css2?family='
                + encodeURIComponent(family).replace(/%20/g, '+')
                + ':wght@400;700&display=swap';
            document.head.appendChild(link);
        },

        /** Variables shown as their sample values, so line length is honest. */
        richPreviewHtml(overlay) {
            return String(overlay.html || '').replace(
                /\{\{\s*([A-Za-z0-9_\-]+)\s*\}\}/g,
                (_, key) => this.getSampleText(key),
            );
        },

        richPreviewStyle(overlay) {
            const size = Math.max(8, (overlay.font_size || 32) * this.scale);
            return 'width:' + ((overlay.width || 300) * this.scale) + 'px;'
                + 'font-size:' + size + 'px;'
                + 'font-family:' + JSON.stringify(overlay.font_family || 'Noto Sans Gujarati') + ', serif;'
                + 'color:' + (overlay.color || '#333') + ';'
                + 'font-weight:' + (overlay.bold ? '700' : '400') + ';'
                + 'text-align:' + (overlay.align || 'center') + ';'
                + 'line-height:1.35; white-space:normal; overflow-wrap:break-word;'
                + 'text-shadow: 0 1px 3px rgba(0,0,0,0.25);';
        },

        removeOverlay(idx) {
            this.overlays.splice(idx, 1);
            this.selectedIdx = null;
            this.rteLoadedIdx = null;
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

        startResize(idx, event) {
            this.resizing = true;
            this.resizeIdx = idx;
            this.selectedIdx = idx;
            let pos = event.touches ? event.touches[0] : event;
            this.resizeStartX = pos.clientX;
            this.resizeStartY = pos.clientY;
            let o = this.overlays[idx];
            this.resizeOrigW = o.width || (o.type === 'image' ? 150 : 300);
            this.resizeOrigH = o.height || 150;
            this.resizeOrigFont = o.font_size || 24;
        },

        onResize(event) {
            if (!this.resizing || this.resizeIdx === null) return;
            if (event.cancelable) event.preventDefault();
            let pos = event.touches ? event.touches[0] : event;
            let dx = (pos.clientX - this.resizeStartX) / this.scale;
            let dy = (pos.clientY - this.resizeStartY) / this.scale;
            let o = this.overlays[this.resizeIdx];
            if (o.type === 'image') {
                o.width = Math.max(20, Math.round(this.resizeOrigW + dx));
                o.height = Math.max(20, Math.round(this.resizeOrigH + dy));
            } else {
                // Text: horizontal drag changes the wrap width, vertical drag
                // changes the font size.
                o.width = Math.max(40, Math.round(this.resizeOrigW + dx));
                o.font_size = Math.max(8, Math.round(this.resizeOrigFont + dy));
            }
        },

        stopResize() {
            if (this.resizing) {
                this.resizing = false;
                this.resizeIdx = null;
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
                '_date': '09/04/2026',
                '_temple_name': 'Shree Patadiya Hanumanji',
                '_seva_name': 'Sundarkand Path',
                '_booking_date': '15/08/2026',
                '_slot': '07:00 AM',
                '_sankalp': 'For family wellbeing',
            };
            return samples[key] || key;
        },

        syncToForm() {
            let config = {
                overlays: this.overlays.map(o => {
                    let c = { field_key: o.field_key, type: o.type, x: o.x, y: o.y };
                    // NOTE: this is a WHITELIST — a key not copied here is
                    // dropped on save. Any new overlay property must be added.
                    if (o.type === 'text') { c.font_size = o.font_size || 24; c.color = o.color || '#333'; c.width = o.width || 300; c.bold = !!o.bold; }
                    if (o.type === 'image') { c.width = o.width || 150; c.height = o.height || 150; c.shape = o.shape || 'square'; }
                    // A rich block has no single field_key — its variables
                    // live inside the html. Everything it needs to render has
                    // to be listed here; this map is a WHITELIST.
                    if (o.type === 'rich_text') {
                        delete c.field_key;
                        c.html = o.html || '';
                        c.width = o.width || 300;
                        c.font_size = o.font_size || 32;
                        c.font_family = o.font_family || 'Noto Sans Gujarati';
                        c.color = o.color || '#333';
                        c.align = o.align || 'center';
                        c.bold = !!o.bold;
                    }
                    return c;
                }),
                send_via_email: this._sendConfig.send_via_email,
                send_via_whatsapp: this._sendConfig.send_via_whatsapp,
                show_on_thankyou: this._sendConfig.show_on_thankyou,
            };

            // Push straight into THIS form component's state. $wire always
            // refers to the Livewire component this Alpine widget lives in
            // (the Edit/Create page), so there's no risk of hitting the
            // global-search widget like the old querySelector hack did.
            // The third arg `false` defers the update (no per-keystroke
            // network round-trip) — the value ships with the next request
            // (i.e. when the admin clicks Save).
            try {
                if (this.$wire) {
                    this.$wire.set('{{ $statePath }}', config, false);
                    return;
                }
            } catch (e) {}

            // Fallback: write to the Hidden input directly if $wire is
            // somehow unavailable.
            let jsonStr = JSON.stringify(config);
            let hiddenInput = null;
            document.querySelectorAll('input[type="hidden"]').forEach(el => {
                let wm = el.getAttribute('wire:model') || el.getAttribute('wire:model.defer') || '';
                if (wm.includes('greeting_card_config')) {
                    hiddenInput = el;
                }
            });
            if (hiddenInput) {
                hiddenInput.value = jsonStr;
                hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },
    };
}
</script>
