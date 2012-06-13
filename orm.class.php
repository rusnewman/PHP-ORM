<?php

/**
 * PHP Object-Relational Mapper v0.9
 * Copyright 2012, Russell Newman.
 * Licenced under the MIT licence.
 *
 * @abstract
 * @author		Russell Newman.
 **/

class ormCollection extends ArrayIterator {
	
	public function &__call($function, $args) {
		if(preg_match("/^order_by_(.*?)_?(asc|desc)?$/", $function, $matches)) {
			$a = (array)$this;
			$direction = empty($matches[2]) ? "asc" : $matches[2];
			usort($a, array("not_sure_what_this_bit_is_for___doesnt_seem_to_break_anything", "_ormCompareBy_{$matches[1]}_{$direction}"));
			$out = new ormCollection();
			foreach($a as $i => $b) $out->{"a".$i} = $b;
			return $out;
		}
		throw new BadMethodCallException("Unknown method.");
	}
	
	public function __toString() {
		return (string)count((array)$this);
	}
}

abstract class orm {
	
	protected $ormSettings = array();
	
	/**
	 * __construct
	 *
	 * @param	int		$id			ID of desired element.
	 * @param	array	$fields		Array of attributes that can be used to construct the object, rather than looking them up in the database.
	 * @return	void
	 * @author	Russell Newman
	 **/
	public function __construct($id = null, $fields = null) {
		require_once("db/db.class.php");
		
		// Prevent folks from trying to create objects using IDs and fields simultaneously.
		if(isset($id, $fields)) throw new Exception("You cannot instantiate an object using ID and and array of fields. Use one or the other, but not both simultaneously.");
		
		$db = db::singleton();
		
		// If ID is null and no fields are specified, we are creating a new object so stop processing here.
		if($id == null and $fields == null) {
			$this->ormInitialiseFields();
			return;
		}
		
		// If the fields haven't been passed in through $fields, look up using the ID number
		if($fields == null) {
			
			$fields = $db->oneRow("SELECT * FROM `".get_class($this)."` WHERE `id` = '$id';");
			if(empty($fields)) throw new DomainException("No ".get_class($this)." object was found in the database with ID $id.");
		}
		
		// Now set up all the fields we found in the DB (or that were passed in) as variables in the class
		$this->ormBuildFromArray($fields);
	}
	
	/**
	 * ormInitialiseFields
	 * Queries the database for allowed field types and sets these up in the ORM.
	 * 
	 * @return	null
	 * @author	Russell Newman
	 **/
	private function ormInitialiseFields() {
		// Query DB for allowed fields and datatypes
		$db = db::singleton();
		try {
			$fields = $db->single("DESCRIBE `".get_class($this)."`");
			$f = array();
			foreach($fields as $field) $f[$field['Field']] = null;
			// And now we initialise the object using the normal method, albeit with nulled variables.
			$this->ormBuildFromArray($f);
		} catch(Exception $e) {
			throw new Exception("The ".get_class($this)." table could not be found in the database.");
		}
	}
	
	/**
	 * ormBuildFromArray
	 *
	 * @param	array	$fields		Array of attributes for building the object, keyed on the attribute name.
	 * @return	null
	 * @author	Russell Newman
	 **/
	private function ormBuildFromArray($fields) {
		foreach($fields as $attribute => $field) {
			$this->$attribute = htmlentities($field);
			// Identifies 1-n relationships by field name (e.g group_id) and makes a stdclass in 'group'.
			// When 'get' is run against this attribute (e.g. getGroup()), stdClasses are transformed into objects and returned.
			if(substr($attribute, strlen($attribute) - 3) == '_id') $this->{substr($attribute, 0, strlen($attribute)-3)} = new stdClass();
		}
		$this->ormRefreshHash();
		
	}
	
	/**
	 * ormRefreshHash
	 * This is needed for calculating changes in the object.
	 * Using a dirty flag is not sufficient because developers can write their own setter methods into classes that inherit the ORM, and these custom setters would not set the dirty flag.
	 * By calculating an MD5 when the object is clean, and again when it is suspected-dirty, all instances are covered.
	 *
	 * @return	null
	 * @author	Russell Newman
	 **/
	private function ormRefreshHash() {
		// Bundle up object attributes and hash them. This must be done after doing htmlentities, relationships, etc
		foreach($this as $name => $obj) if($name != "ormSettings" and !is_object($obj)) $hash[$name] = $obj;
		// Keysort fields and hash. When destructing the object, we compare against this hash to see if anything has changed.
		ksort($hash);
		$this->ormSettings['objectHash'] = md5(implode($hash));
	}
	
	/**
	 * __call
	 *
	 * @param	string	$function	Name of the function that was called.
	 * @param	array	$args		Array of arguments that were passed to the function.
	 * @return	mixed				Depending upon function type that was called. Often $this to enable method chaining.
	 * @author	Russell Newman
	 **/
	public function &__call($function, $args) {
		
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
				// Check for read-only mode
				if(ORM_READ_ONLY) throw new Exception("The ORM is currently in read-only mode, and cannot save changes to the database. To remove this message, use emulated writes instead of read-only mode.");
				
				$this->$subject = $args[0];
				
				// If the updated attribute ends in _id, blank out the associated object attribute (e.g. group for group_id)
				// This ensures that when an ID is updated, the newly related object will be served, instead of the old one
				if(substr($subject, strlen($subject) - 3) == '_id') $this->{substr($subject, 0, strlen($subject)-3)} = new stdClass();
				
				// Similarly, if the updated attribute is actually a related object, update the _id attribute also (e.g. group_id for group, see above)
				if(is_object($args[0])) $this->{$subject."_id"} = $this->$subject->getId();
				
				// TODO: SHOULD RETURN REFERENCE TO THIS OBJECT TO ENABLE METHOD CHAINING
				return $this;
			}
		}
		
		if(preg_match("/find_by_(.*)/", $function, $matches)) return $this->ormFindBy($matches[1], $args);
		
		throw new BadMethodCallException("There is no function called $function. Your arguments were:\n".print_r($args, true));
	}
	
	/**
	 * __callStatic
	 * Intercepts static calls to missing functions.
	 * Used to intercept find_by_[field]() functions made in static context.
	 * 
	 * @static
	 * @param	string	$function	Name of the function that was called.
	 * @param	array	$args		Array of arguments that were passed to the function.
	 * @return	mixed				Returns an object or an array of objects.
	 * @author	Russell Newman
	 **/
	public static function __callStatic($function, $args) {
		// Check whether called method is a find_by
		if(preg_match("/find_by_(.*)/", $function, $matches)) return self::ormFindBy($matches[1], $args);
		
		// Intercepts comparison functions and performs to appropriate comparison.
		// This is necessary to enable order_by functions.
		if(preg_match("/_ormCompareBy_(.*)_(asc|desc)/", $function, $matches)) {
			// Set sort up/down values depending on asc or desc
			$up = ($matches[2] == "asc") ? 1 : -1;
			$down = ($matches[2] == "asc") ? -1 : 1;
			// Check that the variable we are comparing actually exists in both objects.
			if(!empty($args[0]->$matches[1]) and !empty($args[1]->$matches[1])) {
				if ($args[0]->$matches[1] == $args[1]->$matches[1]) return 0;
		    	return ($args[0]->$matches[1] < $args[1]->$matches[1]) ? $down : $up;
			}
			throw new Exception("You tried to perform a comparison (or sort/ordering) upon an attribute that does not exist. Are you sure the '{$matches[1]}' attribute exists inside '".get_class($args[0])."' objects?");
		}
		
		throw new BadMethodCallException("There is no static function called $function. Your arguments were:\n".print_r($args, true));
	}
	
	/**
	 * ormFindBy
	 * Parses find_by function calls, performs the lookups and returns the results
	 *
	 * @param	string	$fields		The fields we are searching on, based on the method call (without the find_by_ part).
	 * @param	array	$args		Array of arguments that correspond to desired search fields.
	 * @return	mixed				Array or object, depending on whether one or many objects were found.
	 * @author	Russell Newman
	 **/
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
		// Many results - return a collection of objects
		} else if(count($results) > 1) {
			$out = new ormCollection();
			foreach($results as $i => $result) $out->{"a".$i} = new $class(null, $result);
			return $out;
		}
		return new ormCollection();
	}
	
	/**
	 * __destruct
	 * Collects up all object attributes, checks whether they have changed and commits to the database as necessary.
	 * 
	 * @return	void
	 * @author	Russell Newman
	 **/
	function __destruct() {
		
		// Testing new commit method. If successful, the rest of this method will be deleted.
		try {
			$this->commit();
		} catch(Exception $e) {
			if(ORM_SHOW_DEBUG) echo "An exception was encountered while running the __destruct() method for a ".get_class($this)." object. The exception was: ".$e;
		}
		
		return;
		
		// Check for read-only and emulation modes, and prevent writing as necessary.
		if(ORM_READ_ONLY or ORM_EMULATE_WRITES) return;
		
		// Bundle all object vars up into an array, excluding ormSettings and objects (related objects are copied via xyz_id fields)
		foreach($this as $name => $obj) if($name != "ormSettings" and !is_object($obj)) $set[$name] = $obj;
		
		// Sort vars and check against the hash made when constructing the object (to find if any changes have been made)
		ksort($set);
		if(empty($this->ormSettings['objectHash']) or $this->ormSettings['objectHash'] != md5(implode("", $set))) {
			$db = db::singleton();
			if(!isset($this->id)) {
				$db->insert($set, get_class($this));
			} else {
				$db->update($set, get_class($this), array(array("WHERE", "id", $this->id)));
			}
			$db->runBatch();
		}
	}
	
	/**
	 * commit
	 * Collects up all object attributes, checks whether they have changed and commits to the database as necessary.
	 * Saves back the object ID as an attribute.
	 * 
	 * @return	void
	 * @author	Russell Newman
	 **/
	public function commit() {
		
		// Bundle all object vars up into an array, excluding ormSettings and objects (related objects are copied via xyz_id fields)
		foreach($this as $name => $obj) if($name != "ormSettings" and !is_object($obj)) $set[$name] = $obj;
		
		// Sort vars and check against the hash made when constructing the object (to find if any changes have been made)
		ksort($set);
		if(empty($this->ormSettings['objectHash']) or $this->ormSettings['objectHash'] != md5(implode("", $set))) {
			
			// Check for read-only and emulation modes, and prevent writing as necessary.
			// Following line emulates an ID update, if one has been made, OR sets a fake ID of 1 (to emulate an INSERT) OR does nothing (if no ID update has been requested for an existing object).
			if(ORM_EMULATE_WRITES and (empty($set['id']) or $set['id'] != $this->id)) $this->id = (empty($set['id'])) ? 1 : $set['id'];
			if(ORM_READ_ONLY or ORM_EMULATE_WRITES) return;
			
			$db = db::singleton();
			if(empty($this->id)) {
				$db->insert($set, get_class($this));
				$db->runBatch();
				$this->id = $db->insert_id;
			} else {
				$db->update($set, get_class($this), array(array("WHERE", "id", $this->id)));
				$db->runBatch();
				if(!empty($set['id'])) $this->id = $set['id'];
			}
		}
		$this->ormRefreshHash();
	}
	
	/**
	 * getParent
	 * Finds the specified parent object of this object in a 1-to-many relationship.
	 *
	 * @param	string	$object		Parent object type name.
	 * @return	object
	 * @author	Russell Newman
	 **/
	public function getParent($object = null) {
		if($object == null) throw new InvalidArgumentException("You did not specify what type of parent object you wanted.");
		
		// Check that the ID of the parent is set and that we haven't already loaded the parent.
		// (Parents that are not yet loaded will be populated with stdClass as opposed to the actual object).
		if(!empty($this->{$object."_id"}) and $this->$object instanceof stdClass) $this->$object = new $object($this->{$object."_id"});
		
		return $this->$object;
	}
	
	/**
	 * getChildren
	 * Finds the specified child objects of this object in a 1-to-many relationship. Can also filter and order child objects.
	 *
	 * @param	string	$object		Child object type name.
	 * @param	string	$where		Valid SQL WHERE statement.
	 * @param	string	$order		Valid SQL ORDER BY statement.
	 * @return	array
	 * @author	Russell Newman
	 **/
	public function getChildren($object = null, $where = null, $order = null) {
		if($object == null) throw new InvalidArgumentException("You did not specify what type of child object you wanted.");
		
		// Check to see if children elements are already loaded. Load them if we need them.
		if(empty($this->{$object."_children"}->elements)) {
			$this->{$object."_children"}->elements = new ormCollection();
			$this->{$object."_children"}->order = $order;
			$db = db::singleton();
			if($where != null) $where = " AND $where";
			if($order != null) $order = "ORDER BY $order";
			$children = $db->single("SELECT `id` FROM `$object` WHERE `".get_class($this)."_id` = '$this->id'$where $order");
			
			if(!empty($children)) foreach($children as $child) {
				$this->{$object."_children"}->elements[$child['id']] = new $object($child['id']);
			}
		
		// Re-order the children elements if a changed order has been requested.
		} else if($order != $this->{$object."_children"}->order) {
			$db = db::singleton();
			if($where != null) $where = " AND $where";
			$newOrder = $db->single("SELECT `id` FROM `$object` WHERE `".get_class($this)."_id` = '$this->id' $where ORDER BY $order");
			$newChildren = array();
			foreach($newOrder as $item) $newChildren[$item['id']] = $this->{$object."_children"}->elements[$item['id']];
			$this->{$object."_children"}->elements = $newChildren;
			
			// TO DO: Cache orders of child elements.
			// TO DO: Cache wheres of child elements.
		}
		return $this->{$object."_children"}->elements;
	}
	
	/**
	 * getRelated
	 * Finds the specified related elements of this element in a many-to-many relationship, via an intermediary table.
	 * No filtering or ordering as of yet.
	 *
	 * @param	string	$object		Type of related element requested.
	 * @param	string	$where		Valid SQL WHERE statement.
	 * @param	string	$order		Valid SQL ORDER BY statement.
	 * @return	void
	 * @author	Russell Newman
	 **/
	// Gets related objects from an intermediary table (i.e. many-to-many join)
	public function getRelated($object = null, $where = null, $order = null) {
		if($object == null) throw new InvalidArgumentException("You did not specify what type of related objects you wanted.");
		if(empty($this->id)) throw new InvalidArgumentException("This object does not have an ID, and thus cannot have related objects.");
		if(!isset($this->{$object."_members"})) {
			
			// Build the name of the joining table. Create array, sort() to get the two names in alphabetical order, then implode with _ to get actual name.
			$table = array($object, get_class($this));
			sort($table);
			$table = implode("_", $table);
			$this->{$object."_members"} = array();
			if($where != null) $where = " AND $where";
			if($order != null) $order = "ORDER BY $order";
			
			$db = db::singleton();
			$objects = $db->single("SELECT a.`{$object}_id` AS id FROM `$table` a JOIN {$object} b ON a.`{$object}_id` = b.id WHERE `".get_class($this)."_id` = '{$this->id}'$where $order");
			print_r($objects);
			if(!empty($objects)) foreach($objects as $o) $this->{$object."_members"}[] = new $object($o['id']);
		}
		return $this->{$object."_members"};
	}
	
	/**
	 * __toString
	 * Returns a string of the object's 'name' attribute or, failing that, the ID of the object.
	 *
	 * @return	string
	 * @author	Russell Newman
	 **/
	public function __toString() {
		return (empty($this->name)) ? (String)$this->id : $this->name;
	}
	
	public function validate() {
		$out = stdclass();
	}
}