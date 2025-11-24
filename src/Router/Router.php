<?php namespace Rackage\Router;

/**
 * Application Router
 * 
 * Handles HTTP request routing, controller resolution, and dispatch.
 * This class orchestrates the entire request lifecycle from URL parsing
 * to controller execution with filters.
 * 
 * Responsibilities:
 *   - Load and parse route definitions
 *   - Parse incoming request URLs
 *   - Match URLs to defined routes
 *   - Resolve controller and action
 *   - Execute @before and @after filters
 *   - Dispatch controller method with parameters
 *   - Handle routing errors
 * 
 * Architecture:
 *   This class is part of Rackage (the engine) and is updated via Composer.
 *   It uses dependency injection for testability.
 * 
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Routes
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.0
 */

use Rackage\Registry;
use Rackage\Cache;
use Rackage\Utilities\UrlParser;
use Rackage\Utilities\Inspector;
use ReflectionClass;

class Router {

	/**
	 * Application settings from Registry
	 * @var array
	 */
	private $settings;

	/**
	 * Route definitions from routes.php
	 * @var array
	 */
	private $routes;

	/**
	 * URL parser instance
	 * @var UrlParser
	 */
	private $urlParser;

	/**
	 * Route parser instance
	 * @var RouteParser
	 */
	private $routeParser;

	/**
	 * Resolved controller name
	 * @var string
	 */
	private $controller;

	/**
	 * Resolved action/method name
	 * @var string
	 */
	private $action;

	/**
	 * Method parameters
	 * @var array
	 */
	private $parameters = array();

	/**
	 * Constructor - Initialize router with dependencies
	 * 
	 * @param array $settings Application settings
	 * @param array $routes Route definitions
	 * @param UrlParser|null $urlParser Optional URL parser (for testing)
	 */
	public function __construct($settings, $routes, $urlParser = null)
	{
		$this->settings = $settings;
		$this->routes = $routes;
		$this->urlParser = $urlParser;
	}

	/**
	 * Main dispatch method - handles the entire request lifecycle
	 *
	 * Process:
	 * 1. Check if cached page exists and serve it
	 * 2. Parse the URL
	 * 3. Match against routes
	 * 4. Resolve controller and action
	 * 5. Validate controller and method exist
	 * 6. Execute filters
	 * 7. Dispatch controller method
	 *
	 * @return void
	 * @throws RouteException If routing fails
	 */
	public function dispatch()
	{
		try {
			// Check cache first - serve and exit if hit
			if ($this->checkCache())
			{
				return;
			}

			// Parse URL and resolve routing
			$this->parseUrl();
			$this->matchRoute();
			$this->resolveController();
			$this->resolveAction();

			// Validate and dispatch
			$this->validateController();
			$this->dispatchController();

		} catch (RouteException $e) {
			$e->errorShow();
		}
	}

	// ===========================================================================
	// CACHE HANDLING
	// ===========================================================================

	/**
	 * Check if cached page exists and serve it
	 *
	 * Determines if current request should be cached based on:
	 * - Cache enabled in config
	 * - HTTP request method (only GET/HEAD)
	 * - URL not in exclusion list
	 *
	 * Only sets Registry::setShouldCache(true) after cache miss,
	 * since that's when View will actually need the flag.
	 * If cached version exists, outputs it and returns true.
	 *
	 * @return bool True if cache served, false otherwise
	 */
	private function checkCache()
	{
		$cacheConfig = Registry::cache();

		// Cache disabled?
		if (!$cacheConfig['enabled'])
		{
			return false;
		}

		// Check HTTP method
		$requestMethod = $_SERVER['REQUEST_METHOD'];
		if (!in_array($requestMethod, $cacheConfig['methods']))
		{
			return false;
		}

		// Check URL exclusions
		$requestUri = Registry::url();
		foreach ($cacheConfig['exclude_urls'] as $pattern)
		{
			if ($this->urlMatches($requestUri, $pattern))
			{
				return false;
			}
		}

		// Try to get from cache
		$cacheKey = 'page:' . md5($requestUri);
		if (Cache::has($cacheKey))
		{
			echo Cache::get($cacheKey);
			return true;
		}

		// Cache miss - set flag so View knows to store rendered output
		Registry::setShouldCache(true);

		return false;
	}

	/**
	 * Check if URL matches exclusion pattern
	 *
	 * Supports exact matches and wildcard patterns:
	 * - '/admin' matches only '/admin'
	 * - '/admin/*' matches '/admin/users', '/admin/posts', etc.
	 *
	 * @param string $url URL to check
	 * @param string $pattern Pattern to match against
	 * @return bool True if URL matches pattern
	 */
	private function urlMatches($url, $pattern)
	{
		// Exact match
		if ($url === $pattern)
		{
			return true;
		}

		// Wildcard match: '/admin/*' matches '/admin/anything'
		if (strpos($pattern, '*') !== false)
		{
			$regex = '#^' . str_replace('*', '.*', preg_quote($pattern, '#')) . '$#';
			return preg_match($regex, $url) === 1;
		}

		return false;
	}

	// ===========================================================================
	// URL PARSING
	// ===========================================================================

	/**
	 * Parse the incoming URL into components
	 *
	 * Creates UrlParser instance and extracts controller, method, and parameters
	 * from the URL string.
	 *
	 * @return void
	 */
	private function parseUrl()
	{
		// Create URL parser if not injected (for testing)
		if ($this->urlParser === null) {
			$this->urlParser = new UrlParser(
				Registry::url(),
				$this->settings['url_separator']
			);
		}

		// Parse URL into controller, method, and parameters
		$this->urlParser->setController()
		                ->setMethod()
		                ->setParameters();
	}

	// ===========================================================================
	// ROUTE MATCHING
	// ===========================================================================

	/**
	 * Match URL against defined routes
	 * 
	 * Checks if the current URL matches any defined routes in routes.php.
	 * If a match is found, the RouteParser handles controller/action resolution.
	 * 
	 * @return void
	 */
	private function matchRoute()
	{
		// Create route parser instance
		$this->routeParser = new RouteParser(
			Registry::url(),
			$this->routes,
			$this->urlParser
		);

		// Check if URL matches a defined route
		if ($this->routeParser->matchRoute()) {
			// Route matched - let RouteParser set controller, method, params
			$this->routeParser->setController()
			                  ->setMethod()
			                  ->setParameters();
		} else {
			// No route matched - set parameters from URL parser
			$this->routeParser->setParameters();
		}
	}

	// ===========================================================================
	// CONTROLLER RESOLUTION
	// ===========================================================================

	/**
	 * Resolve the controller name
	 * 
	 * Determines which controller to use based on:
	 * 1. Matched route (if route was matched)
	 * 2. URL parsing (if no route matched)
	 * 3. Default controller (if no controller in URL)
	 * 
	 * @return void
	 */
	private function resolveController()
	{
		// Check if URL parser found a controller
		if ($this->urlParser->getController() !== null) {
			
			// Check if a route was matched
			if ($this->routeParser->matchRoute()) {
				// Use controller from matched route
				$this->controller = $this->routeParser->getController();
			} else {
				// Use controller from URL
				$this->controller = $this->urlParser->getController();
			}

		} else {
			// No controller in URL - use default
			$this->controller = $this->settings['default']['controller'];
		}
	}

	/**
	 * Resolve the action/method name
	 * 
	 * Determines which method to call based on:
	 * 1. Matched route (if route was matched)
	 * 2. URL parsing (if no route matched)
	 * 3. Default action (if no method in URL)
	 * 
	 * @return void
	 */
	private function resolveAction()
	{
		// Check if URL parser found a controller
		if ($this->urlParser->getController() !== null) {
			
			// Check if a route was matched
			if ($this->routeParser->matchRoute()) {
				// Use action from matched route or default
				$this->action = $this->routeParser->getMethod() 
				             ?: $this->settings['default']['action'];
			} else {
				// Use action from URL or default
				$this->action = $this->urlParser->getMethod() 
				             ?: $this->settings['default']['action'];
			}

		} else {
			// No controller in URL - use default action
			$this->action = $this->settings['default']['action'];
		}

		// Get parameters from route parser
		$this->parameters = $this->routeParser->getParameters();
	}

	// ===========================================================================
	// CONTROLLER VALIDATION & DISPATCH
	// ===========================================================================

	/**
	 * Validate that controller class and method exist
	 * 
	 * Checks:
	 * 1. Controller class exists
	 * 2. Controller extends base controller
	 * 3. Method exists on controller
	 * 4. Method is callable
	 * 
	 * @return void
	 * @throws RouteException If validation fails
	 */
	private function validateController()
	{
		// Build namespaced controller class name
		$controllerClass = 'Controllers\\' . ucwords($this->controller) . 'Controller';

		// Check if controller class exists
		if (!class_exists($controllerClass)) {

			// Check if catch-all routing is enabled
			if (isset(Registry::settings()['routing']['catch_all']) &&
			    Registry::settings()['routing']['catch_all'] === true) {

				// Use catch-all controller instead of throwing error
				$this->controller = Registry::settings()['routing']['ca_controller'];
				$this->action = Registry::settings()['routing']['ca_method'];

				// Pass full URL as first parameter to catch-all method
				$this->parameters = array(Registry::url());

				// Rebuild controller class name and validate catch-all controller exists
				$controllerClass = 'Controllers\\' . ucwords($this->controller) . 'Controller';
				if (!class_exists($controllerClass)) {
					throw new RouteException(
						"Catch-all controller '{$controllerClass}' is not defined"
					);
				}
			} else {
				// Catch-all disabled - throw original error
				throw new RouteException(
					"Controller class '{$controllerClass}' is not defined"
				);
			}
		}

		// Store the full controller class name
		$this->controller = $controllerClass;

		// Check if method exists
		if (!method_exists($this->controller, $this->action)) {
			
			// Try to call magic method to get action name
			$dispatch = new $this->controller;

			if (!$dispatch->{$this->action}()) {
				throw new RouteException(
					"Method '{$this->action}' does not exist on controller '{$this->controller}'"
				);
			}

			// Magic method returned action name
			$this->action = $dispatch->{$this->action}();
		}
	}

	/**
	 * Dispatch the controller method
	 * 
	 * Process:
	 * 1. Create controller instance
	 * 2. Validate controller extends base controller
	 * 3. Initialize controller properties
	 * 4. Get method reflection info
	 * 5. Prepare method parameters
	 * 6. Execute filters (if enabled)
	 * 7. Call controller method
	 * 
	 * @return void
	 * @throws RouteException If dispatch fails
	 */
	private function dispatchController()
	{
		// Create controller instance
		$dispatch = new $this->controller;

		// Ensure controller extends base controller
		if (!$dispatch instanceof \Rackage\Controller) {
			throw new RouteException(
				"Controller '{$this->controller}' must extend Rackage\\Controller"
			);
		}

		// Initialize controller properties
		$dispatch->_setRachieProperties();

		// Get reflection info for method parameter handling
		$reflection = new ReflectionClass($dispatch);
		$method = $reflection->getMethod($this->action);

		// Get expected parameter count
		$expectedParams = count($method->getParameters());

		// Pad parameters array if needed
		if ($expectedParams > count($this->parameters)) {
			$this->parameters = array_pad(
				$this->parameters,
				$expectedParams,
				null
			);
		}

		// Check if filters are enabled
		if ($dispatch->enable_method_filters === true) {
			// Execute with filters
			$this->dispatchWithFilters($dispatch, $reflection, $method);
		} else {
			// Execute without filters
			call_user_func_array(
				array($dispatch, $this->action),
				$this->parameters
			);
		}
	}

	// ===========================================================================
	// FILTER EXECUTION
	// ===========================================================================

	/**
	 * Dispatch controller method with @before and @after filters
	 * 
	 * Process:
	 * 1. Parse class-level filters
	 * 2. Parse method-level filters
	 * 3. Merge filters (class before, method before, method after, class after)
	 * 4. Execute @before filters
	 * 5. Execute controller method
	 * 6. Execute @after filters
	 * 
	 * @param object $dispatch Controller instance
	 * @param ReflectionClass $reflection Class reflection
	 * @param ReflectionMethod $method Method reflection
	 * @return void
	 * @throws RouteException If filter execution fails
	 */
	private function dispatchWithFilters($dispatch, $reflection, $method)
	{
		try {
			// Get class-level filters from docblock
			$classFilters = Inspector::checkFilter($reflection->getDocComment());

			// Get method-level filters from docblock
			$methodFilters = Inspector::checkFilter($method->getDocComment());

			// Merge filters in correct order
			$filters = $this->mergeFilters($classFilters, $methodFilters);

			// If no filters found, just execute method
			if ($filters === false || empty($filters)) {
				call_user_func_array(
					array($dispatch, $this->action),
					$this->parameters
				);
				return;
			}

			// Execute @before filters
			if (isset($filters['before'])) {
				$this->executeFilters($filters['before'], $dispatch, 'before');
			}

			// Execute controller method
			call_user_func_array(
				array($dispatch, $this->action),
				$this->parameters
			);

			// Execute @after filters
			if (isset($filters['after'])) {
				$this->executeFilters($filters['after'], $dispatch, 'after');
			}

		} catch (RouteException $e) {
			$e->errorShow();
		}
	}

	/**
	 * Merge class-level and method-level filters
	 * 
	 * Order:
	 * 1. Class @before filters (run first)
	 * 2. Method @before filters
	 * 3. Method @after filters
	 * 4. Class @after filters (run last)
	 * 
	 * @param array|false $classFilters Class-level filters
	 * @param array|false $methodFilters Method-level filters
	 * @return array|false Merged filters or false if none
	 */
	private function mergeFilters($classFilters, $methodFilters)
	{
		// If no filters at all, return false
		if ($classFilters === false && $methodFilters === false) {
			return false;
		}

		// Initialize merged filters array
		$merged = array(
			'before' => array(),
			'after' => array()
		);

		// Add class-level @before filters first
		if ($classFilters && isset($classFilters['before'])) {
			$merged['before'] = array_merge(
				$merged['before'],
				$classFilters['before']
			);
		}

		// Add method-level @before filters
		if ($methodFilters && isset($methodFilters['before'])) {
			$merged['before'] = array_merge(
				$merged['before'],
				$methodFilters['before']
			);
		}

		// Add method-level @after filters first
		if ($methodFilters && isset($methodFilters['after'])) {
			$merged['after'] = array_merge(
				$merged['after'],
				$methodFilters['after']
			);
		}

		// Add class-level @after filters last
		if ($classFilters && isset($classFilters['after'])) {
			$merged['after'] = array_merge(
				$merged['after'],
				$classFilters['after']
			);
		}

		return $merged;
	}

	/**
	 * Execute a list of filters
	 * 
	 * Filters can be:
	 * 1. Single method name: ['checkAuth'] -> calls $dispatch->checkAuth()
	 * 2. Class and method: ['AuthFilter', 'check'] -> calls (new AuthFilter())->check()
	 * 
	 * @param array $filters List of filters to execute
	 * @param object $dispatch Controller instance
	 * @param string $type Filter type ('before' or 'after') for error messages
	 * @return void
	 * @throws RouteException If filter validation fails
	 */
	private function executeFilters($filters, $dispatch, $type)
	{
		foreach ($filters as $filter) {
			
			// Determine filter type by array count
			switch (count($filter)) {
				
				// Single element: method on current controller
				case 1:
					$filterMethod = $filter[0];

					// Validate method exists
					if (!method_exists($dispatch, $filterMethod)) {
						throw new RouteException(
							"@{$type} filter method '{$filterMethod}' does not exist on controller '{$this->controller}'"
						);
					}

					// Execute filter
					$dispatch->$filterMethod();
					break;

				// Two elements: external class and method
				case 2:
					$filterClass = $filter[0];
					$filterMethod = $filter[1];

					// Validate class exists
					if (!class_exists($filterClass)) {
						throw new RouteException(
							"@{$type} filter class '{$filterClass}' does not exist"
						);
					}

					// Validate method exists
					if (!method_exists($filterClass, $filterMethod)) {
						throw new RouteException(
							"@{$type} filter method '{$filterMethod}' does not exist on class '{$filterClass}'"
						);
					}

					// Execute filter
					(new $filterClass())->$filterMethod();
					break;
			}
		}
	}

	// ===========================================================================
	// GETTERS (For Testing)
	// ===========================================================================

	/**
	 * Get resolved controller name (for testing)
	 * @return string
	 */
	public function getController()
	{
		return $this->controller;
	}

	/**
	 * Get resolved action name (for testing)
	 * @return string
	 */
	public function getAction()
	{
		return $this->action;
	}

	/**
	 * Get method parameters (for testing)
	 * @return array
	 */
	public function getParameters()
	{
		return $this->parameters;
	}
}