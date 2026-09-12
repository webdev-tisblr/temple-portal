<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\CardPreviewService;
use App\Support\DevoteeLocale;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * POST /admin/card-preview — the "Preview" button on every card editor.
 *
 * Takes the layout the admin is editing (unsaved, straight from the form),
 * renders it with sample values in the requested language and returns the
 * PNG inline. The editor submits a real <form target="_blank">, so the result
 * opens in a new tab like any image, and NOTHING is stored: no R2 object, no
 * temp file that outlives the request. Previews are frequent and throwaway,
 * and the private bucket is a cache of things devotees actually receive.
 *
 * Access: this lives outside the Filament panel, so the panel's own gate does
 * not run here. It re-applies it by hand — the admin guard, then the same
 * canAccessPanel() rule Filament uses (active + panel_user), then the
 * record's UPDATE policy, because seeing a preview of a layout you may not
 * edit is pointless and the policy is the one place that rule is written.
 */
class CardPreviewController extends Controller
{
    public function __invoke(Request $request, CardPreviewService $previews): Response
    {
        $admin = $request->user('admin');

        if (! $admin instanceof AdminUser || ! $admin->canAccessPanel(Filament::getPanel('admin'))) {
            abort(403);
        }

        $data = $request->validate([
            'owner' => ['required', Rule::in(array_keys(CardPreviewService::OWNERS))],
            'id' => ['required', 'string', 'max:64'],
            'locale' => ['required', Rule::in(DevoteeLocale::SUPPORTED)],
            'config' => ['required', 'string', 'max:'.CardPreviewService::MAX_CONFIG_BYTES],
        ]);

        /** @var class-string<Model> $class */
        $class = CardPreviewService::OWNERS[$data['owner']];
        $owner = $class::query()->find($data['id']);

        if (! $owner instanceof Model) {
            abort(404);
        }

        if (! $admin->can('update', $owner)) {
            abort(403);
        }

        $config = json_decode($data['config'], true);
        $overlays = is_array($config) ? ($config['overlays'] ?? null) : null;

        if (! is_array($overlays)) {
            return $this->problem('The layout could not be read. Reload the page and try again.');
        }

        if ($overlays === []) {
            return $this->problem('Add at least one text block or variable to the card before previewing.');
        }

        $png = $previews->render($owner, array_values($overlays), $data['locale']);

        if ($png === null) {
            return $this->problem('No background image is saved for this card yet. Upload one, save, then preview.');
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Content-Disposition' => 'inline; filename="card-preview-'.$data['locale'].'.png"',
            // A preview is of an UNSAVED layout; nothing may hold on to it.
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** A plain sentence in the new tab — the admin is looking at a page, not an API. */
    private function problem(string $message): Response
    {
        return response($message, 422, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
