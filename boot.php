<?
	require_once("orm.class.php");
	
	function __autoload($class) {
		if(file_exists("classes/$class.class.php")) require_once("classes/$class.class.php");
	}