#!/usr/bin/php
<?php

const DEFAULT_CONFIG_FILE = 'config.json';
const DEFAULT_STATIC_FILE = 'racktables_hosts.ansible';

/**
 * Do curl call
 * 
 * @param string $call
 * @param string $userpass
 * 
 * @return array
 */
function do_curl_call($call, $userpass) {
	global $verbose;

	$call = str_replace(" ", "%20", $call);

	if($verbose) echo $call . "\n";

	$a_handle = curl_init($call);
	curl_setopt($a_handle, CURLOPT_USERPWD, $userpass);
	curl_setopt($a_handle, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($a_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	curl_setopt($a_handle, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($a_handle, CURLOPT_SSL_VERIFYHOST, false);

	$result = curl_exec($a_handle);
    if($verbose && !$result) echo curl_error($a_handle) . "\n";
	if ($verbose && $result && strpos($result, "Assertion failed") !== false) var_dump($result);

	curl_close($a_handle);

    //return !array_key_exists("error", $result) ? $result : null;
    
    return $result;
}

/**
 * 
 */
function get_host_list() {
    global $config;

    $hosts = array();

    foreach($config["racktables"]["list_query"] as $indexQuery => $listQuery) {
        $result = do_curl_call($listQuery["host"] . $listQuery["api"], $listQuery["userpwd"]);

        $dom = new DOMDocument();
    
        @$dom->loadHTML($result);

        $host_list = array();

        foreach($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $strong = $link->getElementsByTagName('strong');
            
            if(strpos($href, "object_id") !== false && $strong->count() > 0) {
                $s = str_replace("index.php?page=object&object_id=", "", $href);
                $host_list[$s] = $strong[0]->childNodes[0]->nodeValue;          
            }     
        }

        $hosts[$indexQuery] = array();
        foreach($listQuery["host_filters"] as $hostFilterName => $hostFilter) {
            
            if(is_array($hostFilter["filter"]) && count($hostFilter["filter"]) > 0) {
                $hosts[$indexQuery][$hostFilterName] = array();
                
                foreach($hostFilter["filter"] as $filter) {
                    $hosts[$indexQuery][$hostFilterName] = $hosts[$indexQuery][$hostFilterName] + preg_grep($filter, $host_list);
                }
            } else {
                $hosts[$indexQuery][$hostFilterName] = $host_list;
            }
        }
    }

    var_dump($hosts);

    return $hosts;
}

function get_host_data($hosts) {
    global $config;

    foreach($config["racktables"]["list_query"] as $indexQuery => $listQuery) {
          
        foreach($hosts[$indexQuery] as $groupName => $groupHosts) {
            $host_data = array();
            foreach($groupHosts as $hostId => $hostName) {
                $host_data[$hostId] = array();

                $result = do_curl_call($listQuery["host"] . "/racktables/index.php?page=object&tab=default&object_id=" . $hostId, $listQuery["userpwd"]);

                $host = new DOMDocument();

                @$host->loadHTML($result);

                foreach($host->getElementsByTagName('a') as $host_link) {
                    $href = $host_link->getAttribute('href');
                    if(strpos($href, "ipaddress") !== false) {
                        if(!array_key_exists("address", $host_data[$hostId])) {
                            $host_data[$hostId]["address"] = array();
                        }

                        array_push($host_data[$hostId]["address"], $host_link->nodeValue);
                    }
                }

                $host_data[$hostId]["name"] = $hostName;
            }

            foreach($host_data as $hostId => $hostData) {
                $hosts[$indexQuery][$groupName][$hostId] = $hostData;
            }
        }
    }

    var_dump($hosts);

    return $hosts;
}

function parse_host_vars($hosts) {
    global $config;

    foreach($config["racktables"]["list_query"] as $indexQuery => $listQuery) {
        foreach($listQuery["host_filters"] as $hostFilterName => $hostFilter) {
            
            if(array_key_exists("check_ansible_port", $hostFilter) && $hostFilter["check_ansible_port"]) {
                
                $ports = array_key_exists("host_vars", $hostFilter) && array_key_exists("ansible_port", $hostFilter["host_vars"]) 
                    ? $hostFilter["host_vars"]["ansible_port"] 
                    : array(22);
                
                $checked_host = array();

                foreach($hosts[$indexQuery][$hostFilterName] as $hostId => $hostData) {
                    if($addressPort = check_ansible_port($hostData, $ports)) {
                        $checked_host[$hostId] = $hostData;                      
                        $checked_host[$hostId]["ansible_host"] = $addressPort[0][0];
                        $checked_host[$hostId]["ansible_port"] = $addressPort[0][1];
                    }
                }

                $hosts[$indexQuery][$hostFilterName] = $checked_host;
            }

            if(array_key_exists("host_vars", $hostFilter) && count($hostFilter["host_vars"]) > 0) {
                foreach($hosts[$indexQuery][$hostFilterName] as $hostId => $hostData) {
                    if(!array_key_exists("host_vars", $hosts[$indexQuery][$hostFilterName][$hostId])) {
                        $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"] = array();
                    }
                    foreach($hostFilter["host_vars"] as $key => $value) {
                        switch($key) {
                            case "ansible_host":
                                $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = array_key_exists($key, $hostData) ? $hostData[$key] : $value;
                                if(array_key_exists($key, $hostData)) {
                                    $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = $hostData[$key];
                                } else if(array_key_exists("address", $hostData) && count($hostData["address"]) > 0) {
                                    $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = $hostData["address"][0];
                                }
                                break;
                            case "ansible_port":
                                $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = array_key_exists($key, $hostData) ? $hostData[$key] : $value;
                                if(array_key_exists($key, $hostData)) {
                                    $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = $hostData[$key];
                                } else if(is_array($value) && count($value) > 0) {
                                    $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = $value[0];
                                } else {
                                    $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = $value;
                                }
                                break;
                            default:
                                $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"][$key] = $value;
                                break;
                        }
                    }
                } 
            }
        }
    }

    return $hosts;
}

function check_ansible_port($hostData, $ports) {
    $addressPort = array();
    foreach($hostData["address"] as $address) {
        if($port = get_ansible_port($address, $ports)) {
            array_push($addressPort, array($address, $port));
        }
    }

    return count($addressPort) > 0 ? $addressPort : null;
}

/**
 * Get active ansible port
 * 
 * @param array $host
 * 
 * @return int
 */
function get_ansible_port($address, $ports) {
	global $verbose;

	//if($verbose) echo "\n# Testing ansible ssh port on host " . $host['name'] . " at " . $address;
	
	foreach($ports as $port) {
		if ($fp = @fsockopen($address, $port, $errno, $errstr, 1)) { 
			fclose($fp);
			if($verbose) echo "\n" . $port . " OK";
			return $port;					
		} else {
			if($verbose) echo "\n" . $port . " no";
		}
	}
	if($verbose) echo "\nCould not find any open ssh port for ansible";
	return null;
}

/**
 * 
 */
function to_ansible_list_format($hosts) {
    global $config;

    $ansible_list = array("_meta" => array());

    foreach($config["racktables"]["list_query"] as $indexQuery => $listQuery) {
        foreach($listQuery["host_filters"] as $hostFilterName => $hostFilter) {
            if(count($hosts[$indexQuery][$hostFilterName]) > 0) { 
                if(!array_key_exists("hostvars", $ansible_list["_meta"])) {
                    $ansible_list["_meta"]["hostvars"] = array();
                }
                $ansible_list[$hostFilterName] = array("hosts" => array());
                foreach($hosts[$indexQuery][$hostFilterName] as $host) {
                    array_push($ansible_list[$hostFilterName]["hosts"], $host["name"]);

                    if(array_key_exists("host_vars", $host)) {
                        $ansible_list["_meta"]["hostvars"][$host["name"]] = $host["host_vars"];
                    }
                }
                
                if(array_key_exists("group_vars", $hostFilter) && count($hostFilter["group_vars"]) > 0) {
                    $ansible_list[$hostFilterName]["vars"] = array();

                    foreach($hostFilter["group_vars"] as $key => $value) {
                        $ansible_list[$hostFilterName]["vars"][$key] = $value;
                    }
                }

                if(array_key_exists("children", $hostFilter) && count($hostFilter["children"]) > 0) {
                    $ansible_list[$hostFilterName]["children"] = $hostFilter["children"];
                }
            }
        }
    }

    return $ansible_list;
}

/**
 * Create a static inventory list file
 * 
 * @param array $data
 * @param string $filename
 * @param string $group
 * @param boolean $append
 */
function create_static_inventory_file($data, $filename) {
	$myfile = fopen($filename, "w") or die("Unable to open file!");

	$meta = array_key_exists('_meta', $data) ? $data['_meta'] : null;

	foreach($data as $groupName => $groupValues) {
		$add_n = 1;
		if($groupName !== '_meta') {
			fwrite($myfile, "[" . $groupName . "]\n");
			foreach($groupValues["hosts"] as $host) {
				fwrite($myfile, $host);
				foreach($meta["hostvars"][$host] as $hostVarsName => $hostVarsValueName) {
					$s = " " . $hostVarsName . "=" . $hostVarsValueName;
					fwrite($myfile, $s);
				}
				fwrite($myfile, "\n");
			}

			fwrite($myfile, "\n");

			if(array_key_exists("vars", $groupValues) && count($groupValues["vars"]) > 0) {
				fwrite($myfile, "[" . $groupName . ":vars]\n");
				foreach($groupValues["vars"] as $groupVarsName => $groupVarsValueName) {
					$s = $groupVarsName . "=" . $groupVarsValueName . "\n";
					fwrite($myfile, $s);
				}
				fwrite($myfile, "\n");
			}

			if(array_key_exists("children", $groupValues) && count($groupValues["children"]) > 0) {
				fwrite($myfile, "[" . $groupName . ":children]\n");
				foreach($groupValues["children"] as $child) {
					fwrite($myfile, $child . "\n");
				}
				fwrite($myfile, "\n");
			}
		}
	}

	fclose($myfile);
}

/**
 * 
 */
function get_all_matching_filter() {
    global $config;
    $result = do_curl_call($config["racktables"]["list_query"][0]["host"] . $config["racktables"]["list_query"][0]["api"], $config["racktables"]["list_query"][0]["userpwd"]);

    $dom = new DOMDocument();
    
    @$dom->loadHTML($result);
    $total = 0;
    foreach($dom->getElementsByTagName('a') as $link) {
        # Show the <a href>
        $href = $link->getAttribute('href');
        $strong = $link->getElementsByTagName('strong');
        //echo $href;
        
        if(strpos($href, "object_id") !== false && $strong->count() > 0) {
            $s = str_replace("index.php?page=object&object_id=", "", $href);
    
            echo $s . " " . $strong->count() . " " . $strong[0]->childNodes[0]->nodeValue;
                    
            $total++;
    
            $host_result = do_curl_call(RACKTABLES_HOST . "/racktables/index.php?page=object&tab=default&object_id=" . $s, USERPASS);
            $host_dom = new DOMDocument();
    
            @$host_dom->loadHTML($host_result);
    
            foreach($host_dom->getElementsByTagName('a') as $host_link) {
                $href = $host_link->getAttribute('href');
                if(strpos($href, "ipaddress") !== false) {
                    echo " " . $host_link->nodeValue;
                }
            }
    
            echo "\n";
        }
        
    }
    echo "Total" . $total;
    //echo serialize($result);
}

/**
 * Get ansible host list from racktables
 * 
 * @param array $opts
 * 
 * @return string
 */
function get_inventory($opts) {	
	global $config;

	if(!array_key_exists("help", $opts) && (array_key_exists("list", $opts) || array_key_exists("static", $opts))) {
        
        $data = get_host_list();
        $data = get_host_data($data);
        $data = parse_host_vars($data);
        $data_ansible = to_ansible_list_format($data);

		if(array_key_exists("list", $opts)) {
			$ret = json_encode($data_ansible);
		} else if(array_key_exists("static", $opts)) {
			create_static_inventory_file(
				$data_ansible, 
				array_key_exists("static_filename", $opts) ? $opts["static_filename"] : "op5_hosts.ansible"
			);
			$ret = "";
        }
        
        $ret = isset($ret) ? $ret : "{}";		
	} elseif(!array_key_exists("help", $opts) && array_key_exists("host", $opts)) {
        /*
		$data = get_host($opts["host"]);
		$data = is_host_active($data);

		foreach($data as $key => $value) {
			if(count($data[$key][0]) > 0) {
				$host_vars = parse_host_vars($data[$key][0], 
					$config["op5"]["host_query"][$key]["host_vars"],
					$config["op5"]["host_query"][$key]["columns"]
				);
				break;
			}
		}
		
        $ret = isset($host_vars) ? json_encode($host_vars) : "{}";
        */
        $ret = "{}";
	} else {
		$ret = "Usage: get_op5_inventory.php [OPTION]\n";
		$ret .= "op5-ansible-dynamic-inventory opengd@2018\n\n";
		$ret .= "--list\t\t\t\tget json list of op5 hosts\n";
		$ret .= "--host=host\t\t\tget ansible meta variable from op5 host\n";
		$ret .= "--static\t\t\tcreate inventory file from op5 hosts\n";
		$ret .= "--static_filename=FILENAME\tfilename of static inventory\n";
		$ret .= "--config_file=CONFIG_FILE\tfilepath to config file\n";
		$ret .= "--verbose\t\t\tshow verbose data and errors\n";
		$ret .= "--help\t\t\t\tshow this help message\n";
	}
	
	return $ret;
}


$longopts = array(
    "list",
	"host:",
	"static",
	"static_group",
	"static_filename:",
	"static_limit:",
	"config_file:",
	"verbose",
	"help",
);

$opts = getopt("", $longopts);

$verbose = array_key_exists("verbose", $opts);

$config_file_resource = array_key_exists("config_file", $opts) ? fopen($opts["config_file"], "r") : fopen(DEFAULT_CONFIG_FILE, "r");

if($config_file_resource) {
	$config_file = fread($config_file_resource, filesize(array_key_exists("config_file", $opts) ? $opts["config_file"] : DEFAULT_CONFIG_FILE));
	fclose($config_file_resource);
	$config = json_decode($config_file, true);
}

echo ($verbose) ? "\n" . get_inventory($opts) . "\n" : get_inventory($opts) ;