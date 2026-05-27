<?php
// COMPLETE WORKING VERSION - WITH PAYSTACK USSD PUSH
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack Configuration (LIVE)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';
$paystackPublicKey = 'pk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// Function to fetch from contestants DB (use actual name)
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
    
    // Format phone number (remove +233 and replace with 0)
    $phone = preg_replace('/^\+233/', '0', $phone);
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    $url = "https://api.paystack.co/charge";
    
    $data = [
        'amount' => $amount * 100, // Convert to pesewas
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
            return [
                'success' => true,
                'message' => $result['message'],
                'reference' => $reference
            ];
        } else {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Payment initiation failed'
            ];
        }
    }
    
    return [
        'success' => false,
        'message' => 'Could not connect to payment gateway'
    ];
}

// Function to verify payment status
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

// USSD Logic
$message = "";
$continueSession = false;

if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code:";
    $continueSession = true;
}
// Step 1: Get nominee code
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'get_code') {
    $nomineeCode = strtoupper($userData);
    
    // Try both databases
    $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
    if (!$nominee) {
        $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
    }
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'get_votes';
        
        $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
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
// Step 2: Get number of votes
elseif ($_SESSION['step'] == 'get_votes' && is_numeric($userData)) {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Enter number between 1-1000:";
        $continueSession = true;
    } else {
        $nominee = $_SESSION['nominee'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['total_amount'] = $totalAmount;
        $_SESSION['step'] = 'confirm_payment';
        
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
    }
}
// Step 3: Confirm payment
elseif ($_SESSION['step'] == 'confirm_payment') {
    if ($userData == "1") {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $_SESSION['total_amount'];
        
        // Generate unique reference
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Initiate Paystack USSD payment
        $payment = initiatePaystackUSSD($msisdn, $totalAmount, $reference);
        
        if ($payment['success']) {
            $_SESSION['payment_reference'] = $reference;
            $_SESSION['step'] = 'verify_payment';
            
            $message = "═══════════════════\n";
            $message .= "AUTHORIZATION REQUIRED\n";
            $message .= "═══════════════════\n\n";
            $message .= "Amount: GHC {$totalAmount}\n\n";
            $message .= "Check your phone for a\n";
            $message .= "payment authorization prompt.\n\n";
            $message .= "Follow the instructions to\n";
            $message .= "complete your payment.\n\n";
            $message .= "After authorization, your\n";
            $message .= "votes will be added.\n";
            $message .= "═══════════════════\n\n";
            $message .= "1. Check Payment Status\n";
            $message .= "2. Cancel";
            $continueSession = true;
        } else {
            $message = "Payment error: {$payment['message']}\nPlease try again later.";
            $continueSession = false;
            unset($_SESSION['step']);
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
        }
    } 
    elseif ($userData == "2") {
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    }
    else {
        $message = "Choose:\n1. Confirm & Pay\n2. Cancel";
        $continueSession = true;
    }
}
// Step 4: Verify payment status
elseif ($_SESSION['step'] == 'verify_payment') {
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
            $message .= "Total paid: GHC " . ($votes * $nominee['voteAmount']) . "\n\n";
            $message .= "Thank you for voting!\n";
            $message .= "Dial again to vote more!";
            $continueSession = false;
            
            unset($_SESSION['step']);
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
            unset($_SESSION['payment_reference']);
        } else {
            $message = "Payment not completed yet.\n\n";
            $message .= "Check your phone and\n";
            $message .= "authorize the payment.\n\n";
            $message .= "1. Check again\n";
            $message .= "2. Cancel";
            $continueSession = true;
        }
    }
    elseif ($userData == "2") {
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
        unset($_SESSION['payment_reference']);
    }
    else {
        $message = "1. Check Payment Status\n2. Cancel";
        $continueSession = true;
    }
}
// Handle any invalid state
else {
    $message = "Session refreshed.\nEnter Nominee Code:";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
    unset($_SESSION['payment_reference']);
}

echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
