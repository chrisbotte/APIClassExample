<?php

require_once realpath(__DIR__ . '../../../../') . '\redcap_connect.php';
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

require_once realpath(__DIR__ . '../../../') . '\global\fcns\functions.php';
require_once '\class\des_class_config.php';
require_once '\class\des_class_data.php';
require_once '\class\des_class_ui.php';
require_once '\class\des_class_import.php';


if(isset($_GET['pid'])){
	$pid = $_GET['pid'];
}
else{
	exit(); // elaborate
}

if(isset($_POST['des_key']) && isset($_POST['exc'])){

	$key = $_POST['des_key'];
	$exc = $_POST['exc'];
	try{
		$c = new configDES($_GET['pid']);	
		$config = 	$c->getConfig();
		$desconn = $c->getDESConn(); 
		$import = new srcDataDES($config, $key);
		
		new importSRCtoRCDES($import->getData(), $config, $exc);
		
		
		if($exc != 1){
			echo '<div style="background-color:#5CAD85;border:1px solid;max-width:700px;padding:6px;color:white;">Import of Records with INCLUSION Designation Successful Attempting to redirect... </div>';
			echo '<meta http-equiv="refresh" content="3;url='.REDIRECT_IMPORT.'" />';
		}
		else{
			echo '<div style="background-color:#5CAD85;border:1px solid;max-width:700px;padding:6px;color:white;">Import of Records with EXCLUSION Designation Successful! Attempting to redirect...</div>';
			echo '<meta http-equiv="refresh" content="3;url='.REDIRECT_EXCLUDE.'" />';
		}
	
	}

	catch (Exception $e){
		writeDESLog($e->getMessage(), $_GET['pid']);	
	}
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';

?>