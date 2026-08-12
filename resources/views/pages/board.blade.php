{{--
    Live donor display board — the screen in the temple hall.
    Rebuilt 2026-08-12 as a fixed two-column view.

    Layout is a single viewport with NO scrolling anywhere: nobody can touch
    this screen, so anything that needs scrolling to be seen is invisible.
    Everything is sized in vh/vw and the donor column is capped to what fits.

        ┌───────────────────────────────────────────────┐
        │  crest · trust name · headline                │
        ├──────────────────────┬────────────────────────┤
        │  rotating info card  │  તાજેતરનાં દાન         │
        │  (app QR, sevas,     │  ── list of recent ──  │
        │   darshan, vatika)   │  named offerings,      │
        │                      │  newest at the top     │
        └──────────────────────┴────────────────────────┘

    A new donation slides in as a card over the centre, holds, then flies up
    into position one of the donor column while that row expands in beneath
    it — so the gift visibly *joins* the list rather than replacing the screen.

    Load-bearing rules (not cosmetic):
      • NEVER letter-space Gujarati — matras and conjuncts break, and at this
        size it is the most visible typography error on the estate.
      • Never location.reload() on a fetch error, or temple wifi puts Chrome's
        offline dinosaur on a ten-foot screen until somebody notices.
      • Everything except new donations runs from memory, so an outage leaves a
        screen that is still moving and still dignified.
--}}
@php
    $appQrUrl = $universalStoreUrl ?: ($androidStoreUrl ?: $iosStoreUrl);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('common.temple_name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Gujarati:wght@600;700&family=Hind+Vadodara:wght@400;600;700&family=Noto+Serif+Devanagari:wght@600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --ink:       #120A05;
            --ink-2:     #1E1108;
            --panel:     rgba(255, 246, 230, 0.045);
            --panel-2:   rgba(255, 246, 230, 0.075);
            --parch:     #FFF6E6;
            --parch-dim: rgba(255, 246, 230, 0.60);
            --parch-faint: rgba(255, 246, 230, 0.34);
            --gold:      #E7B65A;
            --gold-soft: rgba(231, 182, 90, 0.28);
            --saffron:   #F0842B;
            --safe:      3.2vmin;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            height: 100%; width: 100%;
            overflow: hidden;                 /* nobody can scroll this screen */
            background: var(--ink);
            color: var(--parch);
            font-family: 'Hind Vadodara', 'Noto Serif Gujarati', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        /* Gujarati never gets tracked, and needs room for stacked matras. */
        body, body * { letter-spacing: normal !important; line-height: 1.45; }

        /* ── Living background ───────────────────────────────────────────
           Three slow, cheap layers: a warm breathing glow, a drifting
           mandala, and rising embers. No video, no canvas — this must run
           for days on a mini-PC without heating it up. */
        .bg { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
        .bg-glow {
            position: absolute; inset: -20%;
            background:
                radial-gradient(ellipse 50% 40% at 30% 30%, rgba(231,182,90,0.13), transparent 65%),
                radial-gradient(ellipse 55% 45% at 75% 70%, rgba(240,132,43,0.11), transparent 65%);
            animation: breathe 17s ease-in-out infinite alternate;
        }
        @keyframes breathe { from { transform: scale(1) translate(0,0); opacity: .85; }
                             to   { transform: scale(1.12) translate(1.5%, -1.5%); opacity: 1; } }

        .bg-mandala {
            position: absolute; top: 50%; left: 50%; width: 130vh; height: 130vh;
            margin: -65vh 0 0 -65vh; opacity: 0.05;
            background: radial-gradient(circle, transparent 30%, var(--gold) 30.4%, transparent 31%),
                        radial-gradient(circle, transparent 44%, var(--gold) 44.4%, transparent 45%),
                        radial-gradient(circle, transparent 58%, var(--gold) 58.4%, transparent 59%),
                        conic-gradient(from 0deg, transparent 0 4deg, rgba(231,182,90,.5) 4deg 5deg, transparent 5deg 15deg);
            border-radius: 50%;
            animation: spin 240s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .ember { position: absolute; bottom: -6vh; width: .5vh; height: .5vh; border-radius: 50%;
                 background: var(--gold); opacity: 0; animation: rise 15s linear infinite; }
        @keyframes rise {
            0%   { opacity: 0; transform: translateY(0) scale(.6); }
            12%  { opacity: .55; }
            85%  { opacity: .35; }
            100% { opacity: 0; transform: translateY(-108vh) scale(1.15); }
        }

        /* ── Frame ───────────────────────────────────────────────────────── */
        .frame {
            position: relative; z-index: 1;
            height: 100vh; padding: var(--safe);
            display: grid; grid-template-rows: auto 1fr; gap: 2vh;
            /* Slow whole-stage drift: burn-in insurance on a panel that shows
               the same layout for months. Invisible to the eye. */
            animation: drift 71s ease-in-out infinite;
        }
        @keyframes drift {
            0%,100% { transform: translate(0,0); } 33% { transform: translate(5px,-4px); }
            66% { transform: translate(-4px,5px); }
        }

        header { display: flex; align-items: center; gap: 1.6vw; }
        .crest { width: 8.4vh; height: 8.4vh; border-radius: 50%; object-fit: cover;
                 background: var(--parch); border: 2px solid var(--gold-soft); flex: none; }
        .titles { min-width: 0; }
        .t-name {
            font-family: 'Noto Serif Gujarati','Noto Serif Devanagari',serif;
            font-weight: 700; font-size: 3.1vh; color: var(--parch);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .t-sub { font-size: 1.9vh; color: var(--gold); margin-top: .3vh; font-weight: 600; }
        .t-head { font-size: 1.6vh; color: var(--parch-faint); margin-top: .2vh; }
        .clock { margin-left: auto; text-align: right; flex: none; }
        .clock-time { font-size: 3vh; font-weight: 700; color: var(--parch-dim); font-variant-numeric: tabular-nums; }
        .clock-date { font-size: 1.5vh; color: var(--parch-faint); }

        /* 60:40 — the information side carries images and a QR and needs the
   room; the donor column only ever holds a name, a tag and a figure. */
        .cols { display: grid; grid-template-columns: 60fr 40fr; gap: 1.6vw; min-height: 0; }

        .panel {
            background: var(--panel); border: 1px solid var(--gold-soft);
            border-radius: 2.2vh; padding: 2.4vh 2vw; min-height: 0;
            display: flex; flex-direction: column;
        }

        /* ── Left: rotating info cards ───────────────────────────────────── */
        .info { position: relative; overflow: hidden; gap: 1.6vh; }
        /* Rotating message on top, QR permanently below: a code that is only
           on screen a quarter of the time is a code nobody scans, and the
           text-only cards left the panel looking empty on their own. */
        .info-rotator { position: relative; flex: 1; min-height: 0; }
        .card { position: absolute; inset: 0; display: none;
                flex-direction: column; justify-content: center; align-items: center; text-align: center; }
        .card.is-on { display: flex; animation: cardIn 1100ms cubic-bezier(.16,1,.3,1) both; }
        @keyframes cardIn { from { opacity: 0; transform: translateY(2.4vh) scale(.985); } to { opacity: 1; transform: none; } }

        .card-eyebrow { font-size: 1.7vh; color: var(--saffron); font-weight: 700; margin-bottom: 1vh; }
        .card-title {
            font-family: 'Noto Serif Gujarati','Noto Serif Devanagari',serif;
            font-size: 3.4vh; font-weight: 700; color: var(--parch); margin-bottom: 1.6vh;
        }
        .card-body { font-size: 2vh; color: var(--parch-dim); max-width: 34vw; }

        .seva-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.4vh 1.2vw; margin-top: 2vh; width: 100%; }
        .seva {
            display: flex; align-items: center; gap: .8vw; font-size: 2.4vh;
            background: var(--panel-2); border: 1px solid var(--gold-soft);
            border-radius: 1.3vh; padding: 1.8vh 1.2vw; color: var(--parch);
        }
        .seva b { color: var(--gold); font-weight: 700; }

        .info-qr {
            flex: none; display: flex; align-items: center; gap: 1.6vw;
            border-top: 1px solid var(--gold-soft); padding-top: 1.6vh;
        }
        .qr-wrap {
            background: #FFF9EE; padding: .9vh; border-radius: 1.2vh; flex: none; order: 2;
            /* Sized HERE, not on the svg: the generated code carries an inline
               width:100%, and an inline style beats a class selector. */
            width: 16vh; height: 16vh;
        }
        .qr-wrap svg, .qr-wrap img { display: block; width: 100%; height: 100%; }
        .qr-text { text-align: left; min-width: 0; order: 1; flex: 1; }
        .qr-title {
            font-family: 'Noto Serif Gujarati','Noto Serif Devanagari',serif;
            font-size: 2.5vh; font-weight: 700; color: var(--parch);
        }
        .qr-cap { font-size: 1.7vh; color: var(--parch-dim); margin-top: .5vh; }
        .qr-feats { display: flex; flex-wrap: wrap; gap: .5vh .8vw; margin-top: 1vh; }
        .qr-feat { font-size: 1.5vh; color: var(--gold); background: var(--panel-2);
                   border: 1px solid var(--gold-soft); border-radius: .9vh; padding: .4vh .8vw; }

        /* Full-bleed photo card. object-fit: cover keeps any aspect ratio
           filling the frame — darshan photos arrive in all shapes. */
        .photo-card { position: absolute; inset: 0; border-radius: 1.4vh; overflow: hidden; }
        .photo-card img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo-veil {
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(10,6,3,.72) 0%, rgba(10,6,3,.06) 34%,
                        rgba(10,6,3,.20) 62%, rgba(10,6,3,.86) 100%);
        }
        .photo-top { position: absolute; top: 2vh; left: 2vh; right: 2vh; text-align: left; }
        .photo-bot { position: absolute; bottom: 2vh; left: 2vh; right: 2vh; text-align: left; }
        .photo-title {
            font-family: 'Noto Serif Gujarati','Noto Serif Devanagari',serif;
            font-size: 3vh; font-weight: 700; color: var(--parch);
            text-shadow: 0 1px 2vh rgba(0,0,0,.8);
        }
        .live-pill {
            display: inline-flex; align-items: center; gap: .6vw; font-size: 1.6vh; font-weight: 700;
            color: var(--parch); background: rgba(200,40,40,.85); border-radius: 3vh; padding: .5vh 1.1vw;
        }
        .live-dot { width: .9vh; height: .9vh; border-radius: 50%; background: #fff;
                    animation: pulse 2s ease-in-out infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .25; } }

        .anon-name {
            font-family: 'Noto Serif Gujarati',serif; font-size: 3.6vh;
            font-weight: 700; color: var(--gold); margin: .6vh 0;
        }

        /* ── Right: recent offerings ─────────────────────────────────────── */
        .donors { min-height: 0; }
        .donors-head {
            display: flex; align-items: baseline; justify-content: space-between;
            padding-bottom: 1.4vh; margin-bottom: .6vh; border-bottom: 1px solid var(--gold-soft); flex: none;
        }
        .donors-title {
            font-family: 'Noto Serif Gujarati','Noto Serif Devanagari',serif;
            font-size: 2.7vh; font-weight: 700; color: var(--gold);
        }
        .donors-count { font-size: 1.6vh; color: var(--parch-faint); }

        /* The list is clipped, never scrolled — rows past the fold simply do
           not render rather than hiding below an invisible scrollbar. */
        .donor-list { list-style: none; overflow: hidden; min-height: 0; flex: 1; }
        .donor-row {
            display: flex; align-items: center; gap: 1vw;
            padding: 1.35vh .4vw; border-bottom: 1px solid rgba(231,182,90,.10);
        }
        .donor-main { min-width: 0; flex: 1; }
        .donor-name {
            font-family: 'Noto Serif Gujarati','Noto Serif Devanagari',serif;
            font-size: 2.5vh; font-weight: 600; color: var(--parch);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .donor-tag { font-size: 1.55vh; color: var(--parch-faint); margin-top: .2vh;
                     white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .donor-tag.is-campaign { color: var(--saffron); }
        .donor-amt {
            font-size: 2.6vh; font-weight: 700; color: var(--gold); flex: none;
            font-variant-numeric: tabular-nums;
        }
        /* A newly-landed row grows into place as the flying card arrives. */
        .donor-row.is-new { animation: land 900ms cubic-bezier(.16,1,.3,1) both; }
        @keyframes land {
            0%   { max-height: 0; opacity: 0; transform: translateX(4vw); background: rgba(231,182,90,.22); }
            60%  { max-height: 12vh; opacity: 1; transform: none; }
            100% { max-height: 12vh; opacity: 1; background: transparent; }
        }

        .empty { display: flex; flex: 1; flex-direction: column; align-items: center; justify-content: center;
                 text-align: center; color: var(--parch-dim); font-size: 2.2vh; gap: 1.4vh; }

        /* ── The announcement ────────────────────────────────────────────── */
        .ann-layer { position: fixed; inset: 0; z-index: 5; display: none; pointer-events: none; }
        .ann-layer.is-on { display: block; }
        .ann-scrim { position: absolute; inset: 0; background: rgba(10,6,3,.55);
                     backdrop-filter: blur(2px); animation: fadeIn 500ms ease both; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .ann-card {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
            width: 62vw; padding: 5vh 4vw; text-align: center;
            background: linear-gradient(160deg, #2A1708 0%, #1A0E06 100%);
            border: 2px solid var(--gold); border-radius: 3vh;
            box-shadow: 0 3vh 9vh rgba(0,0,0,.72), 0 0 0 1px rgba(231,182,90,.18) inset;
        }
        .ann-card.is-in  { animation: annIn 800ms cubic-bezier(.16,1,.3,1) both; }
        /* Flies up-right INTO the top of the donor column, so the gift is seen
           to join the list rather than the screen simply cutting back. */
        .ann-card.is-out { animation: annFly 1000ms cubic-bezier(.6,0,.85,.35) both; }
        @keyframes annIn  { from { opacity: 0; transform: translate(-50%,-50%) scale(.84) translateY(4vh); }
                            to   { opacity: 1; transform: translate(-50%,-50%) scale(1); } }
        @keyframes annFly { from { opacity: 1; transform: translate(-50%,-50%) scale(1); }
                            to   { opacity: 0; transform: translate(18%,-118%) scale(.26); } }

        .ann-eyebrow { font-size: 2.4vh; font-weight: 700; color: var(--saffron); }
        .ann-name {
            font-family: 'Noto Serif Gujarati','Noto Serif Devanagari',serif; font-weight: 700;
            color: var(--parch); margin: 1.6vh 0 .6vh; line-height: 1.3;
            font-size: var(--ann-size, 7.6vh);
        }
        .ann-city { font-size: 2.3vh; color: var(--parch-dim); }
        .ann-amt { font-size: 11vh; font-weight: 700; color: var(--gold); margin-top: 1.4vh;
                   font-variant-numeric: tabular-nums; line-height: 1.15; }
        .ann-tag { font-size: 2.4vh; color: var(--saffron); margin-top: .8vh; }
        .ann-bless { font-family: 'Noto Serif Gujarati',serif; font-size: 3vh; color: var(--gold); margin-top: 2.4vh; }

        /* ── Status: ~1% of the screen, never a dialog ───────────────────── */
        .dot { position: fixed; right: 1vh; bottom: 1vh; width: .8vh; height: .8vh; border-radius: 50%;
               background: transparent; transition: background .6s ease; z-index: 6; }
        .dot.is-bad { background: rgba(231,182,90,.5); }
        .note { position: fixed; left: 1vh; bottom: 1vh; font-size: 1.1vh; color: rgba(255,246,230,.25); z-index: 6; }
        .demo { position: fixed; left: 1vh; top: 1vh; z-index: 7; background: var(--gold); color: var(--ink);
                font-weight: 700; font-size: 1.3vh; padding: .4vh 1vh; border-radius: .6vh; }
    </style>
</head>
<body>

<div class="bg">
    <div class="bg-glow"></div>
    <div class="bg-mandala"></div>
    @for($i = 0; $i < 9; $i++)
        <span class="ember" style="left: {{ 6 + $i * 10.5 }}%; animation-delay: {{ $i * 1.7 }}s; animation-duration: {{ 13 + ($i % 4) * 2.5 }}s;"></span>
    @endfor
</div>

<div class="frame">
    <header>
        <img class="crest" src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="">
        <div class="titles">
            <div class="t-name">{{ __('common.temple_name') }}</div>
            <div class="t-sub" data-s="trust_line"></div>
            <div class="t-head" id="headline"></div>
        </div>
        <div class="clock">
            <div class="clock-time" id="clock-time">—</div>
            <div class="clock-date" id="clock-date"></div>
        </div>
    </header>

    <div class="cols">
        {{-- LEFT — rotating information --}}
        <section class="panel info" id="info">
            <div class="info-rotator">
                <div class="card is-on" data-card>
                    <div class="card-eyebrow" data-s="seva_eyebrow"></div>
                    <div class="card-title" data-s="seva_title"></div>
                    <div class="seva-grid">
                        <div class="seva"><b>◈</b><span data-s="seva_dhwaja"></span></div>
                        <div class="seva"><b>◈</b><span data-s="seva_vastra"></span></div>
                        <div class="seva"><b>◈</b><span data-s="seva_annadaan"></span></div>
                        <div class="seva"><b>◈</b><span data-s="seva_shringar"></span></div>
                    </div>
                    <div class="card-body" style="margin-top:1.6vh" data-s="seva_body"></div>
                </div>

                <div class="card" data-card>
                    <div class="photo-card">
                        <img src="{{ asset('images/hanumanji-hero.jpg') }}" alt="">
                        <div class="photo-veil"></div>
                        <div class="photo-top">
                            <span class="card-eyebrow" data-s="vatika_eyebrow"></span>
                        </div>
                        <div class="photo-bot">
                            <div class="photo-title" data-s="vatika_title"></div>
                            <div class="card-body" style="max-width:none;text-align:left" data-s="vatika_body"></div>
                        </div>
                    </div>
                </div>

                <div class="card" data-card>
                    @if($darshanUrl)
                        <div class="photo-card">
                            <img src="{{ $darshanUrl }}" alt="">
                            <div class="photo-veil"></div>
                            <div class="photo-top">
                                <span class="live-pill"><span class="live-dot"></span><span data-s="darshan_live"></span></span>
                            </div>
                            <div class="photo-bot">
                                <div class="photo-title" data-s="darshan_title"></div>
                                <div class="card-body" style="max-width:none;text-align:left">{{ $darshanCaption ?: '' }}</div>
                            </div>
                        </div>
                    @else
                        <div class="card-eyebrow" data-s="darshan_eyebrow"></div>
                        <div class="card-title" data-s="darshan_title"></div>
                        <div class="card-body" data-s="darshan_body"></div>
                    @endif
                </div>

                {{-- Gupt Daan honour card: masked, shuffled, no time shown, so
                     a quiet gift is honoured without becoming traceable. --}}
                <div class="card" data-card id="card-anon" style="display:none">
                    <div class="card-eyebrow" data-s="anon_eyebrow"></div>
                    <div class="anon-name" id="anon-name"></div>
                    <div class="card-body" data-s="anon_body"></div>
                </div>
            </div>

            {{-- Permanent, always scannable. --}}
            <div class="info-qr">
                @if($appQr)
                    <div class="qr-wrap">{!! $appQr !!}</div>
                @elseif($appQrImage)
                    <div class="qr-wrap"><img src="{{ $appQrImage }}" alt=""></div>
                @endif
                <div class="qr-text">
                    <div class="qr-title" data-s="app_title"></div>
                    <div class="qr-cap" data-s="app_scan"></div>
                    <div class="qr-feats">
                        <span class="qr-feat" data-s="feat_darshan"></span>
                        <span class="qr-feat" data-s="feat_seva"></span>
                        <span class="qr-feat" data-s="feat_donate"></span>
                        <span class="qr-feat" data-s="feat_events"></span>
                    </div>
                </div>
            </div>
        </section>

        {{-- RIGHT — recent offerings --}}
        <section class="panel donors">
            <div class="donors-head">
                <div class="donors-title" data-s="recent_title"></div>
                <div class="donors-count" id="donors-count"></div>
            </div>
            <ul class="donor-list" id="donor-list"></ul>
            <div class="empty" id="donor-empty" style="display:none">
                <div data-s="first_line"></div>
            </div>
        </section>
    </div>
</div>

<div class="ann-layer" id="ann">
    <div class="ann-scrim"></div>
    <div class="ann-card" id="ann-card">
        <div class="ann-eyebrow" data-s="ann_eyebrow"></div>
        <div class="ann-name" id="ann-name"></div>
        <div class="ann-city" id="ann-city"></div>
        <div class="ann-amt" id="ann-amt"></div>
        <div class="ann-tag" id="ann-tag"></div>
        <div class="ann-bless" data-s="bless"></div>
    </div>
</div>

<div class="dot" id="dot"></div>
<div class="note" id="note"></div>
@if($demo)<div class="demo">DEMO</div>@endif

<script>
(function () {
    'use strict';

    var CFG = {
        feed:   @js(url('/api/v1/board/feed')),
        token:  @js($token),
        demo:   @js((bool) $demo),
        locale: @js($locale),
        pollMs: 2000, maxBackoff: 30000, fetchTimeout: 5000,
        catchUpMax: 3, degradedAfter: 60000, noteAfter: 600000,
        cardMs: 11000, maxRows: 15, listCap: 60
    };

    var S = {
        gu: {
            app_eyebrow:'મંદિરની એપ', app_title:'એપ ડાઉનલોડ કરો', app_scan:'સ્કૅન કરો — iPhone અને Android',
            seva_eyebrow:'સેવા', seva_title:'સેવા બુકિંગ',
            seva_dhwaja:'ધ્વજા સેવા', seva_vastra:'વસ્ત્ર સેવા', seva_annadaan:'અન્નદાન સેવા', seva_shringar:'શૃંગાર સેવા',
            trust_line:'સેવા ટ્રસ્ટ • અંતરજાળ', darshan_live:'દાદાનાં જીવંત દર્શન',
            seva_body:'એપ કે વેબસાઇટ પરથી સેવા બુક કરો',
            darshan_eyebrow:'દર્શન', darshan_title:'રોજ દર્શન', darshan_body:'દરરોજનાં દર્શન એપમાં જુઓ',
            vatika_eyebrow:'નિર્માણ', vatika_title:'શ્રી રામ વાટિકા', vatika_body:'શ્રી રામ વાટિકા નિર્માણમાં સહયોગ આપો',
            anon_eyebrow:'ગુપ્ત દાન', anon_body:'નામ વગર અર્પણ કરેલ સેવા',
            feat_darshan:'રોજ દર્શન', feat_seva:'સેવા બુકિંગ', feat_donate:'દાન', feat_events:'ઉત્સવ',
            recent_title:'તાજેતરનાં દાન', ann_eyebrow:'સેવા પ્રાપ્ત થઈ', bless:'જય હનુમાન',
            first_line:'આપનું દાન પ્રથમ હોઈ શકે', off:'જય શ્રી રામ', today:'આજે'
        },
        hi: {
            app_eyebrow:'मंदिर ऐप', app_title:'ऐप डाउनलोड करें', app_scan:'स्कैन करें — iPhone और Android',
            seva_eyebrow:'सेवा', seva_title:'सेवा बुकिंग',
            seva_dhwaja:'ध्वजा सेवा', seva_vastra:'वस्त्र सेवा', seva_annadaan:'अन्नदान सेवा', seva_shringar:'शृंगार सेवा',
            trust_line:'सेवा ट्रस्ट • अंतरजाल', darshan_live:'दादा के जीवंत दर्शन',
            seva_body:'ऐप या वेबसाइट से सेवा बुक करें',
            darshan_eyebrow:'दर्शन', darshan_title:'रोज़ दर्शन', darshan_body:'प्रतिदिन के दर्शन ऐप में देखें',
            vatika_eyebrow:'निर्माण', vatika_title:'श्री राम वाटिका', vatika_body:'श्री राम वाटिका निर्माण में सहयोग दें',
            anon_eyebrow:'गुप्त दान', anon_body:'बिना नाम अर्पित सेवा',
            feat_darshan:'रोज़ दर्शन', feat_seva:'सेवा बुकिंग', feat_donate:'दान', feat_events:'उत्सव',
            recent_title:'हाल के दान', ann_eyebrow:'सेवा प्राप्त हुई', bless:'जय हनुमान',
            first_line:'आपका दान पहला हो सकता है', off:'जय श्री राम', today:'आज'
        },
        en: {
            app_eyebrow:'Temple app', app_title:'Download the app', app_scan:'Scan — iPhone & Android',
            seva_eyebrow:'Seva', seva_title:'Seva booking',
            seva_dhwaja:'Dhwaja Seva', seva_vastra:'Vastra Seva', seva_annadaan:'Annadaan Seva', seva_shringar:'Shringar Seva',
            trust_line:'Seva Trust • Antarjal', darshan_live:'Live darshan',
            seva_body:'Book a seva from the app or website',
            darshan_eyebrow:'Darshan', darshan_title:'Daily Darshan', darshan_body:'See each day’s darshan in the app',
            vatika_eyebrow:'Construction', vatika_title:'Shree Ram Vatika', vatika_body:'Support the Shree Ram Vatika',
            anon_eyebrow:'Gupt Daan', anon_body:'Offered without a name',
            feat_darshan:'Daily Darshan', feat_seva:'Seva booking', feat_donate:'Donate', feat_events:'Events',
            recent_title:'Recent offerings', ann_eyebrow:'Offering received', bless:'Jay Hanuman',
            first_line:'Yours could be the first offering', off:'Jay Shri Ram', today:'Today'
        }
    };
    var T = S[CFG.locale] || S.gu;

    var $ = function (id) { return document.getElementById(id); };
    document.querySelectorAll('[data-s]').forEach(function (n) {
        n.textContent = T[n.getAttribute('data-s')] || '';
    });

    var st = {
        lastSeq: null, seen: new Set(), queue: [], rows: [], anon: [],
        announcing: false, enabled: true, backoff: CFG.pollMs, lastOk: Date.now(),
        card: 0, annSeconds: 8, headline: ''
    };

    var KEY = 'donorBoard.lastSeq';
    try { var v = localStorage.getItem(KEY); if (v) st.lastSeq = parseInt(v, 10); } catch (e) {}
    function seq(n) { st.lastSeq = n; try { localStorage.setItem(KEY, String(n)); } catch (e) {} }

    function money(n) {
        if (n === null || n === undefined) return '';
        try { return '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }); }
        catch (e) { return '₹' + Math.round(n); }
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }
    // A campaign gift shows the campaign; otherwise the donation type.
    function tagOf(e) { return e.campaign_title || e.donation_type || ''; }
    function isCampaign(e) { return !!e.campaign_title; }

    /* ── Clock ─────────────────────────────────────────────────────────── */
    function tick() {
        var d = new Date();
        var h = d.getHours(), m = d.getMinutes();
        var ap = h >= 12 ? 'PM' : 'AM'; var hh = h % 12 || 12;
        $('clock-time').textContent = hh + ':' + (m < 10 ? '0' : '') + m + ' ' + ap;
        try {
            $('clock-date').textContent = d.toLocaleDateString(
                CFG.locale === 'en' ? 'en-IN' : (CFG.locale + '-IN'),
                { day: 'numeric', month: 'long' });
        } catch (e) { $('clock-date').textContent = ''; }
    }
    tick(); setInterval(tick, 20000);

    /* ── Donor column ──────────────────────────────────────────────────── */
    function rowHtml(e, isNew) {
        return '<li class="donor-row' + (isNew ? ' is-new' : '') + '">' +
            '<div class="donor-main">' +
              '<div class="donor-name">' + esc(e.name) + '</div>' +
              (tagOf(e) ? '<div class="donor-tag' + (isCampaign(e) ? ' is-campaign' : '') + '">' + esc(tagOf(e)) + '</div>' : '') +
            '</div>' +
            (e.amount === null || e.amount === undefined ? '' :
              '<div class="donor-amt">' + esc(money(e.amount)) + '</div>') +
            '</li>';
    }

    function renderList(newTop) {
        var list = $('donor-list');
        if (!st.rows.length) {
            list.innerHTML = ''; $('donor-empty').style.display = 'flex';
            $('donors-count').textContent = ''; return;
        }
        $('donor-empty').style.display = 'none';
        // Only render what fits — the list is clipped, never scrolled.
        var html = '';
        for (var i = 0; i < st.rows.length && i < CFG.maxRows; i++) {
            html += rowHtml(st.rows[i], newTop && i === 0);
        }
        list.innerHTML = html;
        $('donors-count').textContent = T.today + ' · ' + st.rows.length;
    }

    function pushRow(e) {
        st.rows.unshift(e);
        if (st.rows.length > CFG.listCap) st.rows.length = CFG.listCap;
    }

    /* ── Left rotation ─────────────────────────────────────────────────── */
    function rotate() {
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-card]'))
            .filter(function (c) { return c.id !== 'card-anon' || st.anon.length; });
        cards.forEach(function (c) { c.classList.remove('is-on'); c.style.display = 'none'; });
        var card = cards[st.card % cards.length];
        st.card++;
        if (card.id === 'card-anon') {
            // Shuffled server-side and shown without a time, so a Gupt Daan
            // gift can never be tied to whoever just left the counter.
            $('anon-name').textContent = st.anon[Math.floor(Math.random() * st.anon.length)].name;
        }
        card.style.display = 'flex';
        card.classList.add('is-on');
    }
    rotate(); setInterval(rotate, CFG.cardMs);

    /* ── Announcement ──────────────────────────────────────────────────── */
    function annSize(name) {
        var n = (name || '').length;
        if (n <= 14) return '7.6vh'; if (n <= 22) return '6vh';
        if (n <= 32) return '5vh';   return '4.4vh';
    }

    function announceNext() {
        if (st.announcing) return;
        var e = st.queue.shift();
        if (!e) return;
        st.announcing = true;

        $('ann-name').textContent = e.name || '';
        $('ann-name').style.setProperty('--ann-size', annSize(e.name));
        $('ann-city').textContent = e.city || '';
        $('ann-amt').textContent = money(e.amount);
        $('ann-tag').textContent = tagOf(e);

        var card = $('ann-card');
        card.classList.remove('is-out'); card.classList.remove('is-in');
        void card.offsetWidth;
        card.classList.add('is-in');
        $('ann').classList.add('is-on');

        // Backlog shortens the hold rather than falling behind the counter.
        var hold = st.annSeconds * 1000;
        if (st.queue.length > 4) hold = Math.min(hold, 4500);
        if (st.queue.length > 10) hold = Math.min(hold, 2800);

        setTimeout(function () {
            // Fly the card into the top of the donor column, and let the new
            // row expand in underneath it as it lands.
            card.classList.remove('is-in');
            void card.offsetWidth;
            card.classList.add('is-out');
            pushRow(e);
            renderList(true);

            setTimeout(function () {
                $('ann').classList.remove('is-on');
                st.announcing = false;
                if (st.queue.length) announceNext();
            }, 1000);
        }, hold);
    }

    /* ── Poll ──────────────────────────────────────────────────────────── */
    function apply(d) {
        st.enabled = d.enabled !== false;
        if (d.config) {
            st.annSeconds = d.config.announce_seconds || 8;
            st.headline = d.config.headline || '';
            $('headline').textContent = st.headline;
        }
        if (Array.isArray(d.anonymous_recent)) st.anon = d.anonymous_recent;

        if (!st.enabled) {
            st.rows = []; renderList(false);
            $('donor-empty').style.display = 'flex';
            $('donor-empty').textContent = T.off;
            return;
        }

        var kill = new Set(Array.isArray(d.suppressed_ids) ? d.suppressed_ids : []);
        if (kill.size) {
            st.queue = st.queue.filter(function (e) { return !kill.has(e.seq); });
            var before = st.rows.length;
            st.rows = st.rows.filter(function (e) { return !kill.has(e.seq); });
            if (st.rows.length !== before) renderList(false);
        }

        // The server's ordered list is the source of truth for the column;
        // locally-added rows only bridge the gap until the next poll.
        if (Array.isArray(d.recent) && d.recent.length && !st.announcing) {
            var fresh = d.recent.filter(function (e) { return !kill.has(e.seq); });
            if (fresh.length !== st.rows.length || (fresh[0] && st.rows[0] && fresh[0].seq !== st.rows[0].seq)) {
                st.rows = fresh; renderList(false);
            }
        }

        var latest = d.latest_seq || 0;

        // Cold start: seed and announce nothing, or a refresh replays the day.
        if (st.lastSeq === null) { seq(latest); renderList(false); return; }

        var entries = Array.isArray(d.entries) ? d.entries : [];
        if (!entries.length) return;

        var stale = (latest - st.lastSeq) > CFG.catchUpMax;
        entries.forEach(function (e) {
            if (st.seen.has(e.seq)) return;
            st.seen.add(e.seq);
            if (!stale) st.queue.push(e);
            if (e.seq > st.lastSeq) seq(e.seq);
        });

        if (stale) { seq(latest); renderList(false); return; }

        if (st.seen.size > 800) st.seen = new Set(st.queue.map(function (e) { return e.seq; }));
        announceNext();
    }

    function poll() {
        var url = CFG.feed + '?token=' + encodeURIComponent(CFG.token) +
                  (st.lastSeq === null ? '' : '&since=' + st.lastSeq);
        var ctrl = new AbortController();
        var to = setTimeout(function () { ctrl.abort(); }, CFG.fetchTimeout);

        fetch(url, { signal: ctrl.signal, cache: 'no-store', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (d) {
                clearTimeout(to); st.lastOk = Date.now(); st.backoff = CFG.pollMs;
                $('dot').classList.remove('is-bad'); $('note').textContent = '';
                apply(d);
            })
            .catch(function () {
                clearTimeout(to);
                // Degrade; never navigate. The panels keep animating.
                st.backoff = Math.min(st.backoff * 2, CFG.maxBackoff);
                var down = Date.now() - st.lastOk;
                if (down > CFG.degradedAfter) $('dot').classList.add('is-bad');
                if (down > CFG.noteAfter) $('note').textContent = 'offline ' + Math.round(down / 60000) + 'm';
            })
            .then(function () { setTimeout(poll, st.backoff); });
    }

    if (CFG.demo) {
        var names = ['રમેશભાઈ પટેલ','Kiran Shah','सुनीता देवी','જયેશ ઠક્કર','Meera Joshi'];
        var tags  = ['સામાન્ય દાન','અન્નદાન','શ્રી રામ વાટિકા',null,'ધ્વજા સેવા'];
        var amts  = [1100, 5100, 11000, 21000, 251000];
        var i = 0;
        setInterval(function () {
            i++;
            st.queue.push({ seq: -i, name: names[i % names.length], city: 'Gandhidham',
                amount: amts[i % amts.length], anonymous: false,
                campaign_title: i % 3 === 0 ? 'શ્રી રામ વાટિકા' : null,
                donation_type: tags[i % tags.length] });
            announceNext();
        }, 9000);
    }

    function wake() { if ('wakeLock' in navigator) navigator.wakeLock.request('screen').catch(function () {}); }
    wake();
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') wake();
    });

    // Nightly refresh for a page that runs for weeks — never mid-announcement.
    setInterval(function () {
        var d = new Date();
        if (d.getHours() === 4 && d.getMinutes() === 0 && !st.announcing) location.reload();
    }, 60000);

    renderList(false);
    poll();
})();
</script>
</body>
</html>
