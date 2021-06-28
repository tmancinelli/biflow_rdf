<?php

# Let's split the URL request in parts.
$parts = explode("/", $_SERVER["REQUEST_URI"]);
if (end($parts) == "biflow") {
  showOntology();
  return;
}

# Let's read the configuration file.
$config = parse_ini_file("config.ini", true);

# we want the last part to be numeric.
if (!is_numeric(end($parts))) {
  die("Invalid request");
}

# This is the last part -> the ID
$id = end($parts);

# This is the type (works, manuscripts, ...)
$class = prev($parts);

# If the class has not been definied the configuration file, let's return an
# error.
if (!isset($config["classes"][$class])) {
  die("Invalid request");
}

# Let's compose the URL for this object on the API server.
$url = $config["general"]["api"] . "/" . $class . "/" . $id . ".json";

# Let's read the data.
$data = file_get_contents($url);

# If error -> quit.
if ($data === false) {
  die("Http request failed");
}

# This is a json. Let's decode it into a PHP object.
$json = json_decode($data, true);
if ($json === false) {
  die("Invalid JSON");
}

# We want to import the corresponding class file, in order to read the ontology
# comments.
$file = $config["classes"][$class];
require_once($config["general"]["path"] . $file);

# Let's take the class name removing the .php from the filename.
$className = explode(".", $file)[0];

# PHP, give me all the comments! (The App\Entity is the namespace of
# api-platform)
$classData = getClassData('App\Entity\\' . $className);

# Here we start composing the RDF file.
$content = "  <biflow:" . $classData["name"] . " rdf:about=\"" . $config["general"]["rdfuri"] . "/" . $class . "/" . $id . "\">\n";

# For each properties of the class...
foreach($classData["properties"] as $name => $property) {
  # If not included in the final JSON file, let's ignore it.
  if (!isset($json[$name]) || !$json[$name]) {
    continue;
  }

  # If the value is an array, let's process each item individually.
  if (is_array($json[$name])) {
    foreach($json[$name] as $value) {
      $content .= addProperty($config, $value, $property);
    }
  } else {
    $content .= addProperty($config, $json[$name], $property);
  }
}

# Now, let's complete the RDF.
$content .= "  </biflow:" . $classData["name"] . ">\n";

# The RDF header.
header("Content-type: application/rdf+xml");

# Let's read the template to be used.
$file = file_get_contents($config["general"]["template_single"]);

# Let's replace the placeholder with our RDF content.
$rdf = str_replace("{biflow.content}", $content, $file);

# This is the output:
echo $rdf;

exit;

# This function adds a single RDF property in the RDF content.
function addProperty($config, $value, $property) {
  # if the property has a range, and the range is a biflow property...
  if (isset($property["range"]) && substr($property["range"][0], 0, 8) == "&biflow;" && $value[0] == "/") {
    # Let's split the value in parts. The value is something like: /catalog_biflow/api/expressions/123
    $parts = explode("/", $value);

    # The ID is the last part of the URL.
    $id = end($parts);

    # the class is the previous one.
    $class = prev($parts);

    # Let's compose the RDF property:
    return "    <biflow:" . $property["name"] . " rdf:resource=\"" . $config["general"]["rdfuri"]. "/" . $class . "/" . $id . "\" />\n";
  }

  # If the property has an "include", fetch the data and print it.
  if (isset($property["include"])) {
    $parts = explode("/", $value);
    $id = end($parts);
    $class = prev($parts);

    $url = $config["general"]["api"] . "/" . $class . "/" . $id . ".json";
    $data = file_get_contents($url);
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
  return "    <biflow:" . $property["name"] . ">" . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</biflow:" . $property["name"] . ">\n";
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

function showOntology() {
  $config = parse_ini_file("config.ini");
  $files = scandir($config["path"]);

  $data = Array();
  foreach ($files as $file) {
    if ($file[0] == '.') continue;

    require_once($config["path"] . $file);
    $className = explode(".", $file)[0];
    $classData = getClassData('App\Entity\\' . $className);

    if ($classData != null) {
      $data[] = $classData;
    }
  }

  $content = generateRDF($data);

  header("Content-type: application/rdf+xml");

  $file = file_get_contents($config["template_all"]);
  $rdf = str_replace("{biflow.classes_and_properties}", $content, $file);
  echo $rdf;
}

function generateRDF($data) {
  $c = "";
  foreach($data as $x) {
    $c .= "  <owl:Class rdf:about=\"&biflow;" . $x["name"] ."\">\n";
    if (isset($x["label"])) {
      $c .= "    <rdfs:label xml:lang=\"en\">" . implode("\n", $x["label"]) . "</rdfs:label>\n";
    } else {
      $c .= "    <rdfs:label xml:lang=\"en\">" . $x["name"] . "</rdfs:label>\n";
    }

    if (isset($x["comment"])) {
      $c .= "    <rdfs:comment xml:lang=\"en\">" . implode("\n", $x["comment"]) . "</rdfs:comment>\n";
    }

    $c .= "    <rdfs:isDefinedBy rdf:resource=\"&biflow;\" />\n";

    if (isset($x["equivalentClass"])) {
      foreach($x['equivalentClass'] as $eq) {
        $c .= "    <owl:equivalentClass rdf:resource=\"" . $eq . "\" />\n";
      }
    }

    if (isset($x["subClassOf"])) {
      foreach($x['subClassOf'] as $sub) {
        $c .="    <rdfs:subClassOf rdf:resource=\"" . $sub . "\" />\n";
      }
    }

    $c .= "  </owl:Class>\n\n";
  }

  $properties = Array();

  foreach($data as $class) {
    foreach($class["properties"] as $x) {
      if (!isset($properties[$x["name"]])) {
        $properties[$x["name"]] = Array();
      }

      foreach($x as $k => $v) {
        if (!isset($properties[$x["name"]][$k])) {
          $properties[$x["name"]][$k] = Array();
        }

        if (is_array($v)) {
          $properties[$x["name"]][$k] = array_merge($properties[$x["name"]][$k], $v);
        } else {
          $properties[$x["name"]][$k][] = $v;
        }
      }

      if (!isset($properties[$x["name"]]["domain"])) {
        $properties[$x["name"]]["domain"] = Array();
      }

      $properties[$x["name"]]["domain"][] = "&biflow;" . $class["name"];
    }
  }

  foreach($properties as $name => $x) {
    $c .= "  <owl:ObjectProperty rdf:about=\"&biflow;" . $name . "\">\n";

    if (isset($x["label"])) {
      $c .= "    <rdfs:label xml:lang=\"en\">" . implode("\n", $x["label"]) . "</rdfs:label>\n";
    } else {
      $c .= "    <rdfs:label xml:lang=\"en\">" . $name . "</rdfs:label>\n";
    }

    if (isset($x["comment"])) {
      $c .= "    <rdfs:comment xml:lang=\"en\">" . implode("\n", $x["comment"]) . "</rdfs:comment>\n";
    }

    $c .= "    <rdfs:isDefinedBy rdf:resource=\"&biflow;\" />\n";

    if (isset($x["domain"])) {
      foreach($x['domain'] as $sub) {
        $c .="    <rdfs:domain rdf:resource=\"" . $sub . "\" />\n";
      }
    }

    if (isset($x["range"])) {
      foreach($x['range'] as $sub) {
        $c .="    <rdfs:range rdf:resource=\"" . $sub . "\" />\n";
      }
    }

    if (isset($x["inverseOf"])) {
      foreach($x['inverseOf'] as $sub) {
        $c .="    <rdfs:inverseOf rdf:resource=\"" . $sub . "\" />\n";
      }
    }

    if (isset($x["equivalentProperty"])) {
      foreach($x['equivalentProperty'] as $sub) {
        $c .="    <owl:equivalentProperty rdf:resource=\"" . $sub . "\" />\n";
      }
    }

    if (isset($x["subPropertyOf"])) {
      foreach($x['subPropertyOf'] as $sub) {
        $c .="    <rdfs:subPropertyOf rdf:resource=\"" . $sub . "\" />\n";
      }
    }

    $c .= "  </owl:ObjectProperty>\n\n";
  }

  return $c;
}
