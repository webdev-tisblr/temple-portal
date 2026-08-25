/**
 * Single-submit guard + "we heard you" feedback for every POST form.
 *
 * The problem this fixes, reported from live use (2026-08-25): the payment
 * buttons — donate, seva book, hall book, store checkout — all POST to a
 * controller that has to call Razorpay's order API before it can render
 * anything. On a good connection that's ~300ms and invisible. On a slow
 * one it is one to three seconds during which the page looks completely
 * inert: the button doesn't depress, nothing spins, nothing moves. So the
 * devotee presses it again. The browser then ABORTS the first POST and
 * fires a second one, while PHP-FPM happily finishes the first — leaving a
 * stray Razorpay order plus an orphan `pending` Payment/Donation/Booking
 * row, and for seva/hall that pending row also holds a slot.
 *
 * iOS makes it the common case rather than an edge case: App Store rule
 * 3.2.2(iv) means the app's donate button opens THIS website, so every
 * iPhone donor goes through this exact form, often on mobile data.
 *
 * Two layers answer it. This is the first — make the wait visible and
 * make the second press impossible. The second is server-side
 * (App\Http\Middleware\IdempotentPaymentRequest), which catches the
 * no-JS, second-tab and already-in-flight cases.
 *
 * Deliberate choices:
 *
 *   • The authoritative guard is the `data-submitting` attribute on the
 *     FORM, not the disabled state of the button. Several of these buttons
 *     carry an Alpine `:disabled` binding (`!amount || amount < 1`), and
 *     Alpine will happily re-evaluate that binding — and re-enable the
 *     button — while our POST is still in flight.
 *
 *   • We never rewrite the button's innerHTML to inject a spinner. Those
 *     labels are Alpine-bound (`x-text="amount.toLocaleString(...)"`), and
 *     swapping the DOM out from under a binding breaks it on restore. The
 *     spinner is a CSS ::before on `.is-submitting` instead — zero DOM
 *     mutation.
 *
 *   • `disabled` is set on the next tick, not inline. Disabling a submit
 *     button synchronously inside its own submit event is a documented way
 *     to make some browsers drop the submission entirely. The class (which
 *     kills pointer-events) lands immediately; the attribute follows.
 *
 *   • bfcache. Coming BACK from Razorpay restores this page verbatim —
 *     overlay up, button dead. `pageshow` with `persisted` undoes the lock,
 *     otherwise the devotee returns to a page they can never submit again.
 */

const LOCK_ATTR = 'data-submitting';
const SLOW_AFTER_MS = 20000;   // "still working" hint
const RELEASE_AFTER_MS = 45000; // hard release so nobody is stranded

let slowTimer = null;
let releaseTimer = null;

const overlay = () => document.querySelector('[data-payment-overlay]');

function showOverlay() {
    const el = overlay();
    if (!el) return;
    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');

    clearTimeout(slowTimer);
    slowTimer = setTimeout(() => {
        const slow = el.querySelector('[data-payment-overlay-slow]');
        if (slow) slow.hidden = false;
    }, SLOW_AFTER_MS);
}

function hideOverlay() {
    const el = overlay();
    clearTimeout(slowTimer);
    if (!el) return;
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
    const slow = el.querySelector('[data-payment-overlay-slow]');
    if (slow) slow.hidden = true;
}

/**
 * Every control that can submit this form: the ones inside it, plus any
 * outside it wired up with the `form="..."` attribute.
 */
function submitControls(form) {
    const found = new Set();
    form.querySelectorAll(
        'button[type="submit"], button:not([type]), input[type="submit"], input[type="image"]'
    ).forEach((el) => found.add(el));

    if (form.id) {
        document.querySelectorAll(`[form="${CSS.escape(form.id)}"]`).forEach((el) => {
            if (el.type === 'submit' || el.type === 'image') found.add(el);
        });
    }
    return Array.from(found);
}

function lock(form) {
    form.setAttribute(LOCK_ATTR, '');

    // A handful of buttons already draw their own spinner from an Alpine
    // `loading` flag (the OTP send/verify pair). They opt out of ours with
    // data-no-spinner so the devotee gets one spinner, not two — the guard
    // itself still applies, because that is the part that matters.
    const withSpinner = !form.hasAttribute('data-no-spinner');

    const controls = submitControls(form);
    form.__submitLock = controls.map((el) => ({ el, wasDisabled: el.disabled === true }));

    // Immediate, synchronous: kills pointer-events and shows the spinner.
    controls.forEach((el) => {
        if (withSpinner) el.classList.add('is-submitting');
        el.setAttribute('aria-busy', 'true');
    });

    // Next tick: the real `disabled`, once the submission is safely under way.
    setTimeout(() => {
        if (!form.hasAttribute(LOCK_ATTR)) return;
        controls.forEach((el) => { el.disabled = true; });
    }, 0);

    if (form.hasAttribute('data-payment-form')) showOverlay();

    clearTimeout(releaseTimer);
    releaseTimer = setTimeout(() => unlock(form), RELEASE_AFTER_MS);
}

function unlock(form) {
    if (!form.hasAttribute(LOCK_ATTR)) return;
    form.removeAttribute(LOCK_ATTR);
    clearTimeout(releaseTimer);

    (form.__submitLock || []).forEach(({ el, wasDisabled }) => {
        // Restore what it WAS, not blanket-enable — several of these
        // buttons are legitimately disabled (empty cart, no amount, no
        // date) and re-enabling them would be a lie.
        el.disabled = wasDisabled;
        el.classList.remove('is-submitting');
        el.removeAttribute('aria-busy');
    });
    form.__submitLock = null;

    hideOverlay();
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.hasAttribute('data-no-submit-lock')) return;

    // GET forms navigate instantly and are idempotent — nothing to guard.
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;

    // The second press. This listener sits on `document`, so the form's own
    // handlers have already run; preventing default here still stops the
    // browser, which only acts once propagation finishes.
    if (form.hasAttribute(LOCK_ATTR)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    // A page handler cancelled this submit (the cart waits for pending
    // quantity syncs before letting checkout through). It will re-submit
    // when it's ready and we'll lock on that pass — locking now would
    // strand the button if it decides not to.
    if (event.defaultPrevented) return;

    lock(form);
});

window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll(`form[${LOCK_ATTR}]`).forEach(unlock);
    hideOverlay();
});
