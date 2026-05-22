<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'firebase.php';
require 'paystack.php';

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
    $message = "Welcome to Ghartey Event Voting System\n";
    $message .= "1. Vote (₵10 per vote)\n";
    $message .= "2. Check Contestants\n";
    $message .= "3. My Voting History\n";
    $message .= "4. Support";
}

/*
|--------------------------------------------------------------------------
| STEP 1 - CHECK CONTESTANTS
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "2") {
    $contestants = firebaseRequest("GET", "contestants");
    
    if ($contestants) {
        $message = "Available Contestants:\n";
        $counter = 1;
        foreach ($contestants as $code => $contestant) {
            $message .= $counter . ". " . $contestant['contestant_name'] . " (Code: " . $code . ")\n";
            $counter++;
            if ($counter > 5) break; // Limit to 5 for USSD
        }
        $message .= "\n0. Back to Main Menu";
    } else {
        $message = "No contestants available\n0. Back to Main Menu";
    }
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - USER SELECTS VOTE
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "1") {
    $message = "Enter contestant code (e.g., FS1, FS2, etc.)";
}

/*
|--------------------------------------------------------------------------
| STEP 2 - PROCESS VOTE WITH PAYMENT
|--------------------------------------------------------------------------
*/
elseif (count($input) == 2 && $input[0] == "1") {
    $contestantCode = strtoupper($input[1]);
    
    // Store in session
    $_SESSION['contestant_code'] = $contestantCode;
    
    $contestant = firebaseRequest("GET", "contestants/" . $contestantCode);
    
    if ($contestant) {
        $message = "Confirm Vote:\n";
        $message .= "Contestant: " . $contestant['contestant_name'] . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "Amount: ₵10 per vote\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel";
    } else {
        $message = "Contestant not found. Please enter valid code.\n0. Back to Main Menu";
    }
}

/*
|--------------------------------------------------------------------------
| STEP 3 - PROCESS PAYMENT
|--------------------------------------------------------------------------
*/
elseif (count($input) == 3 && $input[0] == "1" && $input[2] == "1") {
    $contestantCode = $_SESSION['contestant_code'] ?? $input[1];
    $contestant = firebaseRequest("GET", "contestants/" . $contestantCode);
    
    if ($contestant) {
        // Process payment
        $paymentAmount = 10000; // ₵10 in kobo
        $paymentReference = uniqid('VOTE_');
        
        $paymentResult = processPaystackPayment($msisdn, $paymentAmount, $paymentReference);
        
        if ($paymentResult && $paymentResult['status']) {
            // Record vote in Firebase
            $voteData = [
                'userID' => $userID,
                'msisdn' => $msisdn,
                'contestant_code' => $contestantCode,
                'contestant_name' => $contestant['contestant_name'],
                'timestamp' => date('Y-m-d H:i:s'),
                'amount' => 10,
                'reference' => $paymentReference,
                'status' => 'completed'
            ];
            
            $voteResult = firebaseRequest("POST", "votes/" . $paymentReference, $voteData);
            
            // Update contestant vote count
            $currentVotes = $contestant['votes'] ?? 0;
            firebaseRequest("PATCH", "contestants/" . $contestantCode, [
                'votes' => $currentVotes + 1
            ]);
            
            $message = "✓ Vote successful!\n";
            $message .= "You voted for: " . $contestant['contestant_name'] . "\n";
            $message .= "Thank you for participating!\n";
            $message .= "0. Back to Main Menu";
        } else {
            $message = "Payment failed. Please try again.\n0. Back to Main Menu";
        }
    } else {
        $message = "Invalid contestant. Please try again.\n0. Back to Main Menu";
    }
}

/*
|--------------------------------------------------------------------------
| STEP 3 - VOTING HISTORY
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "3") {
    $votes = firebaseRequest("GET", "votes");
    $userVotes = [];
    
    if ($votes) {
        foreach ($votes as $vote) {
            if ($vote['msisdn'] == $msisdn) {
                $userVotes[] = $vote;
            }
        }
    }
    
    if (count($userVotes) > 0) {
        $message = "Your Voting History:\n";
        foreach ($userVotes as $index => $vote) {
            $message .= ($index + 1) . ". " . $vote['contestant_name'] . " - ₵" . $vote['amount'] . "\n";
            $message .= "   " . date('d/m/Y H:i', strtotime($vote['timestamp'])) . "\n";
            if ($index >= 4) break;
        }
        $message .= "\n0. Back to Main Menu";
    } else {
        $message = "You haven't voted yet\n0. Back to Main Menu";
    }
}

/*
|--------------------------------------------------------------------------
| BACK TO MAIN MENU
|--------------------------------------------------------------------------
*/
elseif (count($input) == 1 && $input[0] == "0") {
    $message = "Main Menu\n";
    $message .= "1. Vote (₵10 per vote)\n";
    $message .= "2. Check Contestants\n";
    $message .= "3. My Voting History\n";
    $message .= "4. Support";
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT
|--------------------------------------------------------------------------
*/
else {
    $message = "Invalid input. Please try again.\n0. Back to Main Menu";
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

session_write_close();
header('Content-Type: application/json');
echo json_encode($response);
?>
