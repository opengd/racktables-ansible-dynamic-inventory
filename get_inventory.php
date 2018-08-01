#!/usr/bin/php
<?php

$a_handle = curl_init("YOUR-RACKTABLES-URL" . "/racktables/index.php?page=depot");
curl_setopt($a_handle, CURLOPT_USERPWD, "admin:admin");
curl_setopt($a_handle, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($a_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($a_handle, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($a_handle, CURLOPT_SSL_VERIFYHOST, false);

$result = curl_exec($a_handle);
if(!$result) echo curl_error($a_handle) . "\n";
//$data = json_decode($result, true);
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
        
        echo "\n";
        $total++;
    }
    
}
echo "Total" . $total;
//echo serialize($result);

curl_close($a_handle);
