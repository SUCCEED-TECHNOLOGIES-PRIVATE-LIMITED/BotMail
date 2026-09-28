<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBearerKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.botmail.key');
        $authorization = (string) $request->header('Authorization', '');
        $given = '';

        if (preg_match('/^Bearer\s+(\S+)/i', $authorization, $matches) === 1) {
            $given = $matches[1];
        }

        if (! is_string($configured) || $configured === '' || ! hash_equals($configured, $given)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
