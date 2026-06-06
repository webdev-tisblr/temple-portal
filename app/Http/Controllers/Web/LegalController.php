<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\View\View;

/**
 * Static legal / store-compliance pages. These must exist at stable,
 * publicly reachable URLs because Apple App Store and Google Play require
 * a privacy policy, terms, refund policy, and an account-deletion URL.
 *
 * Contact details are pulled live from temple_system_settings so the
 * trust can edit them in the admin panel without a code change.
 */
class LegalController extends Controller
{
    private function contact(): array
    {
        return [
            'trustName' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
            'email' => SystemSetting::getValue('trust_email', 'support@patadiyahanumanji.com'),
            'phone' => SystemSetting::getValue('trust_phone', ''),
            'address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch, Gujarat - 370205'),
            'updated' => 'June 2026',
        ];
    }

    public function privacy(): View
    {
        SEOMeta::setTitle('Privacy Policy');
        SEOMeta::setDescription('How Shree Pataliya Hanumanji Seva Trust collects, uses, and protects your data in the temple mobile app and website.');

        return view('pages.legal.privacy', $this->contact());
    }

    public function terms(): View
    {
        SEOMeta::setTitle('Terms of Service');
        SEOMeta::setDescription('Terms and conditions for using the Shree Pataliya Hanumanji Seva Trust app and services.');

        return view('pages.legal.terms', $this->contact());
    }

    public function refund(): View
    {
        SEOMeta::setTitle('Refund & Cancellation Policy');
        SEOMeta::setDescription('Refund and cancellation terms for donations, seva, store orders, and hall bookings.');

        return view('pages.legal.refund', $this->contact());
    }

    public function accountDeletion(): View
    {
        SEOMeta::setTitle('Delete Your Account');
        SEOMeta::setDescription('How to delete your Shree Pataliya Hanumanji Seva Trust account and what data is removed or retained.');

        return view('pages.legal.account-deletion', $this->contact());
    }
}
