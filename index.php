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
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
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
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['nomineeCode']['stringValue'],
                    'name' => $fields['fullName']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
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
    $updateData = ['fields' => ['votes' => ['integerValue' => (string)$newVotes]]];
    
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

// ============ KEY FUNCTION: DIRECT MOBILE MONEY PAYMENT ============
function chargeMobileMoney($amount, $msisdn, $reference, $nomineeCode, $votes, $type) {
    global $paystackSecretKey;
    
    // Clean phone number - MUST be in international format for Paystack
    $phone = preg_replace('/[^0-9]/', '', $msisdn);
    if (substr($phone, 0, 1) == '0') {
        $phone = '233' . substr($phone, 1);
    }
    if (strlen($phone) == 9) {
        $phone = '233' . $phone;
    }
    
    $email = $phone . "@ussd.voter.com";
    
    // Determine mobile money provider based on phone prefix
    $provider = 'mtn'; // default
    if (substr($phone, 3, 2) == '20' || substr($phone, 3, 2) == '50') {
        $provider = 'vodafone';
    } elseif (substr($phone, 3, 2) == '27' || substr($phone, 3, 2) == '57') {
        $provider = 'airtigo';
    }
    
    $url = "https://api.paystack.co/charge";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100,
        'reference' => $reference,
        'currency' => 'GHS',
        'mobile_money' => [
            'phone' => $phone,
            'provider' => $provider
        ],
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Paystack Response: " . $response);
    
    if ($response === false) {
        return ['success' => false, 'message' => 'Network error'];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode == 200 || $httpCode == 201) {
        if ($result['status']) {
            // Check if it requires PIN or is pending
            if (isset($result['data']['status']) && $result['data']['status'] == 'send_pin') {
                return ['success' => true, 'reference' => $reference, 'message' => 'PIN required'];
            }
            return ['success' => true, 'reference' => $reference, 'message' => 'Payment initiated'];
        } else {
            $errorMsg = $result['message'] ?? 'Unknown error';
            return ['success' => false, 'message' => $errorMsg];
        }
    }
    
    return ['success' => false, 'message' => 'HTTP Error: ' . $httpCode];
}

function verifyPayment($reference) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/verify/{$reference}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $paystackSecretKey]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['status'] && $result['data']['status'] == 'success') {
        return $result['data'];
    }
    
    return false;
}

// ============ USSD LOGIC ============
$message = "";
$continueSession = false;

// Handle 0 to reset
if ($userData == "0") {
    @unlink($sessionFile);
    $message = "Welcome to GHartey Voting\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
    echo json_encode(["sessionID" => $sessionID, "userID" => $userID, "msisdn" => $msisdn, "message" => $message, "continueSession" => $continueSession]);
    exit;
}

// New session
if ($newSession == true) {
    @unlink($sessionFile);
    $session = ['step' => 'welcome'];
    saveSession($sessionFile, $session);
    $message = "Welcome to GHartey Voting\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
}
// Step: Get votes
elseif (isset($session['step']) && $session['step'] == 'get_votes') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if (is_numeric($lastInput) && $lastInput > 0) {
        $votes = intval($lastInput);
        $nominee = $session['nominee'];
        
        if ($votes >= 1 && $votes <= 10000000000000000000000000000000000000) {
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
            $message .= "1. Pay GHC {$totalAmount}\n";
            $message .= "2. Cancel\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        } else {
            $message = "Enter number of votes:\n0. Main Menu";
            $continueSession = true;
        }
    } else {
        $message = "Enter number of votes:\n0. Main Menu";
        $continueSession = true;
    }
}
// Step: Confirm payment - THIS IS WHERE MAGIC HAPPENS
elseif (isset($session['step']) && $session['step'] == 'confirm_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $nominee = $session['nominee'];
        $votes = $session['pending_votes'];
        $totalAmount = $session['total_amount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // DIRECT MOBILE MONEY CHARGE - NO LINK, JUST USSD PROMPT
        $payment = chargeMobileMoney($totalAmount, $msisdn, $reference, $nominee['code'], $votes, $nominee['type']);
        
        if ($payment['success']) {
            $session['payment_ref'] = $reference;
            $session['step'] = 'verify_payment';
            saveSession($sessionFile, $session);
            
            $message = "✓ PAYMENT INITIATED\n\n";
            $message .= "Amount: GHC {$totalAmount}\n";
            $message .= "Nominee: {$nominee['name']}\n\n";
            $message .= "📱 CHECK YOUR PHONE NOW!\n\n";
            $message .= "A Mobile Money prompt will appear\n";
            $message .= "on your screen.\n\n";
            $message .= "Enter your MoMo PIN to\n";
            $message .= "complete the transaction.\n\n";
            $message .= "After payment, reply:\n";
            $message .= "1 to confirm\n";
            $message .= "2 to cancel\n\n";
            $message .= "Waiting for payment...";
            $continueSession = true;
        } else {
            $message = "❌ Payment Error: {$payment['message']}\n\n";
            $message .= "Possible reasons:\n";
            $message .= "- Insufficient balance\n";
            $message .= "- Wrong phone number\n";
            $message .= "- Network issue\n\n";
            $message .= "0. Main Menu";
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
        $message = "Choose:\n1. Pay GHC {$session['total_amount']}\n2. Cancel\n0. Main Menu";
        $continueSession = true;
    }
}
// Step: Verify payment after user authorizes
elseif (isset($session['step']) && $session['step'] == 'verify_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $paymentData = verifyPayment($session['payment_ref']);
        
        if ($paymentData) {
            $nominee = $session['nominee'];
            $votes = $session['pending_votes'];
            $metadata = $paymentData['metadata'];
            
            // Update votes in database
            if ($nominee['type'] == 'contestant') {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            } else {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
            
            $message = "✅ VOTE SUCCESSFUL!\n\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: {$votes}\n";
            $message .= "Amount: GHC " . ($votes * $nominee['voteAmount']) . "\n\n";
            $message .= "Thank you for voting!\n";
            $message .= "Dial again to vote more.";
            $continueSession = false;
            @unlink($sessionFile);
        } else {
            $message = "⏳ Payment pending...\n\n";
            $message .= "If you have completed payment,\n";
            $message .= "wait 10 seconds then reply 1.\n\n";
            $message .= "1. Check again\n";
            $message .= "2. Cancel\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        }
    }
    elseif ($lastInput == "2") {
        @unlink($sessionFile);
        $message = "Vote cancelled.\n\nEnter Nominee Code:";
        $continueSession = true;
    }
    else {
        $message = "Waiting for payment confirmation.\n";
        $message .= "1. Check payment status\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
        $continueSession = true;
    }
}
// Step: Get nominee code
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
        $message = "Invalid code '{$nomineeCode}'\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):\n0. Exit";
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
