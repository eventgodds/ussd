<?php
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";
$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack Configuration
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

// File-based session storage
$sessionFile = sys_get_temp_dir() . '/ussd_vote_' . md5($sessionID) . '.json';
$session = [];
if (file_exists($sessionFile)) {
    $session = json_decode(file_get_contents($sessionFile), true);
}

function saveSession($sessionFile, $session) {
    file_put_contents($sessionFile, json_encode($session));
}

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

// Initialize Paystack USSD payment
function initiatePaystackUSSD($amount, $msisdn, $reference, $nomineeCode, $votes, $type) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/charge";
    
    $cleanPhone = preg_replace('/[^0-9]/', '', $msisdn);
    if (strlen($cleanPhone) === 10) {
        $cleanPhone = '233' . substr($cleanPhone, 1);
    }
    if (strlen($cleanPhone) === 9) {
        $cleanPhone = '233' . $cleanPhone;
    }
    
    $email = "user{$cleanPhone}@ussd.vote.com";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100,
        'reference' => $reference,
        'phone' => $cleanPhone,
        'currency' => 'GHS',
        'channels' => ['ussd', 'mobile_money'],
        'metadata' => [
            'msisdn' => $msisdn,
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
    
    error_log("Paystack USSD Charge Response: " . $response);
    
    if ($httpCode == 200) {
        $result = json_decode($response, true);
        if ($result['status'] && isset($result['data']['reference'])) {
            return ['success' => true, 'reference' => $reference];
        }
    }
    
    return ['success' => false, 'message' => 'Payment initiation failed'];
}

// Verify payment status
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

if ($userData == "0") {
    @unlink($sessionFile);
    $message = "Welcome to GHartey Voting\nEnter Nominee Code:";
    $continueSession = true;
    echo json_encode(["sessionID" => $sessionID, "userID" => $userID, "msisdn" => $msisdn, "message" => $message, "continueSession" => $continueSession]);
    exit;
}

if ($newSession == true) {
    @unlink($sessionFile);
    $session = ['step' => 'welcome'];
    saveSession($sessionFile, $session);
    $message = "Welcome to GHartey Voting\nEnter Nominee Code:";
    $continueSession = true;
}
elseif (isset($session['step']) && $session['step'] == 'get_votes') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if (is_numeric($lastInput) && $lastInput > 0) {
        $votes = intval($lastInput);
        $nominee = $session['nominee'];
        
        if ($votes >= 1 && $votes <= 1000) {
            $totalAmount = $votes * $nominee['voteAmount'];
            $session['pending_votes'] = $votes;
            $session['total_amount'] = $totalAmount;
            $session['step'] = 'confirm_payment';
            saveSession($sessionFile, $session);
            
            $message = "VOTE SUMMARY\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: {$votes}\n";
            $message .= "Total: GHC {$totalAmount}\n\n";
            $message .= "1. Proceed to Vote\n";
            $message .= "2. Cancel\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        } else {
            $message = "Enter number between 1-1000:";
            $continueSession = true;
        }
    } else {
        $message = "Enter number of votes (1-1000):";
        $continueSession = true;
    }
}
elseif (isset($session['step']) && $session['step'] == 'confirm_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $nominee = $session['nominee'];
        $votes = $session['pending_votes'];
        $totalAmount = $session['total_amount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Store payment reference
        $session['payment_ref'] = $reference;
        saveSession($sessionFile, $session);
        
        // Initiate USSD payment
        $payment = initiatePaystackUSSD($totalAmount, $msisdn, $reference, $nominee['code'], $votes, $nominee['type']);
        
        if ($payment['success']) {
            $session['step'] = 'check_payment';
            saveSession($sessionFile, $session);
            
            $message = "AUTHORIZATION REQUIRED\n";
            $message .= "Amount: GHC {$totalAmount}\n";
            $message .= "Nominee: {$nominee['name']}\n\n";
            $message .= "Check your phone for a\n";
            $message .= "payment authorization prompt.\n\n";
            $message .= "Follow the instructions to\n";
            $message .= "complete your payment.\n\n";
            $message .= "1. Check Payment Status\n";
            $message .= "2. Cancel";
            $continueSession = true;
        } else {
            $message = "Payment error. Please try again.\n0. Main Menu";
            $continueSession = true;
            $session['step'] = 'welcome';
            saveSession($sessionFile, $session);
        }
    } 
    elseif ($lastInput == "2") {
        @unlink($sessionFile);
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
    }
    else {
        $message = "Choose:\n1. Proceed to Vote\n2. Cancel\n0. Main Menu";
        $continueSession = true;
    }
}
elseif (isset($session['step']) && $session['step'] == 'check_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $reference = $session['payment_ref'];
        $nominee = $session['nominee'];
        $votes = $session['pending_votes'];
        
        $paymentData = verifyPayment($reference);
        
        if ($paymentData) {
            // Payment successful - update database
            $newVotes = $nominee['votes'] + $votes;
            
            if ($nominee['type'] == 'contestant') {
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            } else {
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
            
            $message = "✓ VOTE SUCCESSFUL!\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Votes added: {$votes}\n";
            $message .= "Total paid: GHC " . ($votes * $nominee['voteAmount']) . "\n\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            @unlink($sessionFile);
        } else {
            $message = "Payment pending.\n\n";
            $message .= "Check your phone and\n";
            $message .= "authorize the payment.\n\n";
            $message .= "1. Check again\n";
            $message .= "2. Cancel";
            $continueSession = true;
        }
    }
    elseif ($lastInput == "2") {
        @unlink($sessionFile);
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
    }
    else {
        $message = "1. Check Payment Status\n2. Cancel";
        $continueSession = true;
    }
}
else {
    $nomineeCode = strtoupper($userData);
    
    $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
    if (!$nominee) {
        $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
    }
    
    if ($nominee) {
        $session['nominee'] = $nominee;
        $session['step'] = 'get_votes';
        saveSession($sessionFile, $session);
        
        $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
        $message = "Vote for: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "Price: GHC {$nominee['voteAmount']} per vote\n\n";
        $message .= "Enter number of votes (1-1000):\n";
        $message .= "0. Main Menu";
        $continueSession = true;
    } else {
        $message = "Invalid code '{$nomineeCode}'\nEnter Nominee Code (FS1-FS5, PG1, etc.):\n0. Exit";
        $continueSession = true;
    }
}

echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
