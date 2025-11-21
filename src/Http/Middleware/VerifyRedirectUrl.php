<?php

declare(strict_types=1);

namespace Zen\Obsidian\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VerifyRedirectUrl
{
  /**
   * Handle the incoming request.
   *
   *
   * @throws AccessDeniedHttpException
   */
  public function handle(Request $request, Closure $next): mixed
  {
    /** @var mixed $redirect */
    $redirect = $request->get('redirect');

    if (! $redirect) {
      return $next($request);
    }

    if (is_string($redirect)) {
      $redirectString = $redirect;
    } else {
      /** @phpstan-ignore cast.string */
      $redirectString = (string) $redirect;
    }

    /** @var array<string, mixed>|false $url */
    $url = parse_url($redirectString);

    throw_if(is_array($url) && isset($url['host']) && $url['host'] !== $request->getHost(), AccessDeniedHttpException::class, 'Redirect host mismatch.');

    return $next($request);
  }
}
