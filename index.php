<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'firebase.php';

/*
|--------------------------------------------------------------------------
| GET REQUEST - Arkesel USSD Format
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');

if (empty($json)) {
    $json = json_encode($_REQUEST);
}

$data = json_decode($json, true);

if (!$data) {
    $data = $_POST;
}

// Arkesel USSD parameters
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

/*
|--------------------------------------------------------------------------
| USSD FLOW
|--------------------------------------------------------------------------
*/

// Main Menu
if ($newSession == true || empty($userData)) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help";
}

// Step 1: User selects Vote -> Show contestants
elseif (count($input) == 1 && $input[0] == "1") {
    
    $contestants = firebaseRequest("GET", "contestants");
    
    if ($contestants) {
        $message = "Select Contestant:\n";
        foreach ($contestants as $code => $contestant) {
            $message .= $code . ". " . $contestant['contestant_name'] . "\n";
        }
        $message .= "\nEnter contestant code (e.g., FS1):";
    } else {
        $message = "No contestants available. Please contact admin.";
        $continueSession = false;
    }
}

// Step 2: User enters contestant code -> Show contestant details & ask for votes
elseif (count($input) == 2 && $input[0] == "1") {
    
    $contestantCode = strtoupper($input[1]);
    
    // Check if contestant exists in database
    $contestant = firebaseRequest("GET", "contestants/" . $contestantCode);
    
    if ($contestant && isset($contestant['contestant_name'])) {
        // Store selected contestant in session
        firebaseRequest("PUT", "temp_sessions/" . $sessionID, [
            "contestant_code" => $contestantCode,
            "msisdn" => $msisdn,
            "step" => "awaiting_votes"
        ]);
        
        $message = "Contestant: " . $contestant['contestant_name'] . "\n";
        $message .= "Current votes: " . ($contestant['votes'] ?? 0) . "\n";
        $message .= "\nEnter number of votes (1-10):";
    } else {
        $message = "Contestant code '" . $contestantCode . "' not found.\n";
        $message .= "Please enter a valid code (FS1-FS5).\n";
        $message .= "Send 0 for main menu";
        $continueSession = true;
    }
}

// Step 3: User enters number of votes -> Process voting
elseif (count($input) == 3 && $input[0] == "1") {
    
    $contestantCode = strtoupper($input[1]);
    $numberOfVotes = intval($input[2]);
    
    // Validate number of votes
    if ($numberOfVotes < 1 || $numberOfVotes > 10) {
        $message = "Invalid number of votes.\n";
        $message .= "Please enter between 1 and 10 votes.\n";
        $message .= "Send 0 for main menu";
        $continueSession = true;
    } else {
        // Get contestant data
        $contestant = firebaseRequest("GET", "contestants/" . $contestantCode);
        
        if ($contestant) {
            // Get current votes or initialize to 0
            $currentVotes = isset($contestant['votes']) ? intval($contestant['votes']) : 0;
            $newVoteCount = $currentVotes + $numberOfVotes;
            
            // Update contestant votes in Firebase
            $updateData = [
                "votes" => $newVoteCount,
                "last_voted_at" => time(),
                "last_voted_by" => $msisdn
            ];
            
            $update = firebaseRequest("PATCH", "contestants/" . $contestantCode, $updateData);
            
            if ($update !== null) {
                // Record vote transaction
                $voteRecord = [
                    "msisdn" => $msisdn,
                    "contestant_code" => $contestantCode,
                    "contestant_name" => $contestant['contestant_name'],
                    "number_of_votes" => $numberOfVotes,
                    "timestamp" => time(),
                    "date" => date('Y-m-d H:i:s'),
                    "session_id" => $sessionID
                ];
                
                firebaseRequest("POST", "vote_records", $voteRecord);
                
                // Clear temp session
                firebaseRequest("DELETE", "temp_sessions/" . $sessionID);
                
                $message = "✓ VOTE SUCCESSFUL ✓\n";
                $message .= "You voted " . $numberOfVotes . " time(s)\n";
                $message .= "for: " . $contestant['contestant_name'] . "\n";
                $message .= "Total votes now: " . $newVoteCount . "\n";
                $message .= "Thank you for participating!";
                $continueSession = false;
            } else {
                $message = "Error processing vote.\n";
                $message .= "Please try again.\n";
                $message .= "Send 0 for main menu";
                $continueSession = true;
            }
        } else {
            $message = "Contestant not found.\n";
            $message .= "Please try again.\n";
            $message .= "Send 0 for main menu";
            $continueSession = true;
        }
    }
}

// View Results
elseif (count($input) == 1 && $input[0] == "2") {
    
    $contestants = firebaseRequest("GET", "contestants");
    
    if ($contestants) {
        $message = "CURRENT VOTING RESULTS\n";
        
        // Sort by votes (highest first)
        uasort($contestants, function($a, $b) {
            return ($b['votes'] ?? 0) - ($a['votes'] ?? 0);
        });
        
        $position = 1;
        foreach ($contestants as $code => $contestant) {
            $votes = isset($contestant['votes']) ? $contestant['votes'] : 0;
            $message .= $position . ". " . $contestant['contestant_name'] . "\n";
            $message .= "   Code: " . $code . " | Votes: " . $votes . "\n";
            $position++;
        }
        
        $message .= "\nSend 0 for main menu";
        $continueSession = true;
    } else {
        $message = "No results available.\nSend 0 for main menu";
        $continueSession = true;
    }
}

// Help Menu
elseif (count($input) == 1 && $input[0] == "3") {
    $message = "GHARTEY EVENT HELP\n";
    $message .= "To vote:\n";
    $message .= "1. Select 'Vote' from menu\n";
    $message .= "2. Enter contestant code (FS1-FS5)\n";
    $message .= "3. Enter number of votes (1-10)\n";
    $message .= "\nTo view results:\n";
    $message .= "Select 'View Results'\n";
    $message .= "\nSend 0 for main menu";
    $continueSession = true;
}

// Go back to main menu
elseif ($userData == "0") {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help";
    $continueSession = true;
}

// Invalid input
else {
    $message = "Invalid input.\n";
    $message .= "Send 0 for main menu";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [
    "message" => $message,
    "continueSession" => $continueSession ? "True" : "False"
];

header('Content-Type: application/json');
echo json_encode($response);

?>
