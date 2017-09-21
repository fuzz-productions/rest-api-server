<?php

namespace Fuzz\ApiServer\Exceptions;

use Exception;
use Fuzz\HttpException\HttpException;
use Fuzz\HttpException\NotFoundHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use League\OAuth2\Server\Exception\OAuthException;
use Symfony\Component\HttpKernel\Exception\HttpException as SymfonyHttpException;

class Handler extends ExceptionHandler
{
	/**
	 * Render a JSON HTTP response based on the exception.
	 *
	 * @param \Illuminate\Http\Request $request
	 * @param Exception                $err
	 *
	 * @return JsonResponse
	 */
	public function render($request, Exception $err): JsonResponse
	{
		$httpErr = $this->toHttpException($err);

		return $this->toJsonResponse($httpErr);
	}

	/**
	 * Given an HttpException return a JSON response.
	 *
	 * @param HttpException $err
	 *
	 * @return JsonResponse
	 */
	protected function toJsonResponse(HttpException $err): JsonResponse
	{
		return new JsonResponse($this->getResponseDataFromException($err), $err->getStatusCode(), $err->getHttpHeaders());
	}

	/**
	 * Render an exception into a response.
	 *
	 * @param HttpException $err
	 *
	 * @return array
	 */
	protected function getResponseDataFromException(HttpException $err): array
	{
		$message = [
			'error'             => $err->getError(),
			'error_description' => $err->getErrorDescription(),
			'error_data'        => $err->getErrorData(),
			'user_title'        => $err->getUserTitle(),
			'user_message'      => $err->getUserMessage(),
		];

		// Append a stack trace if debug is true.
		if ($this->appInDebug()) {
			$trace = $err->getPrevious() ?? $err;

			$message['debug'] = [
				'code'    => $trace->getCode(),
				'message' => $trace->getMessage(),
				'line'    => $trace->getLine(),
				'file'    => $trace->getFile(),
				'class'   => get_class($trace),
				'trace'   => explode("\n", str_replace(base_path(), '', $trace->getTraceAsString())),
			];
		}

		return $message;
	}

	/**
	 * Convert err to HttpException.
	 *
	 * @param mixed $err
	 *
	 * @return HttpException
	 */
	protected function toHttpException(Exception $err): HttpException
	{
		if ($err instanceof HttpException) {
			return $err;
		} elseIf ($err instanceof SymfonyHttpException) {
			return $this->convertSymfonyHttpException($err);
		} elseIf ($err instanceof OAuthException) {
			return $this->convertOAuthException($err);
		} elseif ($err instanceof ModelNotFoundException) {
			return $this->convertFromModelNotFound($err);
		}

		return $this->defaultHttpException();
	}

	/**
	 * Converts a Symfony HttpException into a Fuzz HttpException
	 *
	 * @param \Symfony\Component\HttpKernel\Exception\HttpException $err
	 *
	 * @return \Fuzz\HttpException\HttpException
	 */
	protected function convertSymfonyHttpException(SymfonyHttpException $err)
	{
		return new HttpException($err->getStatusCode(), $err->getMessage(), null, [], null, null, $err->getHeaders(), $err);
	}

	/**
	 * Converts a OAuthh Exception into a Fuzz HttpException
	 *
	 * @param \League\OAuth2\Server\Exception\OAuthException $err
	 *
	 * @return \Fuzz\HttpException\HttpException
	 */
	protected function convertOAuthException(OAuthException $err)
	{
		$error = snake_case(str_replace('Exception', '', class_basename($err)));

		return new HttpException($err->httpStatusCode, $error, $err->getMessage(), [], null, null, $err->getHttpHeaders(), $err);
	}

	/**
	 * Creates a 404 error when a model is not found.
	 *
	 * @param $err
	 *
	 * @return HttpException
	 */
	protected function convertFromModelNotFound(ModelNotFoundException $err): HttpException
	{
		$errorDescription = 'Unable to find ' . class_basename($err->getModel()) . '.';
		$errorData        = [
			'model' => $err->getModel(),
			'ids'   => $err->getIds(),
		];
		$userTitle        = 'Not Found!';
		$userMessage      = 'Sorry, seems we can\'t find what you\'re looking for.';

		return new NotFoundHttpException($errorDescription, $errorData, $userTitle, $userMessage, [], $err);
	}

	/**
	 * Create a generic HttpException.
	 *
	 * @return HttpException
	 */
	protected function defaultHttpException(): HttpException
	{
		return new HttpException();
	}

	/**
	 * Checks if the app is in debug mode.
	 *
	 * @return bool
	 */
	protected function appInDebug(): bool
	{
		return config('app.debug');
	}
}