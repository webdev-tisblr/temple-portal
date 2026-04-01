<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\View\View;

class EventWebController extends Controller
{
    public function index(): View
    {
        $upcoming = Event::where('status', 'published')
            ->where('start_date', '>=', now())
            ->orderBy('start_date')
            ->paginate(12);

        $recent = Event::where('status', 'published')
            ->where('start_date', '<', now())
            ->orderByDesc('start_date')
            ->take(6)->get();

        SEOMeta::setTitle('કાર્યક્રમો — શ્રી પાતળિયા હનુમાનજી');
        SEOMeta::setDescription('મંદિરના આગામી ઉત્સવો અને કાર્યક્રમો.');

        return view('pages.events.index', compact('upcoming', 'recent'));
    }

    public function show(Event $event): View
    {
        SEOMeta::setTitle("{$event->title} — શ્રી પાતળિયા હનુમાનજી");
        SEOMeta::setDescription(strip_tags($event->description ?? '') ?: '');

        return view('pages.events.show', compact('event'));
    }
}
