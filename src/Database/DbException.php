<?php namespace Rackage\Database;

/**
 *This class handles all exceptions throw in the context of caching
 *
 *@author Geoffrey Okongo <code@rachie.dev>
 *@copyright 2015 - 2030 Geoffrey Okongo
 *@category Rackage
 *@package Rackage\Database
 *@link https://github.com/glivers/rackage
 *@license http://opensource.org/licenses/MIT MIT License
 *@version 2.0.1
 */

use Rackage\Path;

class DbeException extends \Exception {

	/**
	 *This method displays the error message
	 *
	 *@param 
	 *
	 */
	public function show()
	{
		//get the global $config array for site title
		global $config;

		//set the title variable
		$title = $config['title'];

		//the variable to be populated with the error message
		$msg = $this->getCode() . ': Error on line '.$this->getLine().' in '.$this->getFile() .': <b>  "'.$this->getMessage().' " </b> ';

		//load the template file
		include Path::sys() . 'Exceptions' . DIRECTORY_SEPARATOR . 'index.php';

		//stop further output
		exit();

	}

}
