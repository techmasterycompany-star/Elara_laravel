<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('lang');

        if (! is_string($locale) || ! in_array($locale, self::SUPPORTED, true)) {
            $locale = $request->getPreferredLanguage(self::SUPPORTED);
        }

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = config('app.fallback_locale');
        }

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}