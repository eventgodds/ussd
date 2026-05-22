<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

/*
|--------------------------------------------------------------------------
| SAMPLE CONTESTANTS DATA (Hardcoded for testing)
|--------------------------------------------------------------------------
*/

$sampleContestants = [
    'FS1' => [
        'code' => 'FS1',
        'stageName' => 'EGYIRWAA',
        'name' => 'Lordina',
        'votes' => 1382,
        'voteAmount' => 1,
        'bio' => 'Egyirwaa is very passionate about education'
    ],
    'FS2' => [
        'code' => 'FS2',
        'stageName' => 'ADWOA SMART',
        'name' => 'Adwoa',
        'votes' => 2456,
        'voteAmount' => 1,
        'bio' => 'Talented singer and dancer'
    ],
    'FS3' => [
        'code' => 'FS3',
        'stageName' => 'KOFI KING',
        'name' => 'Kofi',
        'votes' => 987,
        'voteAmount' => 1,
        'bio' => 'Amazing vocalist'
    ],
    'FS4' => [
        'code' => 'FS4',
        'stageName' => 'ABENA STAR',
        'name' => 'Abena',
        'votes' => 3421,
        'voteAmount' => 1,
        'bio' => 'Multi-talented performer'
    ],
    'FS5' => [
        'code' => 'FS5',
        'stageName' => 'KWAME FIRE',
        'name' => 'Kwame',
        'votes' => 567,
        'voteAmount' => 1,
        'bio' => 'Energetic stage performer'
    ]
];

/*
|--------------------------------------------------------------------------
| GET REQUEST
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? uniqid();
$userID = $data['userID'] ?? 'user_' . rand(1000, 9999);
$msisdn = $data['msisdn'] ?? '233XXXXXXXXX';
$newSession = $data['newSession'] ?? false;
$userData = trim($data['userData'] ?? '');

// Initialize session storage for votes if not exists
if (!isset($_SESSION['votes_recorded'])) {
    $_SESSION['votes_recorded'] = [];
}

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
error_log("New Session: " . ($newSession ? 'Yes' : 'No'));

/*
|--------------------------------------------------------------------------
| MAIN MENU
|--------------------------------------------------------------------------
*/

if ($newSession == true) {
    $message = "Welcome to Ghartey Event Voting System\n";
    $message .= "===============================\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "3. My Voting History\n";
    $message .= "0. Exit\n";
    $message .= "===============================\n";
    $message .= "Enter option:";
}
elseif (count($input) == 1 && $input[0] == "0") {
    $message = "Thank you for using Ghartey Event Voting System!\n";
    $message .= "Goodbye!";
    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| VIEW CONTESTANTS (Option 2)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "2") {
    $message = "=== ALL CONTESTANTS ===\n";
    foreach ($sampleContestants as $code => $contestant) {
        $message .= $code . " - " . $contestant['stageName'] . "\n";
        $message .= "   Votes: " . $contestant['votes'] . "\n";
        $message .= "-------------------\n";
    }
    $message .= "\n0. Main Menu\n";
    $message .= "Or enter code to vote:";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VIEW CONTESTANTS (Option 3 - Voting History)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "3") {
    if (count($_SESSION['votes_recorded']) > 0) {
        $message = "=== YOUR VOTING HISTORY ===\n";
        foreach ($_SESSION['votes_recorded'] as $index => $vote) {
            $num = $index + 1;
            $message .= "$num. " . $vote['stageName'] . " (" . $vote['code'] . ")\n";
            $message .= "   Time: " . $vote['time'] . "\n";
            $message .= "-------------------\n";
        }
        $message .= "\n0. Main Menu";
    } else {
        $message = "You haven't voted yet.\n";
        $message .= "1. Vote Now\n";
        $message .= "0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VOTE AGAIN FROM HISTORY
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "3" && $input[1] == "1") {
    $message = "Enter contestant code (FS1-FS5):";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| START VOTE PROCESS (Option 1)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "1") {
    $message = "Enter contestant code:\n";
    $message .= "Valid codes: FS1, FS2, FS3, FS4, FS5\n";
    $message .= "Example: FS1";
}

/*
|--------------------------------------------------------------------------
| SHOW CONTESTANT DETAILS (After entering code)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1") {
    $contestantCode = strtoupper(trim($input[1]));
    
    if (isset($sampleContestants[$contestantCode])) {
        $contestant = $sampleContestants[$contestantCode];
        
        // Store in session for confirmation
        $_SESSION['pending_vote'] = $contestantCode;
        
        $message = "=== CONFIRM VOTE ===\n";
        $message .= "Stage Name: " . $contestant['stageName'] . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "Current Votes: " . $contestant['votes'] . "\n";
        $message .= "Vote Value: " . $contestant['voteAmount'] . " vote(s)\n";
        $message .= "===================\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
    } else {
        $message = "Invalid code: '" . $contestantCode . "'\n";
        $message .= "Valid codes: FS1, FS2, FS3, FS4, FS5\n";
        $message .= "1. Try Again\n";
        $message .= "2. View Contestants\n";
        $message .= "0. Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| PROCESS CONFIRMED VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 3 && $input[0] == "1" && $input[2] == "1") {
    $contestantCode = $_SESSION['pending_vote'] ?? $input[1];
    
    if (isset($sampleContestants[$contestantCode])) {
        $contestant = $sampleContestants[$contestantCode];
        
        // Update votes (in sample data)
        $oldVotes = $sampleContestants[$contestantCode]['votes'];
        $sampleContestants[$contestantCode]['votes'] = $oldVotes + $contestant['voteAmount'];
        
        // Record vote history
        $_SESSION['votes_recorded'][] = [
            'code' => $contestantCode,
            'stageName' => $contestant['stageName'],
            'time' => date('H:i:s d/m/Y'),
            'votes_cast' => $contestant['voteAmount']
        ];
        
        $message = "✓✓✓ VOTE SUCCESSFUL! ✓✓✓\n";
        $message .= "========================\n";
        $message .= "You voted for: " . $contestant['stageName'] . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "Votes Cast: " . $contestant['voteAmount'] . "\n";
        $message .= "Total Votes Now: " . $sampleContestants[$contestantCode]['votes'] . "\n";
        $message .= "========================\n";
        $message .= "Thank you for voting!\n";
        $message .= "1. Vote Again\n";
        $message .= "2. Main Menu\n";
        $message .= "0. Exit";
        
        // Clear pending vote
        unset($_SESSION['pending_vote']);
    } else {
        $message = "Error: Contestant not found.\n";
        $message .= "1. Try Again\n";
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
    unset($_SESSION['pending_vote']);
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| TRY AGAIN (After invalid code)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "Enter contestant code (FS1-FS5):";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VIEW CONTESTANTS FROM TRY AGAIN MENU
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "2") {
    $message = "=== ALL CONTESTANTS ===\n";
    foreach ($sampleContestants as $code => $contestant) {
        $message .= $code . " - " . $contestant['stageName'] . " (" . $contestant['votes'] . " votes)\n";
    }
    $message .= "\nEnter code to vote or 0 for Main Menu:";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| VOTE AGAIN (After successful vote)
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1" && $input[1] == "1") {
    $message = "Enter contestant code (FS1-FS5):";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| MAIN MENU NAVIGATION
|--------------------------------------------------------------------------
*/
elseif ((count($input) == 2 && $input[1] == "2") || 
        (count($input) == 1 && $input[0] == "00")) {
    $message = "Main Menu\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "3. My Voting History\n";
    $message .= "0. Exit";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| DEFAULT/INVALID INPUT HANDLER
|--------------------------------------------------------------------------
*/
else {
    $message = "Invalid input: '" . $userData . "'\n";
    $message .= "Please try again.\n";
    $message .= "================\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "3. My Voting History\n";
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

// Log response for debugging
error_log("USSD Response: " . json_encode($response));

header('Content-Type: application/json');
echo json_encode($response);
?>
