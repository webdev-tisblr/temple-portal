<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $devotee = Auth::guard('devotee')->user();

        if ($devotee && empty($devotee->name)) {
            return redirect()->route('profile.complete');
        }

        return $next($request);
    }
}
