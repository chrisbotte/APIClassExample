<?php

require_once realpath(__DIR__ . '../../../../') . '\global\api\RestCallRequest.php';

class interfaceDES{

	static $page_html;
	private static $head;
	private static $body;
	private static $custom_action;
	private static $custom_content;
	private static $custom_js;
	private static $config;
	const action_holder = "[____XXXX99action99XXXX____]";
	const content_holder = "[____XXXX99content99XXXX____]";

	public function __construct($config, $data, $fields, $meta){

		try{
			$this->config = $config;
			$this->buildCustomAction($this->config['project_id'],"import.php"); 
			$this->buildCustomContent($fields, $data, $meta);
			$tfn = realpath(__DIR__ . '../../' . '\html\body.html' );
			$this->HTMLBody($tfn);
			$this->page_html =$this->body;
			}

		catch (Exception $e){
			throw new Exception($e->getMessage());
		}
	}

	// *****************************************************************************************************************
	// takes a field name and raw val as args
	// references the metadata constructed in the config array
	// if a single val is looked up, returns a simple str
	// if multi vals referenced, a concatenated string is returned with br between strings
	// if no val provided, null is returned
	// *****************************************************************************************************************
	private function lookupMeta($fld, $val){

		if($val == null){
			return null;
		}else{
			$returnval = null;
			$val = explode("," ,$val); 
			$temp = $this->config['fields'];

			for ($i=0; $i < count($temp) ; $i++) { 
				if($temp[$i]['src_field'] == $fld){
					for ($j=0; $j < count($val); $j++) { 				
						$rc_fld = $temp[$i]['local_rc_field'];
						$returnval .= ($j==0) ? $temp[$i]['metadata'][$val[$j]] : "<br>" . $temp[$i]['metadata'][$val[$j]];
					}
				}
			}
		}

		return $returnval;
	}

	// *****************************************************************************************************************
	// used to build a string that will define action of form
	// *****************************************************************************************************************
	private function buildCustomAction($pid, $filename){
		
		$this->custom_action = '"'.$filename.'?pid='.$pid.'"';
	}



	// *****************************************************************************************************************
	// given 3 arrays (data, fields and meta flags)
	// returns html table that formats the data in data array
	// hides all non-conf fields from view
	// $fields arg = Array ('field1'-->0, 'field2'-->1) 0 or 1 to denote if val is to be displayed
	// $data is complete array of data from src
	// $meta is array of fields with a tag of 0 or 1 to denote if metadata is available
	// *****************************************************************************************************************
	private function buildCustomContent($fields, $data, $meta){

		$fields_keys = array_keys($fields);
		$pkey = $this->config['src_pkey_fld'];
		$t = '';

		$s = '<table id="selection"><thead>';

		$f = $this->config['fields'];

				for ($i=0; $i < count($f) ; $i++) { 

			if($fields[$f[$i]['src_field']]){ // if current src field is to be displayed...
				$t .= "<th nowrap>".$f[$i]['field_alias']."</th>";
			 }
		
		}

		$t = "<tr id='' class='temphdr'>" . $t . "</tr>";

		$s = $s . $t . '</thead><tbody>';

		$hdrcnt = 1;
		for ($i=0; $i < count($data) ; $i++) { // get current record (array element)

			if($hdrcnt % 16 == 0 && $hdrcnt> 0){
				$s .= $t;
				$hdrcnt += 1;
				$i -= 1;
				continue;
			}

			$hdrcnt += 1;
			$s .= "<tr id='".$data[$i][$pkey]."'>";

			for ($j=0; $j < count($fields) ; $j++) { // step thru all of the fields in current record
				// set the current field
				$cf = $fields_keys[$j];
		 		if($fields[$cf]){ // ensures only fields that are supposed to be visible are displayed
			 		$cv = $data[$i][$cf];
			 		
			 		if($meta[$cf]==1){  // if meta avail for current field...
		 				$fv = $this->lookupMeta($cf, $cv);
		 			}
		 			else{ // otherwise just set the raw val to the final value to be displayed
		 				$fv = $cv;
		 			}
		 		
		 			// display final value	
		 			$s .= "<td nowrap title='".$fv."'>" . $fv ."</td>";	
		 		}
			} 
			$s .= '</tr>';
		}
		
		$s .= '</tbody></table>';

		$this->custom_content = $s;
	}


	private function HTMLHead($filename){
		$this->head = file_get_contents($filename);
	}

	private function HTMLBody($filename, $content=null){
		$body = file_get_contents($filename);
		$body = str_replace(interfaceDES::action_holder, $this->custom_action, $body);
		$body = str_replace(interfaceDES::content_holder, $this->custom_content, $body);
		$this->body = $body;
	}
}

?>