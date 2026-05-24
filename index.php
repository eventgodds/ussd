<?php

/*
|--------------------------------------------------------------------------
| GHARTEY EVENTS USSD VOTING SYSTEM (FIRESTORE)
|--------------------------------------------------------------------------
*/

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| FIREBASE REQUEST FUNCTION
|--------------------------------------------------------------------------
*/

function firebaseRequest($method, $collection, $docId, $data = null)
{
    $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";

    $url = $baseURL . "/" . $collection . "/" . $docId;

    // Update only votes field
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

    if (curl_errno($ch)) {
        return null;
    }

    curl_close($ch);

    return json_decode($response, true);
}

/*
|--------------------------------------------------------------------------
| GET JSON INPUT
|--------------------------------------------------------------------------
*/

$json = file_get_contents("php://input");
$data = json_decode($json, true);

/*
|--------------------------------------------------------------------------
| REQUEST VARIABLES
|--------------------------------------------------------------------------
*/

$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$msisdn     = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData   = trim($data['userData'] ?? '');

/*
|--------------------------------------------------------------------------
| DEFAULT RESPONSE
|--------------------------------------------------------------------------
*/

$message = "";
$continueSession = true;

/*
|--------------------------------------------------------------------------
| SPLIT INPUT
|--------------------------------------------------------------------------
*/

$input = explode("*", $userData);

/*
|--------------------------------------------------------------------------
| MAIN MENU
|--------------------------------------------------------------------------
*/

if ($newSession == true) {

    $message  = "Welcome To Ghartey Eventsssss\n";
    $message .= "1. Vote";

    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - ENTER CONTESTANT CODE
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "1") {

    $message = "Enter Contestant Code";

    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 2 - FETCH CONTESTANT
|--------------------------------------------------------------------------
*/

elseif (count($input) == 2 && $input[0] == "1") {

    $contestantCode = strtoupper(trim($input[1]));

    /*
    |--------------------------------------------------------------------------
    | IMPORTANT
    |--------------------------------------------------------------------------
    | Your document ID in Firebase MUST match the contestant code
    |
    | Example:
    |
    | awards_nominees
    |    └── RE_PG
    |
    */

    $contestant = firebaseRequest(
        "GET",
        "awards_nominees",
        $contestantCode
    );

    if (
        isset($contestant['fields']['fullName']['stringValue'])
    ) {

        $contestantName =
            $contestant['fields']['fullName']['stringValue'];

        $message  = "Vote For:\n";
        $message .= $contestantName . "\n";
        $message .= "Code: " . $contestantCode . "\n\n";
        $message .= "1. Confirm\n";
        $message .= "2. Cancel";

        $continueSession = true;

    } else {

        $message = "Contestant Not Found";

        $continueSession = false;
    }
}

/*
|--------------------------------------------------------------------------
| STEP 3 - CONFIRM VOTE
|--------------------------------------------------------------------------
*/

elseif (
    count($input) == 3 &&
    $input[0] == "1" &&
    $input[2] == "1"
) {

    $contestantCode = strtoupper(trim($input[1]));

    $contestant = firebaseRequest(
        "GET",
        "awards_nominees",
        $contestantCode
    );

    if (
        isset($contestant['fields']['fullName']['stringValue'])
    ) {

        $contestantName =
            $contestant['fields']['fullName']['stringValue'];

        /*
        |--------------------------------------------------------------------------
        | CURRENT VOTES
        |--------------------------------------------------------------------------
        */

        $votes = 0;

        if (isset($contestant['fields']['votes']['integerValue'])) {

            $votes = (int)
                $contestant['fields']['votes']['integerValue'];
        }

        /*
        |--------------------------------------------------------------------------
        | INCREMENT VOTES
        |--------------------------------------------------------------------------
        */

        $newVotes = $votes + 1;

        /*
        |--------------------------------------------------------------------------
        | UPDATE FIRESTORE
        |--------------------------------------------------------------------------
        */

        firebaseRequest(
            "PATCH",
            "awards_nominees",
            $contestantCode,
            [
                "fields" => [
                    "votes" => [
                        "integerValue" => strval($newVotes)
                    ]
                ]
            ]
        );

        $message  = "Vote Successful\n";
        $message .= $contestantName . "\n";
        $message .= "Total Votes: " . $newVotes;

    } else {

        $message = "Vote Failed";
    }

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| CANCEL VOTE
|--------------------------------------------------------------------------
*/

elseif (
    count($input) == 3 &&
    $input[0] == "1" &&
    $input[2] == "2"
) {

    $message = "Vote Cancelled";

    $continueSession = false;
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
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID"       => $sessionID,
    "userID"          => $userID,
    "msisdn"          => $msisdn,
    "message"         => $message,
    "continueSession" => $continueSession
];

/*
|--------------------------------------------------------------------------
| RETURN JSON
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json");

echo json_encode($response);

?>
