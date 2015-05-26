<?php

require_once realpath(__DIR__ . '../../../../') . '\global\api\RestCallRequest.php';



class importSRCtoRCDES{

	private static $xml;
	private static $config;
	private static $recs_to_import;
	private static $exc;


	// *****************************************************************************************************************
	// CONSTRUCTOR USED TO GATHER CONFIG INFO AND DATA
	// assemble the XML str
	// import data
	// *****************************************************************************************************************
	public function __construct($data, $config, $exc){		
		try{
			$this->config = $config;
			$this->exc = $exc;
			$this->buildXMLforAPI($data);
			$this->importAPI();			
		}

		catch (Exception $e){
			throw new Exception($e->getMessage());
		}
	}


	// *****************************************************************************************************************
	// Utility fcn used to notify WS that record id was reviewed and imported into RC
	// *****************************************************************************************************************
	private function notifyWSReviewed(){
		$url = $this->config['ws_write'];
		$recs = $this->recs_to_import;

		for ($i=0; $i < count($recs); $i++) { 
			$json = file_get_contents($url . $recs[$i]);
			$data = json_decode($json,true);
			if(strtolower($data['status']) != 'ok'){
				throw new Exception('Error With Notification of WS to report reviewed records. Please copy and report this message to a REDCap Admin. <br>Full Submission Attempted: ' . implode(";",$recs) . '<br>FAILED REC: ' . $recs[$i] . " :: " . $json);	
			}
		}


	}

	// *****************************************************************************************************************
	// Utility fcn used to store records that were loaded into XML for API Import - will need this info
	// to notify WS which rec(s) to mark as reviewed
	// *****************************************************************************************************************

	public function getRecsToImport(){

		return $this->recs_to_import;
	}



	private function importAPI(){

		$cUID = strtolower(USERID);
		$user_rights = REDCap::getUserRights($cUID);
		$rc_token = $user_rights[$cUID][api_token];

		if($rc_token == null){
			throw new Exception('Error With API Import of Data.  No Valid Token Prersent.');	
		}


		$data = array('content' => 'record', 'type' => 'eav', 'format' => 'xml', 'token' => $rc_token , // $this->config['local_src_token'] 
		'data' => $this->xml);

		# create a new API request object
		$request = new RestCallRequest($this->config['api_url'], 'POST', $data);

		# initiate the API request
 		$request->execute();
		
		$r = $request->getResponseInfo();
		if($r['http_code'] != 200){
			throw new Exception('Error With API Import of Data.  Data not Imported!! Check with REDCAP Admin. HTTP_CODE - ' . $r['http_code'] . " " . $request->getResponseBody());
		}

		// print_r($request->getResponseBody()); // used for err trapping
		// add fcn to call url n times to indicate that record was imported/reviewed
		
		if($this->config['ws_flag']==1){
			$this->notifyWSReviewed();
		}
	}	




	// *****************************************************************************************************************
	// given a key val (src fld) returns corresponding local rc field
	// *****************************************************************************************************************
	private function getRCField($key){

		$f = $this->config['fields'];
		$result = null;

		for ($i=0; $i < count($f) ; $i++) { 

			 if($f[$i]['src_field'] == $key){
			 	$result = $f[$i]['local_rc_field'];
			 } 	

		}

		return $result;
	}


	// *****************************************************************************************************************
	// given a key val ($field) returns T/F, depending on if the key val is found as a src field AND 
	// contains a field that is checkbox or not
	// ADDRESS: should be limited to fields in mappings
	// *****************************************************************************************************************
	public function isCheckbox($field){
	
		$result = false;
		$config_fields = $this->config['fields'];
	
		for ($i=0; $i < count($config_fields) ; $i++) { 
			if($config_fields[$i]['src_field']==$field && $config_fields[$i]['checkbox']==1 ){
				return true;
			}
		}

		return $result;
	}



	private function buildXMLforAPI($data){
		
		$xml_begin = '<?xml version="1.0" encoding="UTF-8" ?><records>';
		$xml_end = "</records>";
		$content = '';

		for ($i=0; $i < count($data); $i++) {
			$fields_keys = array_keys($data[$i]); 
			$cr = $data[$i][$this->config['src_pkey_fld']];
			

			for ($j=0; $j < count($data[$i]) ; $j++) { 
				$fn = $fields_keys[$j];

				if(!importSRCtoRCDES::isCheckbox($fn)){
					$cv = (is_array($data[$i][$fn])) ? $data[$i][$fn]['raw'] : $data[$i][$fn];
					$cv = escapeXML($cv);
					$fn = importSRCtoRCDES::getRCField($fn);

$template = <<<DATA
   <item>
      <record>$cr</record>
      <field_name>$fn</field_name>
      <value>$cv</value>
   </item>
DATA;
					$this->recs_to_import[$cr]=1; //load recs to import array
					$content .= $template;
				}
				else{
					$content .= importSRCtoRCDES::buildCheckboxXML($cr, $data[$i][$fn], importSRCtoRCDES::getRCField($fn));
					$this->recs_to_import[$cr]=1; //load recs to import array
				}
			}
		
			if($this->exc == 1){
				$content .=  "<item><record>".$cr."</record><field_name>exc</field_name><value>1</value></item>";
			}
		}



	$this->xml = $xml_begin . $content . $xml_end;	
	$this->recs_to_import = array_keys($this->recs_to_import);

	}


	// *****************************************************************************************************************
    //	Fcn takes a comma sep list of values for a check box and creates the necc XML content for given checkbox field
	// *****************************************************************************************************************
	private function buildCheckboxXML($cr, $cv, $fn){

		if($cv == null){
			return null;
		}

		$content = '';

		$cv = explode(",", $cv);
		for ($k=0; $k < count($cv); $k++) { 
			$new_fn = $fn . "___" . $cv[$k];

$template = <<<DATA
<item>
  <record>$cr</record>
  <field_name>$new_fn</field_name>
  <value>1</value>
</item>
DATA;

		$content .= $template;
		}

	return $content;	
	}
}

?>