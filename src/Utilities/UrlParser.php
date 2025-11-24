<?php namespace Rackage\Utilities;

/**
 * URL Parser
 *
 * Parses URL strings to extract controller, method, and parameters.
 * Uses configurable separator (default: '.' or '/').
 *
 * Usage:
 *   $parser = new UrlParser('Blog/show/123', '/');
 *   $parser->setController()->setMethod()->setParameters();
 *
 *   $controller = $parser->getController();  // "Blog"
 *   $method = $parser->getMethod();          // "show"
 *   $params = $parser->getParameters();      // ["123"]
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2030 Geoffrey Okongo
 * @category Utilities
 * @package Utilities\UrlParserUtility
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.2
 */

use Rackage\Str;
use Rackage\Arr;

class UrlParser {

    /**
     * The raw input URL string to be parsed
     *
     * This is the sanitized URL string after removing HTML tags.
     *
     * @var string
     */
    private $urlString;

    /**
     * The delimiter character used for splitting URL components
     *
     * Configured in settings.php as 'url_component_separator'.
     * Typically '.' (Home.index.123) or '/' (Home/index/123).
     *
     * @var string
     */
    private $urlSeparator;

    /**
     * Array of URL components after splitting and cleaning
     *
     * Example: "Blog/show/123" becomes ["Blog", "show", "123"]
     * Empty elements are removed, and each element is trimmed.
     *
     * @var array
     */
    private $urlComponentsArray = [];

    /**
     * The controller name extracted from the URL
     *
     * This is the first component of the URL.
     * Set to null if URL has no components.
     *
     * @var string|null
     */
    private $controller;

    /**
     * The method name extracted from the URL
     *
     * This is the second component of the URL.
     * Set to null if URL has fewer than 2 components.
     *
     * @var string|null
     */
    private $method;

    /**
     * Array of parameters extracted from the URL
     *
     * These are all components after the controller and method.
     * Example: "Blog/show/123/featured" gives ["123", "featured"]
     *
     * @var array
     */
    private $parameters = [];

    /**
     * Constructor - Initialize and parse URL string
     *
     * Processing steps:
     * 1. Sanitize URL by removing HTML tags (security)
     * 2. Store the URL separator character
     * 3. Split URL string by separator into components array
     * 4. Remove empty elements from the array
     * 5. Trim whitespace from each component
     * 6. Store the cleaned components array
     *
     * Example:
     *   Input:  " Blog / show / 123 " with separator "/"
     *   Output: ["Blog", "show", "123"]
     *
     * Note: You must call setController(), setMethod(), and setParameters()
     * after construction to extract those values from the components array.
     *
     * @param string $url The URL request string (may contain HTML tags)
     * @param string $separator The URL component separator character
     */
    public function __construct($url, $separator)
    {
        // Sanitize URL by stripping HTML tags for security
        $this->urlString = Str::removeTags($url);

        // Store the separator character (e.g., '.' or '/')
        $this->urlSeparator = $separator;

        // Split URL by separator, clean empty elements, trim whitespace
        $this->urlComponentsArray = Arr::parts($this->urlSeparator, $this->urlString)
            ->clean()
            ->trim()
            ->get();
    }

    /**
     * Extract and set the controller from URL
     *
     * Parses the first URL component as the controller name.
     *
     * Example:
     *   URL: "Blog/show/123"
     *   Controller: "Blog"
     *
     * @return $this
     */
    public function setController()
    {
        $this->controller = (count($this->urlComponentsArray) > 0)
            ? $this->urlComponentsArray[0]
            : null;

        return $this;
    }

    /**
     * Get the controller name
     *
     * @return string|null The controller name
     */
    public function getController()
    {
        return $this->controller;
    }

    /**
     * Extract and set the method from URL
     *
     * Parses the second URL component as the method name.
     *
     * Example:
     *   URL: "Blog/show/123"
     *   Method: "show"
     *
     * @return $this
     */
    public function setMethod()
    {
        $this->method = (count($this->urlComponentsArray) > 1)
            ? $this->urlComponentsArray[1]
            : null;

        return $this;
    }

    /**
     * Get the method name
     *
     * @return string|null The method name
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * Extract and set parameters from URL
     *
     * Parses URL components after controller and method as parameters.
     * Optionally append or prepend an additional parameter.
     *
     * Examples:
     *   URL: "Blog/show/123/featured"
     *   Parameters: ["123", "featured"]
     *
     *   // Add parameter
     *   $parser->setParameters('slug', true);   // Append
     *   $parser->setParameters('slug', false);  // Prepend
     *
     * @param string|null $value Additional parameter to add
     * @param bool $appendParameter True to append, false to prepend
     * @return $this
     */
    public function setParameters($value = null, $appendParameter = true)
    {
        // Extract parameters from URL (everything after controller/method)
        $this->parameters = (count($this->urlComponentsArray) > 2)
            ? Arr::slice($this->urlComponentsArray, 2)->get()
            : [];

        // Add additional parameter if provided
        if ($value !== null) {
            if ($appendParameter) {
                $this->parameters[] = $value;
            } else {
                array_unshift($this->parameters, $value);
            }
        }

        return $this;
    }

    /**
     * Get the URL parameters
     *
     * @return array The parameters array
     */
    public function getParameters()
    {
        return $this->parameters;
    }
}
