<?php
// COMPLETE WORKING VERSION - FIXED SESSION STATES
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

// Function to fetch from contestants DB
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

// Function to fetch from awards DB
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

// Function to initiate Paystack USSD payment
function initiatePaystackUSSD($phone, $amount, $reference) {
    global $paystackSecretKey;
    
    // Format phone number
    $phone = preg_replace('/^\+233/', '0', $phone);
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    $url = "https://api.paystack.co/charge";
    
    $data = [
        'amount' => $amount * 100,
        'email' => $phone . '@ussd.voter.com',
        'currency' => 'GHS',
        'reference' => $reference,
        'phone' => $phone,
        'channels' => ['ussd']
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
        if ($result['status']) {
            return ['success' => true, 'reference' => $reference];
        }
    }
    
    return ['success' => false, 'message' => 'Payment initiation failed'];
}

// Function to verify payment
function verifyPayment($reference) {
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

// USSD Logic - FIXED SESSION STATES
$message = "";
$continueSession = false;

// NEW SESSION - Starting fresh
if ($newSession == true) {
    session_destroy();
    session_start();
    $_SESSION['state'] = 'awaiting_code';
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code:";
    $continueSession = true;
}
// STATE: Awaiting nominee code
elseif ($_SESSION['state'] == 'awaiting_code') {
    $nomineeCode = strtoupper($userData);
    
    // Search both databases
    $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
    if (!$nominee) {
        $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
    }
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['state'] = 'awaiting_votes';
        
        $categoryText = isset($nominee['category']) ? "\nCategory: {$nominee['category']}" : "";
        $message = "Vote for: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "GHC {$nominee['voteAmount']}/vote\n\n";
        $message .= "Enter number of votes:";
        $continueSession = true;
    } else {
        $message = "Invalid code: {$nomineeCode}\nTry FS1, FS2, PG1, etc.\nEnter Nominee Code:";
        $continueSession = true;
    }
}
// STATE: Awaiting number of votes
elseif ($_SESSION['state'] == 'awaiting_votes') {
    // Check if input is a number
    if (is_numeric($userData) && $userData > 0) {
        $votes = intval($userData);
        
        if ($votes >= 1 && $votes <= 1000) {
            $nominee = $_SESSION['nominee'];
            $totalAmount = $votes * $nominee['voteAmount'];
            
            $_SESSION['pending_votes'] = $votes;
            $_SESSION['total_amount'] = $totalAmount;
            $_SESSION['state'] = 'awaiting_confirmation';
            
            $message = "═══════════════════\n";
            $message .= "VOTE SUMMARY\n";
            $message .= "═══════════════════\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: {$votes}\n";
            $message .= "Total: GHC {$totalAmount}\n";
            $message .= "═══════════════════\n\n";
            $message .= "1. Confirm & Pay\n";
            $message .= "2. Cancel";
            $continueSession = true;
        } else {
            $message = "Votes must be between 1-1000.\nEnter number of votes:";
            $continueSession = true;
        }
    } else {
        // Not a number - treat as invalid input for votes
        $message = "Please enter a valid number (1-1000):";
        $continueSession = true;
    }
}
// STATE: Awaiting confirmation (Proceed to Pay or Cancel)
elseif ($_SESSION['state'] == 'awaiting_confirmation') {
    if ($userData == "1") {
        // Proceed to payment
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $_SESSION['total_amount'];
        
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        $payment = initiatePaystackUSSD($msisdn, $totalAmount, $reference);
        
        if ($payment['success']) {
            $_SESSION['payment_reference'] = $reference;
            $_SESSION['state'] = 'awaiting_payment_verification';
            
            $message = "═══════════════════\n";
            $message .= "AUTHORIZATION REQUIRED\n";
            $message .= "═══════════════════\n\n";
            $message .= "Amount: GHC {$totalAmount}\n\n";
            $message .= "Check your phone for a\n";
            $message .= "payment prompt.\n\n";
            $message .= "Authorize payment to\n";
            $message .= "complete your vote.\n";
            $message .= "═══════════════════\n\n";
            $message .= "1. Check Status\n";
            $message .= "2. Cancel";
            $continueSession = true;
        } else {
            $message = "Payment error. Please try again.\nEnter Nominee Code:";
            $continueSession = true;
            $_SESSION['state'] = 'awaiting_code';
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
        }
    } 
    elseif ($userData == "2") {
        // Cancel
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
        $_SESSION['state'] = 'awaiting_code';
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    }
    else {
        $message = "Choose:\n1. Confirm & Pay\n2. Cancel";
        $continueSession = true;
    }
}
// STATE: Checking payment status
elseif ($_SESSION['state'] == 'awaiting_payment_verification') {
    if ($userData == "1") {
        $reference = $_SESSION['payment_reference'];
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        
        $paymentData = verifyPayment($reference);
        
        if ($paymentData) {
            // Payment successful - update votes
            $newVotes = $nominee['votes'] + $votes;
            
            if ($nominee['type'] == 'contestant') {
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            } else {
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
            
            $message = "═══════════════════\n";
            $message .= "✓ VOTE SUCCESSFUL!\n";
            $message .= "═══════════════════\n\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Votes added: {$votes}\n";
            $message .= "Amount paid: GHC " . ($votes * $nominee['voteAmount']) . "\n\n";
            $message .= "Thank you for voting!\n";
            $message .= "Dial again to vote more!";
            $continueSession = false;
            
            session_destroy();
        } else {
            $message = "Payment pending.\n\n";
            $message .= "Please check your phone\n";
            $message .= "and authorize payment.\n\n";
            $message .= "1. Check again\n";
            $message .= "2. Cancel";
            $continueSession = true;
        }
    }
    elseif ($userData == "2") {
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
        session_destroy();
        session_start();
        $_SESSION['state'] = 'awaiting_code';
    }
    else {
        $message = "1. Check Payment Status\n2. Cancel";
        $continueSession = true;
    }
}
// Fallback - reset session
else {
    session_destroy();
    session_start();
    $_SESSION['state'] = 'awaiting_code';
    $message = "Session reset.\nEnter Nominee Code:";
    $continueSession = true;
}

echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
