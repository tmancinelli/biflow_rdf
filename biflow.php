<?php

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
    $data["properties"][] = getPropertyData($property);
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

?>
