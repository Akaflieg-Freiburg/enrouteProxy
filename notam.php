<?php
header("Content-Type: application/json");

// Dieses Skript liest NOTAMs über eine für maschinelle Anfragen 
// vorgesehene Schnittstelle der Federal Aviation Administration (FAA)
// der USA ein und gibt sie aus.
//
// Kontakt: Markus Sachs, ms@squawk-vfr.de
// Geschrieben für Enroute Flight Navigation im Jan. 2024.
//
// Weitergehende Informationen über die Datenquelle:
// https://www.faa.gov/
//
// Aufruf: [Server/htdocs]/notams.php?locationLongitude=a&locationLatitude=b&radius=c
// mit 
// a = Längengrad (Punkt als Dezimalkomma) des Zentrums der Suche
// b = Breitengrad (Punkt als Dezimalkomma) des Zentrums der Suche
// c = Radius der Suche in [Einheit?]
// Das Anhängen von &pageSize=d mit d als gewünschter Zahl ist optional; 
// ohne Nennung wird 1000 als Default gesetzt.

function isValidLatitude($input) {
    // Define the regular expression pattern for latitude and longitude
    $pattern = '/^(-?\d+(\.\d+)?)$/';

    // Use preg_match to check if the input string matches the pattern
    if (preg_match($pattern, $input) !== 1) {
        return false; // Format is invalid
    }

    // Validate latitude and longitude ranges
    if (!is_numeric($input) || $input < -90 || $input > 90) {
        return false; // Invalid latitude range
    }

    return true; // Input string is valid
}

function isValidLongitude($input) {
    // Define the regular expression pattern for latitude and longitude
    $pattern = '/^(-?\d+(\.\d+)?)$/';

    // Use preg_match to check if the input string matches the pattern
    if (preg_match($pattern, $input) !== 1) {
        return false; // Format is invalid
    }

    // Validate latitude and longitude ranges
    if (!is_numeric($input) || $input < -180 || $input > 180) {
        return false; // Invalid longitude range
    }

    return true; // Input string is valid
}

function isValidRadius($input) {
    // Check if input is numeric and within the range
    return is_numeric($input) && $input > 0 && $input < 500 && intval($input) == $input;
}

function isValidPageSize($input) {
    // Check if input is numeric and within the range
    return is_numeric($input) && $input > 0 && $input <= 1000 && intval($input) == $input;
}


try {
    // Input validation and sanitization
    $longitude = filter_input(INPUT_GET, 'locationLongitude', FILTER_VALIDATE_FLOAT);
    $latitude = filter_input(INPUT_GET, 'locationLatitude', FILTER_VALIDATE_FLOAT);
    $radius = filter_input(INPUT_GET, 'locationRadius', FILTER_VALIDATE_INT);
    $pageSize = filter_input(INPUT_GET, 'pageSize', FILTER_VALIDATE_INT) ?: 1000;

    if (!$longitude || !$latitude || !$radius || !isValidLongitude($longitude) || !isValidLatitude($latitude) || !isValidRadius($radius) || !isValidPageSize($pageSize)) {
      throw new InvalidArgumentException("Invalid input parameters");
    }


    // Build request
    $url = 'https://external-api.faa.gov/notamapi/v1/notams?'
    . 'locationLongitude=' . $longitude
    . '&locationLatitude=' . $latitude
    . '&locationRadius=' . $radius
    . '&pageSize=' . $pageSize;

    $FAA_KEY = getenv('FAA_KEY');
    $FAA_ID  = getenv('FAA_ID');

    $opts = array(
        'http' => array(
            'header' => "client_id: $FAA_ID\r\n" .
                        "client_secret: $FAA_KEY\r\n"
        )
    );


    // Get data
    $context = stream_context_create($opts);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        throw new Exception("Failed to get NOTAM data from FAA API");
    }

    // Return data
    echo $response;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
    // Log the error
    error_log($e->getMessage());
}

?>