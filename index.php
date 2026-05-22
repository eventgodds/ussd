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

// Get user session from Firestore
$userSession = getSession($msisdn);
if (!$userSession || empty($userSession)) {
    $userSession = ['step' => 'menu'];
    saveSession($msisdn, $userSession);
}

// MAIN MENU
if ($newSession == true || empty($userData)) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help\n";
    $message .= "Enter choice:";
    saveSession($msisdn, ['step' => 'menu']);
}

// HANDLE MENU SELECTIONS
elseif ($userSession['step'] == 'menu') {
    
    if ($userData == "1") {
        $message = "Enter contestant code (FS1, FS2, FS3, FS4, or FS5):";
        saveSession($msisdn, ['step' => 'enter_code']);
    }
    
    elseif ($userData == "2") {
        $contestants = getContestants();
        if ($contestants && !empty($contestants)) {
            $message = "Current Results:\n";
            foreach ($contestants as $code => $contestant) {
                $votes = isset($contestant['votes']) ? $contestant['votes'] : 0;
                $message .= $code . " - " . $contestant['contestant_name'] . ": " . $votes . " votes\n";
            }
            $message .= "\nSend 0 for main menu";
            $continueSession = true;
            saveSession($msisdn, ['step' => 'menu']);
        } else {
            $message = "No results available. Send 0 for main menu";
            $continueSession = true;
        }
    }
    
    elseif ($userData == "3") {
        $message = "Help:\n";
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
        $message = "Invalid option. Select 1, 2, or 3\n";
        $message .= "Send 0 for main menu";
        $continueSession = true;
    }
}

// ENTER CONTESTANT CODE - FETCH FROM FIRESTORE
elseif ($userSession['step'] == 'enter_code') {
    
    $contestantCode = strtoupper($userData);
    
    // Fetch contestant from Firestore database
    $contestant = getContestant($contestantCode);
    
    if ($contestant && isset($contestant['contestant_name'])) {
        $currentVotes = isset($contestant['votes']) ? $contestant['votes'] : 0;
        
        $message = "Contestant found:\n";
        $message .= "Name: " . $contestant['contestant_name'] . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "Current votes: " . $currentVotes . "\n";
        $message .= "Enter number of votes to add (1-100):";
        
        saveSession($msisdn, [
            'step' => 'enter_votes',
            'selected_code' => $contestantCode,
            'selected_name' => $contestant['contestant_name'],
            'current_votes' => $currentVotes
        ]);
    } else {
        $message = "Contestant code '" . $contestantCode . "' not found in database.\n";
        $message .= "Valid codes: FS1, FS2, FS3, FS4, FS5\n";
        $message .= "Enter contestant code or 0 for menu:";
        saveSession($msisdn, ['step' => 'enter_code']);
    }
}

// ENTER NUMBER OF VOTES - UPDATE FIRESTORE
elseif ($userSession['step'] == 'enter_votes') {
    
    $numberOfVotes = intval($userData);
    
    if ($numberOfVotes > 0 && $numberOfVotes <= 100) {
        
        $contestantCode = $userSession['selected_code'];
        
        // Get current contestant data from Firestore
        $contestant = getContestant($contestantCode);
        
        if ($contestant) {
            $currentVotes = isset($contestant['votes']) ? intval($contestant['votes']) : 0;
            $newTotalVotes = $currentVotes + $numberOfVotes;
            
            // Update votes in Firestore
            $updateResult = updateContestantVotes($contestantCode, $newTotalVotes);
            
            // Record transaction
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
            $message .= "Added " . $numberOfVotes . " vote(s) to " . $contestant['contestant_name'] . "\n";
            $message .= "Previous votes: " . $currentVotes . "\n";
            $message .= "Total votes now: " . $newTotalVotes . "\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            deleteSession($msisdn);
        } else {
            $message = "Error: Contestant not found in database.\n";
            $message .= "Please try again.";
            $continueSession = false;
        }
    } else {
        $message = "Invalid number. Enter 1-100 votes:\n";
        $message .= "Or 0 to cancel:";
        $continueSession = true;
    }
}

// HANDLE CANCEL
elseif ($userData == "0") {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help\n";
    $message .= "Enter choice:";
    $continueSession = true;
    saveSession($msisdn, ['step' => 'menu']);
}

// INVALID INPUT
else {
    $message = "Invalid input. Send 0 for main menu";
    $continueSession = true;
}

// RESPONSE FOR ARKESEL
$arkeselResponse = [
    "message" => $message,
    "continueSession" => $continueSession ? "True" : "False"
];

header('Content-Type: application/json');
echo json_encode($arkeselResponse);
?>
