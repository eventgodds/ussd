function firebaseRequest($method, $collection, $docId, $data = null)
{
    $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds-41e4f/databases/(default)/documents";

    $url = $baseURL . "/" . $collection . "/" . $docId;

    if ($method == "PATCH") {
        $url .= "?updateMask.fieldPaths=votes";
    }

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    return json_decode($response, true);
}
