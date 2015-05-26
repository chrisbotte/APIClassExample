<?php

require_once realpath(__DIR__ . '../../../../') . '\global\api\RestCallRequest.php';

class srcDataDES{

	private static $content;
	private static $config;
	private static $src_conn;
	private static $results_single;
	private static $results_multi;
	private static $valid_fields;
	private static $all_fields;
	private static $meta_fields;
	private static $data;

	// *****************************************************************************************************************
	// Utility fcns to return private members
	// *****************************************************************************************************************

	public function getData(){
		return $this->data;
	}

	public function getValidFields(){
		return $this->valid_fields;
	}


	public function getMetaData(){
		return $this->meta_fields;
		
	}

	// *****************************************************************************************************************
	// Utility fcn - used to SET an array of RC field names with a 1 or 0
	// returns something like
	//    Array ( [rec_id] => 0 [mrn] => 0 [institution] => 1 [weight] => 0 [dob] => 0 [premie] => 1 [synd] => 1 [diagnosed_syndrome] => 1 ) 
	// *****************************************************************************************************************
	private function setMetaFields(){

		for ($i=0; $i < count($this->config['fields']); $i++) { 
		
			$cf = $this->config['fields'][$i]; // set curent field in loop iteration

			if (is_array($cf['metadata'])){
				$this->meta_fields[$cf['src_field']] = 1;
			}
			else{
				$this->meta_fields[$cf['src_field']] = 0;
			}
		}
	}

	//************************************************************************
	// remove any fields that are not mapped
	// if needed, remove all records, except ones passed via $ids arg
	//************************************************************************
	private function WSTrimData($data, $flds, $src_pkey_fld, $ids=null){

		for ($i=0; $i < count($data) ; $i++) { 

			$cr = $data[$i];
			$k = array_keys($cr);
			$f = array_keys($flds);

			for ($j=0; $j < count($k) ; $j++) { 
			
				$currkey = $k[$j];
			
				if(!in_array($currkey,$f)){
					unset($data[$i][$currkey]);
				}
			}
		}

		
		if($ids!=null){
			
			$ids = str_replace("'", "", $ids);
			$selarr = explode(',', $ids);
			$y = 0;
			$newdata = array();

			for ($i=0; $i < count($data) ; $i++) { 
				$ckey = $data[$i][$src_pkey_fld];
			
				if(in_array($ckey,$selarr)){

					$newdata[$y] = $data[$i];
					$y = $y + 1;
								}
			}
			$this->data = $newdata;	
		}
		else{
			$this->data = $data;
		}

	}

	//************************************************************************
	// remove all recs from array that already appear in RC project
	//************************************************************************

	private function WSRemoveLoadedData($data, $src_pkey_fld, $ids){

		if ($ids == null || $ids==''){
			return false;
		}

		$ids = str_replace("'", "", $ids);
		$selarr = explode(',', $ids);
	
		$y = 0;
		$newdata = array();

		for ($i=0; $i < count($data) ; $i++) { 
			$ckey = $data[$i][$src_pkey_fld];
		
			if(!in_array($ckey,$selarr)){
				$newdata[$y] = $data[$i];
				$y = $y + 1;
			}
		}
		$this->data = $newdata;	
	}


	// *****************************************************************************************************************
	// execute all fcns needed to build final data array member
	// *****************************************************************************************************************
	public function __construct($config, $ids = null){
		$this->config = $config;
		
		if($config['ws_flag']==1){

		$json = file_get_contents($config['ws_url']);
		// $json = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $json);
		// $json = trim($json);
		$err = null;
		$data = json_decode($json, true);

			if(json_last_error() != JSON_ERROR_NONE){

				switch (json_last_error()) {
				    case JSON_ERROR_NONE:
				        $err = ' - No errors';
				    break;
				    case JSON_ERROR_DEPTH:
				        $err = ' - Maximum stack depth exceeded';
				    break;
				    case JSON_ERROR_STATE_MISMATCH:
				        $err = ' - Underflow or the modes mismatch';
				    break;
				    case JSON_ERROR_CTRL_CHAR:
				        $err = ' - Unexpected control character found';
				    break;
				    case JSON_ERROR_SYNTAX:
				        $err = ' - Syntax error, malformed JSON';
				    break;
				    case JSON_ERROR_UTF8:
				        $err = ' - Malformed UTF-8 characters, possibly incorrectly encoded';
				    break;
				    default:
				        $err = ' - Unknown error';
				    break;

				}
				throw new Exception('ERR_WS: Script Terminated: Failed to Connect to WS: '. $err);
			}
			
			if($data['status']==='OK'){
				$this->data=$data['cases'];
				$this->setMetaFields();
				$this->setConfFields();
				$this->WSTrimData($this->data,$this->getValidFields(), $config['src_pkey_fld'], $ids);
				$this->WSRemoveLoadedData($this->data, $config['src_pkey_fld'], $config['recs_already_loaded']);
			}
			else{
				throw new Exception('ERR_WS: Script Terminated: Failed to Connect to WS');
			}

		}
		else{

			$conn = odbc_connect(SRC_DSN,SRC_UNAME,SRC_PWD);
			if($conn==false){
				throw new Exception('ERR_DES_DATA_CONN: Script Terminated: Failed to Connect to SRC Schema');
			}

			try{
				$this->src_conn = $conn;
				$this->executeSRC_SQL($ids);
				$this->extractSRC_SingleData();
				$this->setMetaFields();
			
			}

			catch (Exception $e){
				throw new Exception($e->getMessage());
			}
		}
	}

	// *****************************************************************************************************************
	// exe extraction sql statement in config array
	// src_pkey_fld is stored in DES table - used to inform the name of the PKEY field in the SQL statement
	// result is only filtered if $ids is not null
	// query is executed and result is stored in class member - throws except if the query does not execute
	// setConfFields is called to set the fields that will be shown on the confirmation screen
	// *****************************************************************************************************************
	private function executeSRC_SQL($ids){

		$sql = $this->config['extraction_sql_discrete'];
	
		$in_valid = ($this->config['valid_recs']==null) ? null : $this->config['extraction_key'] . " in(".$this->config['valid_recs'].")";
		$in_loaded =  ($this->config['recs_already_loaded']==null) ? null : $this->config['src_pkey_fld'] . " not in(".$this->config['recs_already_loaded'].")";
		$wc = null;

		// build where clause based upon loaded and constrain criteria present...

		if($in_valid != null || $in_loaded != null){ // if at least one of the 2 variables is not null...
		
			if($in_valid != null && $in_loaded != null){ // both vars have content...
				$wc = ' where ' . $in_valid . " and "	. $in_loaded;	
			}

			if($in_valid != null && $in_loaded == null){ // nothing loaded, but recs present in valid pi RC project
				$wc = ' where ' . $in_valid;	
			}

			if($in_valid == null && $in_loaded != null){ // recs already loaded, no valid pi project in RC
				$wc = ' where ' . $in_loaded;	
			}
		}


		if($ids==null){
			$sql = $sql . $wc;
		}

		// if no where clause, just build a simple where clause with ids passed to fcn
		if($ids != null && $wc==null){
			$sql = $sql . " where " . $this->config['src_pkey_fld'] ." in (" . $ids . ")";
		}
		
		// extend where clause ids sent to fcn
		if($ids != null && $wc!=null){
			$sql = $sql . $wc . ' and ' . $this->config['src_pkey_fld'] ." in (" . $ids . ")";
		}
		
		//apply any fimal order by clause
		$sql .= $this->config['table_order_by'];
		$res=odbc_exec($this->src_conn, $sql);
		if(!$res){
			throw new Exception("ERR_DES_DATA_SRCSQL: Script Terminated: Could Not Extract Data From SRC System (Single) Using Query: " . $sql);
		}

		$this->results_single = $res;
		
		$res = null;

		srcDataDES::setConfFields();
	}

	// *****************************************************************************************************************
	// used to return array of field names as keys.  each field name has a flag value of 0 or 1 to denote if it should be 
	// visible on confirmation page.
	// class member $valid_fields is set by this fcn
	// *****************************************************************************************************************
	private function setConfFields(){

		$valid_fields = array();
		$flds = explode(",", $this->getFields() );

		for ($i=0; $i < count($flds); $i++) { 
			
			$curr_src_fld  =  $flds[$i];
			$valid_fields[$curr_src_fld] = srcDataDES::isConfirmationField($curr_src_fld);
	
		}

		$this->valid_fields = $valid_fields;
	}

	// *****************************************************************************************************************
	// Utility Fcn
	// returns comma separated list of field names
	// ALSO sets the all_fields member of class
	// names comprised of src data field names
	// *****************************************************************************************************************
	private function getFields(){
		$t = "";
		
		for ($i=0; $i < count($this->config['fields']); $i++) { 

			if($i == 0){
				$t .= $this->config['fields'][$i]['src_field'];
			}
			else{
				$t .= "," . $this->config['fields'][$i]['src_field'];
			}
		}

		$this->all_fields = explode(",",$t);
		return $t;
	}

	// *****************************************************************************************************************
	// utility fcn
	// used to lookup value of 'confirmation' for each field mapping
	// assuming initial des tbl mapping is correct, should return a 0 or 1
	// *****************************************************************************************************************
	private function isConfirmationField($field){
		$result = 0;
		$config_fields = $this->config['fields'];

		for ($i=0; $i < count($config_fields) ; $i++) {
		
			if($config_fields[$i]['src_field']==$field && $config_fields[$i]['confirmation']==1){
				return 1;
			}
		}
		
		return $result;
	}

	// *****************************************************************************************************************
	// ADDRESS:  need exception handling??
	// using result set executed by run of SQL, builds a nested array such that each main element reprsents a record
	// each record element contains an array of data, 1 element per field value
	// *****************************************************************************************************************
	private function extractSRC_SingleData(){

		$flds = $this->all_fields;

		$i = 0;
		while(odbc_fetch_row($this->results_single)){

			for ($j=0; $j < count($flds); $j++) { 
			
				$currdata = odbc_result($this->results_single,$flds[$j] );
				$this->data[$i][$flds[$j]] = $currdata;
			
			}
			
		$i = $i + 1;			
		}
	}
}


?>