<?php

/*
|--------------------------------------------------------------------------
| USSD VOTING SYSTEM - GHARTEY EVENTS
|--------------------------------------------------------------------------
| COMPLETE FIXED VERSION
|--------------------------------------------------------------------------
*/

ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'firebase.php';

/*
|--------------------------------------------------------------------------
| GET JSON REQUEST
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');

$data = json_decode($json, true);

/*
|--------------------------------------------------------------------------
| REQUEST VARIABLES
|--------------------------------------------------------------------------
*/

$sessionID   = $data['sessionID'] ?? '';
$userID      = $data['userID'] ?? '';
$msisdn      = $data['msisdn'] ?? '';
$newSession  = $data['newSession'] ?? false;
$userData    = trim($data['userData'] ?? '');

/*
|--------------------------------------------------------------------------
| RESPONSE VARIABLES
|--------------------------------------------------------------------------
*/

$message = "";
$continueSession = true;

/*
|--------------------------------------------------------------------------
| SPLIT INPUT
|--------------------------------------------------------------------------
*/

$input = explode('*', $userData);

/*
|--------------------------------------------------------------------------
| MAIN MENU
|--------------------------------------------------------------------------
*/

if ($newSession == true) {

    $message  = "Welcome to Ghartey Event\n";
    $message .= "1. Vote";

    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - SELECT VOTE
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "1") {

    $message = "Enter contestant code";

    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 2 - SHOW CONTESTANT DETAILS
|--------------------------------------------------------------------------
*/

elseif (count($input) == 2 && $input[0] == "1") {

    $contestantCode = strtoupper(trim($input[1]));

    $contestant = firebaseRequest(
        "GET",
        "contestants/" . $contestantCode
    );

    if ($contestant && isset($contestant['contestant_name'])) {

        $message  = "Vote For:\n";
        $message .= $contestant['contestant_name'] . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel";

        $continueSession = true;

    } else {

        $message = "Contestant not found";

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

    /*
    |--------------------------------------------------------------------------
    | GET CONTESTANT
    |--------------------------------------------------------------------------
    */

    $contestant = firebaseRequest(
        "GET",
        "contestants/" . $contestantCode
    );

    if ($contestant) {

        /*
        |--------------------------------------------------------------------------
        | CURRENT VOTES
        |--------------------------------------------------------------------------
        */

        $votes = isset($contestant['votes'])
            ? (int)$contestant['votes']
            : 0;

        $newVotes = $votes + 1;

        /*
        |--------------------------------------------------------------------------
        | UPDATE VOTES
        |--------------------------------------------------------------------------
        */

        firebaseRequest(
            "PATCH",
            "contestants/" . $contestantCode,
            [
                "votes" => $newVotes
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | SUCCESS MESSAGE
        |--------------------------------------------------------------------------
        */

        $message  = "Vote Successful!\n";
        $message .= $contestant['contestant_name'] . "\n";
        $message .= "Total Votes: " . $newVotes;

    } else {

        $message = "Vote failed";
    }

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| STEP 3 - CANCEL VOTE
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

    $message = "Invalid input";

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

header('Content-Type: application/json');

echo json_encode($response);

?>