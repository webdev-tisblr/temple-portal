@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => 'મંદિરના નિયમો']]"
    title="મંદિરના નિયમો"
    subtitle="ભક્તો માટે આચાર-સંહિતા — કૃપા કરી પાળો" />

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">
    <div class="card-sacred p-6 sm:p-10">

        <p class="text-amber-100/60 mb-8 leading-relaxed">
            શ્રી પાતાળિયા હનુમાનજી ધામની પવિત્રતા અને સૌ ભક્તોના સુખ-શાંતિ
            જળવાઈ રહે તે ઉદ્દેશ્યથી ટ્રસ્ટ દ્વારા આ નિયમો ઘડ્યા છે.
            સૌ ભક્તોને વિનંતી છે કે આ નિયમોનું પૂર્ણ પાલન કરે.
        </p>

        <ol class="space-y-6">

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">1</span>
                <div>
                    <p class="font-semibold text-amber-100/70">પગરખાં બહાર ઉતારો</p>
                    <p class="text-sm text-amber-100/40 mt-1">મંદિર પ્રવેશ-દ્વાર પહેલાં ચપ્પલ-જૂતા ઉતારવા ફરજિયાત છે. ચપ્પલ-સ્ટેન્ડની સુવિધા ઉપલબ્ધ છે.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">2</span>
                <div>
                    <p class="font-semibold text-amber-100/70">સભ્ય વસ્ત્ર-સંહિતા</p>
                    <p class="text-sm text-amber-100/40 mt-1">ભારતીય પારંપરિક અથવા સભ્ય પોશાક પહેરો. ટૂંકાં, ચુસ્ત કે અશ્લીલ વસ્ત્રો અંદર પ્રવેશ માટે માન્ય નથી.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">3</span>
                <div>
                    <p class="font-semibold text-amber-100/70">ફોટો / વિડિયો — No Photography</p>
                    <p class="text-sm text-amber-100/40 mt-1">ગર્ભગૃહમાં ફોટોગ્રાફી તથા વિડિયોગ્રાફી સંપૂર્ણપણે પ્રતિબંધિત છે. હૉલમાં ટ્રસ્ટની પૂર્વ-મંજૂરી લેવી ફરજિયાત છે.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">4</span>
                <div>
                    <p class="font-semibold text-amber-100/70">મૌન જાળવો</p>
                    <p class="text-sm text-amber-100/40 mt-1">આરતી અને પૂજા સમયે મોબાઈલ સાઈલન્ટ રાખો. મોટેથી વાત કરવાનું ટાળો. ભજનમાં ભાવપૂર્વક સહભાગી થાઓ.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">5</span>
                <div>
                    <p class="font-semibold text-amber-100/70">મોબાઈલ ફોન સાઈલન્ટ મોડ</p>
                    <p class="text-sm text-amber-100/40 mt-1">મંદિર પરિસરમાં મોબાઈલ ફોન સાઈલન્ટ અથવા વાઈબ્રેટ મોડમાં રાખવો. કૉલ બહાર નીકળીને જ રિસીવ કરો.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">6</span>
                <div>
                    <p class="font-semibold text-amber-100/70">સ્વચ્છતા</p>
                    <p class="text-sm text-amber-100/40 mt-1">મંદિર પરિસરમાં ગંદકી ન કરો. કચરાપેટીનો જ ઉપયોગ કરો. પ્રસાદનું વેષ્ટન કચરાપેટીમાં જ નાખો.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">7</span>
                <div>
                    <p class="font-semibold text-amber-100/70">ધૂમ્રપાન / મદિરાપાન પ્રતિબંધિત</p>
                    <p class="text-sm text-amber-100/40 mt-1">મંદિર પરિસરમાં ધૂમ્રપાન, મદિરાપાન અને તમાકુનું સેવન સંપૂર્ણપણે પ્રતિબંધિત છે.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">8</span>
                <div>
                    <p class="font-semibold text-amber-100/70">પ્રસાદ / ચઢાવા માર્ગદર્શિકા</p>
                    <p class="text-sm text-amber-100/40 mt-1">પ્રસાદ અને ચઢાવા તરીકે ફક્ત ટ્રસ્ટ-મંજૂર વસ્તુઓ જ ચઢાવવી. માંસાહારી વસ્તુઓ માન્ય નથી.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">9</span>
                <div>
                    <p class="font-semibold text-amber-100/70">કતાર પાળો</p>
                    <p class="text-sm text-amber-100/40 mt-1">દર્શન સમયે કતારમાં ઊભા રહો જેથી સૌને સમાન દર્શન મળે. કતારમાં વચ્ચેથી ઘૂસવું પ્રતિબંધિત છે.</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">10</span>
                <div>
                    <p class="font-semibold text-amber-100/70">ઉત્સવ / તહેવારના વિશેષ નિયમો</p>
                    <p class="text-sm text-amber-100/40 mt-1">ઉત્સવ અથવા વિશેષ પૂજાના દિવસોમાં ટ્રસ્ટના અનુશાસનનું પાલન કરો. સેવક અને સ્વયંસેવકોની સૂચનાઓ ફરજિયાત છે.</p>
                </div>
            </li>

        </ol>

        <div class="mt-10 bg-amber-900/20 border border-amber-800/30 rounded-xl p-5">
            <p class="text-sm text-amber-100/50">
                નિયમો અંગે ટ્રસ્ટનો નિર્ણય આખરી રહેશે.
                કોઈ પ્રશ્ન માટે <a href="{{ route('contact') }}" class="text-amber-500 hover:text-gold underline font-semibold transition">સંપર્ક</a> કરો.
            </p>
        </div>

    </div>
</div>

@endsection
