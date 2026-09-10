<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateInternalWorker
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.torrent_worker.secret');
        $provided = $request->bearerToken();

        if (! $expected || ! $provided || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid worker token.');
        }

        return $next($request);
    }
}
