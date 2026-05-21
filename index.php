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

$sessionID = $data['sessionID'] ?? $data['sessionId'] ?? $data['SESSION_ID'] ?? '';
$userID = $data['userID'] ?? $data['userId'] ?? $data['USER_ID'] ?? '';
$msisdn = $data['msisdn'] ?? $data['phoneNumber'] ?? $data['MSISDN'] ?? '';
$newSession = $data['newSession'] ?? $data['new_session'] ?? false;
$userData = trim($data['userData'] ?? $data['text'] ?? $data['USER_DATA'] ?? '');

if (is_string($newSession)) {
    $newSession = strtolower($newSession) === 'true';
}

$message = "";
$continueSession = true;
$input = explode('*', $userData);

// Get or create user session
$userSession = firebaseRequest("GET", "sessions/" . $msisdn);
if (!$userSession) {
    $userSession = ['step' => 'menu'];
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
    firebaseRequest("PUT", "sessions/" . $msisdn, ['step' => 'menu']);
}

/*
|--------------------------------------------------------------------------
| HANDLE MENU SELECTIONS
|--------------------------------------------------------------------------
*/

elseif ($userSession['step'] == 'menu') {
    
    if ($userData == "1") {
        // Show contestants list
        $contestants = firebaseRequest("GET", "contestants");
        if ($contestants) {
            $message = "Select contestant:\n";
            foreach ($contestants as $code => $contestant) {
                $message .= $code . " - " . $contestant['contestant_name'] . "\n";
            }
            $message .= "Enter contestant code:";
            firebaseRequest("PUT", "sessions/" . $msisdn, ['step' => 'enter_code']);
        } else {
            $message = "No contestants available. Contact admin.";
            $continueSession = false;
        }
    }
    
    elseif ($userData == "2") {
        // Show results
        $contestants = firebaseRequest("GET", "contestants");
        if ($contestants) {
            $message = "Current Results:\n";
            foreach ($contestants as $code => $contestant) {
                $votes = isset($contestant['votes']) ? $contestant['votes'] : 0;
                $message .= $code . " - " . $contestant['contestant_name'] . ": " . $votes . " votes\n";
            }
            $message .= "\nSend 0 for main menu";
            $continueSession = true;
            firebaseRequest("PUT", "sessions/" . $msisdn, ['step' => 'menu']);
        } else {
            $message = "No results available.";
            $continueSession = false;
        }
    }
    
    elseif ($userData == "3") {
        $message = "Help:\n";
        $message .= "1. Vote - Choose your favorite contestant\n";
        $message .= "2. View Results - See current standings\n";
        $message .= "Send 0 for main menu";
        $continueSession = true;
        firebaseRequest("PUT", "sessions/" . $msisdn, ['step' => 'menu']);
    }
    
    elseif ($userData == "0") {
        $message = "Welcome to Ghartey Event\n";
        $message .= "1. Vote\n";
        $message .= "2. View Results\n";
        $message .= "3. Help\n";
        $message .= "Enter choice:";
        $continueSession = true;
        firebaseRequest("PUT", "sessions/" . $msisdn, ['step' => 'menu']);
    }
    
    else {
        $message = "Invalid option. Select 1, 2, or 3\n";
        $message .= "Send 0 for main menu";
        $continueSession = true;
    }
}

/*
|--------------------------------------------------------------------------
| ENTER CONTESTANT CODE
|--------------------------------------------------------------------------
*/

elseif ($userSession['step'] == 'enter_code') {
    
    $contestantCode = strtoupper($userData);
    
    // Check if contestant exists in database
    $contestant = firebaseRequest("GET", "contestants/" . $contestantCode);
    
    if ($contestant && isset($contestant['contestant_name'])) {
        // Save selected contestant to session
        firebaseRequest("PUT", "sessions/" . $msisdn, [
            'step' => 'enter_votes',
            'selected_code' => $contestantCode,
            'selected_name' => $contestant['contestant_name']
        ]);
        
        $message = "Contestant: " . $contestant['contestant_name'] . "\n";
        $message .= "Current votes: " . ($contestant['votes'] ?? 0) . "\n";
        $message .= "Enter number of votes (1-100):";
    } else {
        $message = "Contestant code '" . $contestantCode . "' not found.\n";
        $message .= "Valid codes: FS1, FS2, FS3, FS4, FS5\n";
        $message .= "Enter contestant code or 0 for menu:";
        firebaseRequest("PUT", "sessions/" . $msisdn, ['step' => 'enter_code']);
    }
}

/*
|--------------------------------------------------------------------------
| ENTER NUMBER OF VOTES
|--------------------------------------------------------------------------
*/

elseif ($userSession['step'] == 'enter_votes') {
    
    $numberOfVotes = intval($userData);
    
    if ($numberOfVotes > 0 && $numberOfVotes <= 100) {
        
        $contestantCode = $userSession['selected_code'];
        $contestant = firebaseRequest("GET", "contestants/" . $contestantCode);
        
        if ($contestant) {
            // Add votes to existing count
            $currentVotes = isset($contestant['votes']) ? intval($contestant['votes']) : 0;
            $newTotalVotes = $currentVotes + $numberOfVotes;
            
            // Update contestant votes
            $updateResult = firebaseRequest("PATCH", "contestants/" . $contestantCode, [
                "votes" => $newTotalVotes
            ]);
            
            // Record transaction
            $transaction = [
                "msisdn" => $msisdn,
                "contestant_code" => $contestantCode,
                "contestant_name" => $contestant['contestant_name'],
                "votes" => $numberOfVotes,
                "timestamp" => time(),
                "date" => date('Y-m-d H:i:s')
            ];
            firebaseRequest("POST", "transactions", $transaction);
            
            $message = "SUCCESS!\n";
            $message .= $numberOfVotes . " vote(s) added to " . $contestant['contestant_name'] . "\n";
            $message .= "Total votes now: " . $newTotalVotes . "\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            // Clear session
            firebaseRequest("DELETE", "sessions/" . $msisdn);
        } else {
            $message = "Error: Contestant not found.\n";
            $message .= "Please try again.";
            $continueSession = false;
        }
    } else {
        $message = "Invalid number. Enter 1-100 votes:\n";
        $message .= "Or 0 to cancel:";
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
    firebaseRequest("PUT", "sessions/" . $msisdn, ['step' => 'menu']);
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
