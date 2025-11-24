<?php namespace Rackage\Router;

/**
 * Route Parser
 *
 * Parses route definitions and maps URLs to controllers/actions.
 * This class handles the complex logic of matching incoming URLs against
 * defined routes and extracting controller, method, and parameter information.
 *
 * Responsibilities:
 *   - Match URL against defined routes
 *   - Parse route metadata (format: "Controller@method/param1/param2")
 *   - Extract controller and method names from routes
 *   - Map URL segments to named parameters
 *   - Inject parameters into Input class for global access
 *
 * Route Format:
 *   Routes are defined as: 'routeName' => 'Controller@method/param1/param2'
 *   - routeName: The URL path to match (e.g., 'user', 'api/posts')
 *   - Controller: The controller class to dispatch to
 *   - method: The controller method to call
 *   - param1/param2: Named parameters extracted from URL
 *
 * Example:
 *   Route: 'user' => 'User@show/id/action'
 *   URL: /user/123/edit
 *   Result: UserController->show($id='123', $action='edit')
 *
 * Architecture:
 *   This class works with UrlParser which handles initial URL parsing.
 *   RouteParser adds the routing layer on top, mapping friendly route names
 *   to actual controllers and extracting named parameters.
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Routes
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.1
 */

use Rackage\Utilities\UrlParser;
use Rackage\Routes\RouteException;
use Rackage\Arr;
use Rackage\Input;

class RouteParser {

	/**
	 * Route pattern delimiter (separates controller from method)
	 * @var string
	 */
	protected $pattern = '@';

	/**
	 * URL parameter separator (separates method from parameters)
	 * @var string
	 */
	protected $separator = '/';

	/**
	 * Route metadata string (e.g., "User@show/id/action")
	 * @var string
	 */
	protected $routeData;

	/**
	 * Method metadata string (e.g., "show/id/action")
	 * @var string
	 */
	protected $methodData;

	/**
	 * Parsed method metadata array (e.g., ['show', 'id', 'action'])
	 * Contains method name and parameter names from route definition
	 * @var array
	 */
	protected $methodArray = array();

	/**
	 * Resolved controller name
	 * @var string
	 */
	protected $controller = null;

	/**
	 * Resolved method name
	 * @var string
	 */
	protected $method = null;

	/**
	 * URL parameters (can be numeric or associative array)
	 * @var array
	 */
	protected $parameters = array();

	/**
	 * Current URL string being parsed
	 * @var string
	 */
	private $url;

	/**
	 * Defined routes from routes.php
	 * @var array
	 */
	protected $routes = array();

	/**
	 * URL parser instance
	 * @var UrlParser
	 */
	protected $urlParser;

	/**
	 * Matched route name
	 * @var string
	 */
	protected $route;

	/**
	 * Pattern match flag - true if route matched via wildcard pattern
	 * @var bool
	 */
	protected $isPatternMatch = false;

	/**
	 * Wildcard value captured from pattern route (everything after prefix/)
	 * @var string|null
	 */
	protected $wildcardValue = null;

	/**
	 * Constructor - Initialize route parser with dependencies
	 *
	 * @param string $url The URL request string to parse
	 * @param array $routes Defined routes array
	 * @param UrlParser $urlParser URL parser instance
	 */
	public function __construct($url, array $routes, UrlParser $urlParser)
	{
		$this->url = $url;
		$this->routes = $routes;
		$this->urlParser = $urlParser;
	}

	// ===========================================================================
	// ROUTE MATCHING
	// ===========================================================================

	/**
	 * Check if URL matches a defined route
	 *
	 * Attempts to match the first segment of the URL against defined routes.
	 * If a match is found, stores the route name and metadata for later processing.
	 *
	 * Process:
	 * 1. Check if URL has a controller segment
	 * 2. Look up controller segment in routes array
	 * 3. If found, store route name and metadata
	 *
	 * Example:
	 *   URL: /user/123/edit
	 *   Routes: ['user' => 'User@show/id/action', 'api' => 'Api@index']
	 *   Result: Matches 'user', stores route name and metadata
	 *
	 * @return bool True if route matched, false otherwise
	 */
	public function matchRoute()
	{
		// URL must have at least a controller segment
		if ($this->urlParser->getController() === null) {
			return false;
		}

		// Check if first URL segment matches a defined route name
		$controllerSegment = $this->urlParser->getController();
		$routeMatch = Arr::exists($controllerSegment, $this->routes);

		if ($routeMatch) {
			// Store matched route for later reference
			$this->route = $controllerSegment;

			// Store route metadata (e.g., "User@show/id/action")
			$this->routeData = $this->routes[$controllerSegment];

			return true;
		}

		// No exact match - try pattern matching (wildcard routes)
		return $this->matchPatternRoute();
	}

	/**
	 * Check if URL matches a pattern route (wildcard matching)
	 *
	 * Checks routes ending with /* for wildcard pattern matching.
	 * Wildcard captures everything after the prefix and passes it as a parameter.
	 *
	 * Process:
	 * 1. Loop through all routes
	 * 2. Find routes ending with /*
	 * 3. Extract prefix (part before /*)
	 * 4. Check if URL starts with prefix/
	 * 5. If match, capture everything after prefix/ as wildcard value
	 *
	 * Example:
	 *   Route: 'blog/*' => 'Blog@show/slug'
	 *   URL: /blog/my-awesome-post
	 *   Prefix: 'blog'
	 *   Wildcard: 'my-awesome-post'
	 *   Result: BlogController::show($slug='my-awesome-post')
	 *
	 * @return bool True if pattern matched, false otherwise
	 */
	protected function matchPatternRoute()
	{
		// Loop through all routes looking for wildcard patterns
		foreach ($this->routes as $routeKey => $routeValue) {

			// Check if route ends with /*
			if (substr($routeKey, -2) === '/*') {

				// Extract prefix (remove the /*)
				$prefix = substr($routeKey, 0, -2);

				// Check if URL starts with prefix/
				// Using strpos for exact prefix match at start of string
				if (strpos($this->url, $prefix . '/') === 0) {

					// Capture everything after prefix/ as wildcard value
					$this->wildcardValue = substr($this->url, strlen($prefix) + 1);

					// Store matched route info
					$this->route = $routeKey;
					$this->routeData = $routeValue;
					$this->isPatternMatch = true;

					return true;
				}
			}
		}

		// No pattern matched
		return false;
	}

	// ===========================================================================
	// CONTROLLER RESOLUTION
	// ===========================================================================

	/**
	 * Set controller from route metadata
	 *
	 * Parses the route metadata to extract the controller name.
	 * Route metadata format: "Controller@method/param1/param2"
	 * This method extracts "Controller" and stores "method/param1/param2" for later.
	 *
	 * Process:
	 * 1. Split route metadata by '@' pattern
	 * 2. First part is controller, second part is method metadata
	 * 3. Validate controller exists
	 *
	 * Example:
	 *   Route data: "User@show/id/action"
	 *   Result: controller='User', methodData='show/id/action'
	 *
	 * @return RouteParser
	 * @throws RouteException If controller not defined in route
	 */
	public function setController()
	{
		// Parse route metadata: "Controller@method/params" -> ['Controller', 'method/params']
		$routeDataArray = Arr::parts($this->pattern, $this->routeData)
		                      ->clean()
		                      ->trim()
		                      ->get();

		// Validate controller exists in route definition
		if (!Arr::exists(0, $routeDataArray) || empty($routeDataArray[0])) {
			throw new RouteException("No controller associated with route: {$this->route}");
		}

		// Extract controller name
		$this->controller = $routeDataArray[0];

		// Store method metadata for later parsing (if exists)
		$this->methodData = (count($routeDataArray) > 1) ? $routeDataArray[1] : null;

		return $this;
	}

	/**
	 * Get resolved controller name
	 *
	 * @return string|null Controller name or null if not set
	 */
	public function getController()
	{
		return $this->controller;
	}

	// ===========================================================================
	// METHOD RESOLUTION
	// ===========================================================================

	/**
	 * Set method from route metadata or URL
	 *
	 * Handles two scenarios:
	 * 1. Route has no method metadata - extract method from URL
	 * 2. Route has method metadata - parse it for method name and parameter names
	 *
	 * Process:
	 * - If no method metadata:
	 *   1. Check if controller has embedded parameters (e.g., "user/id")
	 *   2. Extract controller and parameter names if present
	 *   3. Fall back to URL parser for method name
	 *
	 * - If method metadata exists:
	 *   1. Parse metadata string (e.g., "show/id/action" -> ['show', 'id', 'action'])
	 *   2. First element is method name, rest are parameter names
	 *   3. If URL also has a method, treat it as first parameter value
	 *
	 * Example flows:
	 *   Route: 'user' => 'User@show/id/action', URL: /user/123/edit
	 *   → method='show', methodArray=['show', 'id', 'action']
	 *
	 *   Route: 'api' => 'Api', URL: /api/users/list
	 *   → method='users' (from URL)
	 *
	 * @return RouteParser
	 * @throws RouteException If method format is invalid
	 */
	public function setMethod()
	{
		// Case 1: No method metadata - extract from URL
		if ($this->methodData === null) {

			// Check if controller name has embedded parameters
			// Example: controller might be "user/id/action"
			$keys = Arr::parts($this->separator, $this->controller)
			            ->clean()
			            ->trim()
			            ->get();

			if (count($keys) > 1) {
				// Controller has embedded params - extract them
				$this->controller = $keys[0];
				$this->methodArray = $keys;
			}

			// Get method from URL parser (e.g., /controller/method)
			$this->method = $this->urlParser->getMethod();

			return $this;
		}

		// Case 2: Method metadata exists - parse it
		// Format: "show/id/action" -> ['show', 'id', 'action']
		$methodDataArray = Arr::parts($this->separator, $this->methodData)
		                       ->clean()
		                       ->trim()
		                       ->get();

		// Validate we got at least a method name
		if (empty($methodDataArray)) {
			throw new RouteException(
				"Invalid method format for route '{$this->route}': {$this->controller}@{$this->methodData}"
			);
		}

		// First element is method name
		$this->method = $methodDataArray[0];

		// Store full array including parameter names for later mapping
		$this->methodArray = $methodDataArray;

		// Handle edge case: URL has a method segment when route already defines method
		// Example: Route 'user'=>'User@show', URL '/user/edit' - 'edit' becomes a parameter
		if ($this->urlParser->getMethod() !== null) {
			// Prepend URL method as first parameter value
			$this->urlParser->setParameters($this->urlParser->getMethod(), false);
		}

		return $this;
	}

	/**
	 * Get resolved method name
	 *
	 * @return string|null Method name or null if not set
	 */
	public function getMethod()
	{
		return $this->method;
	}

	// ===========================================================================
	// PARAMETER RESOLUTION
	// ===========================================================================

	/**
	 * Set parameters from URL and inject into Input class
	 *
	 * Extracts URL parameters and maps them to named parameters if route
	 * definition includes parameter names. Then injects them into the Input
	 * class for global access via Input::get() or Input::url().
	 *
	 * Process:
	 * 1. Get parameter values from URL parser
	 * 2. If route defined parameter names, create associative array
	 * 3. Map parameter names to values
	 * 4. Inject into Input class for global access
	 *
	 * Parameter Mapping:
	 *   Route: 'user' => 'User@show/id/action'
	 *   URL: /user/123/edit
	 *   methodArray: ['show', 'id', 'action']
	 *   URL values: ['123', 'edit']
	 *   Result: ['id' => '123', 'action' => 'edit']
	 *
	 * Why inject into Input:
	 *   Controllers can access params both as method arguments AND via Input::get()
	 *   - Method args: public function show($id, $action) { }
	 *   - Input class: $id = Input::get('id') or Input::url('id')
	 *
	 * @return RouteParser
	 */
	public function setParameters()
	{
		// Get parameter values from URL (e.g., ['123', 'edit'])
		$values = $this->urlParser->getParameters();

		// If this is a pattern match, prepend the wildcard value as first parameter
		// Example: Route 'blog/*', URL '/blog/my-post' → wildcard='my-post'
		if ($this->isPatternMatch && $this->wildcardValue !== null) {
			array_unshift($values, $this->wildcardValue);
		}

		// Check if route defined parameter names
		// methodArray[0] is method name, rest are parameter names
		if (count($this->methodArray) > 1) {

			// Extract parameter names (skip first element which is method name)
			$paramNames = array_slice($this->methodArray, 1);

			// Map parameter names to values if we have enough values
			if (count($values) >= count($paramNames)) {
				// Create associative array: ['id' => '123', 'action' => 'edit']
				$mapped = array_combine(
					$paramNames,
					array_slice($values, 0, count($paramNames))
				);

				// Merge with any extra unmapped parameters (keep as numeric)
				$extra = array_slice($values, count($paramNames));
				$this->parameters = array_merge($mapped, $extra);
			} else {
				// Not enough values for all parameter names - keep numeric
				$this->parameters = $values;
			}
		} else {
			// No parameter names defined - keep as numeric array
			$this->parameters = $values;
		}

		// Inject parameters into Input class for global access
		// Now controllers can use: Input::get('id') or Input::url('id')
		Input::setUrl($this->parameters);

		return $this;
	}

	/**
	 * Get URL parameters
	 *
	 * Returns either associative array (if route defined parameter names)
	 * or numeric array (if no parameter names defined).
	 *
	 * @return array Parameters array
	 */
	public function getParameters()
	{
		return $this->parameters;
	}

}
