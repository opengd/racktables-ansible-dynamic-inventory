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

function get_all_matching_filter() {
    $result = do_curl_call(RACKTABLES_HOST . "/racktables/index.php?page=depot", USERPASS);

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
        /*
		$data = get_host_list();
		$data = filter_active_hosts($data, "host_filters");

		$data_group = get_host_list_from_group();
		$data_group = filter_active_group_hosts($data_group, "group_filters");

		$host_list_ansible = parse_host_list_to_ansible($data, "host_filters");
		$group_list_ansible = parse_group_list_to_ansible($data_group, "group_filters");

		$data_ansible = array_merge(array_filter($host_list_ansible, function($k) {
			return $k != "_meta";
		}), array_filter($group_list_ansible, function($k) {
			return $k != "_meta";
		}));

		$data_ansible["_meta"] = array_merge($host_list_ansible["_meta"], $group_list_ansible["_meta"]);
				
		if(array_key_exists("list", $opts)) {
			$ret = json_encode($data_ansible);
		} else if(array_key_exists("static", $opts)) {
			create_static_inventory_file(
				$data_ansible, 
				array_key_exists("static_filename", $opts) ? $opts["static_filename"] : "op5_hosts.ansible"
			);
			$ret = "";
        }
        */
        $ret = "{}";		
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