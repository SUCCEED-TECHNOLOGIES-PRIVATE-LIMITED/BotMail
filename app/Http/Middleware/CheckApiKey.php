<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.botmail.key');
        $given = (string) $request->header('X-API-Key', '');

        if (! is_string($configured) || $configured === '' || ! hash_equals($configured, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
