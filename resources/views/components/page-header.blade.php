@props([
    'title' => '',
    'subtitle' => null,
    'breadcrumb' => [],
])
{{--
    Canonical page-header banner. Use on listing/landing pages. Detail pages
    should use <x-breadcrumb> alone above their content.

    Slots:
      $slot   — optional content rendered after the title block
              (use for inline action rows, language switchers, filters, etc.)

    Example:
      <x-page-header
          :breadcrumb="[['label' => 'સેવા']]"
          title="સેવા અને પૂજા"
          subtitle="…" />
--}}
<section class="border-b"
         style="background: linear-gradient(180deg, #F4EAD5, #FBF5EA); border-color: rgba(122,30,30,0.10);">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        @if(!empty($breadcrumb))
            <x-breadcrumb :items="$breadcrumb" class="mb-4" />
        @endif

        {{-- Decode once before {{ }} re-escapes: some callers pass raw values
             (:title="$model->name"), others pass pre-escaped strings
             (title="{{ __('...') }}"). Decoding normalises both so an "&" in a
             title no longer double-encodes to "&amp;". Still XSS-safe — the
             final {{ }} escapes. --}}
        <h1 class="divine-heading text-3xl sm:text-4xl">{{ html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') }}</h1>

        @if($subtitle)
            <p class="mt-2 divine-subtext">{{ html_entity_decode($subtitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') }}</p>
        @endif

        {{ $slot ?? '' }}
    </div>
</section>
