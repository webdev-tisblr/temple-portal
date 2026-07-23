/**
 * Clean YouTube player — chromeless embed with our own controls.
 *
 * YouTube's iframe can't selectively hide its chrome (controls=0 is
 * all-or-nothing, modestbranding is dead), so we render the player with
 * controls=0 via the IFrame API and draw our own bar: play/pause,
 * mute/volume, timeline, fullscreen. Nothing else — no logos, no cards,
 * no settings, no "watch on YouTube".
 *
 * Usage (server-rendered or Alpine-created):
 *   <div data-yt-clean data-yt-id="VIDEO_ID" data-title="..." [data-live="1"]></div>
 *
 * The module self-mounts on DOMContentLoaded and watches the DOM, so
 * elements added later (Alpine x-if templates, lightboxes) and
 * data-yt-id swaps (gallery prev/next) just work. A poster + play
 * button shows before first play so YouTube's title overlay never
 * flashes; a transparent tap layer keeps clicks off YouTube's UI.
 */

const ROOT_SEL = '[data-yt-clean]';

/* ------------------------------------------------------------------ */
/* IFrame API loader (shared, chains any handler the page already set) */
/* ------------------------------------------------------------------ */
let apiPromise = null;

function loadApi() {
    if (window.YT && window.YT.Player) return Promise.resolve();
    if (apiPromise) return apiPromise;

    apiPromise = new Promise((resolve) => {
        const prev = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (typeof prev === 'function') prev();
            resolve();
        };
        if (!document.getElementById('yt-iframe-api')) {
            const tag = document.createElement('script');
            tag.id = 'yt-iframe-api';
            tag.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(tag);
        }
    });

    return apiPromise;
}

/* ------------------------------------------------------------------ */
/* Styles (injected once — keeps the player fully self-contained)      */
/* ------------------------------------------------------------------ */
const CSS = `
.ytc{position:relative;overflow:hidden;background:#000;width:100%;height:100%}
.ytc,.ytc *{box-sizing:border-box}
.ytc .ytc-frame,.ytc iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.ytc-poster{position:absolute;inset:0;cursor:pointer;z-index:3;display:flex;align-items:center;justify-content:center;background:#000}
.ytc-poster img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.85}
.ytc-poster .ytc-bigplay{position:relative;z-index:1;width:64px;height:64px;border-radius:9999px;border:0;cursor:pointer;
  background:rgba(180,83,9,.9);color:#fff;display:flex;align-items:center;justify-content:center;
  box-shadow:0 4px 24px rgba(0,0,0,.5);transition:transform .15s,background .15s}
.ytc-poster:hover .ytc-bigplay{transform:scale(1.08);background:rgba(217,119,6,.95)}
.ytc-bigplay svg{width:26px;height:26px;margin-left:3px}
.ytc-tap{position:absolute;inset:0;z-index:1;cursor:pointer}
.ytc-bar{position:absolute;left:0;right:0;bottom:0;z-index:2;display:flex;align-items:center;gap:10px;
  padding:22px 12px 8px;background:linear-gradient(to top,rgba(0,0,0,.85),rgba(0,0,0,.45) 60%,transparent);
  opacity:0;transition:opacity .25s;color:#fde68a;font:600 12px/1 system-ui,sans-serif}
.ytc.ytc-ui .ytc-bar{opacity:1}
.ytc-btn{flex:none;width:34px;height:34px;border:0;border-radius:8px;background:transparent;color:#fde68a;
  display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
.ytc-btn:hover{background:rgba(255,255,255,.14);color:#fff}
.ytc-btn svg{width:20px;height:20px;fill:currentColor}
.ytc-time{flex:none;font-variant-numeric:tabular-nums;color:#fef3c7;opacity:.9;min-width:34px;text-align:center}
.ytc-seek{flex:1;-webkit-appearance:none;appearance:none;height:4px;border-radius:2px;cursor:pointer;
  background:linear-gradient(to right,#f59e0b var(--p,0%),rgba(255,255,255,.28) var(--p,0%))}
.ytc-seek::-webkit-slider-thumb{-webkit-appearance:none;width:12px;height:12px;border-radius:50%;background:#fbbf24;border:0}
.ytc-seek::-moz-range-thumb{width:12px;height:12px;border-radius:50%;background:#fbbf24;border:0}
.ytc-vol{flex:none;-webkit-appearance:none;appearance:none;width:64px;height:4px;border-radius:2px;cursor:pointer;
  background:linear-gradient(to right,#fde68a var(--p,100%),rgba(255,255,255,.28) var(--p,100%))}
.ytc-vol::-webkit-slider-thumb{-webkit-appearance:none;width:10px;height:10px;border-radius:50%;background:#fde68a;border:0}
.ytc-vol::-moz-range-thumb{width:10px;height:10px;border-radius:50%;background:#fde68a;border:0}
@media (pointer:coarse){.ytc-vol{display:none}}
.ytc-live{flex:none;display:flex;align-items:center;gap:5px;color:#fca5a5;letter-spacing:.06em}
.ytc-live::before{content:'';width:7px;height:7px;border-radius:50%;background:#ef4444;animation:ytc-pulse 1.6s infinite}
@keyframes ytc-pulse{0%,100%{opacity:1}50%{opacity:.35}}
.ytc:fullscreen{width:100%;height:100%}
.ytc:fullscreen .ytc-frame,.ytc:fullscreen iframe{width:100%;height:100%}
.ytc .ytc-hidden{display:none!important}
`;

function injectStyle() {
    if (document.getElementById('ytc-style')) return;
    const s = document.createElement('style');
    s.id = 'ytc-style';
    s.textContent = CSS;
    document.head.appendChild(s);
}

/* ------------------------------------------------------------------ */
/* Icons                                                               */
/* ------------------------------------------------------------------ */
const I = {
    play: '<svg viewBox="0 0 24 24"><path d="M8 5.14v13.72c0 .8.87 1.3 1.56.9l11-6.86a1.05 1.05 0 0 0 0-1.8l-11-6.86A1.05 1.05 0 0 0 8 5.14z"/></svg>',
    pause: '<svg viewBox="0 0 24 24"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>',
    volOn: '<svg viewBox="0 0 24 24"><path d="M4 9v6h4l5 4V5L8 9H4zm11.5 3a3.5 3.5 0 0 0-2-3.16v6.32a3.5 3.5 0 0 0 2-3.16zm-2-7v2.06a5.5 5.5 0 0 1 0 9.88V19a7.5 7.5 0 0 0 0-14z"/></svg>',
    volOff: '<svg viewBox="0 0 24 24"><path d="M4 9v6h4l5 4V5L8 9H4zm12.3 3 2.35-2.35-1.3-1.3L15 10.7l-2.35-2.35-1.3 1.3L13.7 12l-2.35 2.35 1.3 1.3L15 13.3l2.35 2.35 1.3-1.3L16.3 12z"/></svg>',
    fsOn: '<svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>',
    fsOff: '<svg viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>',
};

const fmt = (s) => {
    s = Math.max(0, Math.floor(s || 0));
    const m = Math.floor(s / 60), h = Math.floor(m / 60);
    const mm = h ? String(m % 60).padStart(2, '0') : m % 60;
    const ss = String(s % 60).padStart(2, '0');
    return h ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
};

/* ------------------------------------------------------------------ */
/* Player                                                              */
/* ------------------------------------------------------------------ */
class CleanPlayer {
    constructor(root) {
        this.root = root;
        this.id = root.dataset.ytId;
        this.live = root.dataset.live === '1' || root.dataset.live === '';
        this.autoplay = root.dataset.autoplay === '1';
        this.title = root.dataset.title || '';
        this.player = null;
        this.ticker = null;
        this.hideTimer = null;
        this.seeking = false;
        this.destroyed = false;

        root.classList.add('ytc');
        this.buildPoster();
        this.buildUi();

        if (this.autoplay) this.start();
    }

    buildPoster() {
        const p = document.createElement('div');
        p.className = 'ytc-poster';
        p.innerHTML =
            `<img alt="" src="https://i.ytimg.com/vi/${this.id}/hqdefault.jpg">` +
            `<button type="button" class="ytc-bigplay" aria-label="Play">${I.play}</button>`;
        // Upgrade to the HD poster when it exists (tiny grey 120px image otherwise).
        const img = p.querySelector('img');
        const hd = new Image();
        hd.onload = () => { if (hd.naturalWidth > 200) img.src = hd.src; };
        hd.src = `https://i.ytimg.com/vi/${this.id}/maxresdefault.jpg`;
        p.addEventListener('click', () => this.start());
        this.poster = p;
        this.root.appendChild(p);
    }

    buildUi() {
        // Target div the IFrame API replaces.
        const frame = document.createElement('div');
        frame.className = 'ytc-frame';
        this.frame = frame;
        this.root.appendChild(frame);

        // Transparent layer: clicks toggle playback, never reach YouTube's UI.
        const tap = document.createElement('div');
        tap.className = 'ytc-tap';
        tap.addEventListener('click', () => this.toggle());
        tap.addEventListener('dblclick', () => this.toggleFullscreen());
        this.root.appendChild(tap);

        const bar = document.createElement('div');
        bar.className = 'ytc-bar';
        bar.innerHTML =
            `<button type="button" class="ytc-btn ytc-play" aria-label="Play/Pause">${I.play}</button>` +
            `<button type="button" class="ytc-btn ytc-mute" aria-label="Mute">${I.volOn}</button>` +
            `<input type="range" class="ytc-vol" min="0" max="100" value="100" aria-label="Volume">` +
            (this.live
                ? `<span class="ytc-live">LIVE</span><span style="flex:1"></span>`
                : `<span class="ytc-time ytc-cur">0:00</span>` +
                  `<input type="range" class="ytc-seek" min="0" max="1000" value="0" aria-label="Seek">` +
                  `<span class="ytc-time ytc-dur">0:00</span>`) +
            `<button type="button" class="ytc-btn ytc-fs" aria-label="Fullscreen">${I.fsOn}</button>`;
        this.root.appendChild(bar);
        this.bar = bar;

        this.$play = bar.querySelector('.ytc-play');
        this.$mute = bar.querySelector('.ytc-mute');
        this.$vol = bar.querySelector('.ytc-vol');
        this.$cur = bar.querySelector('.ytc-cur');
        this.$dur = bar.querySelector('.ytc-dur');
        this.$seek = bar.querySelector('.ytc-seek');
        this.$fs = bar.querySelector('.ytc-fs');

        this.$play.addEventListener('click', () => this.toggle());
        this.$mute.addEventListener('click', () => this.toggleMute());
        this.$vol.addEventListener('input', () => this.setVolume(+this.$vol.value));

        if (this.$seek) {
            this.$seek.addEventListener('input', () => {
                this.seeking = true;
                const d = this.duration();
                if (d) this.$cur.textContent = fmt((this.$seek.value / 1000) * d);
                this.paintSeek();
            });
            const commit = () => {
                const d = this.duration();
                if (this.player && d) this.player.seekTo((this.$seek.value / 1000) * d, true);
                this.seeking = false;
            };
            this.$seek.addEventListener('change', commit);
        }

        // Fullscreen — hide the button where the API doesn't exist (iPhone Safari).
        if (!this.root.requestFullscreen && !this.root.webkitRequestFullscreen) {
            this.$fs.classList.add('ytc-hidden');
        }
        this.$fs.addEventListener('click', () => this.toggleFullscreen());
        document.addEventListener('fullscreenchange', (this._fsHandler = () => {
            const on = document.fullscreenElement === this.root;
            this.$fs.innerHTML = on ? I.fsOff : I.fsOn;
        }));

        // Controls visibility: show on hover/touch, fade while playing.
        const poke = () => this.showUi();
        this.root.addEventListener('mousemove', poke);
        this.root.addEventListener('touchstart', poke, { passive: true });
        this.root.addEventListener('mouseleave', () => {
            if (this.isPlaying()) this.root.classList.remove('ytc-ui');
        });
    }

    /* ---- lifecycle ---- */

    start() {
        if (this.player || this.destroyed) return;
        this.showUi();
        loadApi().then(() => {
            if (this.destroyed) return;
            this.player = new YT.Player(this.frame, {
                videoId: this.id,
                host: 'https://www.youtube-nocookie.com',
                playerVars: {
                    autoplay: 1,
                    controls: 0,
                    rel: 0,
                    playsinline: 1,
                    fs: 0,
                    disablekb: 1,
                    iv_load_policy: 3,
                    modestbranding: 1,
                    origin: window.location.origin,
                },
                events: {
                    onReady: (e) => {
                        if (this.autoplay && this.root.dataset.mute === '1') e.target.mute();
                        e.target.playVideo();
                        this.syncVolume();
                    },
                    onStateChange: (e) => this.onState(e.data),
                },
            });
        });
    }

    onState(s) {
        const YTP = window.YT.PlayerState;
        if (s === YTP.PLAYING) {
            this.poster.style.display = 'none';
            this.$play.innerHTML = I.pause;
            this.startTicker();
            this.scheduleHide();
        } else if (s === YTP.PAUSED || s === YTP.BUFFERING) {
            this.$play.innerHTML = s === YTP.PAUSED ? I.play : this.$play.innerHTML;
            if (s === YTP.PAUSED) this.showUi(true);
        } else if (s === YTP.ENDED) {
            // Back to the poster — never show YouTube's end screen.
            this.$play.innerHTML = I.play;
            this.stopTicker();
            try { this.player.seekTo(0); this.player.pauseVideo(); } catch (e) { /* noop */ }
            this.poster.style.display = '';
            this.showUi(true);
        }
    }

    destroy() {
        this.destroyed = true;
        this.stopTicker();
        clearTimeout(this.hideTimer);
        document.removeEventListener('fullscreenchange', this._fsHandler);
        try { if (this.player) this.player.destroy(); } catch (e) { /* noop */ }
        this.player = null;
        this.root.classList.remove('ytc', 'ytc-ui');
        this.root.innerHTML = '';
    }

    /* ---- controls ---- */

    isPlaying() {
        try {
            return this.player && this.player.getPlayerState() === window.YT.PlayerState.PLAYING;
        } catch (e) { return false; }
    }

    toggle() {
        if (!this.player) return this.start();
        this.isPlaying() ? this.player.pauseVideo() : this.player.playVideo();
    }

    toggleMute() {
        if (!this.player) return;
        if (this.player.isMuted() || this.player.getVolume() === 0) {
            this.player.unMute();
            if (this.player.getVolume() === 0) this.player.setVolume(60);
        } else {
            this.player.mute();
        }
        setTimeout(() => this.syncVolume(), 120);
    }

    setVolume(v) {
        if (!this.player) return;
        this.player.setVolume(v);
        if (v > 0 && this.player.isMuted()) this.player.unMute();
        if (v === 0) this.player.mute();
        this.paintVolume(v, v === 0);
    }

    syncVolume() {
        if (!this.player) return;
        try {
            const muted = this.player.isMuted();
            const v = this.player.getVolume();
            this.$vol.value = muted ? 0 : v;
            this.paintVolume(muted ? 0 : v, muted);
        } catch (e) { /* noop */ }
    }

    paintVolume(v, muted) {
        this.$mute.innerHTML = muted || v === 0 ? I.volOff : I.volOn;
        this.$vol.style.setProperty('--p', `${v}%`);
    }

    toggleFullscreen() {
        const doc = document;
        if (doc.fullscreenElement === this.root) {
            doc.exitFullscreen && doc.exitFullscreen();
        } else if (this.root.requestFullscreen) {
            this.root.requestFullscreen();
        } else if (this.root.webkitRequestFullscreen) {
            this.root.webkitRequestFullscreen();
        }
    }

    /* ---- timeline ---- */

    duration() {
        try { return this.player ? this.player.getDuration() : 0; } catch (e) { return 0; }
    }

    startTicker() {
        if (this.ticker || this.live) return;
        this.ticker = setInterval(() => {
            if (!this.player || this.seeking) return;
            try {
                const t = this.player.getCurrentTime();
                const d = this.duration();
                this.$cur.textContent = fmt(t);
                this.$dur.textContent = fmt(d);
                if (d) {
                    this.$seek.value = Math.round((t / d) * 1000);
                    this.paintSeek();
                }
            } catch (e) { /* noop */ }
        }, 250);
    }

    stopTicker() {
        clearInterval(this.ticker);
        this.ticker = null;
    }

    paintSeek() {
        this.$seek.style.setProperty('--p', `${this.$seek.value / 10}%`);
    }

    /* ---- UI visibility ---- */

    showUi(sticky = false) {
        this.root.classList.add('ytc-ui');
        if (!sticky) this.scheduleHide();
        else clearTimeout(this.hideTimer);
    }

    scheduleHide() {
        clearTimeout(this.hideTimer);
        this.hideTimer = setTimeout(() => {
            if (this.isPlaying()) this.root.classList.remove('ytc-ui');
        }, 2600);
    }
}

/* ------------------------------------------------------------------ */
/* Mounting — initial scan + DOM/attribute observation                 */
/* ------------------------------------------------------------------ */
const INSTANCES = new WeakMap();

function mount(el) {
    if (INSTANCES.has(el) || !el.dataset.ytId) return;
    injectStyle();
    INSTANCES.set(el, new CleanPlayer(el));

    // Alpine lightboxes swap data-yt-id in place on prev/next — rebuild.
    const attrObs = new MutationObserver(() => {
        const inst = INSTANCES.get(el);
        if (inst && inst.id !== el.dataset.ytId) {
            inst.destroy();
            INSTANCES.delete(el);
            if (el.dataset.ytId) mount(el);
        }
    });
    attrObs.observe(el, { attributes: true, attributeFilter: ['data-yt-id'] });
}

function unmountWithin(node) {
    if (node.nodeType !== 1) return;
    const roots = node.matches && node.matches(ROOT_SEL) ? [node] : node.querySelectorAll ? node.querySelectorAll(ROOT_SEL) : [];
    roots.forEach((el) => {
        const inst = INSTANCES.get(el);
        if (inst) { inst.destroy(); INSTANCES.delete(el); }
    });
}

function scan(node) {
    if (node.nodeType !== 1) return;
    if (node.matches && node.matches(ROOT_SEL)) mount(node);
    if (node.querySelectorAll) node.querySelectorAll(ROOT_SEL).forEach(mount);
}

function init() {
    document.querySelectorAll(ROOT_SEL).forEach(mount);
    new MutationObserver((muts) => {
        muts.forEach((m) => {
            m.addedNodes.forEach(scan);
            m.removedNodes.forEach(unmountWithin);
        });
    }).observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

// Manual hook, if a page ever needs it.
window.CleanTube = { mount };
