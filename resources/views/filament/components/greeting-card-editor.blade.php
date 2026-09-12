@php
    $record = $record ?? null;
    $extraFields = $record?->extra_fields ?? [];
    $config = $record?->greeting_card_config ?? [];
    $overlays = $config['overlays'] ?? [];
    $statePath = $statePath ?? 'data.greeting_card_config';

    // One background per language. The bare column is Gujarati/default and
    // hi/en fall back to it — the same rule the renderer applies — so the
    // canvas can show the exact artwork a Hindi or English card will use.
    $templatePath = $record?->greeting_card_template;
    $templateUrls = [
        'gu' => $templatePath ? image_url($templatePath) : null,
        'hi' => ($record?->getAttribute('greeting_card_template_hi') ?: $templatePath) ? image_url($record?->getAttribute('greeting_card_template_hi') ?: $templatePath) : null,
        'en' => ($record?->getAttribute('greeting_card_template_en') ?: $templatePath) ? image_url($record?->getAttribute('greeting_card_template_en') ?: $templatePath) : null,
    ];
    $templateUrl = $templateUrls['gu'];

    // The server-side "Preview" needs to know which record's backgrounds to
    // draw on. Null on a Create page (nothing saved yet), which hides the
    // button — the canvas is empty there too.
    $previewOwner = $record ? \App\Services\CardPreviewService::aliasFor($record) : null;
    $previewId = $record?->getKey();

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

    $varLabels = collect($availableVars)->pluck('label', 'key')->all();
@endphp

<div wire:ignore x-data="greetingCardEditor(@js($overlays), @js($config), @js($templateUrls))" x-init="init()" class="gce space-y-4">

    {{-- Language + preview bar. The language chosen here drives EVERYTHING
         below: which background is shown, which wording a text block shows,
         which sample values the variables take, and which language the
         server-side Preview renders. --}}
    <div class="gce-bar">
        <div class="gce-bar-group">
            <span class="gce-label">Language:</span>
            <template x-for="l in langs" :key="l.code">
                <button type="button" class="gce-tab" :class="lang === l.code ? 'is-active' : ''" @click="setLang(l.code)" x-text="l.title"></button>
            </template>
        </div>
        @if($previewOwner && $previewId)
            <div class="gce-bar-group">
                <button type="button" class="gce-btn gce-btn-primary" @click="openPreview()" title="Renders the card on the server, exactly as a devotee receives it, with sample values — opens in a new tab, nothing is saved">
                    <svg style="width:14px;height:14px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <span>Preview <span x-text="currentLang().short"></span> card</span>
                </button>
                <span class="gce-hint">Real render, new tab, not saved anywhere.</span>
            </div>
        @endif
    </div>

    {{-- Canvas Area --}}
    <div class="relative border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-800" style="min-height: 300px;">
        @if($templateUrl)
            <img :src="templateUrls[lang] || templateUrls.gu" alt="Template" class="w-full h-auto block" x-ref="bgImage"
                 @load="onBgLoad($event)">
            <div x-show="lang !== 'gu' && !hasOwnTemplate(lang)" class="gce-badge" style="position:absolute; top:.5rem; left:.5rem;">
                No <span x-text="currentLang().title"></span> background — using the Gujarati one
            </div>
        @else
            <div class="flex items-center justify-center h-64 text-gray-400 dark:text-gray-500">
                <div class="text-center">
                    <svg class="w-12 h-12 mx-auto mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p>Upload a background template image above and save first.</p>
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
                    {{-- Drawn the way the server draws it: the value centred
                         inside the width box, top edge at y, in the same
                         family pango will be asked for, no shadow, the font's
                         own line height. Anything prettier here is a lie. --}}
                    <div :style="textPreviewStyle(overlay)" x-text="getSampleText(overlay.field_key)"></div>
                </template>
                <template x-if="overlay.type === 'rich_text'">
                    {{-- WYSIWYG preview in the selected language. Variables
                         show their sample values so the admin sees the real
                         line length, which is the whole reason this block
                         type exists. --}}
                    <div :style="richPreviewStyle(overlay)" x-html="richPreviewHtml(overlay)"></div>
                </template>
                <template x-if="overlay.type === 'image'">
                    <div :style="'width:' + ((overlay.width || 100) * scale) + 'px; height:' + ((overlay.height || 100) * scale) + 'px;'"
                         class="bg-white/30 border-2 border-dashed border-gray-400 flex items-center justify-center backdrop-blur-sm"
                         :class="overlay.shape === 'circle' ? 'rounded-full' : 'rounded-lg'">
                        <span class="text-xs text-gray-600 font-medium" x-text="labelFor(overlay.field_key)"></span>
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
    <div class="gce-bar" style="justify-content:flex-start;">
        <span class="gce-label">Add:</span>
        {{-- The preferred way to put words on a card (2026-08-29): one block
             holding the whole sentence, variables included, instead of a
             single-variable overlay parked on wording painted into the
             artwork. Listed first because it is what an admin should reach
             for. --}}
        <button type="button" @click="addTextBlock()" class="gce-chip gce-chip-primary">+ Text block</button>
        <span class="gce-sep"></span>
        @foreach($availableVars as $v)
            <button type="button"
                @click="addOverlay('{{ $v['key'] }}', '{{ $v['type'] === 'image' ? 'image' : 'text' }}')"
                class="gce-chip {{ $v['auto'] ? 'gce-chip-blue' : 'gce-chip-green' }}">
                + {{ $v['label'] }}
            </button>
        @endforeach
    </div>

    {{-- Selected Overlay Properties --}}
    <template x-if="selectedIdx !== null && overlays[selectedIdx]">
        <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 space-y-3" x-transition>
            <div class="flex items-center justify-between">
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300">
                    Editing: <span class="text-primary-600" x-text="editingTitle(overlays[selectedIdx])"></span>
                </h4>
                <button type="button" @click="removeOverlay(selectedIdx)" class="gce-btn gce-btn-danger">
                    <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete
                </button>
            </div>

            {{-- ── Rich text block editor ──────────────────────────────
                 Everything the sentence needs, in one place: the words in
                 each language, the variables inside them, and the typography.
                 Bold/italic/underline/colour/size apply to the SELECTION;
                 family, base size, alignment and block colour apply to the
                 whole block, in every language. --}}
            <template x-if="overlays[selectedIdx]?.type === 'rich_text'">
                <div class="space-y-3">
                    {{-- Language tabs — the same three the rest of the admin
                         uses. Switching here also switches the canvas. --}}
                    <div class="gce-tabs">
                        <template x-for="l in langs" :key="'rte-' + l.code">
                            <button type="button" class="gce-tab" :class="lang === l.code ? 'is-active' : ''" @click="setLang(l.code)">
                                <span x-text="l.title"></span>
                                <span x-show="l.code !== 'gu' && !hasOwnHtml(overlays[selectedIdx], l.code)" class="gce-tab-dot" title="No text in this language yet — the Gujarati text is used"></span>
                            </button>
                        </template>
                    </div>

                    <div class="gce-toolbar">
                        <button type="button" @click="fmt('bold')" title="Bold (selection)" class="rte-btn" style="font-weight:800;">B</button>
                        <button type="button" @click="fmt('italic')" title="Italic (selection)" class="rte-btn" style="font-style:italic;">I</button>
                        <button type="button" @click="fmt('underline')" title="Underline (selection)" class="rte-btn" style="text-decoration:underline;">U</button>
                        <span class="gce-sep"></span>
                        <label class="rte-btn cursor-pointer" title="Colour for the selected words">
                            <span style="text-decoration:underline; text-decoration-thickness:3px;" :style="'text-decoration-color:' + inlineColor">A</span>
                            <input type="color" x-model="inlineColor" @input="applyInlineColor()" class="sr-only">
                        </label>
                        <select @change="applyInlineSize($event.target.value); $event.target.value = ''" class="rte-select" title="Size for the selected words (px on the card)">
                            <option value="">Size…</option>
                            <template x-for="px in [16, 20, 24, 28, 32, 36, 40, 48, 56, 64, 72, 80, 96]" :key="px">
                                <option :value="px" x-text="px + ' px'"></option>
                            </template>
                        </select>
                        <span class="gce-sep"></span>
                        {{-- Alignment is a property of the BLOCK (pango lays
                             the whole box out one way), so these are not
                             selection commands. --}}
                        <template x-for="a in aligns" :key="a.value">
                            <button type="button" class="rte-btn" :class="(overlays[selectedIdx].align || 'center') === a.value ? 'is-active' : ''"
                                :title="a.title" @click="overlays[selectedIdx].align = a.value; syncToForm()">
                                <svg style="width:14px;height:14px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <template x-if="a.value === 'left'"><g><path d="M4 6h16M4 12h10M4 18h14"/></g></template>
                                    <template x-if="a.value === 'center'"><g><path d="M4 6h16M7 12h10M5 18h14"/></g></template>
                                    <template x-if="a.value === 'right'"><g><path d="M4 6h16M10 12h10M6 18h14"/></g></template>
                                    <template x-if="a.value === 'justify'"><g><path d="M4 6h16M4 12h16M4 18h16"/></g></template>
                                </svg>
                            </button>
                        </template>
                        <span class="gce-sep"></span>
                        <button type="button" @click="fmt('removeFormat')" title="Clear formatting on the selection" class="rte-btn">Clear</button>
                    </div>

                    {{-- Shown at the card's REAL pixel size, in the chosen
                         family: 32 px here is 32 px on the finished card. --}}
                    <div contenteditable="true"
                         x-ref="rte"
                         x-effect="loadRte(selectedIdx, lang)"
                         @input="onRteInput()"
                         @blur="onRteInput()"
                         :style="rteStyle(overlays[selectedIdx])"
                         :placeholder="lang === 'gu' ? 'Type the card wording…' : 'Type the ' + currentLang().title + ' wording — blank means the Gujarati text is used'"
                         class="gce-rte"></div>
                    <p x-show="lang !== 'gu' && !hasOwnHtml(overlays[selectedIdx], lang)" class="gce-hint">
                        No <span x-text="currentLang().title"></span> text yet — cards in this language will carry the Gujarati wording until you type a translation here.
                    </p>

                    <div class="gce-bar" style="justify-content:flex-start;">
                        <span class="gce-label">Insert:</span>
                        @foreach($availableVars as $v)
                            @if(($v['type'] ?? 'text') !== 'image')
                                <button type="button" @click="insertVariable('{{ $v['key'] }}')" class="gce-chip gce-chip-green">{{ $v['label'] }}</button>
                            @endif
                        @endforeach
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="col-span-2">
                            <label class="gce-field-label">Font (Google Fonts) — shared by all languages</label>
                            <select x-model="overlays[selectedIdx].font_family" @change="loadPreviewFont(overlays[selectedIdx].font_family); syncToForm()" class="gce-input">
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
                            <label class="gce-field-label">Base size (px)</label>
                            <input type="number" min="8" x-model.number="overlays[selectedIdx].font_size" @input="syncToForm()" class="gce-input">
                        </div>
                        <div>
                            <label class="gce-field-label">Alignment</label>
                            <select x-model="overlays[selectedIdx].align" @change="syncToForm()" class="gce-input">
                                <template x-for="a in aligns" :key="'sel-' + a.value">
                                    <option :value="a.value" x-text="a.title"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </template>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <label class="gce-field-label">X (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].x" @input="syncToForm()" class="gce-input">
                </div>
                <div>
                    <label class="gce-field-label">Y (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].y" @input="syncToForm()" class="gce-input">
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'text'">
                    <label class="gce-field-label">Font Size (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].font_size" @input="syncToForm()" class="gce-input">
                </div>
                <div x-show="isTextish(overlays[selectedIdx])">
                    <label class="gce-field-label">Color</label>
                    <input type="color" x-model="overlays[selectedIdx].color" @input="syncToForm()" class="gce-input" style="height:2.25rem; padding:2px; cursor:pointer;">
                </div>
                <div x-show="isTextish(overlays[selectedIdx])">
                    <label class="gce-field-label">Weight</label>
                    <button type="button"
                        @click="overlays[selectedIdx].bold = !overlays[selectedIdx].bold; syncToForm()"
                        class="gce-input gce-toggle" :class="overlays[selectedIdx].bold ? 'is-active' : ''">
                        <span style="font-weight:800;">B</span>
                        <span x-text="overlays[selectedIdx].bold ? 'Bold' : 'Normal'"></span>
                    </button>
                </div>
                <div>
                    <label class="gce-field-label">Width (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].width" @input="syncToForm()" class="gce-input">
                    <span x-show="isTextish(overlays[selectedIdx])" class="gce-hint">Text wraps &amp; aligns inside this width</span>
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'image'">
                    <label class="gce-field-label">Height (px)</label>
                    <input type="number" x-model.number="overlays[selectedIdx].height" @input="syncToForm()" class="gce-input">
                </div>
                <div x-show="overlays[selectedIdx]?.type === 'image'">
                    <label class="gce-field-label">Shape</label>
                    <select x-model="overlays[selectedIdx].shape" @change="syncToForm()" class="gce-input">
                        <option value="square">Square</option>
                        <option value="circle">Circle</option>
                    </select>
                </div>
            </div>
        </div>
    </template>

    <p class="gce-hint">
        Drag an overlay to position it. Click to select, then drag the blue corner handle to resize (photo size / text size). Coordinates are saved in the background image's own pixels, so what you see here is where it lands on the card.
    </p>
    <p class="gce-hint">
        <strong>Prefer a text block</strong> for anything with words in it. Leave the background artwork blank where the words go and write the whole sentence here, in each language, variables included &mdash; that way the wording, its weight and its alignment are one thing, instead of a variable balanced on top of text painted into the picture. Use <strong>Preview</strong> before saving: it is the real card, rendered on the server.
    </p>
</div>

<style>
    /* Plain CSS rather than Tailwind utilities: this partial is rendered
       inside the Filament panel, whose build does not scan
       resources/views/filament — so any utility not already in Filament's
       own bundle silently does nothing (that is how the "Insert" chips
       ended up white-on-white, 2026-09-12). */
    .gce-bar { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.5rem .75rem; }
    .gce-bar-group { display:inline-flex; flex-wrap:wrap; align-items:center; gap:.375rem; }
    .gce-label { font-size:.7rem; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:rgb(107 114 128); }
    .gce-sep { display:inline-block; width:1px; height:1rem; margin:0 .25rem; background:rgb(209 213 219); }
    .dark .gce-sep { background:rgb(75 85 99); }
    .gce-hint { font-size:.72rem; color:rgb(107 114 128); }
    .dark .gce-hint { color:rgb(156 163 175); }
    .gce-field-label { display:block; font-size:.72rem; font-weight:500; color:rgb(107 114 128); margin-bottom:.15rem; }
    .dark .gce-field-label { color:rgb(156 163 175); }

    .gce-input {
        display:block; width:100%; height:2.25rem; padding:0 .5rem; font-size:.875rem;
        border-radius:.5rem; border:1px solid rgb(209 213 219); background:#fff; color:rgb(31 41 55);
    }
    .dark .gce-input { background:rgb(31 41 55); border-color:rgb(75 85 99); color:rgb(229 231 235); }
    .gce-toggle { display:inline-flex; align-items:center; justify-content:center; gap:.375rem; cursor:pointer; }
    .gce-toggle.is-active { background:#C45F12; border-color:#C45F12; color:#fff; font-weight:700; }

    .gce-btn {
        display:inline-flex; align-items:center; gap:.375rem; height:2rem; padding:0 .75rem; border-radius:.5rem;
        font-size:.78rem; font-weight:600; line-height:1; border:1px solid transparent; cursor:pointer;
    }
    .gce-btn-primary { background:#C45F12; color:#fff; }
    .gce-btn-primary:hover { background:#9C480B; }
    .gce-btn-danger { background:#dc2626; color:#fff; }
    .gce-btn-danger:hover { background:#b91c1c; }

    .gce-chip {
        display:inline-flex; align-items:center; height:1.75rem; padding:0 .625rem; border-radius:.5rem;
        font-size:.75rem; font-weight:500; line-height:1; border:1px solid; cursor:pointer; white-space:nowrap;
    }
    .gce-chip-blue { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .gce-chip-blue:hover { background:#dbeafe; }
    .gce-chip-green { background:#ecfdf5; border-color:#a7f3d0; color:#047857; }
    .gce-chip-green:hover { background:#d1fae5; }
    .gce-chip-primary { background:#FDF3E8; border-color:#F4C994; color:#9C480B; font-weight:600; }
    .gce-chip-primary:hover { background:#FAE1C3; }
    .dark .gce-chip-blue { background:rgba(30,58,138,.25); border-color:#1e40af; color:#93c5fd; }
    .dark .gce-chip-green { background:rgba(6,78,59,.25); border-color:#065f46; color:#6ee7b7; }
    .dark .gce-chip-primary { background:rgba(156,72,11,.25); border-color:#9C480B; color:#F4C994; }

    .gce-tabs { display:flex; gap:.25rem; border-bottom:1px solid rgb(229 231 235); padding-bottom:.25rem; }
    .dark .gce-tabs { border-color:rgb(55 65 81); }
    .gce-tab {
        display:inline-flex; align-items:center; gap:.375rem; height:1.9rem; padding:0 .75rem; border-radius:.5rem;
        font-size:.8rem; font-weight:500; border:1px solid rgb(209 213 219); background:#fff; color:rgb(55 65 81); cursor:pointer;
    }
    .gce-tab:hover { background:rgb(243 244 246); }
    .gce-tab.is-active { background:#C45F12; border-color:#C45F12; color:#fff; font-weight:600; }
    .dark .gce-tab { background:rgb(31 41 55); border-color:rgb(75 85 99); color:rgb(229 231 235); }
    .dark .gce-tab:hover { background:rgb(55 65 81); }
    .dark .gce-tab.is-active { background:#C45F12; border-color:#C45F12; color:#fff; }
    .gce-tab-dot { width:.4rem; height:.4rem; border-radius:9999px; background:#f59e0b; display:inline-block; }

    .gce-badge {
        font-size:.7rem; font-weight:600; padding:.2rem .5rem; border-radius:.375rem;
        background:rgba(17,24,39,.75); color:#fff; pointer-events:none;
    }

    .gce-toolbar {
        display:flex; flex-wrap:wrap; align-items:center; gap:.375rem; padding:.375rem; border-radius:.5rem;
        border:1px solid rgb(229 231 235); background:#fff;
    }
    .dark .gce-toolbar { background:rgb(31 41 55); border-color:rgb(55 65 81); }

    .rte-btn {
        display:inline-flex; align-items:center; justify-content:center; min-width:1.9rem; height:1.9rem; padding:0 .4rem;
        border-radius:.375rem; border:1px solid rgb(209 213 219); background:#fff; font-size:.8rem; line-height:1; color:rgb(55 65 81); cursor:pointer;
    }
    .rte-btn:hover { background:rgb(243 244 246); }
    .rte-btn.is-active { background:#C45F12; border-color:#C45F12; color:#fff; }
    .rte-select { height:1.9rem; border-radius:.375rem; border:1px solid rgb(209 213 219); background:#fff; font-size:.75rem; padding:0 .4rem; color:rgb(55 65 81); }
    .dark .rte-btn, .dark .rte-select { background:rgb(31 41 55); border-color:rgb(75 85 99); color:rgb(229 231 235); }
    .dark .rte-btn:hover { background:rgb(55 65 81); }
    .dark .rte-btn.is-active { background:#C45F12; border-color:#C45F12; color:#fff; }

    .gce-rte {
        min-height:110px; width:100%; padding:.5rem .75rem; border-radius:.5rem; outline:none;
        border:1px solid rgb(209 213 219); background:#fff; color:rgb(31 41 55); white-space:pre-wrap; overflow-wrap:break-word;
        line-height:normal;
    }
    .gce-rte:focus { box-shadow:0 0 0 2px rgba(196,95,18,.35); }
    .gce-rte:empty::before { content:attr(placeholder); color:rgb(156 163 175); font-size:.875rem; font-family:ui-sans-serif, system-ui, sans-serif; }
    .dark .gce-rte { background:rgb(31 41 55); border-color:rgb(75 85 99); color:rgb(229 231 235); }
</style>

<script>
function greetingCardEditor(initialOverlays, initialConfig, templateUrls) {
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
            // blank back. `html` is the Gujarati/default wording; the other
            // two languages were added 2026-09-12 and default to empty, which
            // the renderer treats as "use the Gujarati text".
            align: o.type === 'rich_text' ? (o.align || 'center') : o.align,
            font_family: o.type === 'rich_text' ? (o.font_family || 'Noto Sans Gujarati') : o.font_family,
            html: o.type === 'rich_text' ? (o.html || '') : o.html,
            html_hi: o.type === 'rich_text' ? (o.html_hi || '') : o.html_hi,
            html_en: o.type === 'rich_text' ? (o.html_en || '') : o.html_en,
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

        // ── Language ─────────────────────────────────────────────────
        lang: 'gu',
        langs: [
            { code: 'gu', title: 'ગુજરાતી', short: 'ગુ' },
            { code: 'hi', title: 'हिन्दी', short: 'हि' },
            { code: 'en', title: 'English', short: 'En' },
        ],
        templateUrls: templateUrls || {},
        aligns: [
            { value: 'left', title: 'Left' },
            { value: 'center', title: 'Center' },
            { value: 'right', title: 'Right' },
            { value: 'justify', title: 'Justify' },
        ],

        // ── Rich text block state ────────────────────────────────────
        // Which overlay + language the contenteditable currently holds.
        // Reloading its innerHTML on every Alpine tick would move the caret
        // to the start mid-typing, so it is only rewritten when the
        // selection or the language changes.
        rteLoadedKey: null,
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
            // The faces pango uses for single-variable overlays, so the
            // canvas measures Gujarati/Hindi text in the same font the card
            // will. (Latin goes through DejaVu Sans, which Google does not
            // host; Verdana is its metric twin and is used as the stand-in.)
            this.loadPreviewFont('Noto Sans Gujarati');
            this.loadPreviewFont('Noto Sans Devanagari');
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

        currentLang() {
            return this.langs.find((l) => l.code === this.lang) || this.langs[0];
        },

        setLang(code) {
            // Flush any pending edit under the OLD language before the
            // editable is reloaded with the new one.
            this.onRteInput();
            this.lang = code;
        },

        hasOwnTemplate(code) {
            return !!this.templateUrls[code] && this.templateUrls[code] !== this.templateUrls.gu;
        },

        htmlKey(code) {
            return code === 'gu' ? 'html' : 'html_' + code;
        },

        /** Does the block have its own wording in this language? */
        hasOwnHtml(overlay, code) {
            if (!overlay) return false;
            const html = overlay[this.htmlKey(code)] || '';
            return html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() !== '';
        },

        /** The wording the RENDERER would use for this language — own text, else Gujarati. */
        htmlForLang(overlay, code) {
            return this.hasOwnHtml(overlay, code) ? overlay[this.htmlKey(code)] : (overlay.html || '');
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
                html: '<p>જય સિયારામ, <b>' + token(firstVar) + '</b></p>',
                html_hi: '',
                html_en: '',
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
            this.rteLoadedKey = null;
            this.loadPreviewFont('Noto Sans Gujarati');
            this.syncToForm();
        },

        /** Both overlay kinds that draw words — used by the shared controls. */
        isTextish(overlay) {
            return overlay && (overlay.type === 'text' || overlay.type === 'rich_text');
        },

        editingTitle(overlay) {
            if (!overlay) return '';
            if (overlay.type === 'rich_text') return 'Text block';
            return this.labelFor(overlay.field_key) + (overlay.type === 'image' ? ' (photo)' : '');
        },

        labelFor(key) {
            const labels = @js($varLabels);
            return labels[key] || key;
        },

        /** Put the selected block's wording (in the current language) into the editable div, once. */
        loadRte(idx, lang) {
            const overlay = this.overlays[idx];
            if (!overlay || overlay.type !== 'rich_text') { this.rteLoadedKey = null; return; }
            const key = idx + ':' + lang;
            if (this.rteLoadedKey === key) return;
            const el = this.$refs.rte;
            if (!el) return;
            el.innerHTML = overlay[this.htmlKey(lang)] || '';
            this.rteLoadedKey = key;
            this.loadPreviewFont(overlay.font_family);
        },

        onRteInput() {
            const overlay = this.overlays[this.selectedIdx];
            if (!overlay || overlay.type !== 'rich_text' || !this.$refs.rte) return;
            if (this.rteLoadedKey !== this.selectedIdx + ':' + this.lang) return;
            overlay[this.htmlKey(this.lang)] = this.$refs.rte.innerHTML;
            this.syncToForm();
        },

        /**
         * styleWithCSS makes execCommand emit <span style="…"> rather than
         * the legacy <font>/<b> elements — the renderer reads both, but
         * inline styles are what it sees most reliably.
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
         *
         * Any font-size already inside the selection is stripped first:
         * otherwise a second size applied over a first one nested a smaller
         * span inside a bigger one, the inner won, and the size "did not
         * change" (2026-09-12).
         */
        applyInlineSize(px) {
            if (!px) return;
            const el = this.$refs.rte;
            if (!el) return;
            el.focus();
            try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
            document.execCommand('fontSize', false, '7');
            const retag = (node) => {
                const span = document.createElement('span');
                span.style.fontSize = px + 'px';
                span.innerHTML = node.innerHTML;
                span.querySelectorAll('[style*="font-size"]').forEach((inner) => {
                    inner.style.fontSize = '';
                    if (!inner.getAttribute('style')) inner.removeAttribute('style');
                    if (inner.tagName === 'SPAN' && !inner.attributes.length) {
                        inner.replaceWith(...inner.childNodes);
                    }
                });
                span.querySelectorAll('font[size]').forEach((inner) => inner.replaceWith(...inner.childNodes));
                node.replaceWith(span);
            };
            el.querySelectorAll('font[size="7"]').forEach(retag);
            // Also catch the CSS-mode output some browsers produce.
            el.querySelectorAll('span[style*="xxx-large"], span[style*="x-large"]').forEach(retag);
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

        /** Same tolerance as CardRichText::TOKEN_PATTERN — contenteditable turns spaces into &nbsp;. */
        tokenPattern() {
            return /\{\{(?:\s|&nbsp;| )*([A-Za-z0-9_\-]+)(?:\s|&nbsp;| )*\}\}/g;
        },

        /**
         * Variables shown as their sample values, so line length is honest —
         * and every inline px size scaled by the canvas scale, so a 48 px
         * span looks 48 CARD pixels here rather than 48 screen pixels. The
         * unscaled version made text look bigger on the canvas than on the
         * card whenever the canvas was narrower than the image (2026-09-12).
         */
        richPreviewHtml(overlay) {
            const html = this.htmlForLang(overlay, this.lang);
            const scale = this.scale;
            return String(html || '')
                .replace(this.tokenPattern(), (_, key) => this.getSampleText(key))
                .replace(/font-size\s*:\s*([0-9.]+)px/gi, (_, n) => 'font-size:' + (parseFloat(n) * scale) + 'px');
        },

        richPreviewStyle(overlay) {
            const size = Math.max(4, (overlay.font_size || 32) * this.scale);
            return 'width:' + ((overlay.width || 300) * this.scale) + 'px;'
                + 'font-size:' + size + 'px;'
                + 'font-family:' + JSON.stringify(overlay.font_family || 'Noto Sans Gujarati') + ', serif;'
                + 'color:' + (overlay.color || '#333') + ';'
                + 'font-weight:' + (overlay.bold ? '700' : '400') + ';'
                + 'text-align:' + (overlay.align || 'center') + ';'
                // The font's own line height, which is what pango uses too.
                + 'line-height:normal; white-space:pre-wrap; overflow-wrap:break-word; word-break:break-word;';
        },

        /** Single-variable overlay: centred in its width box, in pango's family for the script. */
        textPreviewStyle(overlay) {
            const sample = this.getSampleText(overlay.field_key);
            return 'width:' + ((overlay.width || 300) * this.scale) + 'px;'
                + 'font-size:' + Math.max(4, (overlay.font_size || 24) * this.scale) + 'px;'
                + 'font-family:' + this.familyForText(sample) + ';'
                + 'color:' + (overlay.color || '#333') + ';'
                + 'font-weight:' + (overlay.bold ? '700' : '400') + ';'
                + 'text-align:center; line-height:normal; white-space:normal; overflow-wrap:break-word; word-break:break-word;';
        },

        /** Mirrors CardOverlayPainter::familyForText. */
        familyForText(text) {
            if (/[઀-૿]/.test(text)) return '"Noto Sans Gujarati", sans-serif';
            if (/[ऀ-ॿ]/.test(text)) return '"Noto Sans Devanagari", sans-serif';
            return '"DejaVu Sans", Verdana, sans-serif';
        },

        /** The contenteditable: real card pixels, real family, block colour and weight. */
        rteStyle(overlay) {
            if (!overlay) return '';
            return 'font-family:' + JSON.stringify(overlay.font_family || 'Noto Sans Gujarati') + ', serif;'
                + 'font-size:' + Math.max(12, overlay.font_size || 32) + 'px;'
                + 'font-weight:' + (overlay.bold ? '700' : '400') + ';'
                + 'text-align:' + (overlay.align || 'center') + ';';
        },

        removeOverlay(idx) {
            this.overlays.splice(idx, 1);
            this.selectedIdx = null;
            this.rteLoadedKey = null;
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

        /**
         * Sample values in the selected language. These are the SAME strings
         * CardPreviewService uses, so the canvas and the server preview agree
         * — and they are long-ish Gujarati/Hindi names on purpose, because
         * "does a real name still fit" is what the admin is checking.
         */
        getSampleText(key) {
            const byLang = {
                gu: { '_donor_name': 'રમેશભાઈ મગનભાઈ પટેલ', '_seva_name': 'સુંદરકાંડ પાઠ', '_campaign_title': 'મંદિર જીર્ણોદ્ધાર', '_sub_cause': 'અન્નદાન', '_caption': 'મંગળા આરતી દર્શન', '_slot': 'પૂર્ણ દિવસ' },
                hi: { '_donor_name': 'रमेशभाई मगनभाई पटेल', '_seva_name': 'सुंदरकांड पाठ', '_campaign_title': 'मंदिर जीर्णोद्धार', '_sub_cause': 'अन्नदान', '_caption': 'मंगला आरती दर्शन', '_slot': 'पूरा दिन' },
                en: { '_donor_name': 'Rameshbhai Maganbhai Patel', '_seva_name': 'Sundarkand Path', '_campaign_title': 'Temple Renovation', '_sub_cause': 'Annadaan', '_caption': 'Mangala Aarti Darshan', '_slot': 'Full Day' },
            };
            const common = {
                '_amount': '₹5,100.00',
                '_date': @js(now()->setTimezone('Asia/Kolkata')->format('d/m/Y')),
                '_booking_date': @js(now()->setTimezone('Asia/Kolkata')->addDays(7)->format('d/m/Y')),
                '_temple_name': @js(\App\Models\SystemSetting::getLocalized('trust_name', 'gu', 'Shree Patadiya Hanumanji Seva Trust')),
            };
            const templeNames = {
                gu: @js(\App\Models\SystemSetting::getLocalized('trust_name', 'gu', 'Shree Patadiya Hanumanji Seva Trust')),
                hi: @js(\App\Models\SystemSetting::getLocalized('trust_name', 'hi', 'Shree Patadiya Hanumanji Seva Trust')),
                en: @js(\App\Models\SystemSetting::getLocalized('trust_name', 'en', 'Shree Patadiya Hanumanji Seva Trust')),
            };
            if (key === '_temple_name') return templeNames[this.lang] || common._temple_name;
            const local = byLang[this.lang] || byLang.gu;
            if (local[key] !== undefined) return local[key];
            if (common[key] !== undefined) return common[key];
            // A custom extra field: show its label so the admin can tell
            // which slot is which — the same thing the server preview does.
            return this.labelFor(key);
        },

        currentConfig() {
            return {
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
                        c.html_hi = o.html_hi || '';
                        c.html_en = o.html_en || '';
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
        },

        /**
         * Server-side preview of the layout AS IT IS NOW (unsaved) in the
         * selected language. A real <form target="_blank"> POST, so the PNG
         * opens in a new tab like any image and nothing is written to R2 —
         * see App\Http\Controllers\Admin\CardPreviewController.
         */
        openPreview() {
            this.onRteInput();
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = @js(route('admin.card-preview'));
            form.target = '_blank';
            form.style.display = 'none';
            const fields = {
                _token: @js(csrf_token()),
                owner: @js($previewOwner),
                id: @js((string) $previewId),
                locale: this.lang,
                config: JSON.stringify(this.currentConfig()),
            };
            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value ?? '';
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            form.remove();
        },

        syncToForm() {
            let config = this.currentConfig();

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
