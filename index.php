<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Paystack Configuration
define('PAYSTACK_SECRET_KEY', 'sk_live_b8d6b1eba856a6da4d891482e1324c55a05c69cc');
define('PAYSTACK_PUBLIC_KEY', 'pk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e');

/*
|--------------------------------------------------------------------------
| FIREBASE REQUEST
|--------------------------------------------------------------------------
*/
function firebaseRequest($method, $collection, $docId, $data = null, $mask = null)
{
    $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";
    $url = $baseURL . "/" . $collection . "/" . $docId;

    if ($method == "PATCH" && $mask !== null) {
        $url .= "?updateMask.fieldPaths=" . $mask;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return json_decode($response, true);
}

/*
|--------------------------------------------------------------------------
| PAYSTACK FUNCTIONS
|--------------------------------------------------------------------------
*/
function initializePaystackTransaction($email, $amount, $reference, $metadata = []) {
    $url = "https://api.paystack.co/transaction/initialize";
    
    $data = [
        "email" => $email,
        "amount" => $amount * 100, // Convert to kobo/cents
        "reference" => $reference,
        "metadata" => $metadata,
        "channels" => ["ussd"] // Enable USSD payments
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
        "Content-Type: application/json",
        "Cache-Control: no-cache"
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        return json_decode($response, true);
    }
    
    return false;
}

function verifyPaystackTransaction($reference) {
    $url = "https://api.paystack.co/transaction/verify/" . $reference;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
        "Content-Type: application/json"
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        return json_decode($response, true);
    }
    
    return false;
}

/*
|--------------------------------------------------------------------------
| HARDCODED NOMINEES DATA
|--------------------------------------------------------------------------
*/
function initializeNominees() {
    $nominees = [
        "FS1" => ["fullName" => "EGYIRWAA", "voteAmount" => 1],
        "FS2" => ["fullName" => "AGYEKUMWAA", "voteAmount" => 1],
        "FS3" => ["fullName" => "BOATEMAA", "voteAmount" => 1],
        "FS4" => ["fullName" => "ABENA", "voteAmount" => 1],
        "FS5" => ["fullName" => "SEDEM", "voteAmount" => 1]
    ];
    
    foreach ($nominees as $code => $data) {
        $existing = firebaseRequest("GET", "awards_nominees", $code);
        
        if (!isset($existing['fields'])) {
            $nomineeData = [
                "fields" => [
                    "fullName" => ["stringValue" => $data['fullName']],
                    "votes" => ["integerValue" => 0],
                    "voteAmount" => ["integerValue" => $data['voteAmount']]
                ]
            ];
            firebaseRequest("PATCH", "awards_nominees", $code, $nomineeData);
        }
    }
}

initializeNominees();

/*
|--------------------------------------------------------------------------
| SESSION HELPERS
|--------------------------------------------------------------------------
*/
function saveSession($sessionID, $step, $contestantCode, $voteCount = 0, $transactionRef = '', $email = '')
{
    $data = [
        "fields" => [
            "step" => ["integerValue" => $step],
            "contestantCode" => ["stringValue" => $contestantCode],
            "voteCount" => ["integerValue" => $voteCount],
            "transactionRef" => ["stringValue" => $transactionRef],
            "email" => ["stringValue" => $email]
        ]
    ];
    firebaseRequest("PATCH", "sessions", $sessionID, $data, "step,contestantCode,voteCount,transactionRef,email");
}

function loadSession($sessionID)
{
    $session = firebaseRequest("GET", "sessions", $sessionID);
    if (!$session || !isset($session['fields'])) {
        return [
            "step" => 0,
            "contestantCode" => '',
            "voteCount" => 0,
            "transactionRef" => '',
            "email" => ''
        ];
    }
    
    return [
        "step" => isset($session['fields']['step']['integerValue']) ? (int)$session['fields']['step']['integerValue'] : 0,
        "contestantCode" => $session['fields']['contestantCode']['stringValue'] ?? '',
        "voteCount" => isset($session['fields']['voteCount']['integerValue']) ? (int)$session['fields']['voteCount']['integerValue'] : 0,
        "transactionRef" => $session['fields']['transactionRef']['stringValue'] ?? '',
        "email" => $session['fields']['email']['stringValue'] ?? ''
    ];
}

/*
|--------------------------------------------------------------------------
| SAVE VOTES TO DATABASE
|--------------------------------------------------------------------------
*/
function saveVotesToDatabase($contestantCode, $voteCount) {
    $contestant = firebaseRequest("GET", "awards_nominees", $contestantCode);
    
    if (isset($contestant['fields']['votes']['integerValue'])) {
        $currentVotes = (int)$contestant['fields']['votes']['integerValue'];
        $updateData = [
            "fields" => [
                "votes" => ["integerValue" => $currentVotes + $voteCount]
            ]
        ];
        firebaseRequest("PATCH", "awards_nominees", $contestantCode, $updateData, "votes");
        return true;
    }
    return false;
}

/*
|--------------------------------------------------------------------------
| GET REQUEST
|--------------------------------------------------------------------------
*/
$json = file_get_contents("php://input");
$data = json_decode($json, true);

/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/
$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$msisdn     = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData   = trim($data['userData'] ?? '');

$message = "";
$continueSession = true;
$contestantCode = '';
$step = 0;
$voteCount = 0;
$transactionRef = '';
$userEmail = '';

/*
|--------------------------------------------------------------------------
| LOAD OR INIT SESSION
|--------------------------------------------------------------------------
*/
if ($newSession) {
    $step = 0;
    $contestantCode = '';
    $voteCount = 0;
    $transactionRef = '';
    $userEmail = '';
    saveSession($sessionID, $step, $contestantCode, $voteCount, $transactionRef, $userEmail);
    
    $message = "Welcome To Ghartey Events\n";
    $message .= "1. Vote\n";
    $message .= "Vote Cost: GHC 1 per vote";
} else {
    $sessionState = loadSession($sessionID);
    $step = $sessionState['step'];
    $contestantCode = $sessionState['contestantCode'];
    $voteCount = $sessionState['voteCount'];
    $transactionRef = $sessionState['transactionRef'];
    $userEmail = $sessionState['email'];
}

/*
|--------------------------------------------------------------------------
| FLOW LOGIC
|--------------------------------------------------------------------------
*/
if ($step == 0 && $userData == "1") {
    $step = 1;
    saveSession($sessionID, $step, '', 0, '', '');
    $message = "Enter Contestant Code:\n";
    $message .= "FS1 - EGYIRWAA\n";
    $message .= "FS2 - AGYEKUMWAA\n";
    $message .= "FS3 - BOATEMAA\n";
    $message .= "FS4 - ABENA\n";
    $message .= "FS5 - SEDEM";
}

elseif ($step == 1 && preg_match('/^FS[1-5]$/i', strtoupper($userData))) {
    $contestantCode = strtoupper($userData);
    
    $nominees = [
        "FS1" => "EGYIRWAA",
        "FS2" => "AGYEKUMWAA", 
        "FS3" => "BOATEMAA",
        "FS4" => "ABENA",
        "FS5" => "SEDEM"
    ];
    
    if (isset($nominees[$contestantCode])) {
        $contestantName = $nominees[$contestantCode];
        $step = 2;
        saveSession($sessionID, $step, $contestantCode, 0, '', '');
        
        $message  = "Vote for " . $contestantName . "\n";
        $message .= "Nominee Code: " . $contestantCode . "\n";
        $message .= "Cost: GHC 1 per vote\n";
        $message .= "Enter number of votes (1-100):";
    }
}

elseif ($step == 2 && is_numeric($userData) && $userData >= 1 && $userData <= 100) {
    $voteCount = (int)$userData;
    $step = 3;
    $totalAmount = $voteCount * 1;
    
    saveSession($sessionID, $step, $contestantCode, $voteCount, '', '');
    
    $message = "Vote Summary:\n";
    $message .= "Nominee: " . $contestantCode . "\n";
    $message .= "Votes: " . $voteCount . "\n";
    $message .= "Total: GHC " . $totalAmount . "\n\n";
    $message .= "Enter your email address to continue:";
}

elseif ($step == 3 && filter_var($userData, FILTER_VALIDATE_EMAIL)) {
    $userEmail = $userData;
    $step = 4;
    $totalAmount = $voteCount * 1;
    $transactionRef = "VOTE_" . time() . "_" . rand(1000, 9999);
    
    // Initialize Paystack transaction
    $metadata = [
        "custom_fields" => [
            [
                "display_name" => "Nominee Code",
                "variable_name" => "nominee_code",
                "value" => $contestantCode
            ],
            [
                "display_name" => "Number of Votes",
                "variable_name" => "vote_count",
                "value" => $voteCount
            ],
            [
                "display_name" => "Phone Number",
                "variable_name" => "phone_number",
                "value" => $msisdn
            ]
        ]
    ];
    
    $paystackResponse = initializePaystackTransaction($userEmail, $totalAmount, $transactionRef, $metadata);
    
    if ($paystackResponse && $paystackResponse['status']) {
        saveSession($sessionID, $step, $contestantCode, $voteCount, $transactionRef, $userEmail);
        
        $message = "Payment Required:\n";
        $message .= "Amount: GHC " . $totalAmount . "\n";
        $message .= "Reference: " . $transactionRef . "\n\n";
        $message .= "Dial *402*" . $paystackResponse['data']['ussd_code'] . "# to pay\n";
        $message .= "OR\n";
        $message .= "Send to: " . $paystackResponse['data']['payment_url'] . "\n\n";
        $message .= "After payment, reply with your payment reference number:";
    } else {
        $error = $paystackResponse ? $paystackResponse['message'] : "Failed to initialize payment";
        $message = "Payment initialization failed!\n";
        $message .= "Error: " . $error . "\n";
        $message .= "Please try again later.";
        $continueSession = false;
        saveSession($sessionID, 0, '', 0, '', '');
    }
}

elseif ($step == 4 && !empty($userData)) {
    $paymentRef = $userData;
    
    // Verify payment with Paystack
    $verification = verifyPaystackTransaction($transactionRef);
    
    if ($verification && $verification['status'] && $verification['data']['status'] == 'success') {
        // Payment successful, save votes to database
        if (saveVotesToDatabase($contestantCode, $voteCount)) {
            $nominees = [
                "FS1" => "EGYIRWAA",
                "FS2" => "AGYEKUMWAA", 
                "FS3" => "BOATEMAA",
                "FS4" => "ABENA",
                "FS5" => "SEDEM"
            ];
            $contestantName = $nominees[$contestantCode];
            $totalAmount = $voteCount * 1;
            
            $message = "✓ VOTE SUCCESSFUL! ✓\n";
            $message .= "========================\n";
            $message .= "Nominee: " . $contestantName . "\n";
            $message .= "Code: " . $contestantCode . "\n";
            $message .= "Votes Cast: " . $voteCount . "\n";
            $message .= "Amount Paid: GHC " . $totalAmount . "\n";
            $message .= "Transaction ID: " . $verification['data']['reference'] . "\n";
            $message .= "========================\n";
            $message .= "Thank you for voting!\n";
            $message .= "Your support matters!";
            
            // Log successful transaction
            $logData = [
                "fields" => [
                    "transactionRef" => ["stringValue" => $transactionRef],
                    "nomineeCode" => ["stringValue" => $contestantCode],
                    "votes" => ["integerValue" => $voteCount],
                    "amount" => ["integerValue" => $totalAmount],
                    "phone" => ["stringValue" => $msisdn],
                    "email" => ["stringValue" => $userEmail],
                    "status" => ["stringValue" => "success"],
                    "timestamp" => ["stringValue" => date('Y-m-d H:i:s')]
                ]
            ];
            firebaseRequest("PATCH", "transactions", $transactionRef, $logData);
        } else {
            $message = "Payment verified but failed to record votes.\nPlease contact support with your reference: " . $transactionRef;
        }
        $continueSession = false;
        saveSession($sessionID, 0, '', 0, '', '');
    } 
    elseif ($verification && $verification['status'] && $verification['data']['status'] == 'pending') {
        $message = "Payment is still pending.\n";
        $message .= "Please complete the payment using the USSD code provided.\n";
        $message .= "Reply 'CHECK' to verify again or 'CANCEL' to cancel.";
        $step = 5;
        saveSession($sessionID, $step, $contestantCode, $voteCount, $transactionRef, $userEmail);
    }
    else {
        $message = "Payment verification failed!\n";
        $message .= "Please ensure you've completed the payment.\n";
        $message .= "Reply 'RETRY' to try again or 'CANCEL' to cancel.";
        $step = 5;
        saveSession($sessionID, $step, $contestantCode, $voteCount, $transactionRef, $userEmail);
    }
}

elseif ($step == 5 && strtoupper($userData) == 'CHECK') {
    // Re-verify payment
    $verification = verifyPaystackTransaction($transactionRef);
    
    if ($verification && $verification['status'] && $verification['data']['status'] == 'success') {
        if (saveVotesToDatabase($contestantCode, $voteCount)) {
            $nominees = [
                "FS1" => "EGYIRWAA",
                "FS2" => "AGYEKUMWAA", 
                "FS3" => "BOATEMAA",
                "FS4" => "ABENA",
                "FS5" => "SEDEM"
            ];
            $contestantName = $nominees[$contestantCode];
            $totalAmount = $voteCount * 1;
            
            $message = "✓ VOTE SUCCESSFUL! ✓\n";
            $message .= "Nominee: " . $contestantName . "\n";
            $message .= "Votes: " . $voteCount . "\n";
            $message .= "Amount: GHC " . $totalAmount . "\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            saveSession($sessionID, 0, '', 0, '', '');
        }
    } else {
        $message = "Payment still not confirmed.\n";
        $message .= "Please complete payment or contact support.";
    }
}

elseif (($step == 5 || $step == 4) && strtoupper($userData) == 'CANCEL') {
    $message = "Voting cancelled.\nThank you for your interest!";
    $continueSession = false;
    saveSession($sessionID, 0, '', 0, '', '');
}

elseif ($step == 5 && strtoupper($userData) == 'RETRY') {
    $step = 4;
    saveSession($sessionID, $step, $contestantCode, $voteCount, $transactionRef, $userEmail);
    $message = "Please complete your payment using the USSD code provided.\n";
    $message .= "After payment, reply with your payment reference number:";
}

else {
    if ($step == 1) {
        $message = "Invalid Contestant Code.\nPlease enter FS1, FS2, FS3, FS4, or FS5";
    } elseif ($step == 2) {
        $message = "Invalid input.\nPlease enter number of votes (1-100)";
    } elseif ($step == 3) {
        $message = "Invalid email format.\nPlease enter a valid email address";
    } else {
        $message = "Invalid Option.\nPlease try again or contact support.";
    }
    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/
$response = [
    "sessionID"       => $sessionID,
    "userID"          => $userID,
    "msisdn"          => $msisdn,
    "message"         => $message,
    "continueSession" => $continueSession
];

header("Content-Type: application/json");
echo json_encode($response);
?>
