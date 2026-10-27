<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Tags every request so a user-visible error code can be matched to the log line. */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id, 'user_id' => $request->user()?->id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        return $response;
    }
}
