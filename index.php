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

    $contestants = getAllContestants();

    if (!empty($contestants)) {

        $message = "Contestants\n";
        $message .= "----------------\n";

        foreach ($contestants as $contestant) {

            $stageName = $contestant['stageName'] ?? 'Unknown';
            $code = $contestant['code'] ?? 'N/A';
            $votes = $contestant['votes'] ?? 0;

            $message .= $stageName . "\n";
            $message .= "Code: " . $code . "\n";
            $message .= "Votes: " . $votes . "\n\n";
        }

        $message .= "Enter contestant code";

    } else {

        $message = "No contestants found";
    }

    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - USER SELECTS VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "1") {

    $message = "Enter contestant code\n";
    $message .= "Example: FS1";

    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 2 - SHOW CONTESTANT DETAILS
|--------------------------------------------------------------------------
*/

    $contestantCode = strtoupper(trim($input[1]));

    $contestant = getContestantByCode($contestantCode);

    if ($contestant) {

        $stageName = $contestant['stageName'] ?? $contestant['name'] ?? 'Unknown';
        $votes = $contestant['votes'] ?? 0;
        $voteAmount = $contestant['voteAmount'] ?? 1;

        $_SESSION['contestant_code'] = $contestantCode;

        $message = "Contestant Details\n";
        $message .= "----------------\n";
        $message .= "Stage Name: " . $stageName . "\n";
        $message .= "Current Votes: " . $votes . "\n";
        $message .= "Vote Amount: " . $voteAmount . "\n\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel";

    } else {

        $message = "Invalid contestant code\n";
        $message .= "Please try again";
    }

    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 3 - CONFIRM VOTE
|--------------------------------------------------------------------------
*/
elseif (
    count($input) == 3 &&
    $input[0] == "1" &&
    $input[2] == "1"
) {

    $contestantCode = $_SESSION['contestant_code'] ?? strtoupper(trim($input[1]));

    $contestant = getContestantByCode($contestantCode);

    if ($contestant) {

        $stageName = $contestant['stageName'] ?? $contestant['name'] ?? 'Unknown';

        $currentVotes = $contestant['votes'] ?? 0;
        $voteAmount = $contestant['voteAmount'] ?? 1;

        $newVotes = $currentVotes + $voteAmount;

        $updated = updateContestantVotes($contestantCode, $newVotes);

        if ($updated) {

            $voteData = [
                'userID' => $userID,
                'msisdn' => $msisdn,
                'contestant_code' => $contestantCode,
                'contestant_name' => $stageName,
                'votes_cast' => $voteAmount,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            saveVoteRecord($voteData);

            $message = "Vote Successful\n";
            $message .= "----------------\n";
            $message .= "Contestant: " . $stageName . "\n";
            $message .= "Votes Added: " . $voteAmount . "\n";
            $message .= "Total Votes: " . $newVotes . "\n";
            $message .= "Thank you";

            unset($_SESSION['contestant_code']);

            $continueSession = false;

        } else {

            $message = "Failed to record vote";
            $continueSession = false;
        }

    } else {

        $message = "Contestant not found";
        $continueSession = false;
    }
}

/*
|--------------------------------------------------------------------------
| CANCEL VOTE
|--------------------------------------------------------------------------
*/
elseif (
    count($input) == 3 &&
    $input[0] == "1" &&
    $input[2] == "2"
) {

    $message = "Vote cancelled";

    unset($_SESSION['contestant_code']);

    $continueSession = false;
}

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
