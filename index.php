<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'firebase.php';

$json = file_get_contents('php://input');

if (empty($json)) {
    $json = json_encode($_REQUEST);
}
$data = json_decode($json, true);

if (!$data) {
    $data = $_POST;
}

$sessionID = $data['sessionID'] ?? $data['sessionId'] ?? '';
$userID = $data['userID'] ?? $data['userId'] ?? '';
$msisdn = $data['msisdn'] ?? $data['phoneNumber'] ?? '';
$newSession = $data['newSession'] ?? $data['new_session'] ?? false;
$userData = trim($data['userData'] ?? $data['text'] ?? '');

if (is_string($newSession)) {
    $newSession = strtolower($newSession) === 'true';
}

$message = "";
$continueSession = true;
$input = explode('*', $userData);

// Get or create user session from Firestore
$userSession = getSession($msisdn);
if (!$userSession || empty($userSession)) {
    $userSession = ['step' => 'menu'];
    saveSession($msisdn, $userSession);
}
/*
|--------------------------------------------------------------------------
| MAIN MENU
|--------------------------------------------------------------------------
*/

if ($newSession == true || empty($userData)) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help\n";
    $message .= "Enter choice:";
    saveSession($msisdn, ['step' => 'menu']);
}

/*
|--------------------------------------------------------------------------
| HANDLE MENU SELECTIONS
|--------------------------------------------------------------------------
*/

elseif ($userSession['step'] == 'menu') {
    
    if ($userData == "1") {
        $message = "Enter contestant code (FS1, FS2, FS3, FS4, or FS5):";
        saveSession($msisdn, ['step' => 'enter_code']);
    }
    
    elseif ($userData == "2") {
        // Fetch from Firestore contestants collection
        $contestants = getContestants();
        
        if ($contestants && !empty($contestants)) {
            $message = "CURRENT RESULTS\n";
            $message .= "--------------------------------\n";
            foreach ($contestants as $code => $contestant) {
                $votes = isset($contestant['votes']) ? $contestant['votes'] : 0;
                $name = isset($contestant['contestant_name']) ? $contestant['contestant_name'] : $code;
                $message .= "$code - $name: $votes votes\n";
            }
            $message .= "--------------------------------\n";
            $message .= "Send 0 for main menu";
            $continueSession = true;
            saveSession($msisdn, ['step' => 'menu']);
        } else {
            $message = "No contestants found in database.\nSend 0 for main menu";
            $continueSession = true;
        }
    }
    
    elseif ($userData == "3") {
        $message = "HELP\n";
        $message .= "1. Vote - Enter contestant code (FS1-FS5)\n";
        $message .= "2. View Results - See current standings\n";
        $message .= "Send 0 for main menu";
        $continueSession = true;
        saveSession($msisdn, ['step' => 'menu']);
    }
    
    elseif ($userData == "0") {
        $message = "Welcome to Ghartey Event\n";
        $message .= "1. Vote\n";
        $message .= "2. View Results\n";
        $message .= "3. Help\n";
        $message .= "Enter choice:";
        $continueSession = true;
        saveSession($msisdn, ['step' => 'menu']);
    }
    
    else {
        $message = "Invalid option. Select 1, 2, or 3\nSend 0 for main menu";
        $continueSession = true;
    }
}

/*
|--------------------------------------------------------------------------
| ENTER CONTESTANT CODE - FETCH FROM FIRESTORE
|--------------------------------------------------------------------------
*/

elseif ($userSession['step'] == 'enter_code') {
    
    $contestantCode = strtoupper($userData);
    
    // Fetch contestant data from Firestore 'contestants' collection
    $contestant = getContestant($contestantCode);
    
    if ($contestant && !empty($contestant)) {
        // Contestant found in Firestore - display their data
        $currentVotes = isset($contestant['votes']) ? $contestant['votes'] : 0;
        $contestantName = isset($contestant['contestant_name']) ? $contestant['contestant_name'] : $contestantCode;
        
        $message = "CONTESTANT FOUND IN DATABASE\n";
        $message .= "--------------------------------\n";
        $message .= "Name: $contestantName\n";
        $message .= "Code: $contestantCode\n";
        $message .= "Current Votes: $currentVotes\n";
        $message .= "--------------------------------\n";
        $message .= "Enter number of votes to add (1-100):";
        
        // Save to session for next step
        saveSession($msisdn, [
            'step' => 'enter_votes',
            'selected_code' => $contestantCode,
            'selected_name' => $contestantName,
            'current_votes' => $currentVotes
        ]);
    } else {
        // Contestant not in Firestore
        $message = "Contestant code '$contestantCode' not found in database.\n";
        $message .= "Valid codes: FS1, FS2, FS3, FS4, FS5\n";
        $message .= "Enter contestant code or 0 for menu:";
        saveSession($msisdn, ['step' => 'enter_code']);
    }
}

/*
|--------------------------------------------------------------------------
| ENTER NUMBER OF VOTES - UPDATE FIRESTORE
|--------------------------------------------------------------------------
*/

elseif ($userSession['step'] == 'enter_votes') {
    
    $numberOfVotes = intval($userData);
    
    if ($numberOfVotes > 0 && $numberOfVotes <= 100) {
        
        $contestantCode = $userSession['selected_code'];
        
        // Get current contestant data from Firestore again to ensure latest
        $contestant = getContestant($contestantCode);
        
        if ($contestant && !empty($contestant)) {
            // Calculate new vote total
            $currentVotes = isset($contestant['votes']) ? intval($contestant['votes']) : 0;
            $newTotalVotes = $currentVotes + $numberOfVotes;
            
            // Update contestant votes in Firestore 'contestants' collection
            $updateResult = updateContestantVotes($contestantCode, $newTotalVotes);
            
            if ($updateResult) {
                // Record transaction in Firestore
                $transaction = [
                    "msisdn" => $msisdn,
                    "contestant_code" => $contestantCode,
                    "contestant_name" => $contestant['contestant_name'],
                    "votes_added" => $numberOfVotes,
                    "previous_total" => $currentVotes,
                    "new_total" => $newTotalVotes,
                    "timestamp" => time(),
                    "date" => date('Y-m-d H:i:s')
                ];
                saveTransaction($transaction);
                
                $message = "VOTE SUCCESSFUL!\n";
                $message .= "--------------------------------\n";
                $message .= "Added $numberOfVotes vote(s) to " . $contestant['contestant_name'] . "\n";
                $message .= "Previous votes: $currentVotes\n";
                $message .= "Total votes now: $newTotalVotes\n";
                $message .= "--------------------------------\n";
                $message .= "Thank you for voting!";
                $continueSession = false;
                
                // Clear session
                deleteSession($msisdn);
            } else {
                $message = "Error updating votes. Please try again.\nSend 0 for menu";
                $continueSession = false;
            }
        } else {
            $message = "Error: Contestant not found in database.\nSend 0 for menu";
            $continueSession = false;
        }
    } else {
        $message = "Invalid number. Enter 1-100 votes:\nOr 0 to cancel:";
        $continueSession = true;
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE CANCEL (0)
|--------------------------------------------------------------------------
*/

elseif ($userData == "0") {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help\n";
    $message .= "Enter choice:";
    $continueSession = true;
    saveSession($msisdn, ['step' => 'menu']);
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT
|--------------------------------------------------------------------------
*/

else {
    $message = "Invalid input. Send 0 for main menu";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

$arkeselResponse = [
    "message" => $message,
    "continueSession" => $continueSession ? "True" : "False"
];

header('Content-Type: application/json');

if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == '127.0.0.1:8000') {
    echo json_encode($response, JSON_PRETTY_PRINT);
} else {
    echo json_encode($arkeselResponse);
}

?>
