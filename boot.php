<?
	define("ORM_AUTO_GENERATE_CLASSES", true);
	define("ORM_SHOW_DEBUG", true); // If enabled, exceptions encountered in __destruct will be printed to the screen. If disabled, they will not be shown.
	
	define("ORM_READ_ONLY", false);
	define("ORM_EMULATE_WRITES",true);
	
	require_once("orm.class.php");
	
	function __autoload($class) {
		if(file_exists("classes/$class.class.php")) {
			require_once("classes/$class.class.php");
			return;
		}
		
		// Experimental auto-class-generator as a last resort.
		if(ORM_AUTO_GENERATE_CLASSES) @eval("class $class extends orm { }");
	}