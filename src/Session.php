<?php namespace Rackage;
/**
 *This class handles php session operations
 *
 *@author Geoffrey Okongo <code@rachie.dev>
 *@copyright 2015 - 2030 Geoffrey Okongo
 *@category Rackage
 *@package Rackage\Sessions
 *@link https://github.com/glivers/rackage
 *@license http://opensource.org/licenses/MIT MIT License
 *@version 2.0.1
 */

class Session {

	/**
	 *This is the constructor class. We make this private to avoid creating instances of
	 *this object
	 *
	 *@param null
	 *@return void
	 */
	private function __construct() {}

	/**
	 *This method stops creation of a copy of this object by making it private
	 *
	 *@param null
	 *@return void
	 *
	 */
	private function __clone(){}

	/**
	 *This method initializes the session environment.
	 *@param null
	 *@return void
	 */
	public static function start()
	{
		//initialize session
		session_start();

	}

	/**
	 *This method stores data into session
	 *
	 *@param string $key Then key with which to store this data
	 *@param mixed $input The input data to be stored
	 *@return void
	 */
	public static function  set($key, $data)
	{
		//store data in session
		$_SESSION[$key] = $data;

	}
	
	/**
	 *This method returns a session data by key
	 *
	 *@param string $key The key with which to search session data
	 *@return mixed The value stored in session
	 */
	public static function  get($key, $default = false) {

		return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;

	}

	/**
	 *This method erases all session data
	 *
	 *@param null
	 *@return void
	 */
	public static function flush()
	{
		// remove all session variables
		session_unset();


		// destroy the session
		session_destroy(); 
	}

	/**
	 *This method checks if a session with a particular key has been set
	 *
	 *@param string $key
	 *@return bool true|false Reurns true if key was found or false if null
	 */
	public static function has($key){
		
		//check and return if this key exists 
		return (isset($_SESSION[$key])) ? true : false;

	}

	/**
	 * Regenerate session ID to prevent session fixation attacks
	 * 
	 * @param bool $deleteOldSession Delete old session data
	 * @return bool True on success
	 */
	public static function refresh($deleteOld = true) {
	    return session_regenerate_id($deleteOld);
	}
}