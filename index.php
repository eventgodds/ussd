<?php
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack configuration (LIVE)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// ============ FUNCTIONS ============
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
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
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
                    'name' => $fields['stageName']['stringValue'] ?? $fields['fullName']['stringValue'] ?? '',
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

// Initialize Mobile Money payment with Paystack
function initiateMobileMoneyPayment($phone, $amount, $reference, $nomineeCode, $votes, $type) {
    global $paystackSecretKey;
    
    // Format phone number (remove +233 and add 0 if needed)
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 3) == '233') {
        $phone = '0' . substr($phone, 3);
    }
    if (substr($phone, 0, 1) != '0') {
        $phone = '0' . $phone;
    }
    
    $url = "https://api.paystack.co/charge";
    
    $data = [
        'email' => $phone . '@ussd.voter.com',
        'amount' => $amount * 100,
        'reference' => $reference,
        'currency' => 'GHS',
        'mobile_money' => [
            'phone' => $phone,
            'provider' => 'mtn' // or 'vodafone', 'airteltigo'
        ],
        'metadata' => [
            'msisdn' => $phone,
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
    
    if ($httpCode == 200) {
        $result = json_decode($response, true);
        return $result;
    }
    
    return false;
}

// Verify transaction status
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
    
    $result = json_decode($response, true);
    
    if ($result['status'] && $result['data']['status'] == 'success') {
        return $result['data'];
    }
    
    return false;
}

// ============ WEBHOOK FOR PAYMENT STATUS ============
// Paystack will call this when payment is completed
$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature && hash_hmac('sha512', $input, $paystackSecretKey) === $signature) {
    $event = json_decode($input, true);
    
    if ($event['event'] == 'charge.success') {
        $metadata = $event['data']['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        $type = $metadata['type'];
        
        // Update votes in respective database
        if ($type == 'contestant') {
            $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            }
        } else {
            $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
        }
        
        file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . " | SUCCESS | Code: {$nomineeCode} | Votes: {$votes}\n", FILE_APPEND);
        http_response_code(200);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// ============ USSD MENU LOGIC ============
$message = "";
$continueSession = false;

// INITIAL MENU
if ($newSession == true) {
    $_SESSION = [];
    $_SESSION['step'] = 'awaiting_code';
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, FS2, PG1, BAP1, etc.):";
    $continueSession = true;
}
// STEP 1: Get nominee code
elseif ($_SESSION['step'] == 'awaiting_code') {
    $nomineeCode = strtoupper(trim($userData));
    
    $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
    if (!$nominee) {
        $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
    }
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'awaiting_votes';
        
        $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
        $message = "✓ " . $nominee['name'] . $categoryText . "\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "GHC {$nominee['voteAmount']}/vote\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid code '{$nomineeCode}'\nTry: FS1, FS2, PG1, BAP1, etc.\n\nEnter code:";
        $continueSession = true;
    }
}
// STEP 2: Get number of votes
elseif ($_SESSION['step'] == 'awaiting_votes' && is_numeric($userData)) {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Enter votes (1-1000):";
        $continueSession = true;
    } else {
        $nominee = $_SESSION['nominee'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['total_amount'] = $totalAmount;
        $_SESSION['step'] = 'confirm_payment';
        
        $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
        $message = "📊 SUMMARY\n";
        $message .= "Nominee: {$nominee['name']}{$categoryText}\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Total: GHC {$totalAmount}\n\n";
        $message .= "1️⃣ Pay Now (MTN MoMo)\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// STEP 3: Process payment
elseif ($_SESSION['step'] == 'confirm_payment') {
    if ($userData == "1") {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $_SESSION['total_amount'];
        
        // Generate unique reference
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Initiate Mobile Money payment
        $paymentResult = initiateMobileMoneyPayment($msisdn, $totalAmount, $reference, $nominee['code'], $votes, $nominee['type']);
        
        if ($paymentResult && $paymentResult['status']) {
            $_SESSION['payment_reference'] = $reference;
            $_SESSION['step'] = 'awaiting_payment';
            
            $message = "💰 Payment Initiated!\n";
            $message .= "Amount: GHC {$totalAmount}\n";
            $message .= "Check your phone ({$msisdn}) for MoMo prompt.\n\n";
            $message .= "Enter 1 to check payment status\n";
            $message .= "Enter 2 to cancel";
            $continueSession = true;
        } else {
            $errorMsg = $paymentResult['message'] ?? 'Payment failed';
            $message = "❌ {$errorMsg}\nPlease try again later.";
            $continueSession = false;
            unset($_SESSION['step']);
        }
    } 
    elseif ($userData == "2") {
        $message = "❌ Vote cancelled.\n\nEnter new nominee code:";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_code';
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    }
    else {
        $message = "❌ Invalid!\n1️⃣ Pay Now\n2️⃣ Cancel";
        $continueSession = true;
    }
}
// STEP 4: Check payment status
elseif ($_SESSION['step'] == 'awaiting_payment') {
    if ($userData == "1") {
        $reference = $_SESSION['payment_reference'];
        $verification = verifyTransaction($reference);
        
        if ($verification) {
            $nominee = $_SESSION['nominee'];
            $votes = $_SESSION['pending_votes'];
            
            $message = "✅ PAYMENT SUCCESSFUL!\n\n";
            $message .= "{$votes} votes added for {$nominee['name']}\n";
            $message .= "Total paid: GHC {$_SESSION['total_amount']}\n\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            unset($_SESSION['step']);
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
        } else {
            $message = "⏳ Payment pending or failed.\n";
            $message .= "Enter 1 to check again\n";
            $message .= "Enter 2 to cancel";
            $continueSession = true;
        }
    } 
    elseif ($userData == "2") {
        $message = "❌ Payment cancelled.\n\nEnter new nominee code:";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_code';
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
        unset($_SESSION['payment_reference']);
    }
    else {
        $message = "1️⃣ Check status\n2️⃣ Cancel";
        $continueSession = true;
    }
}
// Handle unexpected input
else {
    $_SESSION['step'] = 'awaiting_code';
    $message = "Enter nominee code (FS1, FS2, PG1, etc.):";
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
