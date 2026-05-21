<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'firebase.php';
require 'paystack.php';

/*
|--------------------------------------------------------------------------
| GET REQUEST - Arkesel USSD Format
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');

// Handle both JSON and form-data
if (empty($json)) {
    $json = json_encode($_REQUEST);
}

$data = json_decode($json, true);

// If still no data, try to get from $_POST
if (!$data) {
    $data = $_POST;
}

// Arkesel typical USSD parameters
$sessionID = $data['sessionID'] ?? $data['sessionId'] ?? $data['SESSION_ID'] ?? '';
$userID = $data['userID'] ?? $data['userId'] ?? $data['USER_ID'] ?? '';
$msisdn = $data['msisdn'] ?? $data['phoneNumber'] ?? $data['MSISDN'] ?? '';
$newSession = $data['newSession'] ?? $data['new_session'] ?? false;
$userData = trim($data['userData'] ?? $data['text'] ?? $data['USER_DATA'] ?? '');

// Convert string 'true'/'false' to boolean
if (is_string($newSession)) {
    $newSession = strtolower($newSession) === 'true';
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

/*
|--------------------------------------------------------------------------
| CHECK USER SESSION STATE (for payment flow)
|--------------------------------------------------------------------------
*/

// Get user session state from Firebase
$userSession = firebaseRequest("GET", "user_sessions/" . $msisdn);
$paymentPending = isset($userSession['payment_pending']) && $userSession['payment_pending'] === true;
$selectedContestant = $userSession['selected_contestant'] ?? null;
$paymentReference = $userSession['payment_reference'] ?? null;

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
    $message .= "Enter your choice:";
}

/*
|--------------------------------------------------------------------------
| HANDLE PAYMENT CALLBACK/RESPONSE
|--------------------------------------------------------------------------
*/

elseif ($paymentPending && $userData == "1") {
    // User confirms payment was made
    if ($paymentReference) {
        // Verify payment with Paystack
        $paymentStatus = verifyPayment($paymentReference);
        
        if ($paymentStatus && $paymentStatus['data']['status'] == 'success') {
            // Payment successful, record vote
            $contestant = firebaseRequest("GET", "contestants/" . $selectedContestant);
            
            if ($contestant) {
                // Record the vote
                $voteData = [
                    "msisdn" => $msisdn,
                    "contestant_code" => $selectedContestant,
                    "contestant_name" => $contestant['contestant_name'],
                    "timestamp" => time(),
                    "date" => date('Y-m-d H:i:s'),
                    "payment_reference" => $paymentReference,
                    "amount" => "100.00"
                ];
                
                $saveVote = firebaseRequest(
                    "PUT",
                    "votes/" . $msisdn,
                    $voteData
                );
                
                // Update contestant vote count
                $currentVotes = isset($contestant['votes']) ? $contestant['votes'] : 0;
                firebaseRequest(
                    "PATCH",
                    "contestants/" . $selectedContestant,
                    ["votes" => $currentVotes + 1]
                );
                
                // Clear payment session
                firebaseRequest("DELETE", "user_sessions/" . $msisdn);
                
                $message = "VOTE SUCCESSFUL!\n";
                $message .= "You voted for: " . $contestant['contestant_name'] . "\n";
                $message .= "Payment of GHS 1.00 confirmed\n";
                $message .= "Thank you for participating!";
                $continueSession = false;
            } else {
                $message = "Error: Contestant not found.\n";
                $message .= "Please contact support.";
                $continueSession = false;
            }
        } else {
            $message = "Payment not confirmed.\n";
            $message .= "Please make payment of GHS 1.00\n";
            $message .= "to Momo number 024XXXXXXX\n";
            $message .= "Send 1 after payment or 0 to cancel";
            $continueSession = true;
        }
    }
}

elseif ($paymentPending && $userData == "0") {
    // User cancels payment
    firebaseRequest("DELETE", "user_sessions/" . $msisdn);
    $message = "Vote cancelled.\n";
    $message .= "Send 0 to return to main menu";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - USER SELECTS VOTE
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "1") {

    // Check if user has already voted
    $existingVote = firebaseRequest("GET", "votes/" . $msisdn);
    
    if ($existingVote && isset($existingVote['contestant_code'])) {
        $message = "You have already voted!\n";
        $message .= "You voted for: " . $existingVote['contestant_name'] . "\n";
        $message .= "One vote per person allowed.";
        $continueSession = false;
    } else {
        // Get list of contestants
        $contestants = firebaseRequest("GET", "contestants");
        
        if ($contestants) {
            $message = "Select Contestant:\n";
            $counter = 1;
            foreach ($contestants as $code => $contestant) {
                $message .= $counter . ". " . $contestant['contestant_name'] . " (" . $code . ")\n";
                $counter++;
            }
            $message .= "Enter contestant code (e.g., CONT001):";
        } else {
            $message = "No contestants available.\nPlease try again later.";
            $continueSession = false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| STEP 2 - PROCESS VOTE AND INITIATE PAYMENT
|--------------------------------------------------------------------------
*/

elseif (count($input) == 2 && $input[0] == "1") {

    $contestantCode = strtoupper($input[1]);
    
    // Get contestant details
    $contestant = firebaseRequest("GET", "contestants/" . $contestantCode);
    
    if ($contestant && isset($contestant['contestant_name'])) {
        
        // Initialize payment
        $amount = 100; // GHS 1.00 (in pesewas)
        $email = $msisdn . "@ussd.ghartey.com"; // Generate email from phone number
        $callbackUrl = "https://your-app.onrender.com/index.php";
        
        $payment = initializePayment($email, $amount, $callbackUrl);
        
        if ($payment && isset($payment['data']['authorization_url'])) {
            // Store payment session
            $sessionData = [
                "payment_pending" => true,
                "selected_contestant" => $contestantCode,
                "contestant_name" => $contestant['contestant_name'],
                "payment_reference" => $payment['data']['reference'],
                "amount" => $amount,
                "timestamp" => time()
            ];
            
            firebaseRequest("PUT", "user_sessions/" . $msisdn, $sessionData);
            
            $message = "Vote for: " . $contestant['contestant_name'] . "\n";
            $message .= "Fee: GHS 1.00\n";
            $message .= "Pay with Mobile Money:\n";
            $message .= "1. Dial *402# and select Paystack\n";
            $message .= "2. Or visit:\n";
            $message .= $payment['data']['authorization_url'] . "\n";
            $message .= "Send 1 after payment or 0 to cancel";
            $continueSession = true;
        } else {
            $message = "Payment system error.\nPlease try again later.";
            $continueSession = false;
        }
        
    } else {
        $message = "Contestant code not found.\n";
        $message .= "Please try again with valid code.\n";
        $message .= "Send 0 to go back to main menu.";
        $continueSession = true;
    }
}

/*
|--------------------------------------------------------------------------
| VIEW RESULTS (Free - No Payment)
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "2") {
    
    $contestants = firebaseRequest("GET", "contestants");
    
    if ($contestants) {
        $message = "CURRENT VOTING RESULTS\n";
        
        // Sort contestants by votes (descending)
        uasort($contestants, function($a, $b) {
            return ($b['votes'] ?? 0) - ($a['votes'] ?? 0);
        });
        
        $position = 1;
        foreach ($contestants as $code => $contestant) {
            $votes = isset($contestant['votes']) ? $contestant['votes'] : 0;
            $message .= $position . ". " . $contestant['contestant_name'] . "\n";
            $message .= "   Votes: " . $votes . " | Code: " . $code . "\n";
            $position++;
        }
        
        $message .= "Send 0 for main menu";
        $continueSession = true;
    } else {
        $message = "No results available.\nSend 0 for main menu";
        $continueSession = true;
    }
}

/*
|--------------------------------------------------------------------------
| HELP MENU
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "3") {
    
    $message = "GHARTEY EVENT HELP\n";
    $message .= "1. Vote - GHS 1.00 per vote\n";
    $message .= "2. View Results - Free\n";
    $message .= "3. Help - This menu\n\n";
    $message .= "How to vote:\n";
    $message .= "• Select option 1\n";
    $message .= "• Enter contestant code\n";
    $message .= "• Pay GHS 1.00 via Mobile Money\n";
    $message .= "• Confirm payment\n\n";
    $message .= "Each phone number can vote once.\n";
    $message .= "Send 0 for main menu";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| GO BACK TO MAIN MENU
|--------------------------------------------------------------------------
*/

elseif ($userData == "0") {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Results\n";
    $message .= "3. Help\n";
    $message .= "Enter your choice:";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT HANDLER
|--------------------------------------------------------------------------
*/

else {
    $message = "Invalid input: '$userData'\n";
    $message .= "Valid options:\n";
    $message .= "1. Vote (GHS 1.00)\n";
    $message .= "2. View Results (Free)\n";
    $message .= "3. Help\n";
    $message .= "Send 0 for main menu";
    $continueSession = true;
}

/*
|--------------------------------------------------------------------------
| PREPARE RESPONSE FOR ARKESEL
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

// Arkesel specific format
$arkeselResponse = [
    "message" => $message,
    "continueSession" => $continueSession ? "True" : "False"
];

header('Content-Type: application/json');

// Return appropriate format
if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == '127.0.0.1:8000') {
    echo json_encode($response, JSON_PRETTY_PRINT);
} else {
    echo json_encode($arkeselResponse);
}

?>
