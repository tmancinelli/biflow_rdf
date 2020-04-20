<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

readfile(".sparql_cache");
