<?
abstract class orm {
	
	protected $ormSettings = array();
	
	public function __construct($id = null, $fields = null) {
		require_once("db.class.php");
		
		// Prevent folks from trying to create objects using IDs and fields simultaneously.
		if(isset($id, $fields)) throw new Exception("You cannot instantiate an object using ID and and array of fields. Use one or the other, but not both simultaneously.");
		
		// If ID is null and no fields are specified, we are creating a new object so stop processing here.
		if($id == null and $fields == null) return;
		
		// If the fields haven't been passed in through $fields, look up using the ID number
		if($fields == null) {
			$db = db::singleton();
			$fields = $db->oneRow("SELECT * FROM `".get_class($this)."` WHERE id = '$id';");
			if(empty($fields)) throw new DomainException("No ".get_class($this)." object was found in the database with ID $id.");
		}
		
		// Now set up all the fields we found in the DB (or that were passed in) as variables in the class
		$this->ormBuildFromArray($fields);
	}
	
	private function ormBuildFromArray($fields) {
		foreach($fields as $attribute => $field) {
			$this->$attribute = htmlentities($field);
			// Identifies 1-n relationships by field name (e.g group_id) and makes a stdclass in 'group'.
			// When 'get' is run against this attribute (e.g. getGroup()), stdClasses are transformed into objects and returned.
			if(substr($attribute, strlen($attribute) - 3) == '_id') $this->{substr($attribute, 0, strlen($attribute)-3)} = new stdClass();
		}
		ksort($fields);
		$this->ormSettings['objectHash'] = md5(implode($fields));
	}
	
	public function __call($function, $args) {
		
		// First, work out if a getter or setter was called
		if(preg_match("/(get|set)([A-Z].*)/", $function, $matches)) {
			// We have a getter or setter
			$matches[2]{0} = strtolower($matches[2]{0});
			$action = $matches[1];				// e.g. get
			$subject = $matches[2];				// e.g. name
			
			if(!property_exists($this, $subject)) throw new BadMethodCallException("You tried to $action $subject, but there is no $subject variable. Check that the variable exists in your database, and that you have requested a valid object.");

			if($action == "get") {
				if($this->$subject instanceof stdClass) $this->$subject = new $subject($this->$subject."_id");
				return $this->$subject;
			}
			
			// When setting, could check for $this->validate$subject($arg[0]); which would be implemented by the user.
			if($action == "set") {
				$this->$subject = $args[0];
				
				// TODO: Check whether $subject ends _id and update associated $subject without _id (and vice-versa)
				
				// SHOULD RETURN REFERENCE TO THIS OBJECT TO ENABLE METHOD CHAINING
				return $this;
			}
		}
		
		if(preg_match("/find_by_(.*)/", $function, $matches)) {
			return $this->ormFindBy($matches[1], $args);
		}
		
		throw new BadMethodCallException("There is no function called $function. Your arguments were:\n".print_r($args, true));
	}
	
	public static function __callStatic($function, $args) {
		// Check whether called method is a find_by
		if(preg_match("/find_by_(.*)/", $function, $matches)) {
			return self::ormFindBy($matches[1], $args);
		}
		throw new BadMethodCallException("There is no static function called $function. Your arguments were:\n".print_r($args, true));
	}
	
	private function ormFindBy($fields, $args) {
		// Works out class name based on whether we are static or not. This is for PHP < 5.3, which does not have get_called_class() and would return 'orm' in static context
		$class = !(isset($this)) ? get_called_class() : get_class($this);
		
		// Explode the query into a set of field names, then check that we have a parameter for each field
		$fields = explode("_and_", $fields);
		
		if(count($fields) != count($args)) throw new Exception("You have attempted to search on {count($fields)} fields, but have provided {count($args)} arguments to search those fields for. Ensure that you are providing a search term for each field specified.");
		
		// Build the fields and parameters into a WHERE array for the DB class
		$where = array();
		foreach($fields as $i => $field) {
			$w[] = "AND";
			$w[] = is_object($args[$i]) ? $field."_id" : $field;
			$w[] = "=";
			$w[] = is_object($args[$i]) ? $args[$i]->getId() : $args[$i];
			$where[] = $w;
			unset($w);
		}
		
		// Run the select query
		$db = db::singleton();
		$db->select(array("*"), $class, $where);
		$results = $db->runBatch();
		$results = $results[0];
		
		// Single result - return an object
		if(count($results) == 1) {
			return new $class(null, $results[0]);
		// Many results - return an array of objects
		} else if(count($results) >1) {
			$out = array();
			foreach($results as $result) $out[] = new $class(null, $result);
			return $out;
		}
		return null;
	}
	
	function __destruct() {
		// Bundle all object vars up into an array, excluding ormSettings and objects (related objects are copied via xyz_id fields)
		foreach($this as $name => $obj) if($name != "ormSettings" and !is_object($obj)) $set[$name] = $obj;
		
		// Sort vars and check against the hash made when constructing the object (to find if any changes have been made)
		ksort($set);
		if(empty($this->ormSettings['objectHash']) or $this->ormSettings['objectHash'] != md5(implode($set))) {
			$db = db::singleton();
			if(!isset($this->id)) {
				$db->insert($set, get_class($this));
			} else {
				$db->update($set, get_class($this), array(array("WHERE", "id", $this->id)));
				echo "updating";
			}
			$db->runBatch();
		}
	}
	
	/**
	 * getParent
	 *
	 * @param	string of object type name
	 * @return	object
	 * @author	Russell Newman
	 **/
	public function getParent($object = null) {
		if($object == null) throw new InvalidArgumentException("You did not specify what type of parent object you wanted.");
		// could poss use instanceof stdClass here
		// Check that the ID of the parent is set and that we haven't already loaded the parent.
		// (Parents that are not yet loaded will be populated with stdClass as opposed to the actual object).
		if(!empty($this->{$object."_id"}) and get_class($this->$object) == "stdClass") {
			$this->$object = new $object($this->{$object."_id"});
		}
		return $this->$object;
	}
	
	public function getChildren($object = null, $where = null, $order = null) {
		//echo "<p>Ordering $object by $order WHERE $where</p>";
		if($object == null) throw new InvalidArgumentException("You did not specify what type of child object you wanted.");
		
		// Check to see if children elements are already loaded. Load them if we need them.
		if(empty($this->{$object."_children"}->elements)) {
			$this->{$object."_children"}->elements = array();
			$this->{$object."_children"}->order = $order;
			$db = db::singleton();
			if($where != null) $where = " AND $where";
			if($order != null) $order = "ORDER BY $order";
			$children = $db->single("SELECT id FROM `$object` WHERE ".get_class($this)."_id = '$this->id'$where $order");
			
			if(!empty($children)) {
				foreach($children as $child) {
					$this->{$object."_children"}->elements[$child['id']] = new $object($child['id']);
				}
			}
		
		// Re-order the children elements if a changed order has been requested.
		} else if($order != $this->{$object."_children"}->order) {
			//echo "SELECT id FROM $object WHERE ".get_class($this)."_id = '$this->id' ORDER BY $order";
			$db = db::singleton();
			if($where != null) $where = " AND $where";
			$newOrder = $db->single("SELECT id FROM `$object` WHERE ".get_class($this)."_id = '$this->id' $where ORDER BY $order");
			$newChildren = array();
			foreach($newOrder as $item) $newChildren[$item['id']] = $this->{$object."_children"}->elements[$item['id']];
			$this->{$object."_children"}->elements = $newChildren;
			
			// TO DO: Cache orders of child elements.
			// TO DO: Cache wheres of child elements.
		}
		return $this->{$object."_children"}->elements;
	}
	
	// Gets related objects from an intermediary table (i.e. many-to-many join)
	public function getRelated($object = null) {
		if($object == null) throw new InvalidArgumentException("You did not specify what type of related objects you wanted.");
		if(empty($this->id)) throw new InvalidArgumentException("This object does not have an ID, and thus cannot have related objects.");
		if(!isset($this->{$object."_members"})) {
			
			// Build the name of the joining table. Create array, sort() to get the two names in alphabetical order, then implode with _ to get actual name.
			$table = array($object, get_class($this));
			$table = implode("_", sort($table));
			$this->{$object."_members"} = array();
			
			$db = db::singleton();
			$objects = $db->single("SELECT {$object}_id FROM `$table` WHERE {get_class($this)}_id = '$this->id'");
			if(!empty($objects)) foreach($objects as $o) $this->{$object."_members"}[] = new $object($o['id']);
		}
		return $this->{$object."_members"};
		
		// could also add ORDER criteria and ASC/DESC
	}
	
	public function __toString() {
		return (empty($this->name)) ? (String)$this->id : $this->name;
	}
}