import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

/**
 * Crop-before-upload for every photo a devotee picks on the website.
 *
 * The app got this first; the website's file inputs went straight from
 * "choose file" to the server, so whatever the camera framed is what was
 * stored — faces off-centre, the interesting half cut off by whatever shape
 * the destination wanted. Same problem, same fix, same 1:1 result, so a photo
 * looks identical whether it was uploaded from a phone or a laptop.
 *
 * Opt in per input with `data-crop`:
 *
 *     <input type="file" accept="image/*" data-crop>
 *
 * Everything is delegated from the document, so inputs rendered later by
 * Alpine (the donate form builds its extra fields at runtime) are covered
 * without re-initialising anything.
 *
 * HIGH RESOLUTION IN, SENSIBLE SIZE OUT — deliberately the same rule the app
 * follows. The original is never downscaled before cropping, because that
 * throws away the detail the crop was meant to keep; the ceiling is applied to
 * the region the devotee actually chose.
 */

/** Longest edge of the CROPPED result. Matches the app and the server's own cap. */
const MAX_EDGE = 2048;

const JPEG_QUALITY = 0.88;

/** Files we can meaningfully crop. Anything else is left alone. */
const CROPPABLE = /^image\/(jpeg|png|webp)$/i;

let activeCropper = null;
let activeModal = null;

/**
 * Inputs whose `change` WE fired, so the delegated listener below can ignore
 * its own echo.
 *
 * replaceInputFile has to dispatch `change` — Alpine bindings and any
 * validation listening on these inputs need to know the value moved. But this
 * module listens for `change` on the document, so without this guard it
 * catches its own event and opens a second cropper on the file it just
 * cropped: the new modal collides with the still-open cropper, never
 * initialises, and leaves a plain image with no crop box and buttons wired to
 * stale state. That is the "not square, buttons dead" report.
 *
 * A WeakSet so an input removed from the DOM is not kept alive by this.
 */
const selfDispatched = new WeakSet();

function closeModal() {
    if (activeCropper) {
        activeCropper.destroy();
        activeCropper = null;
    }
    if (activeModal) {
        activeModal.remove();
        activeModal = null;
    }
    document.body.style.overflow = '';
}

/**
 * Put the cropped blob back into the original <input type="file">.
 *
 * DataTransfer is the only way to write to input.files. Supported in every
 * browser this site targets; if it were ever missing, the catch in the caller
 * leaves the untouched original in place rather than losing the upload.
 */
function replaceInputFile(input, blob, originalName) {
    const name = originalName.replace(/\.[^.]+$/, '') + '.jpg';
    const file = new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });

    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;

    // Alpine and any listening validation need to know the value changed —
    // but this module must not re-crop its own result. @see selfDispatched
    selfDispatched.add(input);
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function buildModal(imageUrl, { onConfirm, onCancel }) {
    const modal = document.createElement('div');
    modal.className = 'crop-modal';
    modal.innerHTML = `
        <div class="crop-modal__panel" role="dialog" aria-modal="true">
            <div class="crop-modal__stage">
                <img alt="">
            </div>
            <div class="crop-modal__actions">
                <button type="button" data-crop-cancel class="crop-modal__btn crop-modal__btn--ghost"></button>
                <button type="button" data-crop-confirm class="crop-modal__btn crop-modal__btn--primary"></button>
            </div>
        </div>
    `;

    // Labels come from data-* on the document root so the three languages are
    // translated in Blade rather than hardcoded here.
    const root = document.documentElement;
    modal.querySelector('[data-crop-cancel]').textContent = root.dataset.cropCancel || 'Cancel';
    modal.querySelector('[data-crop-confirm]').textContent = root.dataset.cropConfirm || 'Use photo';

    const image = modal.querySelector('img');
    image.src = imageUrl;

    modal.querySelector('[data-crop-cancel]').addEventListener('click', onCancel);
    modal.querySelector('[data-crop-confirm]').addEventListener('click', onConfirm);

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    return { modal, image };
}

function openCropper(input, file) {
    // One cropper at a time. A second pick while one is open replaces it
    // rather than stacking an orphan modal nothing can close.
    closeModal();

    const imageUrl = URL.createObjectURL(file);

    const { modal, image } = buildModal(imageUrl, {
        onCancel: () => {
            // Clear the input: a devotee who backs out has chosen nothing, and
            // silently keeping the uncropped original would be the one
            // outcome they did not ask for.
            input.value = '';
            URL.revokeObjectURL(imageUrl);
            closeModal();
        },
        onConfirm: () => {
            // No cropper means something went wrong initialising it. Keep the
            // devotee's original file rather than swallowing the click and
            // leaving them pressing a dead button.
            if (!activeCropper) {
                URL.revokeObjectURL(imageUrl);
                closeModal();

                return;
            }

            activeCropper
                .getCroppedCanvas({
                    maxWidth: MAX_EDGE,
                    maxHeight: MAX_EDGE,
                    imageSmoothingQuality: 'high',
                    fillColor: '#fff',
                })
                .toBlob(
                    (blob) => {
                        if (blob) replaceInputFile(input, blob, file.name);
                        URL.revokeObjectURL(imageUrl);
                        closeModal();
                    },
                    'image/jpeg',
                    JPEG_QUALITY,
                );
        },
    });

    activeModal = modal;

    let started = false;
    const start = () => {
        if (started) return;
        started = true;
        activeCropper = new Cropper(image, {
            // Square, locked — the same shape the app crops to and the shape
            // every destination frames these photos in.
            aspectRatio: 1,
            viewMode: 1,
            autoCropArea: 1,
            background: false,
            responsive: true,
// Dragging must MOVE the crop box, not draw a new one: on a
            // phone the first touch would otherwise reset the selection.
            dragMode: 'move',
            toggleDragModeOnDblclick: false,
        });
    };

    // A blob URL can finish decoding before the listener is attached, and a
    // cropper that never initialises makes "Use photo" do nothing at all —
    // so handle the already-loaded case rather than relying on the event.
    if (image.complete && image.naturalWidth > 0) {
        start();
    } else {
        image.addEventListener('load', start, { once: true });
        image.addEventListener('error', () => {
            input.value = '';
            URL.revokeObjectURL(imageUrl);
            closeModal();
        }, { once: true });
    }
}

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    if (input.type !== 'file' || !input.hasAttribute('data-crop')) return;

    // Our own write-back, not a fresh pick by the devotee.
    if (selfDispatched.has(input)) {
        selfDispatched.delete(input);

        return;
    }

    const file = input.files && input.files[0];
    if (!file || !CROPPABLE.test(file.type)) return;

    // Guard the whole thing: a cropper that fails to open must leave the
    // devotee with a working upload, not a broken form.
    try {
        openCropper(input, file);
    } catch (e) {
        closeModal();
        if (import.meta.env?.DEV) console.error('[crop] failed to open', e);
    }
});
