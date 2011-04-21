<?
	const AUTO_GENERATE_CLASSES = true;
	
	require_once("orm.class.php");
	
	function __autoload($class) {
		if(file_exists("classes/$class.class.php")) {
			require_once("classes/$class.class.php");
			return;
		}
		
		// Experimental auto-class-generator as a last resort.
		if(AUTO_GENERATE_CLASSES) @eval("class $class extends orm { }");
	}