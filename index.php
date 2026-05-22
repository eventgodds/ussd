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

// Handle empty input (for testing)
if (empty($json)) {
    $json = json_encode($_REQUEST);
}

$data = json_decode($json, true);

// Support both JSON and form data
if (!$data) {
    $data = $_REQUEST;
}

$sessionID = $data['sessionID'] ?? $data['sessionId'] ?? '';
$userID = $data['userID'] ?? $data['userId'] ?? '';
$msisdn = $data['msisdn'] ?? $data['phoneNumber'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData = trim($data['userData'] ?? $data['text'] ?? '');

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

if ($newSession == true || empty($userData)) {

    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help";
}

/*
|--------------------------------------------------------------------------
| STEP 1 - USER SELECTS VOTE
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

    $contestantCode = $input[1];
    
    // Check if user has already voted
    $existingVote = firebaseRequest(
        "GET",
        "votes/" . $msisdn . "_" . $sessionID
    );
    
    if ($existingVote) {
        $message = "You have already voted!\n";
        $message .= "Thank you for participating.";
        $continueSession = false;
    } else {
        $contestant = firebaseRequest(
            "GET",
            "contestants/" . $contestantCode
        );
        
        if ($contestant) {
            // Record the vote
            $voteData = [
                "msisdn" => $msisdn,
                "contestant_code" => $contestantCode,
                "contestant_name" => $contestant['contestant_name'],
                "timestamp" => time(),
                "session_id" => $sessionID
            ];
            
            $saveVote = firebaseRequest(
                "POST",
                "votes/" . $msisdn . "_" . time(),
                $voteData
            );
            
            // Update contestant vote count
            $currentVotes = $contestant['votes'] ?? 0;
            $updateContestant = firebaseRequest(
                "PATCH",
                "contestants/" . $contestantCode,
                ["votes" => $currentVotes + 1]
            );
            
            $message = "✓ Vote recorded successfully!\n";
            $message .= "You voted for: " . $contestant['contestant_name'] . "\n";
            $message .= "Thank you for participating!";
            
        } else {
            $message = "Contestant not found.\n";
            $message .= "Please try again.";
        }
        $continueSession = false;
    }
}

/*
|--------------------------------------------------------------------------
| VIEW RESULTS
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "2") {
    
    $contestants = firebaseRequest("GET", "contestants.json");
    
    if ($contestants) {
        $message = "=== CURRENT RESULTS ===\n";
        foreach ($contestants as $code => $contestant) {
            $votes = $contestant['votes'] ?? 0;
            $message .= $contestant['contestant_name'] . ": " . $votes . " votes\n";
        }
        $message .= "\nReply 1 to vote or 0 to exit";
        $continueSession = true;
    } else {
        $message = "No contestants available at the moment.";
        $continueSession = false;
    }
}

/*
|--------------------------------------------------------------------------
| HELP
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "3") {
    
    $message = "=== HELP ===\n";
    $message .= "1. Vote - Select your favorite contestant\n";
    $message .= "2. View Results - See current standings\n";
    $message .= "3. Help - Show this menu\n\n";
    $message .= "To vote:\n";
    $message .= "1. Select 'Vote' from main menu\n";
    $message .= "2. Enter contestant code\n";
    $message .= "3. Confirm your vote\n\n";
    $message .= "Reply 0 to go back to main menu";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| GO BACK TO MENU
|--------------------------------------------------------------------------
*/

elseif ($userData == "0") {
    $message = "Welcome back to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT
|--------------------------------------------------------------------------
*/

else {
    $message = "Invalid input. Please try again.\n";
    $message .= "Reply 0 for main menu";
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

// Arkesel specific response format
$arkeselResponse = [
    "message" => $message,
    "continueSession" => $continueSession ? "True" : "False"
];

header('Content-Type: application/json');

// Support both response formats
echo json_encode($response);
echo "\n";
echo json_encode($arkeselResponse);

?>
