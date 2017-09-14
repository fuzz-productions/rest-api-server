<?php

namespace Fuzz\ApiServer\Throttling;

use Closure;
use Fuzz\ApiServer\Throttling\Contracts\Throttler;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\InvalidRequestException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class TokenPerRouteThrottler
 *
 * TokenPerRouteThrottler throttles by a token string, URI, and method.
 *
 * @package Fuzz\ApiServer\Throttling
 */
class TokenPerRouteThrottler extends TokenThrottler implements Throttler
{
	/**
	 * Throttle type key
	 *
	 * @const string
	 */
	const THROTTLE_TYPE = 'token';
	const TOKEN_HEADER  = 'Authorization';

	/**
	 * Throttle a request
	 *
	 * @param \Illuminate\Http\Request $request
	 * @param \Closure                 $next
	 * @param int                      $max_attempts
	 * @param int                      $decay_minutes
	 * @param string                   $token_header
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \League\OAuth2\Server\Exception\InvalidRequestException
	 */
	public function handle(Request $request, Closure $next, int $max_attempts = 60, int $decay_minutes = 1, string $token_header = self::TOKEN_HEADER): Response
	{
		$key = implode(':', [
			$request->getRequestUri(),
			$request->method(),
			$this->getTokenFromRequest($request, $token_header),
		]);

		$headers = self::assertThrottle($key, $max_attempts, $decay_minutes);

		$response = $next($request);

		$response->headers->add($headers);

		return $response;
	}
}