<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once 'firebase.php';

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
error_log("USSD Input: " . print_r($input, true));

/*
|--------------------------------------------------------------------------
| MAIN MENU - NEW SESSION
|--------------------------------------------------------------------------
*/

if ($newSession == true) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Contestants\n";
    $message .= "0. Exit";
}
/*
|--------------------------------------------------------------------------
| EXIT
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "0") {
    $message = "Thank you for using Ghartey Event Voting System";
    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| OPTION 2 - CHECK ALL CONTESTANTS
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "2") {
    $contestants = getAllContestants();
    
    if ($contestants && count($contestants) > 0) {
        $message = "=== ALL CONTESTANTS ===\n";
        foreach ($contestants as $index => $contestant) {
            $message .= ($index + 1) . ". " . ($contestant['stageName'] ?? $contestant['name']) . "\n";
            $message .= "   Code: " . ($contestant['code'] ?? 'N/A') . "\n";
            $message .= "   Votes: " . ($contestant['votes'] ?? 0) . "\n";
            $message .= "-------------------\n";
        }
        $message .= "Enter code to vote or 0 for Main Menu";
    } else {
        $message = "No contestants found\n0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| OPTION 1 - START VOTE PROCESS (User selects Vote)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "1") {
    $message = "Enter contestant code (Example: FS1, FS2, FS3, FS4, FS5):";
}

/*
|--------------------------------------------------------------------------
| STEP 2 - SHOW CONTESTANT DETAILS AFTER CODE ENTERED
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1") {
    $contestantCode = strtoupper(trim($input[1]));
    error_log("Searching for contestant code: " . $contestantCode);
    
    // Search for contestant by code
    $contestant = getContestantByCode($contestantCode);
    
    if ($contestant) {
        // Store in session for later
        $_SESSION['voting_for'] = $contestant;
        
        $message = "=== CONFIRM VOTE ===\n";
        $message .= "Stage Name: " . ($contestant['stageName'] ?? $contestant['name']) . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "Current Votes: " . ($contestant['votes'] ?? 0) . "\n";
        $message .= "Vote Amount: " . ($contestant['voteAmount'] ?? 1) . " vote(s)\n";
        $message .= "==================\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
    } else {
        $message = "❌ Contestant code '$contestantCode' not found!\n";
        $message .= "Please check the code and try again.\n";
        $message .= "1. Try Again\n";
        $message .= "2. View All Contestants\n";
        $message .= "0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 3 - PROCESS CONFIRMED VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 3 && $input[0] == "1" && $input[2] == "1") {
    // Get contestant from session
    $contestant = $_SESSION['voting_for'] ?? null;
    
    if (!$contestant) {
        $message = "Session expired. Please start over.\n";
        $message .= "1. Vote\n";
        $message .= "0. Main Menu";
        $continueSession = true;
    } else {
        $contestantCode = $contestant['code'];
        $voteAmount = $contestant['voteAmount'] ?? 1;
        $currentVotes = $contestant['votes'] ?? 0;
        $newVotes = $currentVotes + $voteAmount;
        
        // Update votes in Firestore
        $updateResult = updateContestantVotes($contestantCode, $newVotes);
        
        if ($updateResult) {
            // Record vote history
            $voteRecord = [
                'msisdn' => $msisdn,
                'userID' => $userID,
                'contestant_code' => $contestantCode,
                'contestant_name' => $contestant['stageName'] ?? $contestant['name'],
                'votes_cast' => $voteAmount,
                'previous_votes' => $currentVotes,
                'new_votes' => $newVotes,
                'timestamp' => date('Y-m-d H:i:s'),
                'sessionID' => $sessionID
            ];
            saveVoteHistory($voteRecord);
            
            $message = "✅ VOTE SUCCESSFUL! ✅\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "Voted for: " . ($contestant['stageName'] ?? $contestant['name']) . "\n";
            $message .= "Code: " . $contestantCode . "\n";
            $message .= "Votes Cast: " . $voteAmount . "\n";
            $message .= "Total Votes Now: " . $newVotes . "\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "Thank you for voting!\n";
            $message .= "1. Vote Again\n";
            $message .= "2. Main Menu\n";
            $message .= "0. Exit";
            
            // Clear session
            unset($_SESSION['voting_for']);
        } else {
            $message = "❌ Error recording vote. Please try again.\n";
            $message .= "1. Try Again\n";
            $message .= "0. Main Menu";
        }
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
    unset($_SESSION['voting_for']);
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| TRY AGAIN - Invalid code menu option 1
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "Enter contestant code (Example: FS1, FS2, FS3, FS4, FS5):";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VIEW ALL CONTESTANTS - From invalid code menu option 2
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "2") {
    $contestants = getAllContestants();
    
    if ($contestants && count($contestants) > 0) {
        $message = "=== ALL CONTESTANTS ===\n";
        foreach ($contestants as $index => $contestant) {
            $message .= ($index + 1) . ". " . ($contestant['stageName'] ?? $contestant['name']) . "\n";
            $message .= "   Code: " . ($contestant['code'] ?? 'N/A') . "\n";
            $message .= "   Votes: " . ($contestant['votes'] ?? 0) . "\n";
            $message .= "-------------------\n";
        }
        $message .= "Enter code to vote or 0 for Main Menu";
    } else {
        $message = "No contestants found\n0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VOTE AGAIN (After successful vote)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "Enter contestant code (Example: FS1, FS2, FS3, FS4, FS5):";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| MAIN MENU NAVIGATION
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
| INVALID INPUT HANDLER - CATCH EVERYTHING ELSE
|--------------------------------------------------------------------------
*/
else {
    error_log("Unhandled input: " . print_r($input, true));
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
