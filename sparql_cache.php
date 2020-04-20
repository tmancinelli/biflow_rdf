<?php

# Let"s read the configuration file.
$config = parse_ini_file("config.ini", true);

$array = [];

foreach($config["classes"] as $class => $value) {
  $url = $config["general"]["api"] . "/" . $class . ".json";
  $data = file_get_contents($url);
  if ($data === false) {
    die("Http request failed");
  }

  # This is a json. Let"s decode it into a PHP object.
  $json = json_decode($data, true);
  if ($json === false) {
    die("Invalid JSON");
  }

  $file = $config["classes"][$class];
  require_once($config["general"]["path"] . $file);

  # Let"s take the class name removing the .php from the filename.
  $className = explode(".", $file)[0];

  # PHP, give me all the comments! (The App\Entity is the namespace of
  # api-platform)
  $classData = getClassData("App\Entity\\" . $className);

  if ($classData == null) {
    continue;
  }

  foreach($json as $item) {
    addItem($array, $config, $item, $classData, $class);
  }
}

$f = fopen(".sparql_cache", "w") or die("Unable to open file!");
fwrite($f, json_encode($array));
fclose($f);

function addItem(&$array, $config, $item, $classData, $class) {
  $url = "<" . $config["general"]["rdfuri"] . "/" . $class . "/" . $item["id"] . ">";
  $array[] = "$url a biflow:" . $classData["name"] . ".";

  foreach($classData["properties"] as $name => $property) {
    if (!isset($item[$name]) || !$item[$name]) {
      continue;
    }

    if (is_array($item[$name])) {
      foreach($item[$name] as $value) {
        $array[] = addProperty($url, $config, $value, $property);
      }
    } else {
      $array[] = addProperty($url, $config, $item[$name], $property);
    }
  }
}

# This function adds a single RDF property in the RDF content.
function addProperty($url, $config, $value, $property) {
  # if the property has a range, and the range is a biflow property...
  if (isset($property["range"]) && substr($property["range"][0], 0, 8) == "&biflow;" && $value[0] == "/") {
    # Let's split the value in parts. The value is something like: /catalog_biflow/api/expressions/123
    $parts = explode("/", $value);

    # The ID is the last part of the URL.
    $id = end($parts);

    # the class is the previous one.
    $class = prev($parts);

    # Let's compose the RDF property:
    return "$url biflow:" . $property["name"] . " <" . $config["general"]["rdfuri"]. "/" . $class . "/" . $id . "> .";
  }

  # If the property has an "include", fetch the data and print it.
  if (isset($property["include"])) {
    $parts = explode("/", $value);
    $id = end($parts);
    $class = prev($parts);

    $nestedurl = $config["general"]["api"] . "/" . $class . "/" . $id . ".json";
    $data = file_get_contents($nestedurl);
    if ($data === false) {
      die("Http request failed");
    }

    $json = json_decode($data, true);
    if ($json === false) {
      die("Invalid JSON");
    }

    $value = $json[$property["include"][0]];
  }

  # Let's compose the RDF property with the 'string' value.
  return "$url biflow:" . $property["name"] . " \"" . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "\" .";
}

function getClassData($class) {
  $reflector = new ReflectionClass($class);

  $data = getGenericData($reflector->getDocComment());
  $data["name"] = $reflector->getShortName();

  $data["properties"] = Array();

  foreach($reflector->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
    $data["properties"][$property->getName()] = getPropertyData($property);
  }

  if (isset($data["ignore"])) {
    return null;
  }

  return $data;
}

function getPropertyData($property) {
  $data = getGenericData($property->getDocComment());

  if (!isset($data["name"])) {
    $data["name"] = $property->getName();
  } else if (is_array($data["name"])) {
    $data["name"] = $data["name"][0];
  }

  return $data;
}

# This is taken from biflow.php.
function getGenericData($comment) {
  $data = Array();

  $lines = explode("\n", $comment);
  foreach($lines as $line) {
    $a = strpos($line, "-ontology-");
    if ($a !== false) {
      $v = trim(substr($line, $a + strlen("-ontology-")));
      $key = explode(" ", $v)[0];

      $v = trim(substr($line, $a + strlen("-ontology-") + strlen($key)));

      if (!isset($data[$key])) {
        $data[$key] = Array();
      }

      $data[$key][] = $v;
    }
  }

  return $data;
}
