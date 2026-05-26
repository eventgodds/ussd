<?php
header('Content-Type: application/json');

// Firebase Firestore REST API configuration
$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

// Paystack configuration (LIVE)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get values
$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn     = $data['msisdn'] ?? '';
$userData   = trim($data['userData'] ?? '');

session_start();

// Function to fetch contestant by code from Firestore
function fetchContestantByCode($firestoreUrl, $code) {
    $url = $firestoreUrl . "/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && 
                strtoupper($fields['code']['stringValue']) === strtoupper($code)) {
                
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'engagement' => $fields['engagement']['integerValue'] ?? 0
                ];
            }
        }
    }
    
    return null;
}

// Function to update votes in Firestore
function updateContestantVotes($firestoreUrl, $documentId, $newVotes) {
    $updateUrl = $firestoreUrl . "/contestants/{$documentId}?updateMask.fieldPaths=votes";
    
    $updateData = [
        'fields' => [
            'votes' => [
                'integerValue' => (string)$newVotes
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $updateUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Function to charge via Paystack (Direct Debit/Momo)
function processPaystackPayment($msisdn, $amount, $reference, $contestantCode, $votes) {
    global $paystackSecretKey;
    
    // Format phone number (remove spaces and +)
    $phone = preg_replace('/[^0-9]/', '', $msisdn);
    if (strlen($phone) == 9) {
        $phone = '233' . $phone;
    } elseif (strlen($phone) == 10) {
        $phone = '233' . substr($phone, 1);
    }
    
    // Create a charge request for mobile money
    $url = "https://api.paystack.co/charge";
    
    $data = [
        'email' => $phone . '@ussd.voter.com',
        'amount' => $amount * 100, // Convert to pesewas/kobo
        'reference' => $reference,
        'mobile_money' => [
            'phone' => $phone,
            'provider' => 'mtn' // Can be 'mtn', 'vodafone', 'airteltigo'
        ],
        'metadata' => [
            'msisdn' => $msisdn,
            'contestant_code' => $contestantCode,
            'votes' => $votes,
            'custom_fields' => [
                [
                    'display_name' => 'Contestant',
                    'variable_name' => 'contestant',
                    'value' => $contestantCode
                ],
                [
                    'display_name' => 'Number of Votes',
                    'variable_name' => 'votes',
                    'value' => $votes
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $result = json_decode($response, true);
        return $result;
    }
    
    return false;
}

// Function to verify transaction status
function verifyTransaction($reference) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/verify/{$reference}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Check for payment verification via USSD callback
if (isset($_GET['verify']) && isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $verification = verifyTransaction($reference);
    
    if ($verification && $verification['data']['status'] == 'success') {
        $metadata = $verification['data']['metadata'];
        $contestant = fetchContestantByCode($firestoreUrl, $metadata['contestant_code']);
        
        if ($contestant) {
            $newVotes = $contestant['votes'] + $metadata['votes'];
            updateContestantVotes($firestoreUrl, $contestant['id'], $newVotes);
            
            echo "SUCCESS: {$metadata['votes']} votes added for {$contestant['stageName']}";
        } else {
            echo "ERROR: Contestant not found";
        }
    } else {
        echo "ERROR: Payment not verified";
    }
    exit;
}

// USSD Menu Logic
$message = "";
$continueSession = false;

// MAIN WELCOME (First time)
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to Ghartey Event Voting\n";
    $message .= "Enter Contestant Code:\n";
    $message .= "FS1, FS2, FS3, FS4, or FS5:";
    $continueSession = true;
}

// Check for pending payment verification
elseif (isset($_SESSION['awaiting_payment']) && $_SESSION['awaiting_payment'] === true) {
    $reference = $_SESSION['payment_reference'];
    $verification = verifyTransaction($reference);
    
    if ($verification && $verification['data']['status'] == 'success') {
        // Payment successful
        $contestantCode = $_SESSION['pending_contestant']['code'];
        $votes = $_SESSION['pending_votes'];
        $contestant = fetchContestantByCode($firestoreUrl, $contestantCode);
        
        if ($contestant) {
            $newVotes = $contestant['votes'] + $votes;
            updateContestantVotes($firestoreUrl, $contestant['id'], $newVotes);
            
            $message = "✓ Payment Successful!\n";
            $message .= "{$votes} votes added for {$contestant['stageName']}\n";
            $message .= "Total votes now: {$newVotes}\n\n";
            $message .= "Thank you for voting!\n";
            $message .= "Dial *XXX# to vote again";
            
            // Clear session
            unset($_SESSION['awaiting_payment']);
            unset($_SESSION['payment_reference']);
            unset($_SESSION['pending_contestant']);
            unset($_SESSION['pending_votes']);
            
            $continueSession = false;
        }
    } elseif ($verification && $verification['data']['status'] == 'pending') {
        $message = "Payment pending.\n";
        $message = "Please check your phone and approve the payment request.\n";
        $message = "Reply with 1 to check status\n";
        $message = "Reply with 2 to cancel";
        $continueSession = true;
    } else {
        $message = "Payment failed or cancelled.\n";
        $message = "Enter Contestant Code (FS1-FS5) to try again:";
        $continueSession = true;
        unset($_SESSION['awaiting_payment']);
    }
}

// User entered contestant code (FS1-FS5)
elseif (preg_match('/^FS[1-5]$/i', $userData)) {
    $contestantCode = strtoupper($userData);
    $contestant = fetchContestantByCode($firestoreUrl, $contestantCode);
    
    if ($contestant) {
        $_SESSION['selected_contestant'] = $contestant;
        
        $message = "✓ Contestant Found!\n\n";
        $message .= "Name: " . $contestant['stageName'] . "\n";
        $message .= "Code: " . $contestant['code'] . "\n";
        $message .= "Vote Price: GHC " . $contestant['voteAmount'] . "/vote\n";
        $message .= "Current Votes: " . $contestant['votes'] . "\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid Contestant Code!\n";
        $message .= "Please enter valid code (FS1, FS2, FS3, FS4, or FS5):";
        $continueSession = true;
    }
}

// User entered number of votes
elseif (isset($_SESSION['selected_contestant']) && is_numeric($userData) && $userData > 0) {
    $votes = intval($userData);
    $contestant = $_SESSION['selected_contestant'];
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Please enter between 1 and 1000 votes:";
        $continueSession = true;
    } else {
        $totalAmount = $votes * $contestant['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['pending_contestant'] = $contestant;
        
        $message = "📋 Vote Summary:\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "Contestant: {$contestant['stageName']}\n";
        $message .= "Code: {$contestant['code']}\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Amount per vote: GHC {$contestant['voteAmount']}\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "Total: GHC {$totalAmount}\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";
        $message .= "1. Pay via Mobile Money\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}

// Process payment
elseif ($userData == "1" && isset($_SESSION['pending_contestant'])) {
    $contestant = $_SESSION['pending_contestant'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $votes * $contestant['voteAmount'];
    $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
    
    // Initiate Paystack payment
    $paymentResponse = processPaystackPayment($msisdn, $totalAmount, $reference, $contestant['code'], $votes);
    
    if ($paymentResponse && $paymentResponse['status']) {
        $_SESSION['payment_reference'] = $reference;
        $_SESSION['awaiting_payment'] = true;
        
        $message = "💰 Payment Initiated!\n\n";
        $message = "Amount: GHC {$totalAmount}\n";
        $message = "Reference: {$reference}\n\n";
        $message = "Please check your phone ({$msisdn})\n";
        $message = "You will receive a payment prompt.\n\n";
        $message = "Approve the transaction to complete your vote.\n\n";
        $message = "Reply with 1 to check payment status";
        $continueSession = true;
        
        // Log payment initiation
        $logEntry = date('Y-m-d H:i:s') . " | PAYMENT INITIATED | MSISDN: {$msisdn} | Ref: {$reference} | Contestant: {$contestant['code']} | Votes: {$votes} | Amount: GHC {$totalAmount}\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
    } else {
        $errorMsg = $paymentResponse['message'] ?? 'Unknown error';
        $message = "❌ Payment Error: {$errorMsg}\n\n";
        $message .= "Please try again later.\n";
        $message .= "Enter Contestant Code (FS1-FS5):";
        $continueSession = true;
        unset($_SESSION['pending_contestant']);
        unset($_SESSION['pending_votes']);
    }
}

// Check payment status
elseif ($userData == "1" && isset($_SESSION['awaiting_payment']) && $_SESSION['awaiting_payment'] === true) {
    $reference = $_SESSION['payment_reference'];
    $verification = verifyTransaction($reference);
    
    if ($verification && $verification['data']['status'] == 'success') {
        $contestant = $_SESSION['pending_contestant'];
        $votes = $_SESSION['pending_votes'];
        $newVotes = $contestant['votes'] + $votes;
        updateContestantVotes($firestoreUrl, $contestant['id'], $newVotes);
        
        $message = "✓ PAYMENT SUCCESSFUL!\n\n";
        $message = "{$votes} votes added for {$contestant['stageName']}\n";
        $message = "Total votes: {$newVotes}\n\n";
        $message = "Thank you for supporting {$contestant['stageName']}!";
        
        unset($_SESSION['awaiting_payment']);
        unset($_SESSION['payment_reference']);
        unset($_SESSION['pending_contestant']);
        unset($_SESSION['pending_votes']);
        $continueSession = false;
    } 
    elseif ($verification && $verification['data']['status'] == 'pending') {
        $message = "⏳ Payment still pending...\n\n";
        $message = "Please check your phone and approve the payment.\n\n";
        $message = "1. Check status again\n";
        $message = "2. Cancel payment";
        $continueSession = true;
    }
    else {
        $message = "❌ Payment failed or was cancelled.\n\n";
        $message = "Enter Contestant Code (FS1-FS5) to try again:";
        $continueSession = true;
        unset($_SESSION['awaiting_payment']);
        unset($_SESSION['payment_reference']);
    }
}

// Cancel payment
elseif ($userData == "2") {
    unset($_SESSION['selected_contestant']);
    unset($_SESSION['pending_contestant']);
    unset($_SESSION['pending_votes']);
    unset($_SESSION['awaiting_payment']);
    unset($_SESSION['payment_reference']);
    
    $message = "❌ Vote cancelled.\n\n";
    $message .= "Enter Contestant Code (FS1-FS5) to vote:";
    $continueSession = true;
}

// Handle invalid inputs
else {
    $message = "❌ Invalid option!\n\n";
    $message .= "Enter Contestant Code:\n";
    $message .= "FS1, FS2, FS3, FS4, or FS5\n";
    $message .= "Or reply 0 to exit";
    $continueSession = true;
}

// Response to Arkesel
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);

?>
