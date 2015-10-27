<?php

/**
 * @file
 * Defines the base API server.
 */

namespace Fuzz\ApiServer\Routing;

use Fuzz\Data\Serialization\FuzzDataArraySerializer;
use Fuzz\Data\Serialization\FuzzModelTransformer;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Request;
use Illuminate\Pagination\AbstractPaginator;
use Fuzz\ApiServer\Exception\ConflictException;
use Fuzz\ApiServer\Exception\NotFoundException;
use Fuzz\ApiServer\Exception\ForbiddenException;
use Fuzz\ApiServer\Exception\BadRequestException;
use Fuzz\ApiServer\Exception\NotImplementedException;
use Illuminate\Routing\Controller as RoutingBaseController;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Base Controller class.
 */
abstract class Controller extends RoutingBaseController
{
	/**
	 * Parameter name for pagination controller: items per page.
	 *
	 * @var string
	 */
	const PAGINATION_PER_PAGE = 'per_page';

	/**
	 * Parameter name for pagination controller: current page.
	 *
	 * @var string
	 */
	const PAGINATION_CURRENT_PAGE = 'page';

	/**
	 * Default items per page.
	 *
	 * @var int
	 */
	const PAGINATION_PER_PAGE_DEFAULT = 10;

	/**
	 * Maximum items per page.
	 *
	 * @var int
	 */
	const PAGINATION_PER_PAGE_MAXIMUM = 50;

	/**
	 * Produce a responder for sending responses.
	 *
	 * @return Responder
	 */
	protected function getResponder()
	{
		return new Responder;
	}

	/**
	 * Success!
	 *
	 * @param mixed $data
	 * @param int   $status_code
	 * @param array $headers
	 *
	 * @return \Illuminate\Http\JsonResponse
	 */
	protected function succeed($data, $status_code = Response::HTTP_OK, $headers = [])
	{
		// Append pagination data automatically
		if ($data instanceof AbstractPaginator) {
			$pagination = $this->getPagination($data);
			$data       = $data->getCollection();

			return $this->getResponder()->send(compact('data', 'pagination'), $status_code, $headers);
		}

		return $this->getResponder()->send($data, $status_code, $headers);
	}

	/**
	 * Created!
	 *
	 * @param mixed $data
	 * @param array $headers
	 *
	 * @return \Illuminate\Http\JsonResponse
	 */
	protected function created($data, $headers = [])
	{
		return $this->succeed($data, Response::HTTP_CREATED, $headers);
	}

	/**
	 * Object not found.
	 *
	 * @param string $message
	 * @param mixed  $data
	 * @throws \Fuzz\ApiServer\Exception\NotFoundException
	 * @return void
	 */
	protected function notFound($message = null, $data = null)
	{
		throw new NotFoundException($message, $data);
	}

	/**
	 * Access denied.
	 *
	 * @param string $message
	 * @param mixed  $data
	 * @throws \Fuzz\ApiServer\Exception\ForbiddenException
	 * @return void
	 */
	protected function forbidden($message = null, $data = null)
	{
		throw new ForbiddenException($message, $data);
	}

	/**
	 * Bad request.
	 *
	 * @param string $message
	 * @throws \Fuzz\ApiServer\Exception\BadRequestException
	 * @return void
	 */
	protected function badRequest($message = null, $data = null)
	{
		throw new BadRequestException($message, $data);
	}

	/**
	 * Not implemented.
	 *
	 * @param string $message
	 * @throws \Fuzz\ApiServer\Exception\NotImplementedException
	 * @return void
	 */
	protected function notImplemented($message = null, $data = null)
	{
		throw new NotImplementedException($message, $data);
	}

	/**
	 * Conflict
	 *
	 * @param string $message
	 * @param string $data
	 * @throws \Fuzz\ApiServer\Exception\ConflictException
	 * @return void
	 */
	protected function conflict($message = null, $data = null)
	{
		throw new ConflictException($message, $data);
	}

	/**
	 * Inform caller about available methods.
	 *
	 * @param array $valid_methods
	 * @return \Illuminate\Http\JsonResponse
	 */
	final private function expectMethods(array $valid_methods)
	{
		return $this->getResponder()->send(
			[
				'error'      => 'method_not_allowed',
				'error_data' => compact('valid_methods'),
			], 405, [
				'Allow' => implode(', ', $valid_methods),
			]
		);
	}

	/**
	 * API calls without a routed string will resolve to the base controller.
	 * This method catches all of them and notifies the caller of failure.
	 *
	 * @param array $parameters
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function missingMethod($parameters = [])
	{
		// Check if there are valid methods that could have been used
		$url_parts = parse_url(app('request')->getRequestUri());
		$uri       = $url_parts['path'];

		$valid_methods = [];
		$request       = Request::instance();

		foreach (Route::getRoutes() as $route) {
			if (// Ignore catch-all routes
				! strpos($route->getActionName(), '@any')
				&& // Ignore "method missing" routes
				! strpos($route->getActionName(), '@missing')
				&& // Catch only routes with URI regex strings catching the current request URI
				preg_match($route->bind($request)->getCompiled()->getRegex(), $uri)
			) {
				$valid_methods = array_merge($valid_methods, array_map('strtoupper', $route->methods()));
			}
		}

		// If there are valid methods available, let the client know
		if (count($valid_methods) !== 0) {
			return $this->expectMethods($valid_methods);
		}

		// Otherwise, this is a simple 404
		$this->notFound();
	}

	/**
	 * Returns the value of the pagination "per page" parameter.
	 *
	 * @return int
	 */
	public static function getPerPage($default = self::PAGINATION_PER_PAGE_DEFAULT)
	{
		return min((int) Input::get(static::PAGINATION_PER_PAGE, $default), self::PAGINATION_PER_PAGE_MAXIMUM);
	}

	/**
	 * Get pagination metadata from a Paginator instance.
	 *
	 * @param  AbstractPaginator $paginator
	 * @return array
	 */
	final private function getPagination(AbstractPaginator $paginator)
	{
		// Pass in any additional query variables
		foreach (
			array_except(
				Request::instance()->query->all(), [
					self::PAGINATION_CURRENT_PAGE,
					self::PAGINATION_PER_PAGE
				]
			) as $key => $value
		) {
			$paginator->addQuery($key, $value);
		}

		// Add our "per page" pagination parameter to the constructed URLs
		$paginator->addQuery(self::PAGINATION_PER_PAGE, $paginator->perPage());

		// Prepare useful pagination metadata
		return [
			'page'     => $paginator->currentPage(),
			'total'    => $paginator->total(),
			'per_page' => $paginator->perPage(),
			'next'     => $paginator->nextPageUrl(),
			'previous' => $paginator->previousPageUrl(),
		];
	}

	/**
	 * Require a set of parameters.
	 *
	 * @return array
	 * @throws BadRequestException
	 * @todo reimplement as validation middleware
	 */
	protected function requireParameters()
	{
		$passed_parameters = [];
		$missing_required  = [];

		foreach (func_get_args() as $parameter_name) {
			if (! Input::has($parameter_name)) {
				$missing_required[] = $parameter_name;
			}

			$passed_parameters[] = Input::get($parameter_name);
		}

		if (count($missing_required) !== 0) {
			$this->badRequest('Required fields were not provided.', compact('missing_required'));
		}

		return $passed_parameters;
	}

	/**
	 * Suggest a set of parameters.
	 *
	 * @return array
	 * @todo reimplement as validation middleware
	 */
	protected function suggestParameters()
	{
		$passed_parameters = [];

		foreach (func_get_args() as $parameter_name) {
			$passed_parameters[] = Input::get($parameter_name, null);
		}

		return $passed_parameters;
	}

	/**
	 * Read an array parameter.
	 *
	 * @return array
	 */
	protected function readArrayParameter($parameter_name)
	{
		return array_values(array_filter((array) Input::get($parameter_name)));
	}

	/**
	 * Get an instance of the serialization manager
	 *
	 * @return \League\Fractal\Manager
	 */
	public function serializerManager()
	{
		$manager = new Manager;
		$serializer_class = config('view.data_serializer', FuzzDataArraySerializer::class);
		$manager->setSerializer(new $serializer_class);
		return $manager;
	}

	/**
	 * Serialize a collection of resources
	 *
	 * @param \Illuminate\Pagination\AbstractPaginator|array $data
	 * @param string|callable $transformer
	 * @return array
	 */
	public function serializeCollection($data, $transformer = FuzzModelTransformer::class)
	{
		$manager = $this->serializerManager();

		$collection = $data;

		// Transformers can be a class namespace string or a callable closure
		$transformer = is_callable($transformer) ? $transformer : new $transformer;

		if ($data instanceof AbstractPaginator) {
			$collection = $data->getCollection();
			$resource = new Collection($collection, $transformer, Route::currentRouteName());
			$resource->setPaginator(new IlluminatePaginatorAdapter($data));
		} else {
			$resource = new Collection($collection, $transformer, Route::currentRouteName());
		}

		return $manager->createData($resource)->toArray();
	}

	/**
	 * Serialize a single resource
	 *
	 * @param \Fuzz\MagicBox\Contracts\Repository|array $data
	 * @param string|callable $transformer
	 * @return array
	 */
	public function serialize($data, $transformer = FuzzModelTransformer::class)
	{
		$manager = $this->serializerManager();

		// Transformers can be a class namespace string or a callable closure
		$transformer = is_callable($transformer) ? $transformer : new $transformer;

		$resource = new Item($data, $transformer, Route::currentRouteName());

		return $manager->createData($resource)->toArray();
	}
}
