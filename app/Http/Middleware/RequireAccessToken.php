<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal tokens: the API takes one as a Bearer token, the review screen as
 * the HTTP Basic password (any user name). The token says who is calling, so
 * a tender records who created it and a review who decided it — nobody types
 * a name.
 *
 * Until the first token is issued the service is open outside production, so
 * a fresh checkout works; in production it is closed until then.
 */
final class RequireAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = $request->bearerToken() ?? $request->getPassword();
        $token = is_string($given) && $given !== '' ? AccessToken::findActive($given) : null;

        if ($token !== null) {
            Auth::setUser($token->user);
            if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
                $token->forceFill(['last_used_at' => now()])->saveQuietly();
            }

            return $next($request);
        }

        if (! AccessToken::query()->exists()) {
            return app()->isProduction()
                ? $this->deny($request, 'No access tokens have been issued; the service is closed until one is (php artisan rfq:issue-token).')
                : $next($request);
        }

        return $this->deny($request, 'A valid access token is required.');
    }

    private function deny(Request $request, string $message): Response
    {
        return $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => $message], 401)
            : response($message, 401, ['WWW-Authenticate' => 'Basic realm="Fit-out RFQ"']);
    }
}
