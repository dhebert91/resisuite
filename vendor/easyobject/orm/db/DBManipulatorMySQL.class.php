<?php
/**
*	This file is part of the easyObject project.
*	http://www.cedricfrancoys.be/easyobject
*
*	Copyright (C) 2012  Cedric Francoys
*
*	This program is free software: you can redistribute it and/or modify
*	it under the terms of the GNU General Public License as published by
*	the Free Software Foundation, either version 3 of the License, or
*	(at your option) any later version.
*
*	This program is distributed in the hope that it will be useful,
*	but WITHOUT ANY WARRANTY; without even the implied warranty of
*	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*	GNU General Public License for more details.

*	You should have received a copy of the GNU General Public License
*	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
namespace easyobject\orm\db;
use \Exception as Exception;

/**
 * DBManipulatorMySQL class
 *
 */
// this script requires debugging constants and methods
// defined('__QN_LIB') or die(__FILE__.' requires qn.lib.php');
// load interface class
// load_class('db/DBManipulator');

class DBManipulatorMySQL extends DBManipulator {
    
    
	public function select($db_name) {
		return mysqli_select_db($this->dbms_handler, $db_name);
	}
    
	/**
	 * Open the DBMS connection
	 *
	 * @return   integer   The status of the connect function call
	 * @access   public
	 */
	public function connect($auto_select=true) {
		$result = false;
		if(DBManipulator::is_db_server($this->host_name, $this->port)) {
			if($this->dbms_handler = mysqli_connect($this->host_name, $this->user_name, $this->password, $this->db_name, $this->port)){
				if(!$auto_select) $result = true;
				elseif($result = $this->select($this->db_name)) {
					mysqli_query($this->dbms_handler, 'SET NAMES '.DB_CHARSET);
					$result = true;
				}
			}
		}
		return $result;
	}


	/**
	* Close the DBMS connection
	*
	* @return   integer   Status of the close function call
	* @access   public
	*/
	public function disconnect() {
        if(isset($this->dbms_handler)) {
			mysqli_close($this->dbms_handler);
			unset($this->dbms_handler);
		}
		return true;
	}

	/**
	* Send a SQL query.
	*
	* @param string The query to send to the DBMS.
	* @return resource Returns a resource identifier or -1 if the query was not executed correctly.
	*/
	function sendQuery($query) {
	    if(function_exists('debug_mode') && (debug_mode() & DEBUG_SQL)) print("$query\n");
		$this->setLastQuery($query);
		if(($result = mysqli_query($this->dbms_handler, $query)) === false) throw new Exception(__METHOD__.' : query failure, '.mysqli_error($this->dbms_handler), SQL_ERROR);
		else {
			// if everything went well, fetch some additional info
			// a) for 'select' queries
			if(stristr(substr($query, 0, 6), 'select')) {
				if(($res = mysqli_query($this->dbms_handler, "SELECT FOUND_ROWS();")) === false) throw new Exception(__METHOD__.' : query failure, '.mysqli_error($this->dbms_handler), SQL_ERROR);
				$row = mysqli_fetch_row($res);
				$this->setAffectedRows($row[0]);
			}
			// b) for 'show' queries
			else if(stristr(substr($query, 0, 4), 'show')) $this->setAffectedRows(mysqli_num_rows($result));
			// c) for other queries (insert, update, replace, delete)
			else $this->setAffectedRows(mysqli_affected_rows($this->dbms_handler));
			$this->setLastId(mysqli_insert_id($this->dbms_handler));
		}
		return $result;
	}

	public static function fetchRow(&$result) {
		return mysqli_fetch_row($result);
	}

	public static function fetchArray(&$result) {
		return mysqli_fetch_array($result, MYSQLI_ASSOC);
	}

	/**
	* Escapes a string containing the name of an object's field to match the SQL notation : `table`.`field` or `field`
	*
	* @param string $field_name
	* @return string
	*/
	private static function escapeFieldName($field_name) {
		$parts = explode('.', str_replace('`', '', $field_name));
		return (count($parts) > 1)?"`{$parts[0]}`.`{$parts[1]}`":"`{$parts[0]}`";
	}

	/**
	* Escapes a string for safe SQL insertion
	*
	* @param string $value
	* @return string
	*/
	private function escapeString($value) {
		$result = '';
        if(gettype($value) == 'string' && strlen($value) == 0) $result = "''";
        else {
            if(is_null($value)) $result = 'NULL';
            else {
                $value = (string) $value;                
                // value is a field name
                if(strlen($value) && $value{0} == '`') $result = DBManipulatorMySQL::escapeFieldName($value);
                 // value represents NULL SQL value
                else if($value == 'null' || $value == 'NULL') $result = 'NULL';
                // value is any other kind of string
                else $result = "'".mysqli_real_escape_string($this->dbms_handler, $value)."'";
            }
        }
		return $result;
	}

	/**
	* Gets the mysql WHERE clause
	*
	* @param string $id_field
	* @param array $ids
	* @param array $conditions
	*
	* array( array( array(operand, operator, operand)[, array(operand, operator, operand) [, ...]]) [, array( array(operand, operator, operand)[, array(operand, operator, operand) [, ...]])])
	* array of several series of clauses joined by logical ANDs themselves joined by logical ORs : disjunctions of conjunctions
	* i.e.: (clause[, AND clause [, AND ...]) OR (clause[, AND clause [, AND ...])
	*/
	private function getConditionClause($id_field, $ids, $conditions) {
		$sql = '';
		if(empty($conditions)) $conditions = array(array(array()));
		for($j = 0, $max_j = count($conditions); $j < $max_j; ++$j) {
			if($j > 0 && strlen($sql) > 0) $sql .= ') OR (';
			if(!empty($ids)) $conditions[$j][] = array($id_field, 'in', $ids);
			for($i = 0, $max_i = count($conditions[$j]); $i < $max_i; ++$i) {
				if($i > 0 && strlen($sql) > 0) $sql .= ' AND ';
				$cond = $conditions[$j][$i];
				if(!count($cond)) continue;
				// adjust the field syntax (if necessary)
				$cond[0] = DBManipulatorMySQL::escapeFieldName($cond[0]);
				// operator 'in' having a single value as right operand
				if(strcasecmp($cond[1], 'in') == 0 && !is_array($cond[2])) $cond[2] = array($cond[2]);
				// case-sensitive comparison ('like' operator)
				if(strcasecmp($cond[1], 'like') == 0){
					// force mysql to convert field to binary (result will be case-sensitive comparison)
					$cond[0] = 'BINARY '.$cond[0];
					$cond[1] = 'LIKE';
				}
				// ilike operator does not exist in MySQL
				if(strcasecmp($cond[1], 'ilike') == 0) {
					// force mysql to handle the field as a char (necessary for translations that are stored in a binary field)
					$cond[0] = ' CAST('.$cond[0].' AS CHAR )';
					$cond[1] = 'LIKE';
				}
	            // format the value operand
				if(is_array($cond[2])) $value = '('.implode(',', array_map(__NAMESPACE__.'\DBManipulatorMySQL::escapeString', $cond[2])).')';
				else $value = DBManipulatorMySQL::escapeString($cond[2]);
				// concatenate query string with current condition
				$sql .= $cond[0].' '.$cond[1].' '.$value;
			}
		}
		if(strlen($sql) > 0) $sql = ' WHERE ('.$sql.')';
		return $sql;
	}

	/**
	 * Get records from specified table, according to some conditions.
	 *
	 * @param	array $tables name of involved tables
	 * @param	array $fields list of requested fields
	 * @param	array $ids ids to which the selection is limited
	 * @param	array $conditions list of arrays (field, operand, value)
	 * @param	string $id_field name of the id field ('id' by default)
	 * @param	string $order name of the order field
	 * @return	resource reference to query resource
	 */
	public function getRecords($tables, $fields=NULL, $ids=NULL, $conditions=NULL, $id_field='id', $order='', $sort='ASC', $start=0, $limit=0) {       
		// cast tables to an array (passing a single table is accepted)
		if(!is_array($tables))						$tables = (array) $tables;
		// in case fields is not null ans is not an array, cast it to an array (passing a single field is accepted)		
		if(isset($fields) && !is_array($fields))	$fields = (array) $fields;
		// in case ids is not null ans is not an array, cast it to an array (passing a single id is accepted)				
		if(isset($ids) && !is_array($ids))			$ids = (array) $ids;						

        // test values and types        
		if(empty($tables)) throw new Exception(__METHOD__." : unable to build sql query, parameter 'tables' array is empty.", SQL_ERROR);
/* irrelevant
		if(!empty($fields) && !is_array($fields)) throw new Exception(__METHOD__." : unable to build sql query, parameter 'fields' is not an array.", SQL_ERROR);
		if(!empty($ids) && !is_array($ids)) throw new Exception(__METHOD__." : unable to build sql query, parameter 'ids' is not an array.", SQL_ERROR);
*/		
		if(!empty($conditions) && !is_array($conditions)) throw new Exception(__METHOD__." : unable to build sql query, parameter 'conditions' is not an array.", SQL_ERROR);

		// select clause
		// we could add the following directive for better performance (we disabled it to maximize code portability)
		// $sql = 'SELECT SQL_CALC_FOUND_ROWS ';
		$sql = 'SELECT ';
		if(empty($fields)) $sql .= '*';
		else foreach($fields as $field) $sql .= DBManipulatorMySQL::escapeFieldName($field).', ';
		$sql = rtrim($sql, ' ,');

		// from clause
        $sql .= ' FROM ';
		foreach($tables as $table_alias => $table_name) {
			if(!is_numeric($table_alias)) $sql .= '`'.$table_name.'` as `'.$table_alias.'`, ';
			else $sql .= '`'.$table_name.'`, ';
		}
		$sql = rtrim($sql, ' ,');

		// where clause
		$sql .= $this->getConditionClause($id_field, $ids, $conditions);

		// order clause
		if(!empty($order)) $sql .= ' ORDER BY '.DBManipulatorMySQL::escapeFieldName($order)." $sort";

		// limit clause
		if($limit) $sql .= sprintf(" LIMIT %d, %d", $start, $limit);
		return $this->sendQuery($sql);
	}

	public function setRecords($table, $ids, $fields, $conditions=null, $id_field='id'){
        // test values and types
		if(empty($table)) throw new Exception(__METHOD__." : unable to build sql query ($sql), parameter 'table' empty.", SQL_ERROR);
		if(empty($fields)) throw new Exception(__METHOD__." : unable to build sql query ($sql), parameter 'fields' empty.", SQL_ERROR);

		// update clause
		$sql = 'UPDATE `'.$table.'`';

		// set clause
        $sql .= ' SET ';
		foreach ($fields as $key => $value) $sql .= "`$key`={$this->escapeString($value)}, ";
		$sql = rtrim($sql, ', ');

		// where clause
		$sql .= $this->getConditionClause($id_field, $ids, $conditions);

		return $this->sendQuery($sql);
	}

	/**
	* @deprecated	use addRecords
	*/
	public function addRecord($table, $fields) {
		$result = false;
		if (!is_array($fields)) throw new Exception(__METHOD__." : parameter 'fields' is missing", SQL_ERROR);
		$cols = '';
		$vals = '';
		foreach ($fields as $field => $val) {
			if(strlen($cols) > 0 ) $cols .= ',';
			if(strlen($vals) > 0 ) $vals .= ',';
			$cols .= "`$field`";
			$vals .= $this->escapeString($val);
		}
		if(strlen($cols) > 0 && strlen($vals) > 0) {
			$sql = "INSERT INTO `$table` ($cols) VALUES ($vals);";
			$result = $this->sendQuery($sql);
		}
		return $result;
	}

	/**
	 * Inserts new records in specified table.
	 *
	 * @param	string $table name of the table in which insert the records
	 * @param	array $fields list of involved fields
	 * @param	array $values array of arrays specifying the values related to each specified field
	 * @return	resource reference to query resource
	 */
	public function addRecords($table, $fields, $values) {
		$result = false;
		if (!is_array($fields) || !is_array($values)) throw new Exception(__METHOD__.' : at least one parameter is missing', SQL_ERROR);
		$cols = '';
		$vals = '';
		foreach ($fields as $field) $cols .= "`$field`,";
		$cols = rtrim($cols, ',');
		foreach ($values as $val_array) {
			$vals .= '(';
			foreach($val_array as $val) $vals .= $this->escapeString($val).',';
			$vals = rtrim($vals, ',').'),';
		}
		$vals = rtrim($vals, ',');
		if(strlen($cols) > 0 && strlen($vals) > 0) {
			// note: we ignore duplicate enties, if any
			$sql = "INSERT IGNORE INTO `$table` ($cols) VALUES $vals;";
			$result = $this->sendQuery($sql);
		}
		return $result;
	}

	public function deleteRecords($table, $ids, $conditions=null, $id_field='id') {
		// delete clause
		$sql = 'DELETE FROM `'.$table.'`';
		// where clause
		$sql .= $this->getConditionClause($id_field, $ids, $conditions);
		return $this->sendQuery($sql);
	}

}
