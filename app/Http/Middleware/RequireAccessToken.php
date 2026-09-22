<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One shared secret for the API (Bearer) and the review screen (HTTP Basic,
 * any user name, the token as password). Enough to keep tenders — client
 * documents — off the open internet; not a user system: there is one key,
 * no tenants and no per-person audit yet.
 *
 * With no token configured the app is open outside production and closed in
 * production, so a missing secret fails safe.
 */
final class RequireAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('rfq.access_token');

        if (! is_string($token) || $token === '') {
            return app()->isProduction()
                ? $this->deny($request, 'RFQ_ACCESS_TOKEN is not set; the service is closed until it is.')
                : $next($request);
        }

        $given = $request->bearerToken() ?? $request->getPassword();
        if (! is_string($given) || ! hash_equals($token, $given)) {
            return $this->deny($request, 'A valid access token is required.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $message): Response
    {
        return $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => $message], 401)
            : response($message, 401, ['WWW-Authenticate' => 'Basic realm="Fit-out RFQ"']);
    }
}
