<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\View\View;

class FacilityController extends Controller
{
    public function bhojanalay(): View
    {
        SEOMeta::setTitle('ભોજનાલય — શ્રી પાતળિયા હનુમાનજી');
        return view('pages.facilities.bhojanalay');
    }

    public function yatriwas(): View
    {
        SEOMeta::setTitle('યાત્રીવાસ — શ્રી પાતળિયા હનુમાનજી');
        return view('pages.facilities.yatriwas');
    }

    public function placesAround(): View
    {
        SEOMeta::setTitle('આસપાસના સ્થળો — શ્રી પાતળિયા હનુમાનજી');
        return view('pages.facilities.places-around');
    }
}
