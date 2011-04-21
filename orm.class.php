<?
abstract class orm {
	
	protected $ormSettings = array();
	
	public function __construct($id = null) {
		require_once("db/class.db.php");
		
		// If ID is null, we are creating a new object so stop processing here.
		if($id == null) return;
		//$this->id = $id;
		$db = db::singleton();
		$fields = $db->oneRow("SELECT * FROM ".get_class($this)." WHERE id = '$id'");
		if(empty($fields)) throw new DomainException("No ".get_class($this)." object was found in the database with ID $id.");
		
		// Now set up all the fields we found in the DB as variables in the class
		foreach($fields as $attribute => $field) {
			$this->$attribute = htmlentities($field);
			// Identifies 1-n relationships by field name (e.g group_id) and makes a stdclass in 'group'.
			// When 'get' is run against this attribute (e.g. getGroup()), stdClasses are transformed into objects and returned.
			if(substr($attribute, strlen($attribute) - 3) == '_id') $this->{substr($attribute, 0, strlen($attribute)-3)} = new stdClass();
		}
	}
	
	public function __call($function, $args) {
		$action = substr($function, 0, 3);
		$subject = strtolower(substr($function, 3));
		
		if(($action == "get" or $action == "set") and !property_exists($this, $subject)) throw new BadMethodCallException("You tried to $action $subject, but no variable exists by the name of $subject. Check that the variable is visible to the ORM in your class (it should be protected or public).");
		
		if($action == "get") {
			if($this->$subject instanceof stdClass) $this->$subject = new $subject($this->$subject."_id");
			return $this->$subject;
		}
		// When setting, could check for $this->validate$subject($arg[0]); which would be implemented by the user.
		if($action == "set") {
			// PROBLEM: If the setName (e.g.) method is written into a child class, the attribute will not be written to ormsettings->set.
			// Need to copy original vars to a safe loaction in ormsettings then compare to object properties when destructing.
			// Or could just write everything ...
			
			//echo $args[0]."<br/>";
			$this->ormSettings["set"][$subject] = $args[0];
			
			// SHOULD RETURN REFERENCE TO THIS OBJECT TO ENABLE METHOD CHAINING
			return $this->$subject = $args[0];
		} 
		
		throw new BadMethodCallException("There is no function called $function. Your arguments were:\n".print_r($args, true));		
	}
	
	function __destruct() {
		/*
			TODO	Destruct currently saves regardless of whether the object has been changed or not.
					Need to update only if the object has been changed!
					(i.e. only if it doesn't match the one in the DB).
		*/
		
		//return;
		// user could add array of compulsory fields in their classes with a standard array name. Best to do this check in __set().
		
		if(!empty($this->ormSettings["set"])) {
			foreach($this as $name => $obj) if($name != "ormSettings" and !is_object($obj)) $set[$name] = $obj;
			$db = db::singleton();
			
			/*
				TODO Do we need to remove ID from UPDATE query? Perhaps not, as the user may be updating the ID!
			*/
			if(!isset($this->id)) {
				$db->insert($set, get_class($this));
				$db->runBatch();
			} else {
				$db->update($set, get_class($this), array(array("WHERE", "id", $this->id)));
				$db->runBatch();
			}
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
			$children = $db->single("SELECT id FROM $object WHERE ".get_class($this)."_id = '$this->id'$where $order");
			
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
			$newOrder = $db->single("SELECT id FROM $object WHERE ".get_class($this)."_id = '$this->id' $where ORDER BY $order");
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
			$db = db::singleton();
			$t2 = get_class($this);
			$table = array($object, $t2);
			$table = implode("_", sort($table));
			$this->{$object."_members"} = array();
			$objects = $db->single("SELECT {$object}_id FROM $table WHERE {get_class($this)}_id = '$this->id'");
			if(!empty($objects)) foreach($objects as $o) $this->{$object."_members"}[] = new $object($o['id']);
		}
		return $this->{$object."_members"};
		// if ID is set in ormSettings else throw
		// SELECT id FROM $object WHERE {get_class()}_id = $this->ormSettings['id'];
		// make array of new objects of type $object
		// serve
		
		// could also add ORDER criteria and ASC/DESC
	}
	
	public function __toString() {
		return (empty($this->name)) ? (String)$this->id : $this->name;
	}
}