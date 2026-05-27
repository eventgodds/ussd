<?php
// COMPLETE WORKING VERSION WITH PAYMENT INTEGRATION
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack Configuration (LIVE)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// Function to fetch from contestants DB (use actual name, not stage name)
function fetchFromContestantsDB($url, $code) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . "/contestants");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && $fields['code']['stringValue'] === $code) {
                // Use actual name, fallback to stage name if name not available
                $actualName = $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '';
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $actualName,
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'type' => 'contestant'
                ];
            }
        }
    }
    return null;
}

// Function to fetch from awards DB (use actual full name)
function fetchFromAwardsDB($url, $code) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . "/awards_nominees");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['nomineeCode']['stringValue']) && $fields['nomineeCode']['stringValue'] === $code) {
                // Use fullName (actual name), fallback to stageName
                $actualName = $fields['fullName']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '';
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['nomineeCode']['stringValue'],
                    'name' => $actualName,
                    'category' => $fields['categoryName']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => 1,
                    'type' => 'award'
                ];
            }
        }
    }
    return null;
}

// Function to update votes in database
function updateVotesInDB($firestoreUrl, $collection, $documentId, $newVotes) {
    $updateUrl = $firestoreUrl . "/{$collection}/{$documentId}?updateMask.fieldPaths=votes";
    
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Function to initiate direct USSD payment (NO LINKS)
function initiateDirectUSSPayment($amount, $msisdn, $reference, $nomineeCode, $votes, $type) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/charge";
    
    // Clean phone number (remove +233, add 0)
    $phone = preg_replace('/[^0-9]/', '', $msisdn);
    if (substr($phone, 0, 3) == '233') {
        $phone = '0' . substr($phone, 3);
    }
    if (strlen($phone) < 10) {
        $phone = '0' . $phone;
    }
    
    $email = $phone . "@ussd.voter.com";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100,
        'reference' => $reference,
        'phone' => $phone,
        'channels' => ['ussd'],
        'metadata' => [
            'nominee_code' => $nomineeCode,
            'votes' => $votes,
            'type' => $type
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
    
    error_log("Paystack USSD Response: " . $response);
    
    if ($httpCode == 200) {
        $result = json_decode($response, true);
        if ($result['status']) {
            // USSD charge initiated successfully
            return [
                'success' => true,
                'reference' => $reference,
                'message' => $result['message']
            ];
        } else {
            error_log("Paystack Error: " . json_encode($result));
            return ['success' => false, 'message' => $result['message'] ?? 'Payment initiation failed'];
        }
    }
    
    return ['success' => false, 'message' => 'Could not connect to payment gateway'];
}

// Function to verify USSD payment status
function verifyUSSDPayment($reference) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/verify/{$reference}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['status'] && $result['data']['status'] == 'success') {
        return $result['data'];
    }
    
    return false;
}

// Check for payment callback
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    
    // Verify payment with Paystack
    $verifyUrl = "https://api.paystack.co/transaction/verify/{$reference}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['status'] && $result['data']['status'] == 'success') {
        $metadata = $result['data']['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        $type = $metadata['type'];
        
        // Update votes in the appropriate database
        if ($type == 'contestant') {
            $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
                echo "Payment successful! {$votes} votes added for {$nominee['name']}";
            }
        } else {
            $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
                echo "Payment successful! {$votes} votes added for {$nominee['name']}";
            }
        }
        exit;
    } else {
        echo "Payment verification failed!";
        exit;
    }
}

// USSD Logic
// USSD Logic - USING ARKESEL SESSION ID
$message = "";
$continueSession = false;

// Use Arkesel's sessionID to track state (NOT PHP sessions)
$stateFile = "/tmp/ussd_state_" . md5($sessionID) . ".json";

// Load existing state
$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true);
}

// Handle "0" to reset
if ($userData == "0") {
    @unlink($stateFile);
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
    echo json_encode(["sessionID" => $sessionID, "userID" => $userID, "msisdn" => $msisdn, "message" => $message, "continueSession" => $continueSession]);
    exit;
}

// NEW SESSION
if ($newSession == true) {
    @unlink($stateFile);
    $state = ['step' => 'welcome'];
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
}
// STEP: Get votes - Extract the LAST input from Arkesel's format
elseif (isset($state['step']) && $state['step'] == 'get_votes') {
    // Arkesel sends "FS1*5" - get the LAST part after *
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if (is_numeric($lastInput) && $lastInput != "0") {
        $votes = intval($lastInput);
        $nominee = $state['nominee'];
        
        if ($votes >= 1 && $votes <= 1000) {
            $totalAmount = $votes * $nominee['voteAmount'];
            $state['pending_votes'] = $votes;
            $state['total_amount'] = $totalAmount;
            $state['step'] = 'confirm_payment';
            
            $message = "═══════════════════\n";
            $message .= "VOTE SUMMARY\n";
            $message .= "═══════════════════\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: {$votes}\n";
            $message .= "Total: GHC {$totalAmount}\n";
            $message .= "═══════════════════\n\n";
            $message .= "1. Proceed to vote GHC {$totalAmount} for {$nominee['name']}\n";
            $message .= "2. Cancel\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        } else {
            $message = "Invalid! Enter number between 1-1000:\n(Or enter 0 to go back)";
            $continueSession = true;
        }
    } else {
        $message = "Enter number of votes (1-1000):\n(Or enter 0 to go back)";
        $continueSession = true;
    }
}
// STEP: Confirm payment
// STEP: Confirm payment - REPLACE THIS WHOLE BLOCK
elseif (isset($state['step']) && $state['step'] == 'confirm_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $nominee = $state['nominee'];
        $votes = $state['pending_votes'];
        $totalAmount = $state['total_amount'];
        
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Initiate DIRECT USSD payment (NO LINK)
        $payment = initiateDirectUSSPayment($totalAmount, $msisdn, $reference, $nominee['code'], $votes, $nominee['type']);
        
        if ($payment['success']) {
            $state['payment_ref'] = $reference;
            $state['step'] = 'verify_payment';
            file_put_contents($stateFile, json_encode($state));
            
            $message = "AUTHORIZATION REQUIRED\n";
            $message .= "Amount: GHC {$totalAmount}\n";
            $message .= "Nominee: {$nominee['name']}\n\n";
            $message .= "Check your phone now!\n";
            $message .= "A USSD prompt will appear\n";
            $message .= "to authorize payment.\n\n";
            $message .= "Follow the instructions to\n";
            $message .= "complete your payment.\n\n";
            $message .= "After authorization, reply:\n";
            $message .= "1 to confirm payment\n";
            $message .= "2 to cancel\n\n";
            $message .= "Waiting for authorization...";
            $continueSession = true;
        } else {
            $message = "Payment error: {$payment['message']}\n";
            $message .= "Please try again later.\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        }
    } 
    elseif ($lastInput == "2") {
        @unlink($stateFile);
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
    }
    else {
        $message = "Choose:\n1. Pay GHC {$state['total_amount']}\n2. Cancel\n0. Main Menu";
        $continueSession = true;
    }
}
// NEW STEP: Verify payment after USSD authorization
elseif (isset($state['step']) && $state['step'] == 'verify_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $paymentData = verifyUSSDPayment($state['payment_ref']);
        
        if ($paymentData) {
            $metadata = $paymentData['metadata'];
            $nominee = $state['nominee'];
            $votes = $state['pending_votes'];
            
            // Update votes in database
            if ($nominee['type'] == 'contestant') {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            } else {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
            
            $message = "✓ VOTE SUCCESSFUL!\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Votes added: {$votes}\n";
            $message .= "Total paid: GHC " . ($votes * $nominee['voteAmount']) . "\n\n";
            $message .= "Thank you for voting!\n";
            $message .= "Dial again to vote more.";
            $continueSession = false;
            @unlink($stateFile);
        } else {
            $message = "Payment not confirmed yet.\n\n";
            $message .= "If you have authorized payment,\n";
            $message .= "wait a moment then reply 1.\n\n";
            $message .= "1. Check again\n";
            $message .= "2. Cancel\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        }
    }
    elseif ($lastInput == "2") {
        @unlink($stateFile);
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
    }
    else {
        $message = "Waiting for payment authorization.\n";
        $message .= "1. Check payment status\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
        $continueSession = true;
    }
}

// Save state to file
file_put_contents($stateFile, json_encode($state));

echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
