<?php

require_once realpath(__DIR__ . '../../../../') . '\global\api\RestCallRequest.php';

class configDES{
	private static $config;
	private static $conn;
	private static $rc_conn;
	private static $des_global_tbl;
	private static $pid;
	private static $logtxt;
	private static $deshost;
	private static $despwd;
	private static $desuname;
	private static $desschema;

	// *****************************************************************************************************************
	// Simple getFcns to return private members
	// *****************************************************************************************************************
	public function getConfig(){
		return $this->config;
	}

	public function getDESConn(){
		return $this->conn;
	}

	// *****************************************************************************************************************
	// Connect to current RC Schema - set member $rc_conn
	// retrieves connection info from DES tables
	// No 'hard-coded' values in connection string
	// throws exception if connection to RC is not possible
	// exception handled in calling function
	// *****************************************************************************************************************
	private function connectRC(){
		$conn= mysqli_connect($this->config['rc_hostname'],$this->config['conn_uname'],$this->config['conn_pwrd'],$this->config['curr_rc_schema']);
	
		if(mysqli_connect_errno()){
			throw new Exception('ERR_DES_CONFIG_RCCONN: Script Terminated: Failed to Connect to RC Schema: '. mysqli_connect_errno());
		}
		$this->rc_conn = $conn;
	}

	// *****************************************************************************************************************
	// Connect to current DES Schema (custom mysql) - set member $conn
	// throws exception if connection to RC is not possible
	// exception handled in calling function
	// *****************************************************************************************************************
	private function connectDES(){
		$conn= mysqli_connect(DES_HOST,DES_UNAME,DES_PWD, DES_SCHEMA);

		if(mysqli_connect_errno()){
			throw new Exception('ERR_DES_CONFIG_DESCONN: Script Terminated: Failed to Connect to DES Schema. Initialization Failure:: '. mysqli_connect_errno());
		}
		$this->conn = $conn;
	}


	// *****************************************************************************************************************
	// fcn uses extraction_already_loaded sql from config
	// attempts to set the recs_already_loaded element of array with a comma sep string of ids in form of '1','2', ...
	// if sql is empy, null is set to the element noted above
	// will only utilize a RC project on the RC server in which DES is running
	// *****************************************************************************************************************
	private function setCurrentRECID(){
		$sql = $this->config['extraction_already_loaded'];
		if($sql==null){
			$this->config['recs_already_loaded'] = null;
			return;
		}

		$res = mysqli_query($this->rc_conn, $sql);
		if(is_bool($res)){
			throw new Exception("ERR_DES_CONFIGS_SETCURR_RECID: Script Terminated: Could Not Execute Query: ". $sql);
		}
		
		$num_rows = mysqli_num_rows($res);

		// fetch rows and build string for config array element 'recs_already_loaded'
		$result='';
 		for ($i=0; $i <$num_rows; $i++) { 
			$row = mysqli_fetch_assoc($res);
		 	$result .= ($i == 0) ? "'".$row['record']."'" : ",'" . $row['record'] ."'"; 
		}
		$this->config['recs_already_loaded'] = $result;
	}


	// *****************************************************************************************************************
	// fcn uses extraction_constrain sql from config
	// attempts to set the valid_recs element of array with a comma sep string of ids in form of '1','2', ...
	// if sql is empy, null is set to the element noted above
	// will only utilize a RC project on the RC server in which DES is running
	// *****************************************************************************************************************
	private function setValidRECID(){
		$sql = $this->config['extraction_constrain'];
		if($sql==null){
			$this->config['valid_recs'] = null;
			return;
		}

		$res = mysqli_query($this->rc_conn, $sql);
		if(is_bool($res)){
			throw new Exception("ERR_DES_CONFIGS_SETVALID_RECID: Script Terminated: Could Not Execute Query: ". $sql);
		}
		$num_rows = mysqli_num_rows($res);
		
		$result=null;
 		for ($i=0; $i <$num_rows; $i++) { 
			$row = mysqli_fetch_assoc($res);
			$result .= ($i == 0) ? "'".$row['record']."'" : ",'" . $row['record'] ."'"; 
		}
		$this->config['valid_recs'] = $result;
		
	}


	// *****************************************************************************************************************
	// Build SQL to extract DES global values
	// throws exception in 2 cases - issue with SQL EXE or FETCH 
	// exception handled in calling function
	// takes global vals and merges them with the current config array
	// *****************************************************************************************************************
	private function setDESGlobals(){
		$sql = 'SELECT * FROM ' . DES_GLBL_TBL;

		$res = mysqli_query($this->conn, $sql);

		if(is_bool($res)){
			throw new Exception("ERR_DES_CONFIGS_DESGLOB: Script Terminated: Could Not Extract Needed Global Values Using Query: ". $sql);
		}

		$des_glob_vals = mysqli_fetch_assoc($res);
		if($des_glob_vals == null){
			throw new Exception("ERR_DES_CONFIGS_DESGLOB_FETCH: Script Terminated: Could Not Extract Needed Global Values Using mysqli FETCH". mysqli_connect_errno());
		}

		$this->config = array_merge($this->config, $des_glob_vals);
		mysqli_free_result($res);
	}

	// *****************************************************************************************************************
	// Build SQL to extract DES PROJECT VALS
	// throws exception in 2 cases - issue with SQL EXE or FETCH 
	// exception handled in calling function
	// takes project level vals and merges them with the current config array
	// *****************************************************************************************************************
	private function setDESProjectInfo(){
		$sql = 'SELECT * FROM ' . $this->config['des_project_tbl'] . ' WHERE project_id = ' . $this->pid;
		$res = mysqli_query($this->conn, $sql);

		if(is_bool($res)){
			throw new Exception("ERR_DES_CONFIGS_DESPROJ: Script Terminated: Could Not Extract Needed Project Info Using Query: " . $sql);
			}



		$des_project_vals = mysqli_fetch_assoc($res);
		if($des_project_vals == null){
			throw new Exception("ERR_DES_CONFIGS_DESGPROJ_FETCH: Script Terminated: Could Not Extract Needed Project Info Using mysqli FETCH". $sql . mysqli_connect_errno());
		}


		// merge array rtrieved from curr SQL with config
		$this->config = array_merge($this->config, $des_project_vals);
		mysqli_free_result($res);
	}

	// *****************************************************************************************************************
	// Build SQL to extract DES Mappings
	// throws exception in 2 cases - issue with SQL EXE or FETCH 
	// exception handled in calling function
	// converts rows in des_config to array
		
		// project_id|local_rc_field|src_field|alias|....
		// 18        |study_id      |rec_id   |ID   |....

		// to
		
		// [fields][1]=>(
				// ['local_rc_field'] = study_id
				// ['src_field'] = rec_idf
				// ['alias'] = ID
				// ...
				// ...)
	// *****************************************************************************************************************
	private function setDESFieldMappings(){
		$sql = 'SELECT * FROM ' . $this->config['des_config_tbl'] . ' WHERE project_id = ' . $this->pid . ' order by fld_order';
		$res = mysqli_query($this->conn, $sql);

		if(is_bool($res)){
			throw new Exception("ERR_DES_CONFIGS_DESMAP: Script Terminated: Could Not Extract Needed Field Mappings Using Query: " . $sql);
		}

		// set row count and field names from des mappings table (not actual fields to be extracted)
		$num_rows = mysqli_num_rows($res);
		if($num_rows <= 0){
			throw new Exception("ERR_DES_CONFIGS_DESMAP_ROWS: Script Terminated: No Field Mappings Found in DES");
		}

		// note: next block obtains field names for the mapping table, not the actual field names that will be mapped/xferred
		$proj_config_flds = mysqli_fetch_fields($res);
		if($proj_config_flds==null){
			throw new Exception("ERR_DES_CONFIGS_DESMAP_FETCH: Script Terminated: Could Not Extract Needed MAPPING Info Using mysqli FETCH");
		}

		for ($x=0; $x < $num_rows ; $x++) { 

			// retrieve a row...
			$config_vals = mysqli_fetch_assoc($res);		

			for ($i=0; $i < count($proj_config_flds) ; $i++) { 

				// ... now cycle through all of the fields returned in query
				// some fields require different configurations
				$curr_fld = $proj_config_flds[$i]->orgname;
				
				if($curr_fld != 'project_id'){
					$curr_val = $config_vals[$curr_fld];
					$this->config['fields'][$x][$curr_fld] = $curr_val;
				}
				
				// add field type for all RC fields (not part of des_config)
				if($curr_fld == 'local_rc_field'){
					$this->config['fields'][$x]['fld_type'] = REDCap::getFieldType($curr_val);
				}		
			}
		}
	}

	// *****************************************************************************************************************
	// for each field - get meta data string and convert it to array
	// if no string (implying no meta), set to null
	// *****************************************************************************************************************
	public function setMetaData(){
		$flds = $this->config['fields'];

		for ($i=0; $i < count($flds) ; $i++) { 
			$meta = $this->getFieldMetaData($flds[$i]['local_rc_field']);
			$this->config['fields'][$i]['metadata']= mapStringToAssocArray($meta,'\n',",");
		}
	}

	// *****************************************************************************************************************
	// go to des tabl for project and build/execute sql to generate checkbox fld array
	// *****************************************************************************************************************
	private function getCheckboxFields(){
		// if sql is missing for some reason, assume that there are no checkbox vals
		if ($this->config['sql_checkbox_flds']==null){
			$this->config['cb_flds'] = null;
			return;
		}

		// build sql using project id from config array
		$sql = $this->config['sql_checkbox_flds'] . $this->config['project_id'];
		
		// exe sql
		$res = mysqli_query($this->conn, $sql);
		if(is_bool($res)){
			throw new Exception("ERR_DES_CONFIGS_CB: Script Terminated: Could Not Extract Checkbox Fields Using Query: " . $sql);
		}

		$final = '';
		
		$num_rows = mysqli_num_rows($res);
		if($num_rows == 0){
			return null;
		}

		// assuming there are checkbox fields present (per mapping table in DES), build string
		for ($i=0; $i < $num_rows ; $i++) { 
			$row = mysqli_fetch_assoc($res);

			if($row==null){
				throw new Exception("ERR_DES_CONFIGS_DES_CB_FETCH: Script Terminated: Could Not Extract Needed Checkbox Info Using mysqli FETCH");
			}

			$final .= ($i == 0) ? $row['src_field'] : "," . $row['src_field'];	
		}

		// explode str into array
		$this->config['cb_flds'] = explode(",",$final);
	}

	// *****************************************************************************************************************
	// Utility fcn - used to extract actual metadata str for given field name in RC project 
	// if no string (implying no meta), set to null
	// *****************************************************************************************************************
	public function getFieldMetaData($field){
		$pid = $this->pid;

// define sql
$sql = <<<DATA
SELECT
element_enum
, element_type 
FROM 
redcap_metadata 
WHERE 
project_id = $pid and 
field_name='$field' and 
element_type in ('select','yesno','checkbox','radio') LIMIT 1;
DATA;

		$results=mysqli_query($this->rc_conn, $sql);
		$rows = mysqli_num_rows($results);
		$r = null;
		
		//extract result - should only retrieve 1 row, error otherwise
		if($rows == 1){ 
			$row = mysqli_fetch_assoc($results);
			$r = ($row['element_type']=="yesno" || $row['element_type']=="truefalse" ) ? "0, No \\n 1, Yes" : $row['element_enum'];
		} 

		return $r;	
	}


	public function __construct($pid){
		$this->config = array();
		$this->pid = $pid;

		try{
			$this->connectDES();
			$this->setDESGlobals();
			$this->setDESProjectInfo();
			$this->setDESFieldMappings();	
			$this->connectRC();
			$this->setMetaData();
			$this->getCheckboxFields();
			$this->setCurrentRECID();
			$this->setValidRECID();
		}

		catch (Exception $e){
			// propogate exception to caller
			throw new Exception($e->getMessage());
		}
	}
}


?>