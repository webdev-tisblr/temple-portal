{{--
    Live donor display board — the screen in the temple hall (2026-08-13).

    Deliberately a standalone HTML document with NO layout, no @vite, no site
    chrome: a kiosk screen wants no header, footer, install banner, popup or
    cookie notice, and it must not break because an unrelated layout partial
    changed. Everything it needs is inline, so the page has exactly one network
    dependency after first paint — the feed.

    Design rules that are load-bearing rather than cosmetic:

      • NEVER letter-space Gujarati. Matras and conjuncts break visibly, and at
        this size it would be the most obvious typography error on the estate.
        letter-spacing:normal is set explicitly so nothing can inherit in.
      • Never location.reload() on a fetch failure. That puts Chrome's offline
        dinosaur on a ten-foot screen until a human notices. Failures degrade;
        they never navigate.
      • The attract loop runs entirely from data already in memory, so an
        outage leaves a screen that is still moving and still dignified.
      • Sizes in vw/vh with a 5% safe-area inset, because TVs and projectors
        overscan and will crop a flush-edged layout.
--}}
@php
    // Locale is the BOARD's setting, never the visitor cookie — a staff member
    // switching language on their phone must not change the hall screen.
    $dir = 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('common.temple_name') }}</title>

    {{-- Self-hosted with a Google Fonts fallback. A failed CDN fetch on temple
         wifi renders Gujarati as tofu boxes ten feet tall, so local files win
         when present; the remote link is the safety net until they are added. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Gujarati:wght@600;700&family=Hind+Vadodara:wght@400;600;700&family=Noto+Serif+Devanagari:wght@600;700&display=swap" rel="stylesheet">

    <style>
        /* ── Tokens ─────────────────────────────────────────────────────── */
        :root {
            --ink:        #140B06;
            --ink-2:      #241408;
            --parch:      #FFF6E6;
            --parch-dim:  rgba(255, 246, 230, 0.62);
            --gold:       #E0AE4C;
            --gold-dim:   rgba(224, 174, 76, 0.30);
            --saffron:    #E8751A;
            --safe:       5vmin;          /* overscan inset */
            --announce-in: 900ms;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            height: 100%;
            overflow: hidden;
            background: var(--ink);
            color: var(--parch);
            font-family: 'Hind Vadodara', 'Noto Serif Gujarati', sans-serif;
            /* Gujarati: never tracked. */
            letter-spacing: normal;
            -webkit-font-smoothing: antialiased;
        }

        /* Every text node on this page inherits normal tracking and roomy
           leading — Gujarati stacks matras above and below the baseline and
           the tight leading that flatters Latin display type clips them. */
        body, body * {
            letter-spacing: normal !important;
            line-height: 1.5;
        }

        .stage {
            position: fixed;
            inset: 0;
            padding: var(--safe);
            display: grid;
            place-items: center;
            /* Whole-stage pixel shift on a slow cycle — cheap, invisible,
               and the difference between a panel that survives a year of
               daily use and one with a ghosted logo burned into it. */
            animation: drift 61s ease-in-out infinite;
        }
        @keyframes drift {
            0%, 100% { transform: translate(0, 0); }
            25%      { transform: translate(6px, -5px); }
            50%      { transform: translate(-5px, 6px); }
            75%      { transform: translate(5px, 4px); }
        }

        .bg-glow {
            position: fixed; inset: 0; pointer-events: none;
            background:
                radial-gradient(ellipse 60% 50% at 50% 42%, rgba(224,174,76,0.12), transparent 70%),
                radial-gradient(ellipse 80% 60% at 50% 100%, rgba(232,117,26,0.10), transparent 70%);
        }

        .scene { display: none; width: 100%; text-align: center; }
        .scene.is-active { display: block; }

        /* ── Announcement ───────────────────────────────────────────────── */
        .eyebrow {
            font-size: 2.2vw; font-weight: 600; color: var(--gold);
            opacity: 0.9; margin-bottom: 1.4vh;
        }
        .donor-name {
            font-family: 'Noto Serif Gujarati', 'Noto Serif Devanagari', serif;
            font-weight: 700;
            font-size: 7vw;
            line-height: 1.35;
            color: var(--parch);
            text-wrap: balance;
            /* Long names step down rather than overflowing; JS narrows this
               further via --name-size when a name is genuinely long. */
            font-size: var(--name-size, 7vw);
        }
        .donor-city {
            font-size: 2.4vw; color: var(--parch-dim); margin-top: 0.8vh;
        }
        .donor-amount {
            font-family: 'Hind Vadodara', sans-serif;
            font-weight: 700;
            font-size: 11vw;
            line-height: 1.2;
            color: var(--gold);
            margin-top: 2.2vh;
            /* Latin digits, Indian grouping — far more legible at 10 metres
               than Gujarati numerals, and consistent with every receipt. */
            font-variant-numeric: tabular-nums;
        }
        .donor-meta {
            font-size: 2vw; color: var(--parch-dim); margin-top: 1.4vh;
        }
        .blessing {
            font-family: 'Noto Serif Gujarati', serif;
            font-size: 3vw; color: var(--saffron); margin-top: 3vh; font-weight: 600;
        }

        .announce-anim { animation: rise var(--announce-in) cubic-bezier(.16,1,.3,1) both; }
        @keyframes rise {
            from { opacity: 0; transform: translateY(3vh) scale(0.97); }
            to   { opacity: 1; transform: none; }
        }

        /* ── Attract / honour roll ──────────────────────────────────────── */
        .crest { width: 13vh; height: 13vh; border-radius: 50%; object-fit: cover;
                 border: 2px solid var(--gold-dim); background: var(--parch); }
        .temple-name {
            font-family: 'Noto Serif Gujarati', 'Noto Serif Devanagari', serif;
            font-size: 3.4vw; font-weight: 700; margin-top: 2vh; color: var(--parch);
        }
        .headline { font-size: 2.2vw; color: var(--gold); margin-top: 1vh; }

        .roll { margin-top: 4vh; display: grid; gap: 1.6vh; justify-items: center; }
        .roll-title { font-size: 1.9vw; color: var(--gold); opacity: 0.85; }
        .roll-row {
            display: flex; align-items: baseline; gap: 1.6vw;
            font-size: 3vw;
        }
        .roll-name { font-family: 'Noto Serif Gujarati', serif; font-weight: 600; }
        .roll-amount { color: var(--gold); font-variant-numeric: tabular-nums; }
        .roll-city { font-size: 1.7vw; color: var(--parch-dim); }

        .fade { animation: fade 900ms ease both; }
        @keyframes fade { from { opacity: 0; } to { opacity: 1; } }

        /* ── Status dot: ~1% of screen, never a modal ───────────────────── */
        .status {
            position: fixed; bottom: calc(var(--safe) / 2); right: calc(var(--safe) / 2);
            width: 0.8vh; height: 0.8vh; border-radius: 50%;
            background: transparent; transition: background 600ms ease;
        }
        .status.is-degraded { background: rgba(224, 174, 76, 0.55); }
        .status-note {
            position: fixed; bottom: calc(var(--safe) / 2); left: calc(var(--safe) / 2);
            font-size: 1.1vh; color: rgba(255,246,230,0.28); display: none;
        }
        .status-note.is-shown { display: block; }

        .demo-badge {
            position: fixed; top: calc(var(--safe) / 2); left: calc(var(--safe) / 2);
            font-size: 1.4vh; letter-spacing: normal; color: var(--ink);
            background: var(--gold); padding: 0.4vh 1vh; border-radius: 0.5vh;
            font-weight: 700;
        }
    </style>
</head>
<body>

<div class="bg-glow"></div>

<div class="stage" id="stage">

    {{-- BOOT: dignified from the first frame. Never a spinner, never
         "Loading…" — this is what the hall sees while the first poll runs. --}}
    <section class="scene is-active" id="scene-boot">
        <img class="crest" src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="">
        <div class="temple-name">{{ __('common.temple_name') }}</div>
    </section>

    {{-- ATTRACT: honour roll + identity. Runs purely from client memory. --}}
    <section class="scene" id="scene-attract">
        <img class="crest" src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="">
        <div class="temple-name">{{ __('common.temple_name') }}</div>
        <div class="headline" id="attract-headline"></div>
        <div class="roll" id="attract-roll"></div>
    </section>

    {{-- ANNOUNCEMENT --}}
    <section class="scene" id="scene-announce">
        <div id="announce-inner">
            <div class="eyebrow" id="ann-eyebrow"></div>
            <div class="donor-name" id="ann-name"></div>
            <div class="donor-city" id="ann-city"></div>
            <div class="donor-amount" id="ann-amount"></div>
            <div class="donor-meta" id="ann-meta"></div>
            <div class="blessing" id="ann-blessing"></div>
        </div>
    </section>

    {{-- EMPTY DAY / BOARD OFF --}}
    <section class="scene" id="scene-quiet">
        <img class="crest" src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="">
        <div class="temple-name">{{ __('common.temple_name') }}</div>
        <div class="headline" id="quiet-line"></div>
    </section>
</div>

<div class="status" id="status"></div>
<div class="status-note" id="status-note"></div>
@if($demo)<div class="demo-badge">DEMO</div>@endif

<script>
(function () {
    'use strict';

    var CFG = {
        feed:      @js(url('/api/v1/board/feed')),
        token:     @js($token),
        demo:      @js((bool) $demo),
        pollMs:    @js($pollMs),
        locale:    @js($locale),
        maxBackoff: 30000,
        fetchTimeout: 5000,
        // A gap larger than this, or older than this, is history — fold it
        // into the roll instead of replaying takeovers at the wall.
        catchUpMaxEntries: 3,
        catchUpMaxAgeMs: 5 * 60 * 1000,
        degradedAfterMs: 60000,
        staffNoteAfterMs: 10 * 60 * 1000,
        attractCardMs: 9000,
        maxEntries: 200
    };

    var STRINGS = {
        gu: { eyebrow: 'સેવા પ્રાપ્ત થઈ', blessing: 'જય હનુમાન', roll: 'આજના દાતાઓ',
              first: 'આપનું દાન પ્રથમ હોઈ શકે', off: 'જય શ્રી રામ' },
        hi: { eyebrow: 'सेवा प्राप्त हुई', blessing: 'जय हनुमान', roll: 'आज के दाता',
              first: 'आपका दान पहला हो सकता है', off: 'जय श्री राम' },
        en: { eyebrow: 'Offering received', blessing: 'Jay Hanuman', roll: 'Today’s donors',
              first: 'Yours could be the first offering', off: 'Jay Shri Ram' }
    };
    var T = STRINGS[CFG.locale] || STRINGS.gu;

    var el = function (id) { return document.getElementById(id); };

    var state = {
        lastSeq: null,           // null = cold start
        seen: new Set(),
        queue: [],
        roll: [],
        headline: '',
        announcing: false,
        enabled: true,
        backoff: CFG.pollMs,
        lastOkAt: Date.now(),
        attractIdx: 0,
        announceSeconds: 8
    };

    /* ── Cursor persistence ────────────────────────────────────────────
       localStorage is what makes a refresh not replay the day. A kiosk
       profile must therefore be persistent (not incognito). If storage is
       unavailable we simply stay in cold-start mode, which is safe. */
    var STORE_KEY = 'donorBoard.lastSeq';
    try {
        var saved = window.localStorage.getItem(STORE_KEY);
        if (saved !== null && saved !== '') { state.lastSeq = parseInt(saved, 10); }
    } catch (e) { /* storage blocked — cold start every time, still correct */ }

    function persistSeq(seq) {
        state.lastSeq = seq;
        try { window.localStorage.setItem(STORE_KEY, String(seq)); } catch (e) {}
    }

    /* ── Formatting ───────────────────────────────────────────────────── */
    function money(n) {
        try { return '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }); }
        catch (e) { return '₹' + Math.round(n); }
    }

    // Long names step down in size, then wrap, rather than overflowing the
    // safe area. Floor is still readable from the back of the hall.
    function nameSize(name) {
        var n = (name || '').length;
        if (n <= 14) return '7vw';
        if (n <= 22) return '5.6vw';
        if (n <= 32) return '4.8vw';
        return '4.5vw';
    }

    /* ── Scenes ───────────────────────────────────────────────────────── */
    var scenes = ['boot', 'attract', 'announce', 'quiet'];
    function show(name) {
        scenes.forEach(function (s) {
            el('scene-' + s).classList.toggle('is-active', s === name);
        });
    }

    function renderAttract() {
        el('attract-headline').textContent = state.headline || '';
        var roll = el('attract-roll');

        if (!state.roll.length) { show('quiet'); el('quiet-line').textContent = T.first; return; }

        // Three at a time, cycling — a window, never the whole day.
        var page = [];
        for (var i = 0; i < 3 && i < state.roll.length; i++) {
            page.push(state.roll[(state.attractIdx + i) % state.roll.length]);
        }
        state.attractIdx = (state.attractIdx + 3) % Math.max(state.roll.length, 1);

        var html = '<div class="roll-title">' + escapeHtml(T.roll) + '</div>';
        page.forEach(function (r) {
            html += '<div class="roll-row">' +
                '<span class="roll-name">' + escapeHtml(r.name) + '</span>' +
                (r.amount !== null && r.amount !== undefined
                    ? '<span class="roll-amount">' + escapeHtml(money(r.amount)) + '</span>' : '') +
                (r.city ? '<span class="roll-city">' + escapeHtml(r.city) + '</span>' : '') +
                '</div>';
        });
        roll.innerHTML = html;
        roll.classList.remove('fade');
        void roll.offsetWidth;
        roll.classList.add('fade');
        show('attract');
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function announceNext() {
        if (state.announcing) return;
        var item = state.queue.shift();
        if (!item) { renderAttract(); return; }

        state.announcing = true;

        el('ann-eyebrow').textContent = T.eyebrow;
        el('ann-name').textContent = item.name || '';
        el('ann-name').style.setProperty('--name-size', nameSize(item.name));
        el('ann-city').textContent = item.city || '';
        el('ann-amount').textContent = (item.amount === null || item.amount === undefined)
            ? '' : money(item.amount);
        el('ann-meta').textContent = item.campaign_title || item.donation_type || '';
        el('ann-blessing').textContent = T.blessing;

        var inner = el('announce-inner');
        inner.classList.remove('announce-anim');
        void inner.offsetWidth;
        inner.classList.add('announce-anim');
        show('announce');

        // A backlog shortens each takeover rather than falling minutes behind
        // the counter; it never drops anyone.
        var hold = state.announceSeconds * 1000;
        if (state.queue.length > 5) hold = Math.min(hold, 4000);
        if (state.queue.length > 12) hold = Math.min(hold, 2500);

        window.setTimeout(function () {
            state.announcing = false;
            if (state.queue.length) { announceNext(); } else { renderAttract(); }
        }, hold);
    }

    /* ── Polling ──────────────────────────────────────────────────────── */
    function apply(data) {
        state.enabled = data.enabled !== false;
        if (data.config) {
            state.announceSeconds = data.config.announce_seconds || 8;
            state.headline = data.config.headline || '';
        }
        if (Array.isArray(data.recent)) {
            state.roll = data.recent;
        }

        if (!state.enabled) { show('quiet'); el('quiet-line').textContent = T.off; return; }

        // A takedown must also pull an entry that is already on air.
        if (Array.isArray(data.suppressed_ids) && data.suppressed_ids.length) {
            var kill = new Set(data.suppressed_ids);
            state.queue = state.queue.filter(function (e) { return !kill.has(e.seq); });
        }

        var latest = data.latest_seq || 0;

        // COLD START: seed the cursor, announce nothing. Without this every
        // browser refresh replays the whole day as takeovers.
        if (state.lastSeq === null) {
            persistSeq(latest);
            renderAttract();
            return;
        }

        var entries = Array.isArray(data.entries) ? data.entries : [];
        if (!entries.length) {
            if (!state.announcing) renderAttract();
            return;
        }

        var behind = latest - state.lastSeq;
        var stale = behind > CFG.catchUpMaxEntries;

        entries.forEach(function (e) {
            if (state.seen.has(e.seq)) return;   // belt to the cursor's braces
            state.seen.add(e.seq);
            if (!stale) state.queue.push(e);
            if (e.seq > state.lastSeq) persistSeq(e.seq);
        });

        // Came back from a long outage: fold history into the roll rather than
        // announcing stale news at the hall for the next five minutes.
        if (stale) { persistSeq(latest); renderAttract(); return; }

        // Bound memory — this page runs for days.
        if (state.seen.size > CFG.maxEntries * 4) {
            state.seen = new Set(state.queue.map(function (e) { return e.seq; }));
        }
        if (state.queue.length > CFG.maxEntries) {
            state.queue = state.queue.slice(-CFG.maxEntries);
        }

        announceNext();
    }

    function degraded(isBad) {
        el('status').classList.toggle('is-degraded', isBad);
        var downFor = Date.now() - state.lastOkAt;
        el('status-note').classList.toggle('is-shown', downFor > CFG.staffNoteAfterMs);
        if (downFor > CFG.staffNoteAfterMs) {
            el('status-note').textContent = 'board offline ' + Math.round(downFor / 60000) + 'm';
        }
    }

    function poll() {
        var url = CFG.feed + '?token=' + encodeURIComponent(CFG.token) +
                  (state.lastSeq === null ? '' : '&since=' + state.lastSeq);

        var ctrl = new AbortController();
        var timer = window.setTimeout(function () { ctrl.abort(); }, CFG.fetchTimeout);

        fetch(url, { signal: ctrl.signal, headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) {
                if (!r.ok) throw new Error('http ' + r.status);
                return r.json();
            })
            .then(function (data) {
                window.clearTimeout(timer);
                state.lastOkAt = Date.now();
                state.backoff = CFG.pollMs;
                degraded(false);
                apply(data);
            })
            .catch(function () {
                window.clearTimeout(timer);
                // Degrade, never navigate. A reload here would show Chrome's
                // offline page on a ten-foot screen until someone noticed.
                state.backoff = Math.min(state.backoff * 2, CFG.maxBackoff);
                if (Date.now() - state.lastOkAt > CFG.degradedAfterMs) degraded(true);
                if (!state.announcing) renderAttract();   // keep moving
            })
            .then(function () {
                // Self-scheduling chain, NOT setInterval — setInterval stacks
                // requests on a slow network and stampedes on recovery.
                window.setTimeout(poll, state.backoff);
            });
    }

    /* ── Demo mode: synthetic entries, writes nothing ─────────────────── */
    if (CFG.demo) {
        var names = ['રમેશભાઈ પટેલ', 'Kiran Shah', 'सुनीता देवी', 'જયેશ ઠક્કર'];
        var i = 0;
        window.setInterval(function () {
            state.queue.push({
                seq: -(++i), name: names[i % names.length], city: 'Gandhidham',
                amount: [1100, 5100, 11000, 251000][i % 4], anonymous: false,
                campaign_title: null, donation_type: 'સામાન્ય દાન'
            });
            announceNext();
        }, 7000);
    }

    /* ── Housekeeping ─────────────────────────────────────────────────── */
    // Keep the panel awake without relying on OS settings alone. Released
    // whenever the tab hides, so it must be re-acquired.
    function keepAwake() {
        if (!('wakeLock' in navigator)) return;
        navigator.wakeLock.request('screen').catch(function () {});
    }
    keepAwake();
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') keepAwake();
    });

    // One scheduled reload at 04:00 for memory hygiene on a page that runs for
    // days — and only ever while idle, never mid-announcement.
    window.setInterval(function () {
        var now = new Date();
        if (now.getHours() === 4 && now.getMinutes() === 0 && !state.announcing) {
            window.location.reload();
        }
    }, 60000);

    show('boot');
    poll();
})();
</script>
</body>
</html>
