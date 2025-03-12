<?php namespace Rackage\Routes;

/**
 *This class performs url resolving. 
 *It inspects the input url and gets the appropriate controller and method to launch based on the 
 *defined or infered routes
 *
 *@author Geoffrey Okongo <code@rachie.dev>
 *@copyright 2015 - 2030 Geoffrey Okongo
 *@category Rackage
 *@package Rackage
 *@link https://github.com/glivers/rackage
 *@license http://opensource.org/licenses/MIT MIT License
 *@version 2.0.1
 */

use Rackage\ArrayHelper\ArrayHelper;

class BaseRouteClass {

	/**
	 *@var string $pattern The url string pattern to check for
	 *@readwrite 
	 */
	protected $pattern = '@';

	/**
	 *@var string $pattern The url string pattern to check for
	 *@readwrite 
	 */
	protected $urlParameterSeparator = '/';

	/**
	*@var string The route metaData
	*/
	protected $routeMetaData;

	/**
	*@var string The method metaData
	*/
	protected $methodMetaData;

	/**
	*@var array The array containing the methodMetaData
	*/
	protected $methodMetaDataArray = array();
	/**
	 *@var string $controller The controller name to launch
	 *@readwrite
	 */
	protected $controller = null;
	/**
	 *@var string $action The controller method to invoke
	 *@readwrite
	 */
	protected $method = null;
	/**
	 *@var array $parameters The url request parameters
	 *@readwrite
	 */
	protected $parameters = array();

}
