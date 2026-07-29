<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('Accept-Language');

        if (in_array($locale, config('app.available_locales'))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
