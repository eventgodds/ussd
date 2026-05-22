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
            $code = $contestant['code'] ?? 'N/A';
            $stageName = $contestant['stageName'] ?? $contestant['name'] ?? 'Unknown';
            $message .= $counter . ". " . $stageName . " (Code: " . $code . ")\n";
            $counter++;
            if ($counter > 10) break;
        }
        $message .= "\n0. Back to Main Menu\n";
        $message .= "Enter code to vote";
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
    $message = "Enter contestant code (e.g., FS1, FS2, etc.):";
}

/*
|--------------------------------------------------------------------------
| STEP 2 - SHOW CONTESTANT DETAILS AND CONFIRM VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1") {
    $contestantCode = strtoupper(trim($input[1]));
    
    // Search for contestant by code
    $contestant = findContestantByCode($contestantCode);
    
    if ($contestant) {
        // Store contestant data in session
        $_SESSION['current_vote'] = [
            'code' => $contestantCode,
            'stageName' => $contestant['stageName'],
            'voteAmount' => $contestant['voteAmount'] ?? 1,
            'current_votes' => $contestant['votes'] ?? 0
        ];
        
        $message = "Confirm Vote\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "Stage Name: " . ($contestant['stageName'] ?? $contestant['name']) . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "Current Votes: " . ($contestant['votes'] ?? 0) . "\n";
        $message .= "Vote Amount: " . ($contestant['voteAmount'] ?? 1) . " vote(s)\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
    } else {
        $message = "Contestant code '" . $contestantCode . "' not found.\n";
        $message .= "Please check the code and try again.\n";
        $message .= "1. Try Again\n";
        $message .= "2. View All Contestants\n";
        $message .= "0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 3 - PROCESS THE VOTE (After confirmation)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 3 && $input[0] == "1" && $input[2] == "1") {
    // Get the contestant code from session or input
    $contestantCode = $_SESSION['current_vote']['code'] ?? $input[1];
    
    // Get fresh contestant data
    $contestant = findContestantByCode($contestantCode);
    
    if ($contestant) {
        // Calculate new vote count
        $voteAmount = $contestant['voteAmount'] ?? 1;
        $currentVotes = $contestant['votes'] ?? 0;
        $newVotes = $currentVotes + $voteAmount;
        
        // Update votes in Firestore
        $updateResult = updateContestantVotes($contestantCode, $newVotes);
        
        if ($updateResult) {
            // Record the vote transaction
            $voteData = [
                'userID' => $userID,
                'msisdn' => $msisdn,
                'contestant_code' => $contestantCode,
                'contestant_name' => $contestant['stageName'] ?? $contestant['name'],
                'votes_cast' => $voteAmount,
                'timestamp' => date('Y-m-d H:i:s'),
                'sessionID' => $sessionID,
                'previous_votes' => $currentVotes,
                'new_votes' => $newVotes
            ];
            
            addDocumentToCollection("vote_history", $voteData);
            
            $message = "✓ VOTE SUCCESSFUL! ✓\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "You voted for: " . ($contestant['stageName'] ?? $contestant['name']) . "\n";
            $message .= "Code: " . $contestantCode . "\n";
            $message .= "Votes Cast: " . $voteAmount . "\n";
            $message .= "Total Votes Now: " . $newVotes . "\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "Thank you for voting!\n";
            $message .= "1. Vote Again\n";
            $message .= "2. Main Menu\n";
            $message .= "0. Exit";
            
            // Clear session
            unset($_SESSION['current_vote']);
        } else {
            $message = "Error recording vote. Please try again.\n";
            $message .= "1. Try Again\n";
            $message .= "0. Main Menu";
        }
    } else {
        $message = "Contestant not found. Please try again.\n";
        $message .= "1. Back to Vote\n";
        $message .= "0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| CANCEL VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 3 && $input[0] == "1" && $input[2] == "2") {
    $message = "Vote cancelled.\n";
    $message .= "1. Vote Again\n";
    $message .= "2. Main Menu\n";
    $message .= "0. Exit";
    unset($_SESSION['current_vote']);
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| TRY AGAIN (After invalid code)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "Enter contestant code (e.g., FS1, FS2, etc.):";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VIEW ALL CONTESTANTS (From invalid code menu)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "2") {
    $contestants = getFirestoreCollection("contestants");
    
    if ($contestants && count($contestants) > 0) {
        $message = "All Contestants:\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        foreach ($contestants as $contestant) {
            $code = $contestant['code'] ?? 'N/A';
            $stageName = $contestant['stageName'] ?? $contestant['name'] ?? 'Unknown';
            $votes = $contestant['votes'] ?? 0;
            $message .= $stageName . "\n";
            $message .= "Code: " . $code . " | Votes: " . $votes . "\n";
            $message .= "━━━━━━━━━━━━━━━\n";
        }
        $message .= "\nEnter code to vote or 0 for Main Menu";
    } else {
        $message = "No contestants found\n0. Main Menu";
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
| GO TO MAIN MENU FROM VARIOUS OPTIONS
|--------------------------------------------------------------------------
*/
elseif ((count($input) == 2 && $input[0] == "1" && $input[1] == "2") ||
        (count($input) == 2 && $input[0] == "1" && $input[1] == "0")) {
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
| INVALID INPUT HANDLER
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
