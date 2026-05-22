<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

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
    $message .= "1. Vote\n";
    $message .= "2. Check Contestants\n";
    $message .= "0. Exit";
}
elseif (count($input) == 1 && $input[0] == "0") {
    $message = "Thank you for using Ghartey Event Voting System";
    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - CHECK CONTESTANTS (Option 2)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "2") {
    $contestants = getFirestoreCollection("contestants");
    
    if ($contestants && count($contestants) > 0) {
        $message = "Available Contestants:\n";
        $counter = 1;
        foreach ($contestants as $contestant) {
            $code = $contestant['code'] ?? $contestant['id'] ?? 'N/A';
            $name = $contestant['contestant_name'] ?? $contestant['name'] ?? 'Unknown';
            $message .= $counter . ". " . $name . " (Code: " . $code . ")\n";
            $counter++;
            if ($counter > 10) break;
        }
        $message .= "\n0. Back to Main Menu";
    } else {
        $message = "No contestants available\n0. Back to Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - USER SELECTS VOTE (Option 1)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "1") {
    $message = "Enter contestant code:";
}

/*
|--------------------------------------------------------------------------
| STEP 2 - PROCESS VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1") {
    $contestantCode = strtoupper(trim($input[1]));
    
    // Search for contestant by code
    $contestant = findContestantByCode($contestantCode);
    
    if ($contestant) {
        // Store vote in Firestore
        $voteData = [
            'userID' => $userID,
            'msisdn' => $msisdn,
            'contestant_code' => $contestantCode,
            'contestant_name' => $contestant['contestant_name'],
            'timestamp' => date('Y-m-d H:i:s'),
            'sessionID' => $sessionID
        ];
        
        $voteSaved = addDocumentToCollection("votes", $voteData);
        
        if ($voteSaved) {
            $message = "✓ Vote successful!\n";
            $message .= "You voted for: " . $contestant['contestant_name'] . "\n";
            $message .= "Contestant Code: " . $contestantCode . "\n";
            $message .= "Thank you for voting!\n";
            $message .= "1. Vote Again\n";
            $message .= "2. Main Menu\n";
            $message .= "0. Exit";
        } else {
            $message = "Error recording vote. Please try again.\n1. Try Again\n0. Main Menu";
        }
    } else {
        $message = "Contestant code '" . $contestantCode . "' not found.\n";
        $message .= "Please check the code and try again.\n";
        $message .= "1. Try Again\n";
        $message .= "2. View Contestants\n";
        $message .= "0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VOTE AGAIN
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "Enter contestant code:";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| GO BACK TO MAIN MENU
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "2") {
    $message = "Main Menu\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Contestants\n";
    $message .= "0. Exit";
    $continueSession = true;
}
elseif (count($input) == 1 && $input[0] == "00") {
    $message = "Main Menu\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Contestants\n";
    $message .= "0. Exit";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT
|--------------------------------------------------------------------------
*/
else {
    $message = "Invalid input. Please try again.\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Contestants\n";
    $message .= "0. Exit";
    $continueSession = true;
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
