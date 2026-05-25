<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| FIREBASE REQUEST
|--------------------------------------------------------------------------
*/
function firebaseRequest($method, $collection, $docId, $data = null, $mask = null)
{
    $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";
    $url = $baseURL . "/" . $collection . "/" . $docId;

    if ($method == "PATCH" && $mask !== null) {
        $url .= "?updateMask.fieldPaths=" . $mask;
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
| SESSION HELPERS
|--------------------------------------------------------------------------
*/
function saveSession($sessionID, $step, $contestantCode)
{
    $data = [
        "fields" => [
            "step" => ["integerValue" => $step],
            "contestantCode" => ["stringValue" => $contestantCode]
        ]
    ];
    firebaseRequest("PATCH", "sessions", $sessionID, $data, "step,contestantCode");
}

function loadSession($sessionID)
{
    $session = firebaseRequest("GET", "sessions", $sessionID);
    return [
        "step" => $session['fields']['step']['integerValue'] ?? 0,
        "contestantCode" => $session['fields']['contestantCode']['stringValue'] ?? ''
    ];
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
$contestantCode = '';
$step = 0;

/*
|--------------------------------------------------------------------------
| LOAD OR INIT SESSION
|--------------------------------------------------------------------------
*/
if ($newSession) {
    $step = 0;
    $contestantCode = '';
    saveSession($sessionID, $step, $contestantCode);

    $message = "Welcome To Ghartey Events\n";
    $message .= "1. Vote";
} else {
    $sessionState = loadSession($sessionID);
    $step = $sessionState['step'];
    $contestantCode = $sessionState['contestantCode'];
}

/*
|--------------------------------------------------------------------------
| FLOW LOGIC
|--------------------------------------------------------------------------
*/
if ($step == 0 && $userData == "1") {
    $step = 1;
    saveSession($sessionID, $step, '');
    $message = "Enter Contestant Code";
}

elseif ($step == 1 && preg_match('/^[A-Z0-9_]+$/', strtoupper($userData))) {
    $contestantCode = strtoupper($userData);
    $contestant = firebaseRequest("GET", "awards_nominees", $contestantCode);

    if (isset($contestant['fields']['fullName']['stringValue'])) {
        $contestantName = $contestant['fields']['fullName']['stringValue'];
        $step = 2;
        saveSession($sessionID, $step, $contestantCode);

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

elseif ($step == 2 && $userData == "1") {
    $contestant = firebaseRequest("GET", "awards_nominees", $contestantCode);

    if (isset($contestant['fields']['votes']['integerValue'])) {
        $currentVotes = (int)$contestant['fields']['votes']['integerValue'];
        $updateData = [
            "fields" => [
                "votes" => ["integerValue" => $currentVotes + 1]
            ]
        ];
        firebaseRequest("PATCH", "awards_nominees", $contestantCode, $updateData, "votes");
    }

    $message = "Vote Successful";
    $continueSession = false;
    saveSession($sessionID, 0, '');
}

elseif ($step == 2 && $userData == "2") {
    $message = "Vote Cancelled";
    $continueSession = false;
    saveSession($sessionID, 0, '');
}

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
