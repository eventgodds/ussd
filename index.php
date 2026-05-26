<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'firebase.php';

/*
|--------------------------------------------------------------------------
| GET REQUEST
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');

$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$msisdn = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData = trim($data['userData'] ?? '');

/*
|--------------------------------------------------------------------------
| RESPONSE VARIABLES
|--------------------------------------------------------------------------
*/

$message = "";
$continueSession = true;

/*
|--------------------------------------------------------------------------
| SPLIT USER INPUT
|--------------------------------------------------------------------------
*/

$input = explode('*', $userData);

/*
|--------------------------------------------------------------------------
| MAIN MENU
|--------------------------------------------------------------------------
*/

if ($newSession == true) {

    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote";
}

/*
|--------------------------------------------------------------------------
| STEP 1 - USER SELECTS VOTE
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "1") {

    $message = "Enter contestant code";
}

/*
|--------------------------------------------------------------------------
| STEP 2 - SHOW CONTESTANT DETAILS
|--------------------------------------------------------------------------
*/

elseif (count($input) == 2 && $input[0] == "1") {

    $contestantCode = $input[1];

    $contestant = firebaseRequest(
        "GET",
        "contestants/" . $contestantCode
    );

    if ($contestant) {

        $message = "You're voting for:\n";
        $message .= $contestant['contestant_name'] . "\n";
        $message .= "Code: " . $contestantCode;

    } else {

        $message = "Contestant not found";
    }

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
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

header('Content-Type: application/json');

echo json_encode($response);

?>
