<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once 'firebase_firestore.php';

/*
|--------------------------------------------------------------------------
| GET REQUEST FROM ARKESEL
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? uniqid();
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

// Log for debugging
error_log("=== USSD Request ===");
error_log("Session: $sessionID");
error_log("New Session: " . ($newSession ? 'Yes' : 'No'));
error_log("User Data: $userData");
error_log("Input array: " . print_r($input, true));

/*
|--------------------------------------------------------------------------
| MAIN MENU - NEW SESSION
|--------------------------------------------------------------------------
*/

if ($newSession == true) {
    $message = "CON Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Contestants\n";
    $message .= "0. Exit";
    $continueSession = true;
}
elseif (count($input) == 1 && $input[0] == "0") {
    $message = "END Thank you for using Ghartey Event Voting System";
    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| OPTION 2: VIEW ALL CONTESTANTS
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "2") {
    $contestants = getAllContestants();
    
    if ($contestants && count($contestants) > 0) {
        $message = "CON === CONTESTANTS ===\n";
        foreach ($contestants as $contestant) {
            $code = $contestant['code'] ?? 'N/A';
            $stageName = $contestant['stageName'] ?? $contestant['name'] ?? 'Unknown';
            $votes = $contestant['votes'] ?? 0;
            $message .= "$code - $stageName\n";
            $message .= "Votes: $votes\n";
            $message .= "----------------\n";
        }
        $message .= "\nEnter code to vote\n";
        $message .= "Or 0 for Main Menu";
    } else {
        $message = "CON No contestants found\n0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| OPTION 1: START VOTE PROCESS
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "1") {
    $message = "CON Enter contestant code:\n";
    $message .= "Example: FS1, FS2, etc.";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 2: SHOW CONTESTANT DETAILS AFTER ENTERING CODE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1") {
    $contestantCode = strtoupper(trim($input[1]));
    
    // Get contestant from Firestore
    $contestant = getContestantByCode($contestantCode);
    
    if ($contestant) {
        // Store in session for confirmation
        $_SESSION['vote_code'] = $contestantCode;
        
        $stageName = $contestant['stageName'] ?? $contestant['name'] ?? 'Unknown';
        $currentVotes = $contestant['votes'] ?? 0;
        $voteAmount = $contestant['voteAmount'] ?? 1;
        
        $message = "CON === CONFIRM VOTE ===\n";
        $message .= "Contestant: $stageName\n";
        $message .= "Code: $contestantCode\n";
        $message .= "Current Votes: $currentVotes\n";
        $message .= "Vote Value: $voteAmount vote(s)\n";
        $message .= "===================\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
    } else {
        $message = "CON Contestant '$contestantCode' not found!\n";
        $message .= "1. Try Again\n";
        $message .= "2. View Contestants\n";
        $message .= "0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 3: PROCESS THE VOTE (After confirmation)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 3 && $input[0] == "1" && $input[2] == "1") {
    $contestantCode = $_SESSION['vote_code'] ?? $input[1];
    
    // Get fresh contestant data
    $contestant = getContestantByCode($contestantCode);
    
    if ($contestant) {
        $voteAmount = $contestant['voteAmount'] ?? 1;
        $currentVotes = $contestant['votes'] ?? 0;
        $newVotes = $currentVotes + $voteAmount;
        
        // Update votes in Firestore
        $updated = updateContestantVotes($contestantCode, $newVotes);
        
        if ($updated) {
            // Record vote transaction
            $voteRecord = [
                'userID' => $userID,
                'msisdn' => $msisdn,
                'contestant_code' => $contestantCode,
                'contestant_name' => $contestant['stageName'] ?? $contestant['name'],
                'votes_cast' => $voteAmount,
                'timestamp' => date('Y-m-d H:i:s'),
                'sessionID' => $sessionID
            ];
            saveVoteRecord($voteRecord);
            
            $stageName = $contestant['stageName'] ?? $contestant['name'];
            
            $message = "END ✓ VOTE SUCCESSFUL! ✓\n";
            $message .= "===================\n";
            $message .= "You voted for: $stageName\n";
            $message .= "Code: $contestantCode\n";
            $message .= "Votes Cast: $voteAmount\n";
            $message .= "Total Votes: $newVotes\n";
            $message .= "===================\n";
            $message .= "Thank you for voting!";
            
            unset($_SESSION['vote_code']);
        } else {
            $message = "END Error recording vote.\nPlease try again later.";
        }
    } else {
        $message = "END Contestant not found.\nPlease try again.";
    }
    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| CANCEL VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 3 && $input[0] == "1" && $input[2] == "2") {
    $message = "CON Vote cancelled.\n";
    $message .= "1. Vote Again\n";
    $message .= "2. Main Menu\n";
    $message .= "0. Exit";
    unset($_SESSION['vote_code']);
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| TRY AGAIN (After invalid code)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "CON Enter contestant code (FS1-FS5):";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VIEW CONTESTANTS FROM ERROR MENU
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "2") {
    $contestants = getAllContestants();
    
    if ($contestants) {
        $message = "CON === CONTESTANTS ===\n";
        foreach ($contestants as $contestant) {
            $code = $contestant['code'] ?? 'N/A';
            $stageName = $contestant['stageName'] ?? $contestant['name'] ?? 'Unknown';
            $message .= "$code - $stageName\n";
        }
        $message .= "\nEnter code to vote:\n";
        $message .= "0. Main Menu";
    } else {
        $message = "CON No contestants found\n0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VOTE AGAIN
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "CON Enter contestant code:";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| GO TO MAIN MENU
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "00") {
    $message = "CON Welcome to Ghartey Event\n";
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
    $message = "CON Invalid input: '$userData'\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Contestants\n";
    $message .= "0. Exit";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| SEND RESPONSE BACK TO ARKESEL
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

error_log("USSD Response: " . json_encode($response));

header('Content-Type: application/json');
echo json_encode($response);
?>
