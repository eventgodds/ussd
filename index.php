<?php
header('Content-Type: application/json');

// ============ CONFIGURATION ============
// Project 1: Contestants Database
$projectId1 = 'eventgodds-41e4f';
$firestoreUrl1 = "https://firestore.googleapis.com/v1/projects/{$projectId1}/databases/(default)/documents";

// Project 2: Award Nominees Database
$projectId2 = 'eventgodds';
$firestoreUrl2 = "https://firestore.googleapis.com/v1/projects/{$projectId2}/databases/(default)/documents";

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

// ============ DATABASE FUNCTIONS ============

// Fetch from any Firestore collection
function fetchFromFirestore($url, $collection) {
    $fullUrl = $url . "/" . $collection;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Fetch item by code from both databases
function fetchByCodeFromBoth($code, $firestoreUrl1, $firestoreUrl2) {
    // First, try contestants collection (Project 1)
    $data = fetchFromFirestore($firestoreUrl1, 'contestants');
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && 
                strtoupper($fields['code']['stringValue']) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'type' => 'contestant',
                    'database' => 'eventgodds-41e4f',
                    'collection' => 'contestants',
                    'engagement' => $fields['engagement']['integerValue'] ?? 0
                ];
            }
        }
    }
    
    // Then, try award_nominees collection (Project 2)
    $data = fetchFromFirestore($firestoreUrl2, 'award_nominees');
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            $docCode = $fields['code']['stringValue'] ?? $fields['nomineeCode']['stringValue'] ?? '';
            if (!empty($docCode) && strtoupper($docCode) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['name']['stringValue'] ?? $fields['nomineeName']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? $fields['pricePerVote']['integerValue'] ?? 1,
                    'type' => 'award',
                    'database' => 'eventgodds',
                    'collection' => 'award_nominees',
                    'awardCategory' => $fields['awardCategory']['stringValue'] ?? $fields['award']['stringValue'] ?? ''
                ];
            }
        }
    }
    
    return null;
}

// Update votes in the correct database
function updateVotesInFirestore($item, $newVotes) {
    if ($item['database'] == 'eventgodds-41e4f') {
        $url = "https://firestore.googleapis.com/v1/projects/eventgodds-41e4f/databases/(default)/documents";
    } else {
        $url = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";
    }
    
    $updateUrl = $url . "/{$item['collection']}/{$item['id']}?updateMask.fieldPaths=votes";
    
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

// Create Paystack Mobile Money payment
function createPaystackPayment($msisdn, $amount, $reference, $callbackUrl, $item, $votes) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    // Clean phone number for Paystack
    $phone = preg_replace('/^\+?233/', '0', $msisdn);
    $phone = preg_replace('/^0/', '233', $phone);
    
    $data = [
        'amount' => $amount * 100, // Convert to pesewas
        'email' => $msisdn . '@ussd.voter.com',
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'channels' => ['mobile_money'],
        'mobile_money' => [
            'phone' => $phone,
            'provider' => 'mtn'
        ],
        'metadata' => [
            'msisdn' => $msisdn,
            'item_id' => $item['id'],
            'item_code' => $item['code'],
            'item_name' => $item['name'],
            'item_type' => $item['type'],
            'votes' => $votes,
            'database' => $item['database'],
            'collection' => $item['collection']
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
        if ($result['status']) {
            return $result['data']['authorization_url'];
        }
    }
    
    return false;
}

// Verify Paystack payment
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
    
    return json_decode($response, true);
}

// Log transactions
function logTransaction($message, $data = []) {
    $logEntry = date('Y-m-d H:i:s') . " | " . $message . " | " . json_encode($data) . "\n";
    file_put_contents('ussd_transactions.log', $logEntry, FILE_APPEND);
}

// ============ USSD MENU FLOW ============
$message = "";
$continueSession = false;

// Check for payment callback (from webhook)
if (isset($_GET['reference']) && isset($_GET['sessionID'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPayment($reference);
    
    if ($paymentData && $paymentData['status'] && $paymentData['data']['status'] == 'success') {
        $metadata = $paymentData['data']['metadata'];
        
        // Re-fetch the item with current votes
        $item = fetchByCodeFromBoth($metadata['item_code'], $firestoreUrl1, $firestoreUrl2);
        
        if ($item) {
            $newVotes = $item['votes'] + intval($metadata['votes']);
            $updated = updateVotesInFirestore($item, $newVotes);
            
            if ($updated) {
                logTransaction("PAYMENT_SUCCESS", [
                    'reference' => $reference,
                    'item' => $metadata['item_code'],
                    'votes' => $metadata['votes'],
                    'amount' => $paymentData['data']['amount'] / 100
                ]);
                
                echo "Payment successful! " . $metadata['votes'] . " votes added for " . $metadata['item_name'];
                exit;
            }
        }
    }
    
    echo "Payment verification failed. Please contact support.";
    exit;
}

// USSD Menu Logic
if ($newSession == true) {
    // Start new session
    $_SESSION = [];
    $_SESSION['step'] = 'main';
    
    $message = "Welcome to Ghartey Event Voting!\n";
    $message .= "Enter Nominee Code (e.g., FS1, AWARD01):";
    $continueSession = true;
    
    logTransaction("NEW_SESSION", ['msisdn' => $msisdn]);
}
// Step 2: User entered a code, show details and ask for votes
elseif ($_SESSION['step'] == 'awaiting_code') {
    $code = strtoupper(trim($userData));
    
    // Search in both databases
    $item = fetchByCodeFromBoth($code, $firestoreUrl1, $firestoreUrl2);
    
    if ($item) {
        $_SESSION['pending_item'] = $item;
        $_SESSION['step'] = 'awaiting_votes';
        
        $message = "✅ " . $item['name'] . "\n";
        $message .= "Code: " . $item['code'] . "\n";
        $message .= "💰 Price: GHC " . $item['voteAmount'] . "/vote\n";
        $message .= "📊 Current votes: " . number_format($item['votes']) . "\n";
        
        if ($item['type'] == 'award' && isset($item['awardCategory'])) {
            $message .= "🏆 Award: " . $item['awardCategory'] . "\n";
        }
        
        $message .= "\n🔢 Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid code!\n";
        $message .= "Try FS1-FS5 for contestants or valid award code.\n\n";
        $message .= "Enter nominee code:";
        $continueSession = true;
    }
}
// Step 3: User entered number of votes, show summary and ask for confirmation
elseif ($_SESSION['step'] == 'awaiting_votes') {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid number! Enter votes (1-1000):";
        $continueSession = true;
    } else {
        $item = $_SESSION['pending_item'];
        $totalAmount = $votes * $item['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['pending_total_amount'] = $totalAmount;
        $_SESSION['step'] = 'awaiting_payment';
        
        $message = "📋 VOTE SUMMARY\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "Nominee: " . $item['name'] . "\n";
        $message .= "Code: " . $item['code'] . "\n";
        $message .= "Votes: " . number_format($votes) . "\n";
        $message .= "Price/vote: GHC " . $item['voteAmount'] . "\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "Total: GHC " . number_format($totalAmount, 2) . "\n\n";
        $message .= "1️⃣ Proceed to Payment\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// Step 4: User confirms payment
elseif ($_SESSION['step'] == 'awaiting_payment') {
    if ($userData == "1") {
        // Proceed with payment
        $item = $_SESSION['pending_item'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $_SESSION['pending_total_amount'];
        
        $reference = "VOTE_" . time() . "_" . rand(10000, 99999);
        $callbackUrl = "https://yourdomain.com/ussd_handler.php"; // CHANGE THIS
        
        $paymentUrl = createPaystackPayment($msisdn, $totalAmount, $reference, $callbackUrl, $item, $votes);
        
        if ($paymentUrl) {
            $_SESSION['payment_reference'] = $reference;
            $_SESSION['step'] = 'awaiting_confirmation';
            
            $message = "💳 Payment Required: GHC " . number_format($totalAmount, 2) . "\n";
            $message = "📱 You'll receive a payment prompt on your phone.\n";
            $message = "🔐 Enter your Mobile Money PIN to confirm.\n\n";
            $message = "✅ After payment, votes will be added automatically.\n";
            $message = "🙏 Thank you for voting!";
            $continueSession = false; // End USSD session, wait for payment
            
            logTransaction("PAYMENT_INITIATED", [
                'msisdn' => $msisdn,
                'reference' => $reference,
                'item' => $item['code'],
                'votes' => $votes,
                'amount' => $totalAmount
            ]);
        } else {
            $message = "❌ Payment system error. Please try again later.\n";
            $message .= "Enter new code to vote:";
            $continueSession = true;
            $_SESSION['step'] = 'awaiting_code';
        }
    } 
    elseif ($userData == "2") {
        // Cancel
        $message = "❌ Vote cancelled.\n\n";
        $message .= "Enter new nominee code:";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_code';
        unset($_SESSION['pending_item']);
        unset($_SESSION['pending_votes']);
    }
    else {
        $message = "❌ Invalid option!\n";
        $message .= "1️⃣ Proceed to Payment\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// Default - start over
else {
    $message = "Enter Nominee Code (e.g., FS1, AWARD01):";
    $continueSession = true;
    $_SESSION['step'] = 'awaiting_code';
}

// Send response to Arkesel
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);

?>
