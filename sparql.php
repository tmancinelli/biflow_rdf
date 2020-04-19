<?php

header("Access-Control-Allow-Origin: *");
$data = file_get_contents(".sparql_cache");
header("Content-Type: text/html");

$data = unserialize($data);
echo json_encode($data);
