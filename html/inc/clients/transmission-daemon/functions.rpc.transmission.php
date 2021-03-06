<?php

/* $Id$ */

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

require_once('inc/classes/singleton/db.php');
require_once('inc/lang/transferstatus.php');
require_once('inc/generalfunctions.php');

function rpc_error($errorstr,$dummy="",$dummy="",$response="") {
	require_once('inc/classes/singleton/Configuration.php');
	$cfg = Configuration::get_instance()->get_cfg();
	
	AuditAction("TRANSMISSION_DAEMON", $cfg["constants"]["error"], "Transmission RPC : $errorstr - $response");
	#@error($errorstr, "", "", $response);
	#addGrowlMessage('transmission-rpc',$errorstr.$response);
	//dbError($errorstr);
	print("There was an error: \n" . $errorstr . "\n" . $response);
	exit();
}


/**
 * convertTime
 *
 * @param $seconds
 * @return common time-delta-string
 */
function convertTime($seconds) { // TODO: put this in generalfunctions.php
	// sanity-check
	if ($seconds < 0) return '?';
	// one week is enough
	if ($seconds >= 604800) return '-';
	// format time-delta
	$periods = array (/* 31556926, 2629743, 604800,*/ 86400, 3600, 60, 1);
	$seconds = floatval($seconds);
	$values = array();
	$leading = true;
	foreach ($periods as $period) {
		$count = floor($seconds / $period);
		if ($leading) {
			if ($count == 0)
				continue;
			$leading = false;
		}
		array_push($values, ($count < 10) ? "0".$count : $count);
		$seconds = $seconds % $period;
	}
	return (empty($values)) ? "?" : implode(':', $values);
}


/**
 * get one Transmission transfer data array
 *
 * @param $transfer hash of the transfer
 * @param $fields array of fields needed
 * @return array or false
 */
function getTransmissionTransfer($transfer, $fields=array() ) {
	//$fields = array("id", "name", "eta", "downloadedEver", "hashString", "fileStats", "totalSize", "percentDone", 
	//			"metadataPercentComplete", "rateDownload", "rateUpload", "status", "files", "trackerStats" )
	$db = DB::get_db()->get_handle();
	$required = array('hashString');
	$afields = array_merge($required, $fields);
	
	// Get data that should be retrieved from db
	$sql = "SELECT public, tt.tid, tu.uid uid, user_id user
	FROM tf_transmission_user ttu, tf_transfers tt, tf_users tu
	WHERE ttu.tid=tt.tid
	AND ttu.uid=tu.uid AND tt.tid=\"$transfer\"";
	$row = $db->GetRow($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	
	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$trans = new Transmission();
	$response = $trans->get(array(), $afields);
	$torrentlist = $response['arguments']['torrents'];
	
	if (!empty($torrentlist)) {
		foreach ($torrentlist as $aTorrent) { // Why looping through all torrents? should be only one returned due to the hash
			if ( $aTorrent['hashString'] == $transfer ) {
				$aTorrent['public'] = ( array_key_exists('public', $row) ? $row['public'] : 0 );
				$aTorrent['user'] = ( array_key_exists("user", $row) ? $row['user'] : "foreign" );
				$aTorrent['uid'] = ( array_key_exists("uid", $row) ? $row['uid'] : 0 );
				
				return $aTorrent;
			}
		}
	}
	return false;
}



/**
 * set a property for a Transmission transfer identified by hash
 *
 * @param $transfer hash of the transfer
 * @param array of properties to set
 **/
function setTransmissionTransferProperties($transfer, $fields=array()) {
	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$trans = new Transmission();
	$transferId = getTransmissionTransferIdByHash($transfer);
	
	$response = $trans->set($transferId, $fields);
	if ( $response['result'] !== 'success' )
		rpc_error("Setting transfer properties failed", "", "", $response['result']);
}


/**
 * checks if transfer is running
 *
 * @param $transfer hash of the transfer
 * @return boolean
 */
function isTransmissionTransferRunning($transfer) {
	$aTorrent = getTransmissionTransfer($transfer, array('status'));
	if (is_array($aTorrent)) {
		return ( $aTorrent['status'] != 16 );
	}
	return false;
}

/**
 * checks if transfer is Transmission
 *
 * @param $transfer hash of the transfer
 * @return boolean
 */
function isTransmissionTransfer($transfer) {
	$aTorrent = getTransmissionTransfer($transfer);
	return is_array($aTorrent);
}

/**
 * getRunningTransmissionTransferCount
 *
 * @return int with number of running transfers for transmission daemon
 * TODO: make it return a correct value
 */
function getRunningTransmissionTransferCount() {
	$result = getUserTransmissionTransfers(1);
	$count = 0;

	// Note that this also counts the downloads that are not added through torrentflux
	foreach ($result as $aTorrent) {
		if ( $aTorrent['status']==4 || $aTorrent['status']==8 ) $count++;
	}
	return $count;
}

/**
 * This method gets Transmission transfers from a certain user from database in an array
 *
 * @return array with uid and transmission transfer hash
 */
function getUserTransmissionTransferArrayFromDB($uid = 0) {
	$db = DB::get_db()->get_handle();

	$retVal = array();
	//$sql = "SELECT tid,public,uid,user_id FROM tf_transmission_user ttu, tf_transfers tt, tf_users tu WHERE ttu.tid=tt.tid AND ttu.uid=tu.uid" . ($uid!=0 ? ' AND uid=' . $uid : '' );
	$sql = "SELECT public, tt.tid, public, tu.uid, user_id
FROM tf_transmission_user ttu, tf_transfers tt, tf_users tu
WHERE ttu.tid=tt.tid
AND ttu.uid=tu.uid AND ( public=1 " .
		($uid!=0 && $uid!=1 ? " OR ttu.uid=$uid )" : ")");
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	while($transfer = $recordset->FetchRow())
		array_push($retVal, $transfer);
	return $retVal;
}

/**
 * This method checks if a certain transfer is existing and from the same user
 *
 * @return array with uid and transmission transfer hash
 * TODO: check if $tid is filled in and return error
 * TODO: check that uid being zero cannot lead to security breach (information disclosure)
 */
function isValidTransmissionTransfer($uid,$tid) {
	$db = DB::get_db()->get_handle();

	$retVal = array();
	if ($uid == 1)
		return true;
	else
		$sql = "SELECT tid FROM tf_transmission_user WHERE tid='$tid' AND uid='$uid'";
	$recordset = $db->GetRow($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	if ( sizeof($recordset)!=0 ) return true;
	else return false;
}

/**
 * This method returns the owner name of a certain transmission transfer
 * 
 * @return string with owner of transmission transfer
 */
function getTransmissionTransferOwner($transfer) {
	$db = DB::get_db()->get_handle();

	$retVal = array();
	$sql = "SELECT user_id FROM tf_users u join tf_transmission_user t on (t.uid = u.uid) WHERE t.tid = '$transfer';";
	$row = $db->GetRow($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	if ( sizeof($row)!=0 ) {
		return $row['user_id'];
	}
	else return "Unknown";
}

/**
 * This method starts the Transmission transfer with the matching hash
 *
 * @return void
 */
function startTransmissionTransfer($hash,$startPaused=false) {
	$uid = $_SESSION['uid'];
	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$trans = new Transmission();

	if ( isValidTransmissionTransfer($uid,$hash) ) {
		$transmissionId = getTransmissionTransferIdByHash($hash);
		$response = $trans->start($transmissionId);
		if ( $response['result'] != "success" ) {
			rpc_error("Start failed", "", "", $response['result']);
			return false;
		}
		return true;
	} else {
		rpc_error("startTransmissionTransfer : Not ValidTransmissionTransfer hash=$hash ");
		return false;
	}
}

/**
 * This method stops the Transmission transfer with the matching hash
 *
 * @return void
 */
function stopTransmissionTransfer($hash) {
	$uid = $_SESSION['uid'];
	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$trans = new Transmission();

	if ( isValidTransmissionTransfer($uid,$hash) ) {
		$transmissionId = getTransmissionTransferIdByHash($hash);
		$response = $trans->stop($transmissionId);
		if ( $response['result'] != "success" ) rpc_error("Stop failed", "", "", $response['result']);
	}
}

/**
 * This method deletes the Transmission transfer with the matching hash, without removing the data
 *
 * @return void
 */
function deleteTransmissionTransfer($uid, $hash, $deleteData = false) {
	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$trans = new Transmission();

	if ( isValidTransmissionTransfer($uid, $hash) ) {
		$transmissionId = getTransmissionTransferIdByHash($hash);
		$response = $trans->remove($transmissionId,$deleteData);
		if ( $response['result'] != "success" )
			rpc_error("Delete failed", "", "", $response['result']);
		deleteTransmissionTransferFromDB($uid, $hash);
		AuditAction("DELETE", "INFO", "Transfer deleted: uid=$uid hash=$hash", $_SERVER['PHP_SELF']);
	} else {
		AuditAction("DELETE", "ERROR", "Attempt to delete transfer with other owner: uid=$uid hash=$hash", $_SERVER['PHP_SELF']);
	}
}

/**
 * This method deletes the Transmission transfer with the matching hash, and its data
 *
 * @return void
 */
function deleteTransmissionTransferWithData($uid, $hash) {
	deleteTransmissionTransfer($uid, $hash, true);
}

/**
 * This method retrieves the current ID in transmission for the transfer that matches the $hash hash
 *
 * @return transmissionTransferId
 */
function getTransmissionTransferIdByHash($hash) {
	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$transmissionTransferId = false;
	$trans = new Transmission();
	$response = $trans->get(array(), array('id','hashString'));
	if ( $response['result'] != "success" ) rpc_error("Getting ID for Hash failed: ".$response['result']);
	$torrentlist = $response['arguments']['torrents'];
	foreach ($torrentlist as $aTorrent) {
		if ( $aTorrent['hashString'] == $hash ) {
			$transmissionTransferId = $aTorrent['id'];
			break;
		}
	}
	return $transmissionTransferId;
}

/**
 * This method deletes a Transmission transfer for a certain user from the database
 *
 * @return void
 * TODO: return error if deletion from db does fail
 */
function deleteTransmissionTransferFromDB($uid,$tid) {
	$db = DB::get_db()->get_handle();

	$retVal = array();
	if ($uid == 1) // TODO this shouldn't be here actually, function isValidTransmissionTransfer should make sure deletion is save. THe second query can then be deleted
		$sql = "DELETE FROM tf_transmission_user WHERE tid='$tid'";
	else
		$sql = "DELETE FROM tf_transmission_user WHERE uid='$uid' AND tid='$tid'";
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	/*return $retVal;*/
}

/**
 * This method adds a Transmission transfer for a certain user in database
 *
 * @return array with uid and transmission transfer hash
 * TODO: check if $tid is filled in and return error
 */
function addTransmissionTransferToDB($uid,$tid) {
	$db = DB::get_db()->get_handle();

	$retVal = array();
	$sql = "INSERT INTO tf_transmission_user (uid,tid) VALUES ('$uid','$tid')";
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	/*return $retVal;*/
}

/**
 * This method adds a Transmission transfer to transmission-daemon
 *
 * @return array with uid and transmission transfer hash
 * TODO: generate an error when adding does fail
 */
function addTransmissionTransfer($uid, $url, $path, $paused=true) {
	// $path holds the download path

	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$rpc = new Transmission();

	$result = $rpc->add( $url, $path, array ('paused' => $paused)  );
	if($result["result"]!=="success") rpc_error("addTransmissionTransfer","","",$result["result"]. " url=$url");

	$hash = $result['arguments']['torrent-added']['hashString'];
	//rpc_error("The hash is: $hash. The uid is $uid"); exit();

	addTransmissionTransferToDB($uid, $hash);
	return $hash;
}

/**
 * This method adds a Transmission transfer for a certain user in database
 *
 * @return array with uid and transmission transfer hash
 */
function getUserTransmissionTransfers($uid) {
	$retVal = array();
	//if ( $uid!=1 ) {
		$userTransferDbData = getUserTransmissionTransferArrayFromDB($uid);
	//	if ( empty($userTransferDbData) ) return $retVal;
	//} else {
	//	$userTransferDbData = array();
	//}

	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	$rpc = new Transmission ();
	$fields = array ( "id", "name", "eta", "downloadedEver", "uploadedEver", "hashString", "fileStats", "totalSize", "percentDone", "metadataPercentComplete", "rateDownload", "rateUpload", "status", "files", "trackerStats", "downloadDir" );
	$result = $rpc->get ( array(), $fields );

	if ($result['result']!=="success") rpc_error("Transmission RPC could not get transfers : ".$result['result']);
	$userTransferHashes = array();
	foreach ($userTransferDbData as $transfer)
		$userTransferHashes[ $transfer['tid'] ] = $transfer;
	foreach ( $result['arguments']['torrents'] as $transfer ) {
		if ($uid==1 || array_key_exists( $transfer['hashString'], $userTransferHashes ) ) {
			$transferhash = $transfer['hashString'];
			
			// add some extra details
			if ( array_key_exists($transferhash, $userTransferHashes) ) {
				$transfer['public'] = $userTransferHashes[$transferhash]['public'];
				$transfer['user'] = $userTransferHashes[$transferhash]['user_id'];
				$transfer['uid'] = $userTransferHashes[$transferhash]['uid'];
			} else {
				$transfer['public'] = 0;
				$transfer['user'] = "foreign";
				$transfer['uid'] = 0;
			}
			
			array_push($retVal, $transfer);
		}
	}
	return $retVal;
}

//used in iid/index
function getTransmissionStatusImage($percentDone, $running, $seederCount, $uploadRate){
	$statusImage = "black.gif";
	if ($running) {
		// running
				if ($seederCount < 2)
						$statusImage = "yellow.gif";
				if ($seederCount == 0)
						$statusImage = "red.gif";
				if ($seederCount >= 2)
						$statusImage = "green.gif";
	}
	if ( floor($percentDone*100) >= 100 ) {
		$statusImage = ( $uploadRate != 0 && $running )
						? "green.gif" /* seeding */
						: "black.gif"; /* finished */
	}
	return $statusImage;
}

function getTransmissionSeederCount($transfer) {
	$options = array('trackerStats');
	$transfer = getTransmissionTransfer($transfer, $options);
	$seeds = "";
	foreach ( $transfer['trackerStats'] as $tracker ) {
		$seeds += ($tracker['seederCount']==-1 ? 0 : $tracker['seederCount']);
		//$announceResult = $tracker['lastAnnounceResult'];
	}
	return $seeds;
}

function getSessionInfo() {
	require_once('inc/clients/transmission-daemon/Transmission.class.php');

	$rpc = new Transmission ();
	$result = $rpc->session_get();
	
	return $result['arguments'];
}

function setSessionParameter($parametername, $value) {
	require_once('inc/clients/transmission-daemon/Transmission.class.php');

	$request = array( $parametername => $value );
	$rpc = new Transmission();
	$result = $rpc->session_set($request);
}

function move( $transfer, $target_location, $move_existing_data = true ) {
	require_once('inc/clients/transmission-daemon/Transmission.class.php');
	
	$rpc = new Transmission();
	$response = $rpc->move($transfer, $target_location, $move_existing_data);
	
	if ( $response['result'] !== "success" )
		rpc_error("Error during moving of torrent" , "", "", $response['result']);
	
	return $response;
}

?>
