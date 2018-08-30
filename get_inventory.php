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
 * Get a host list from racktable based on config
 *
 * @return array
 */
function get_host_list() {
    global $config;

    $hosts = array();

    foreach($config["racktables"]["list_query"] as $indexQuery => $listQuery) {
        $result = do_curl_call($listQuery["host"] . $listQuery["list_api"], $listQuery["userpwd"]);

        $dom = new DOMDocument();
    
        @$dom->loadHTML($result);

        $host_list = array();

        foreach($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $strong = $link->getElementsByTagName('strong');
            
            if(strpos($href, "object_id") !== false && $strong->length > 0) {
                $s = str_replace("index.php?page=object&object_id=", "", $href);
                $host_list[$s] = $strong[0]->childNodes[0]->nodeValue;          
            }     
        }

        $hosts[$indexQuery] = array();
        foreach($listQuery["host_filters"] as $hostFilterName => $hostFilter) {
            
            if(array_key_exists("filter", $hostFilter) && is_array($hostFilter["filter"]) && count($hostFilter["filter"]) > 0) {
                $hosts[$indexQuery][$hostFilterName] = array();
                
                foreach($hostFilter["filter"] as $filter) {
                    $hosts[$indexQuery][$hostFilterName] = $hosts[$indexQuery][$hostFilterName] + preg_grep($filter, $host_list);
                }
            } else {
                $hosts[$indexQuery][$hostFilterName] = $host_list;
            }
        }
    }

    return $hosts;
}

/**
 * Get host from racktables matching input hostname
 * 
 * @param string $hostname
 * 
 * @return array
 */
function get_host($hostname) {
    global $config;

    foreach($config["racktables"]["host_query"] as $indexQuery => $hostQuery) {
        $result = do_curl_call($hostQuery["host"] . $hostQuery["list_api"], $hostQuery["userpwd"]);

        $dom = new DOMDocument();
    
        @$dom->loadHTML($result);

        foreach($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $strong = $link->getElementsByTagName('strong');
            
            if(strpos($href, "object_id") !== false && $strong->length > 0) {
                $s = str_replace("index.php?page=object&object_id=", "", $href);
                
                if($strong[0]->childNodes[0]->nodeValue === $hostname) {
                    return array($indexQuery => array("name" => $hostname, "id" => $s));
                }
            }     
        }
    }

    return null;
}

/**
 * Get host data for a single host from racktables
 * 
 * @param array $host
 * 
 * @return array
 */
function get_host_data($host) {
    global $config;

    $conf = $config["racktables"]["host_query"][key($host)];

    $result = do_curl_call($conf["host"] .  $conf["host_api"] . $host[key($host)]["id"], $conf["userpwd"]);

    $host_dom = new DOMDocument();

    @$host_dom->loadHTML($result);

    foreach($host_dom->getElementsByTagName('a') as $host_link) {
        $href = $host_link->getAttribute('href');
        if(strpos($href, "ipaddress") !== false) {
            if(!array_key_exists("address", $host[key($host)])) {
                $host[key($host)]["address"] = array();
            }

            array_push($host[key($host)]["address"], $host_link->nodeValue);
        }
    }

    return $host;
}

/**
 * Get host data for all items in a host list from racktables
 * 
 * @param array $hosts
 * 
 * @return array
 */
function get_host_list_data($hosts) {
    global $config;

    foreach($config["racktables"]["list_query"] as $indexQuery => $listQuery) {
          
        foreach($hosts[$indexQuery] as $groupName => $groupHosts) {
            $host_data = array();
            foreach($groupHosts as $hostId => $hostName) {
                $host_data[$hostId] = array();

                $result = do_curl_call($listQuery["host"] .  $listQuery["host_api"] . $hostId, $listQuery["userpwd"]);

                $host = new DOMDocument();

                @$host->loadHTML($result);

                $host_data[$hostId]["address"] = get_host_addresses($host);

                $host_data[$hostId]["props"] = get_host_properties($host);
                
                $host_data[$hostId]["name"] = $hostName;

                //var_dump($host_data[$hostId]);
            }

            foreach($host_data as $hostId => $hostData) {
                $hosts[$indexQuery][$groupName][$hostId] = $hostData;
            }
        }
    }

    return $hosts;
}

/**
 * Get host addresses
 * 
 * @param  DOMDocument $hostdom
 * 
 * @return array
 */
 function get_host_addresses($hostdom) {
    $addresses = array();

    foreach($hostdom->getElementsByTagName('a') as $host_link) {
        $href = $host_link->getAttribute('href');

        if(strpos($href, "ipaddress") !== false) {
            array_push($addresses, $host_link->nodeValue);
        }
    }

    return $addresses;
 }

/**
 * Get host properties
 * 
 * @param  DOMDocument $hostdom
 * 
 * @return array
 */
function get_host_properties($hostdom) {
    $match = array();

    foreach($hostdom->getElementsByTagName('tr') as $ind => $tr) {
        $th = $tr->getElementsByTagName('th');

        foreach($th as $n) {
            if($n->nodeValue && strpos($n->nodeValue, ":") !== false && $ind > 1) {
                $match[str_replace(":", "", $n->nodeValue)] = $tr;
            }
        }
    }

    foreach($match as $prop => $tr) {
        $td = $tr->getElementsByTagName('td');

        foreach($td as $n) {
            $match[$prop] = (count($n->childNodes) > 0) ? $n->childNodes[0]->nodeValue : $n->nodeValue;
        }
    }

    return $match;
}

/**
 * Filter host that match prop_filters in config
 * 
 * @param array $hosts
 * 
 * @return array
 */
function filter_by_props($hosts) {
    global $config, $verbose;

    if($verbose) echo "\n# Filter props";

    foreach($config["racktables"]["list_query"] as $indexQuery => $listQuery) {
        foreach($listQuery["host_filters"] as $hostFilterName => $hostFilter) {
            if(array_key_exists("props_filters", $listQuery["host_filters"][$hostFilterName]) && count($listQuery["host_filters"][$hostFilterName]["props_filters"]) > 0) {
                if($verbose) echo "\nChecking filter props for host filter " . $hostFilterName;
                $non_matches = array();
                foreach($hosts[$indexQuery][$hostFilterName] as $hostId => $hostData) {
                    if($verbose) echo "\n* " . $hostId . " " . $hostData["name"];
                    $is_match = false;
                    foreach($listQuery["host_filters"][$hostFilterName]["props_filters"] as $prop_name => $prop_val) {
                        if(array_key_exists($prop_name, $hostData["props"])) {
                            //var_dump($hostData["props"][$prop_name]);
                            if($verbose) echo "\n" . $prop_name .  ": " . $prop_val;
                            if(preg_match($prop_val, $hostData["props"][$prop_name])) {
                                if($verbose) echo " MATCH " . $hostData["props"][$prop_name];
                                $is_match = true;
                            } else {
                                if($verbose) echo " NO match " . $hostData["props"][$prop_name] . " (Mark host for removal from list.)";                         
                                $is_match = false;
                                break;
                            }
                        }
                    }
                    if(!$is_match) {
                        array_push($non_matches, $hostId);
                    }  
                }

                foreach($non_matches as $id) {
                    if($verbose) echo "\nRemove host id " . $id . " from list.";
                    unset($hosts[$indexQuery][$hostFilterName][$id]);
                }
            } else if($verbose) {
                echo "\nNo filter props for host filter " . $hostFilterName;
            }
        }
    }

    return $hosts;
}

/**
 * Parse any host variables
 * 
 * @param array $host
 * 
 * @return array
 */
function parse_host_vars($host) {
    global $config;

    $conf = $config["racktables"]["host_query"][key($host)];

    if(array_key_exists("check_ansible_port", $conf) && $conf["check_ansible_port"]) {
                
        $ports = array_key_exists("host_vars", $conf) && array_key_exists("ansible_port", $conf["host_vars"]) 
            ? $conf["host_vars"]["ansible_port"] 
            : array(22);
        
        $checked_host = array();

        if($addressPort = check_ansible_port($host[key($host)], $ports)) {
            $host[key($host)]["ansible_host"] = $addressPort[0][0];
            $host[key($host)]["ansible_port"] = $addressPort[0][1];
        } else {
            return null;
        }
    }

    $host_vars = get_host_vars($conf, $host[key($host)]);

    if(count($host_vars) > 0) {
        $host[key($host)]["host_vars"] = $host_vars;
    }

    return $host;
}

/**
 * Get any ansible host variables
 * 
 * @param array $host_config
 * @param array $host
 * 
 * @return array 
 */
function get_host_vars($host_config, $host) {

    $host_vars = array();

    if(array_key_exists("host_vars", $host_config) && count($host_config["host_vars"]) > 0) {
        foreach($host_config["host_vars"] as $key => $value) {
            switch($key) {
                case "ansible_host":
                    if(array_key_exists($key, $host)) {
                        $host_vars[$key] = $host[$key];
                    } else if(array_key_exists("address", $host) && count($host["address"]) > 0) {
                        $host_vars[$key] = $host["address"][0];
                    }
                    break;
                case "ansible_port":
                    if(array_key_exists($key, $host)) {
                        $host_vars[$key] = $host[$key];
                    } else if(is_array($value) && count($value) > 0) {
                        $host_vars[$key] = $value[0];
                    } else {
                        $host_vars[$key] = $value;
                    }
                    break;
                default:
                    $host_vars[$key] = $value;
                    break;
            }
        }
    }

    return $host_vars;
}

/**
 * Parse any host list ansible variables
 * 
 * @param array $hosts
 * 
 * @return array
 */
function parse_host_list_vars($hosts) {
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

                    $host_vars = get_host_vars($hostFilter, $hostData);

                    if(count($host_vars) > 0) {
                        $hosts[$indexQuery][$hostFilterName][$hostId]["host_vars"] = $host_vars;
                    }
                } 
            }
        }
    }

    return $hosts;
}

/**
 * Check ansible port if it open
 * 
 * @param array $hostData
 * @param array $ports
 * 
 * @return array
 */
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
 * Create a array based on ansible json format
 * 
 * @param array $hosts
 * 
 * @return array
 */
function host_list_to_ansible_list_format($hosts) {
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
        $data = get_host_list_data($data);
        $data = filter_by_props($data);
        $data = parse_host_list_vars($data);
        $data_ansible = host_list_to_ansible_list_format($data);

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
        
        $data = get_host($opts["host"]);
        if($data)
            $data = get_host_data($data);
        if($data)
            $data = parse_host_vars($data);
        
        $ret = $data && count($data[key($data)]["host_vars"]) > 0 ? json_encode($data[key($data)]["host_vars"]) : "{}";
	} else {
		$ret = "Usage: get_inventory.php [OPTION]\n";
		$ret .= "racktables-ansible-dynamic-inventory opengd@2018\n\n";
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