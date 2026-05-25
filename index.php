<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| FIREBASE REQUEST
|--------------------------------------------------------------------------
*/

function firebaseRequest($method, $collection, $docId, $data = null)
{
    $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";

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

/*
|--------------------------------------------------------------------------
| GET REQUEST
|--------------------------------------------------------------------------
*/

$json = file_get_contents("php://input");
$data = json_decode($json, true);

/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$msisdn     = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData   = trim($data['userData'] ?? '');

$message = "";
$continueSession = true;

/*
|--------------------------------------------------------------------------
| STEP 0 - NEW SESSION
|--------------------------------------------------------------------------
*/

if ($newSession == true) {

    $message = "Welcome To Ghartey Events\n";
    $message .= "1. Vote";

}

/*
|--------------------------------------------------------------------------
| STEP 1
|--------------------------------------------------------------------------
*/

elseif ($userData == "1") {

    $message = "Enter Contestant Code";

}

/*
|--------------------------------------------------------------------------
| STEP 2
|--------------------------------------------------------------------------
*/

elseif (preg_match('/^[A-Z0-9_]+$/', strtoupper($userData))) {

    $contestantCode = strtoupper($userData);

    $contestant = firebaseRequest(
        "GET",
        "awards_nominees",
        $contestantCode
    );

    if (isset($contestant['fields']['fullName']['stringValue'])) {

        $contestantName =
            $contestant['fields']['fullName']['stringValue'];

        $message  = "Vote For:\n";
        $message .= $contestantName . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "1. Confirm\n";
        $message .= "2. Cancel";

    } else {

        $message = "Contestant Not Found";
        $continueSession = false;
    }

}

/*
|--------------------------------------------------------------------------
| STEP 3
|--------------------------------------------------------------------------
*/

elseif ($userData == "1") {

    $message = "Vote Successful";
    $continueSession = false;

}

/*
|--------------------------------------------------------------------------
| INVALID
|--------------------------------------------------------------------------
*/

else {

    $message = "Invalid Input";
    $continueSession = false;

}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID"       => $sessionID,
    "userID"          => $userID,
    "msisdn"          => $msisdn,
    "message"         => $message,
    "continueSession" => $continueSession
];

header("Content-Type: application/json");

echo json_encode($response);
?>
