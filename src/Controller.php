<?php namespace Rackage;

/**
 * Base Controller Class
 *
 * All application controllers extend this base class.
 * Provides HTTP verb routing, request timing, and framework property access.
 *
 * Usage:
 *
 *   In Application Controllers:
 *     <?php namespace Controllers;
 *
 *     use Rackage\Controller;
 *
 *     class HomeController extends Controller {
 *         public function getIndex() {
 *             // GET request handler
 *         }
 *
 *         public function postIndex() {
 *             // POST request handler
 *         }
 *     }
 *
 * HTTP Verb Routing:
 *   The __call() magic method enables automatic HTTP verb-based routing.
 *   Methods can be prefixed with HTTP verbs for RESTful routing:
 *
 *   - getMethodName()    Handles GET requests
 *   - postMethodName()   Handles POST requests
 *   - putMethodName()    Handles PUT requests
 *   - deleteMethodName() Handles DELETE requests
 *   - patchMethodName()  Handles PATCH requests
 *   - methodName()       Fallback for any HTTP method
 *
 * Available Properties:
 *   $this->request_start_time  Request start timestamp
 *   $this->site_title          Application title from config
 *   $this->settings            Quick access to all settings
 *
 * Available Methods:
 *   $this->_requestExecutionTime()  Get request execution time in seconds
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Controller
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.2
 */

use Rackage\Registry;

class Controller
{

    /**
     * Request start time (microtime)
     * @var float
     */
    public $request_start_time;

    /**
     * Site title from config/settings.php
     * @var string
     */
    public $site_title;

    /**
     * Application settings (quick access to Registry::settings())
     * @var array
     */
    public $settings;

    /**
     * Magic method for HTTP verb-based method routing
     *
     * Determines the request method (GET, POST, PUT, DELETE, PATCH) and composes
     * a method name by prefixing the requested method with the HTTP verb.
     *
     * Examples:
     *   GET /user/profile → getProfile()
     *   POST /user/profile → postProfile()
     *   PUT /user/profile → putProfile()
     *   DELETE /user/profile → deleteProfile()
     *   PATCH /user/profile → patchProfile()
     *
     * If the prefixed method doesn't exist, returns false and the router
     * will fall back to the unprefixed method (e.g., profile()).
     *
     * @param string $method The method name to compose
     * @param array $param The parameters passed to this function
     * @return string|false Return prefixed method name or false if not found
     */
    public function __call($method, $param = null)
    {
        // Get HTTP request method
        $requestMethod = $_SERVER['REQUEST_METHOD'];

        // Map HTTP verbs to method prefixes
        $prefix = match($requestMethod) {
            'GET' => 'get',
            'POST' => 'post',
            'PUT' => 'put',
            'DELETE' => 'delete',
            'PATCH' => 'patch',
            default => 'post',  // Fallback for other methods (HEAD, OPTIONS, etc.)
        };

        // Compose prefixed method name (e.g., 'get' + 'Profile' = 'getProfile')
        $prefixedMethod = $prefix . ucwords($method);

        // Check if prefixed method exists and return it, otherwise return false
        if (method_exists($this, $prefixedMethod)) {
            return $prefixedMethod;
        }

        return false;
    }

    /**
     * Initialize framework properties
     *
     * Sets up essential framework properties in the controller.
     * Called automatically by the router before controller method execution.
     *
     * Properties set:
     *   - request_start_time: Timestamp when request started (for performance tracking)
     *   - site_title: Application title from config/settings.php
     *   - settings: Complete settings array for quick access
     *
     * Note: Prefixed with underscore to avoid conflicts with developer-defined methods.
     *
     * @return $this Controller instance for method chaining
     */
    public function _setRachieProperties()
    {
        // Set request start time for performance tracking
        $this->request_start_time = Registry::$rachie_app_start;

        // Set site title from configuration
        $this->site_title = Registry::settings()['title'];

        // Set settings for quick access
        $this->settings = Registry::settings();

        // Return instance for chaining
        return $this;
    }

    /**
     * Get request execution time
     *
     * Returns the duration in seconds from when the request started
     * to when this method is called. Useful for performance monitoring.
     *
     * Examples:
     *   // In controller method
     *   $time = $this->_requestExecutionTime();  // 0.0234 (seconds)
     *
     *   // Format for display
     *   $ms = number_format($this->_requestExecutionTime() * 1000, 2);
     *   echo "Page generated in {$ms}ms";
     *
     *   // Pass to view for debug footer
     *   View::with([
     *       'execution_time' => $this->_requestExecutionTime()
     *   ])->render('page');
     *
     * Note: Prefixed with underscore to avoid conflicts with developer-defined methods.
     *
     * @return float Duration in seconds (with microsecond precision)
     */
    public function _requestExecutionTime()
    {
        return microtime(true) - $this->request_start_time;
    }
}
