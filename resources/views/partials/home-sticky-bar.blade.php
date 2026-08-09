{{--
    STICKY ACTION BAR — HOMEPAGE ONLY (included from pages/home.blade.php,
    deliberately NOT from layouts/app.blade.php).

    Two actions, Donate and Book Seva, that slide up once the hero has
    scrolled out of view and slide away again when the visitor scrolls back
    up or reaches the footer.

    ⚠️ GUEST CACHE — `/` is cached twice for logged-out visitors
    (CacheGuestResponse, per-locale 120s, plus a Cloudflare edge Cache Rule).
    So this markup is IDENTICAL for guests and logged-in devotees by design:
      • no @auth branches, no devotee name / cart count / anything per-user,
      • no <form> and therefore no CSRF token baked into a cached page,
      • every show/hide decision is made CLIENT-SIDE at runtime.
    Keep it that way. Anything session-dependent added here would be frozen
    into the guest copy and served to every visitor for the next two minutes
    (and much longer at the edge).

    Z-INDEX ORDER (chosen deliberately, lowest → highest):
        30  social-links rail          (partials/social-links.blade.php)
        40  darshan flyout             (partials/home-darshan-widget.blade.php)
     >> 50  THIS BAR (Tailwind `z-50`) <<
        60  header dropdown / ribbon   (components/layout/header.blade.php)
        80  return-to-app banner       (components/return-to-app-banner.blade.php)
        90  app-install banner, site popup, gallery lightbox
       100  mobile menu, media lightbox
    Rationale: this bar is ambient navigation, the least urgent thing on the
    page. Everything above it is either a dismissible prompt the visitor must
    be able to answer (the two bottom banners), a menu they just opened, or a
    modal. Sitting at 50 means it can never cover any of them.

    Because both bottom banners occupy the *same* corner, sitting below them
    is not enough — the bar would still peek out from under a bottom sheet.
    So it also stands down entirely while either banner card is on screen
    (`banner` flag below), watched via a MutationObserver on their wrappers.
    Nothing is edited in those components; this partial only observes them.
--}}
<style>
    /* Motion + safe-area live here, not in resources/css/app.css (which
       another change owns) and not as arbitrary Tailwind values (which
       would need a fresh `npm run build` to exist at all). `visibility` is
       delayed off so the hidden bar can never eat a tap near the bottom
       edge, and the wrapper is pointer-events-none regardless. */
    .sph-actionbar {
        padding-bottom: calc(.625rem + env(safe-area-inset-bottom));
        transform: translateY(140%);
        opacity: 0;
        visibility: hidden;
        transition: transform .38s cubic-bezier(.22,1,.36,1), opacity .28s ease, visibility 0s linear .38s;
    }
    .sph-actionbar.is-in {
        transform: translateY(0);
        opacity: 1;
        visibility: visible;
        transition: transform .38s cubic-bezier(.22,1,.36,1), opacity .28s ease, visibility 0s;
    }
    @media (prefers-reduced-motion: reduce) {
        .sph-actionbar,
        .sph-actionbar.is-in { transform: none; transition: opacity .2s ease, visibility 0s; }
    }
</style>

<div id="sph-action-bar"
     class="sph-actionbar fixed inset-x-0 bottom-0 z-50 px-3 pointer-events-none"
     role="region"
     aria-label="{{ __('home.sticky_label') }}"
     x-data="{
        past: false,     // hero has scrolled out of view
        atFoot: false,   // footer is on screen — never sit over its links
        banner: false,   // an app-install / return-to-app bottom sheet is up
        get show() { return this.past && ! this.atFoot && ! this.banner; },
        init() {
            const hero = document.querySelector('.hero-section');
            const hasIO = 'IntersectionObserver' in window;

            if (hero && hasIO) {
                new IntersectionObserver(
                    (entries) => { this.past = ! entries[0].isIntersecting; },
                    { threshold: 0 }
                ).observe(hero);
            } else {
                // Fallback for very old browsers: plain scroll offset.
                const onScroll = () => {
                    this.past = window.scrollY > (hero ? hero.offsetHeight : 600);
                };
                window.addEventListener('scroll', onScroll, { passive: true });
                onScroll();
            }

            const foot = document.querySelector('footer');
            if (foot && hasIO) {
                new IntersectionObserver(
                    (entries) => { this.atFoot = entries[0].isIntersecting; },
                    { threshold: 0 }
                ).observe(foot);
            }

            // The two bottom sheets (z-80 / z-90) share this corner. They are
            // rendered by the layout and toggled by their own Alpine x-show,
            // which writes style.display on the card inside the wrapper — so
            // watching attributes on the wrapper tells us when one is up
            // without touching either component.
            const sheets = Array.from(
                document.querySelectorAll('.fixed.inset-x-0.bottom-0')
            ).filter((el) => el !== this.$el);

            if (sheets.length) {
                const sync = () => {
                    this.banner = sheets.some((el) => {
                        const card = el.firstElementChild;
                        return !! card && card.getBoundingClientRect().height > 0;
                    });
                };
                const mo = new MutationObserver(sync);
                sheets.forEach((el) => mo.observe(el, {
                    attributes: true, subtree: true, attributeFilter: ['style', 'class'],
                }));
                sync();
            }
        }
     }"
     :class="show ? 'is-in' : ''">

    {{-- No fixed height and no truncation anywhere: the English labels are
         the longest of the three languages and must be allowed to wrap
         rather than clip. --}}
    <div class="pointer-events-auto mx-auto w-full max-w-xl flex items-stretch gap-2 p-2 rounded-3xl border"
         style="background:linear-gradient(135deg,#FFFCF5 0%,#FBEFE0 100%); border-color:rgba(200,148,52,.38); box-shadow:0 14px 38px -10px rgba(60,30,10,.45);">

        <a href="{{ route('donate') }}"
           class="flex-1 min-w-0 inline-flex items-center justify-center gap-2 px-3 py-3 rounded-2xl text-sm font-extrabold leading-tight text-center transition hover:opacity-90"
           style="background:#E8751A; color:#FFF7EC; box-shadow:0 6px 16px rgba(196,95,18,.32);">
            <svg class="flex-none w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 21s-7.5-4.7-9.6-9A5.4 5.4 0 0 1 12 6.3a5.4 5.4 0 0 1 9.6 5.7C19.5 16.3 12 21 12 21z"/>
            </svg>
            <span>{{ __('home.donate') }}</span>
        </a>

        <a href="{{ route('seva.index') }}"
           class="flex-1 min-w-0 inline-flex items-center justify-center gap-2 px-3 py-3 rounded-2xl text-sm font-extrabold leading-tight text-center transition hover:opacity-80"
           style="background:#FFFFFF; border:1.5px solid rgba(122,30,30,.28); color:#7A1E1E;">
            <svg class="flex-none w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 2c1.6 2.3 1 4-.4 5.4-1.2 1.2-1.9 2.3-1.5 3.8.9-.6 1.6-1.4 2-2.4 1.7 1.4 2.7 3 2.7 4.8a4.8 4.8 0 1 1-9.6 0c0-3.4 2.6-5.5 4.4-7.5C11.2 4.7 11.9 3.4 12 2z"/>
                <path d="M4 20.2h16a.9.9 0 0 1 0 1.8H4a.9.9 0 0 1 0-1.8z"/>
            </svg>
            <span>{{ __('home.book_seva') }}</span>
        </a>
    </div>
</div>
