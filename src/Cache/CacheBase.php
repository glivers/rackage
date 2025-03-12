<?php namespace Rackage\Cache;

/**
 *This the base class which all cache drivers extend
 *
 *@author Geoffrey Okongo <code@rachie.dev>
 *@copyright 2015 - 2030 Geoffrey Okongo
 *@category Rackage
 *@package Rackage\Drivers\Cache
 *@link https://github.com/glivers/rackage
 *@license http://opensource.org/licenses/MIT MIT License
 *@version 2.0.1
 */

class CacheBase {

	use CacheImplementation;

	/**
	 *This constructor method sets the default Caching Service Type to use
	 *
	 *@param string $type Name of Cache Service
	 */
	public function __construct($type)
	{
		//set the default to cache service to use
		$this->type  = $type;

	}
	
}
