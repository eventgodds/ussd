<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| FIREBASE CONFIG
|--------------------------------------------------------------------------
*/
$projectId = "eventgodds-41e4f";

function firebaseRequest($method, $collection, $docId, $data = null, $mask = null)
{
    global $projectId;

    $baseURL = "https://firestore.googleapis.com/v1/projects/$projectId/databases/(default)/documents";

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
| HARDCODED CONTESTANTS
|--------------------------------------------------------------------------
*/
$contestants = [
    "FS1" => "EGYIRWAA",
    "FS2" => "AGYEKUMWAA",
    "FS3" => "BOATEMAA",
    "FS4" => "ABENA",
    "FS5" => "SEDEM"
];

/*
|--------------------------------------------------------------------------
| SESSION HELPERS
|--------------------------------------------------------------------------
*/
function saveSession($sessionID, $step, $contestantCode)
{
    $data = [
        "fields" => [
            "step" => [
                "integerValue" => $step
            ],
            "contestantCode" => [
                "stringValue" => $contestantCode
            ]
        ]
    ];

    firebaseRequest(
        "PATCH",
        "sessions",
        $sessionID,
        $data,
        "step,contestantCode"
    );
}

function loadSession($sessionID)
{
    $session = firebaseRequest(
        "GET",
        "sessions",
        $sessionID
    );

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

$step = 0;
$contestantCode = '';

/*
|--------------------------------------------------------------------------
| LOAD SESSION
|--------------------------------------------------------------------------
*/
if ($newSession) {

    $step = 0;
    $contestantCode = '';

    saveSession($sessionID, 0, '');

    $message  = "Welcome To Ghartey Events\n";
    $message .= "1. Vote";

} else {

    $session = loadSession($sessionID);

    $step = $session['step'];
    $contestantCode = $session['contestantCode'];
}

/*
|--------------------------------------------------------------------------
| FLOW
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| STEP 0
|--------------------------------------------------------------------------
*/
if ($step == 0 && $userData == "1") {

    $step = 1;

    saveSession($sessionID, $step, '');

    $message = "Enter Contestant Code";
}

/*
|--------------------------------------------------------------------------
| STEP 1 - ENTER CONTESTANT CODE
|--------------------------------------------------------------------------
*/
elseif ($step == 1) {

    $contestantCode = strtoupper($userData);

    if (isset($contestants[$contestantCode])) {

        $contestantName = $contestants[$contestantCode];

        $step = 2;

        saveSession($sessionID, $step, $contestantCode);

        $message  = "Vote for $contestantName\n";
        $message .= "Nominee Code: $contestantCode\n";
        $message .= "Vote: GHC 1\n";
        $message .= "Enter number of votes";

    } else {

        $message = "Invalid Contestant Code";
        $continueSession = false;
    }
}

/*
|--------------------------------------------------------------------------
| STEP 2 - ENTER NUMBER OF VOTES
|--------------------------------------------------------------------------
*/
elseif ($step == 2) {

    if (is_numeric($userData) && $userData > 0) {

        $votesToAdd = (int)$userData;

        /*
        |--------------------------------------------------------------------------
        | GET CURRENT FIRESTORE DATA
        |--------------------------------------------------------------------------
        */
        $contestant = firebaseRequest(
            "GET",
            "awards_nominees",
            $contestantCode
        );

        $currentVotes = 0;

        if (isset($contestant['fields']['votes']['integerValue'])) {
            $currentVotes = (int)$contestant['fields']['votes']['integerValue'];
        }

        $newVotes = $currentVotes + $votesToAdd;

        /*
        |--------------------------------------------------------------------------
        | UPDATE FIRESTORE
        |--------------------------------------------------------------------------
        */
        $updateData = [
            "fields" => [
                "fullName" => [
                    "stringValue" => $contestants[$contestantCode]
                ],
                "code" => [
                    "stringValue" => $contestantCode
                ],
                "votes" => [
                    "integerValue" => $newVotes
                ]
            ]
        ];

        firebaseRequest(
            "PATCH",
            "awards_nominees",
            $contestantCode,
            $updateData,
            "fullName,code,votes"
        );

        $message  = "Vote Successful\n";
        $message .= $contestants[$contestantCode] . "\n";
        $message .= "Total Votes Added: " . $votesToAdd;

        $continueSession = false;

        saveSession($sessionID, 0, '');

    } else {

        $message = "Enter a valid number of votes";
    }
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT
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
