<?php namespace Rackage\Templates;

/**
 *This class is the base class that handles template processing 
 *@author Geoffrey Okongo <code@rachie.dev>
 *@copyright 2015 - 2030 Geoffrey Okongo
 *@category Rackage
 *@package Rackage\Templates
 *@link https://github.com/glivers/rackage
 *@license http://opensource.org/licenses/MIT MIT License
 *@version 2.0.1
 */

use Rackage\Templates\GrammarMapTrait;
use Rackage\Templates\TemplateParserClass;

class BaseTemplateClass extends TemplateParserClass {

	//use the class tha define s the grammar map
	use GrammarMapTrait;

}

