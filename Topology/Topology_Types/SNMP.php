<?php
require_once '/opt/fmc_repository/Process/Topology/Common/Topology_populate.php';
require_once '/opt/fmc_repository/Process/Topology/Common/Topology_common.php';
require_once '/opt/fmc_repository/Process/Reference/Common/Library/topology_rest.php';
require_once '/opt/fmc_repository/Process/Reference/Common/Library/configuration_variable_rest.php';

function calculateDeviceTopology($deviceId, $name, $device_nature, $status) {
	global $context;

	logTofile("*** calculateDeviceTopology  deviceId: $deviceId name: $name status: $status \n");

	$nodePlace = createTopology($deviceId, $name, $device_nature, "router", "", $status);
	$response = _configuration_variable_list($deviceId);
	$response = json_decode($response, true);
	$device_id = $deviceId;
	$conf_snmp = $response['wo_newparams'];
	$check_snmp = array("snmpv3_authKey","snmpv3_authProtocol","snmpv3_privKey","snmpv3_privProtocol","snmpv3_securityEngineID","snmpv3_securityLevel","snmpv3_securityName");
	$reg = array();
	$keys = array_keys($conf_snmp);
	foreach($check_snmp as $d) {
		for($i = 0; $i < count($conf_snmp); $i++) {
			foreach($conf_snmp[$keys[$i]] as $key => $value) {
				if (($key == "name") && ($d == $value)) {
					$reg[] = $value;
				}
			}
		}
	}
	$result=array_diff($check_snmp,$reg);
	if(count($result) == 0) {
		try {
			checkSNMPResponds($community, $address);
			$cmd = "snmpwalk -v3 -l $snmpv3_securityLevel -u $username -a $snmpv3_authProtocol -A $snmpv3_authKey $address";
			logTofile("*** calculateDeviceTopology SNMP COMMAND: ".$cmd."\n");
			exec($cmd, $value, $error);
			if (!count($result)) {
				foreach ($value as $search) {
					if (searchAdress($search, $matches) != false) {
						logTofile(debug_dump($matches, "checkSNMPResponds searchAdress matches:\n"));
						if ($matches [1] [0] != 127) {
							$address_link = $matches [0] [0];
							$maskAdr = $matches [0] [1];
							$mask = calcMask($maskAdr);
							$address_link_masked = getNetworkByAddressAndMask($address_link, $mask);
							$addressAndMask = $address_link_masked . "/" . $mask;
							createTopologyNetwork(str_replace(".", "_", $addressAndMask), $addressAndMask, "network", "");
							$context ['Nodes'] [$nodePlace] ["links"] [] = $addressAndMask;
						}
					}
				}
			} else {
				logTofile(debug_dump($value, "*** calculateDeviceTopology ERROR_1***\n"));
			}
		} catch (Exception $e) {
			logTofile(debug_dump($e->getMessage(), "*** calculateDeviceTopology ERROR_2***\n"));
		}
	} else {
		$error = readInformationsFromDevice($deviceId, $community, $address);
		if ($error == "") {
			try {
				checkSNMPResponds($community, $address);
				$cmd = "snmpwalk -v2c -c $community $address IP-MIB::ipAdEntNetMask 2>&1";
				logTofile("*** calculateDeviceTopology SNMP COMMAND: ".$cmd."\n");
				exec($cmd, $value, $error);
				if (!$error) {
					foreach ($value as $search) {
						if (searchAdress($search, $matches) != false) {
							logTofile(debug_dump($matches, "checkSNMPResponds searchAdress matches:\n"));
							if ($matches [1] [0] != 127) {
								$address_link = $matches [0] [0];
								$maskAdr = $matches [0] [1];
								$mask = calcMask($maskAdr);
								$address_link_masked = getNetworkByAddressAndMask($address_link, $mask);
								$addressAndMask = $address_link_masked . "/" . $mask;
								createTopologyNetwork(str_replace(".", "_", $addressAndMask), $addressAndMask, "network", "");
								$context ['Nodes'] [$nodePlace] ["links"] [] = $addressAndMask;
							}
						}
					}
				} else {
					logTofile(debug_dump($value, "*** calculateDeviceTopology ERROR_1***\n"));
				}
			} catch (Exception $e) {
				logTofile(debug_dump($e->getMessage(), "*** calculateDeviceTopology ERROR_2***\n"));
			}
		} else {
			logTofile(debug_dump($error, "*** calculateDeviceTopology ERROR_3 ***\n"));
		}
	}
}
function checkSNMPResponds($community, $address) {
	$cmd_SNMP_RESPOND = "timeout 1 snmpwalk -v2c -c $community $address SNMPv2-MIB::sysName 2>&1";
	logToFile("checkSNMPResponds with command: " . $cmd_SNMP_RESPOND  . "\n");
	$res = exec($cmd_SNMP_RESPOND, $value, $error);
	logToFile("checkSNMPResponds result: " . $res  . "\n");

	if ($error) {
		throw new Exception("checkSNMPResponds SNMP NOT AVAILABLE ON " . $address);
	}
}

function searchAdress($search, &$matches) {
	$res = preg_match_all('#([0-9]{1,3})(\.[0-9]{1,3}){3}#', $search, $matches);
	logToFile("searchAdress : search " . $search  . " result:".$res."\n");
	return $res;
}

function readInformationsFromDevice($device_id, &$community, &$address) {
	$info = json_decode(_device_read_by_id($device_id), true);
	
	if ($info ["wo_status"] == "FAIL") {
		return $info ["wo_comment"];
	}
	
	logTofile(debug_dump($info, "*** readInformationsFromDevice ***\n"));
	
	$address = $info ["wo_newparams"] ["managementAddress"];
	$community = $info ["wo_newparams"] ["snmpCommunity"];
	
	if ((empty($community) || $community == "") && (empty($address) || $address == "")) {
		return "Site with id " . $device_id . " was not found";
	} else if (empty($community) || $community == "") {
		return "Community of site with id " . $device_id . " was not found";
	} else if (empty($address) || $address == "") {
		return "Address of site with id " . $device_id . " was not found";
	} else {
		return "";
	}
}


/*
function launchParallelSNMP($deviceId, $name, $view_type) {
	global $context;
	
	$ubiqube_id = $context ['UBIQUBEID'];
	$service_instance = $context ['SERVICEINSTANCEID'];
	
	$service_name = "Process/Topology/Topology";
	$process_name = "Process/Topology/Process_Call_For_Device";
	
	$add_service_array = $context;
	$add_service_array ['device_id'] = $deviceId;
	$add_service_array ['name'] = $name;
	$json_body = json_encode($add_service_array);
	
	_orchestration_launch_sub_process($ubiqube_id, $service_instance, $service_name, $process_name, $json_body);
	
	logTofile("***TOPOLOGY LAUNCH SNMP $deviceId * $name***");
}
*/

?>
