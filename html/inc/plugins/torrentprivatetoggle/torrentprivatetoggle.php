<?php

require_once('inc/plugins/PluginAbstract.php');
require_once('inc/generalfunctions.php');
require_once('inc/classes/FormGenerator.php');

class TorrentPrivateToggle extends PluginAbstract
{

	function show() {
		// nothing to show
	}
	
	function get() {
		;
	}
	
	function moveFile($transfer, $destination) {
		print("Moving file $transfer to $destination");
		
		require_once('inc/classes/ClientHandler.php');
		$client = ClientHandler::get_ch()->getInstance(getTransferClient($transfer));
		$client->move($transfer, $destination);
	}
	
	function handleRequest($requestdata)
	{
		if ( is_request_set('transfer') ) { // TODO: rewrite this so developer doesn't need to know the exact field name that is generated in the form
			$cfg = Configuration::get_instance()->get_cfg();
			$privatepath = getDownloadPath();
			$sharedpath = getDownloadPath($shared = true);
			
			$client = ClientHandler::get_ch()->getInstance(getTransferClient($_REQUEST['transfer']));
			$transfer = $client->getTransfer($_REQUEST['transfer']);
			$data = $transfer->getTransferListItem();
			
			if ( $cfg['uid'] == 1 || $data['is_owner'] ) { // if admin or owner
				if ( strpos($data['datapath'], $privatepath) === FALSE ) {
					print("Transfer made private<br>");
					$client->move($_REQUEST['transfer'], $privatepath);
				} else {
					$client->move($_REQUEST['transfer'], $sharedpath);
					print("Transfer made public<br>");
				}
			}
		} else {
			$this->show(); // SHOW
		}
	}
	
	/* NOT IN USE */

	
	static function getConfiguration() {
		;
	}
	
	static function setConfiguration($configArray) {
		;
	}
}

?>
