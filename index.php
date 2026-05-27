<?php
header('Content-Type: application/json');

// ============ DATABASE CONFIGURATIONS ============
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
$userInput = trim($data['userData'] ?? '');

session_start();

// ============ FUNCTION: Fetch ANY nominee by code from BOTH databases ============
function fetchNomineeByCode($code) {
    global $contestantsFirestoreUrl, $awardsFirestoreUrl;
    
    // Try Contestants DB first (FS1-FS5)
    $url = $contestantsFirestoreUrl . "/contestants";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            $docCode = $fields['code']['stringValue'] ?? '';
            if (strtoupper($docCode) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'collection' => 'contestants',
                    'db_url' => $contestantsFirestoreUrl,
                    'type' => 'contestant'
                ];
            }
        }
    }
    
    // Try Awards DB
    $url = $awardsFirestoreUrl . "/awards_nominees";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            $docCode = $fields['nomineeCode']['stringValue'] ?? '';
            if (strtoupper($docCode) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['stageName']['stringValue'] ?? $fields['fullName']['stringValue'] ?? '',
                    'category' => $fields['categoryName']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => 1,
                    'collection' => 'awards_nominees',
                    'db_url' => $awardsFirestoreUrl,
                    'type' => 'award'
                ];
            }
        }
    }
    
    return null;
}

// ============ FUNCTION: Update votes in the correct database ============
function updateNomineeVotes($db_url, $collection, $documentId, $newVotes) {
    $updateUrl = $db_url . "/{$collection}/{$documentId}?updateMask.fieldPaths=votes";
    
    $updateData = [
        'fields' => [
            'votes' => ['integerValue' => (string)$newVotes]
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

// ============ FUNCTION: Create Paystack Payment ============
function createPaystackPayment($email, $amount, $reference, $callbackUrl, $metadata) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100,
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'metadata' => $metadata
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
    curl_close($ch);
    
    $result = json_decode($response, true);
    if ($result && $result['status']) {
        return $result['data']['authorization_url'];
    }
    return false;
}

// ============ FUNCTION: Verify Payment ============
function verifyPayment($reference) {
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
    if ($result && $result['status'] && $result['data']['status'] == 'success') {
        return $result['data'];
    }
    return false;
}

// ============ CHECK PAYMENT CALLBACK ============
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPayment($reference);
    
    if ($paymentData) {
        $metadata = $paymentData['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        
        $nominee = fetchNomineeByCode($nomineeCode);
        if ($nominee) {
            $newVotes = $nominee['votes'] + $votes;
            updateNomineeVotes($nominee['db_url'], $nominee['collection'], $nominee['id'], $newVotes);
            
            file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . " | SUCCESS | $nomineeCode +$votes votes\n", FILE_APPEND);
            echo "✅ Payment successful! $votes votes added for $nomineeCode";
        } else {
            echo "✅ Payment received but nominee not found!";
        }
    } else {
        echo "❌ Payment verification failed!";
    }
    exit;
}

// ============ USSD MENU LOGIC - FIXED ============
$message = "";
$continueSession = false;

// Initialize session step if not set
if (!isset($_SESSION['step'])) {
    $_SESSION['step'] = 'welcome';
}

// WELCOME SCREEN
if ($newSession == true) {
    $_SESSION = [];
    $_SESSION['step'] = 'get_code';
    $message = "Welcome to GHartey Event Voting!\n\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "Examples: FS1, FS2, PG1, BAP1, MSS1";
    $continueSession = true;
}
// STEP 1: GET NOMINEE CODE
elseif ($_SESSION['step'] == 'get_code') {
    $nomineeCode = strtoupper($userInput);
    $nominee = fetchNomineeByCode($nomineeCode);
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'get_votes';
        
        $displayName = $nominee['name'];
        $categoryInfo = isset($nominee['category']) ? " ({$nominee['category']})" : "";
        
        $message = "🗳️ VOTE FOR: {$displayName}{$categoryInfo}\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current Votes: " . number_format($nominee['votes']) . "\n";
        $message .= "Price: GHC {$nominee['voteAmount']}/vote\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "📝 Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid Code: '$userInput'\n\n";
        $message .= "Valid codes include:\n";
        $message .= "• FS1, FS2, FS3, FS4, FS5\n";
        $message .= "• PG1, PG2, PG3 (Perfect Gentleman)\n";
        $message .= "• BAP1 (Best Appointee)\n";
        $message .= "• MSS1 (Most Social Student)\n";
        $message .= "• PL1 (Perfect Lady)\n\n";
        $message .= "Enter Nominee Code:";
        $continueSession = true;
    }
}
// STEP 2: GET NUMBER OF VOTES
elseif ($_SESSION['step'] == 'get_votes' && is_numeric($userInput)) {
    $votes = intval($userInput);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Enter between 1-1000 votes:";
        $continueSession = true;
    } else {
        $nominee = $_SESSION['nominee'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['step'] = 'confirm_payment';
        
        $displayName = $nominee['name'];
        
        $message = "📊 VOTE SUMMARY\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n";
        $message .= "Nominee: {$displayName}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Votes: " . number_format($votes) . "\n";
        $message .= "Total: GHC " . number_format($totalAmount, 2) . "\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "1️⃣ Proceed to Payment\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// STEP 3: CONFIRM PAYMENT
elseif ($_SESSION['step'] == 'confirm_payment') {
    if ($userInput == '1') {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $reference = "VOTE_" . time() . "_" . rand(10000, 99999);
        $customerEmail = $msisdn . "@ussd.voter.com";
        
        $metadata = [
            'msisdn' => $msisdn,
            'nominee_code' => $nominee['code'],
            'votes' => $votes,
            'type' => $nominee['type']
        ];
        
        $callbackUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
        $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, $callbackUrl, $metadata);
        
        if ($paymentUrl) {
            $message = "💳 PAYMENT REQUIRED: GHC " . number_format($totalAmount, 2) . "\n\n";
            $message .= "🔗 Click to pay:\n{$paymentUrl}\n\n";
            $message .= "✅ After payment, votes added automatically!\n";
            $message .= "Thank you for voting! 🙏";
            $continueSession = false;
            
            // Clear session after payment
            unset($_SESSION['step']);
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
            
            file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . " | INITIATED | {$nominee['code']} | $votes votes | GHC $totalAmount\n", FILE_APPEND);
        } else {
            $message = "❌ Payment error. Please try again later.\n";
            $message .= "Enter 0 to start over:";
            $_SESSION['step'] = 'get_code';
            $continueSession = true;
        }
    } 
    elseif ($userInput == '2') {
        $message = "❌ Vote cancelled.\n\n";
        $message .= "Enter new Nominee Code to vote:";
        $_SESSION['step'] = 'get_code';
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
        $continueSession = true;
    }
    else {
        $message = "Choose 1 to pay or 2 to cancel:";
        $continueSession = true;
    }
}
// HANDLE ANY INVALID INPUT
else {
    $message = "Enter Nominee Code (FS1, PG1, BAP1, etc.):";
    $_SESSION['step'] = 'get_code';
    $continueSession = true;
}

// ============ RESPONSE ============
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
