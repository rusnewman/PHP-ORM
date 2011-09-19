<?
	define("ORM_AUTO_GENERATE_CLASSES", true);
	
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