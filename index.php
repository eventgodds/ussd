<?php
header('Content-Type: application/json');

// ============ DATABASE CONFIGURATIONS ============
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack Configuration (LIVE - but test first!)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';
$paystackPublicKey = 'pk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// YOUR RAILWAY DOMAIN - CHANGE THIS TO YOUR ACTUAL URL
$yourDomain = "https://ussd-production-eb98.up.railway.app";

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get values
$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// ============ HELPER FUNCTIONS ============

// Fetch contestant by code (from eventgodds-41e4f)
function fetchContestant($code) {
    global $contestantsFirestoreUrl;
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
            if (isset($fields['code']['stringValue']) && strtoupper($fields['code']['stringValue']) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
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
    return null;
}

// Fetch award nominee by code (from eventgodds)
function fetchAwardNominee($code) {
    global $awardsFirestoreUrl;
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
            if (isset($fields['nomineeCode']['stringValue']) && strtoupper($fields['nomineeCode']['stringValue']) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['nomineeCode']['stringValue'],
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

// Update votes in Firebase
function updateVotes($db_url, $collection, $documentId, $newVotes) {
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

// Create Paystack payment link
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
    
    $result = json_decode($response, true);
    if ($result && $result['status'] && $result['data']['status'] == 'success') {
        return $result['data'];
    }
    return false;
}

// ============ CHECK FOR PAYMENT CALLBACK ============
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPayment($reference);
    
    if ($paymentData) {
        $metadata = $paymentData['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        
        // Find and update the correct nominee
        $nominee = fetchContestant($nomineeCode);
        if (!$nominee) {
            $nominee = fetchAwardNominee($nomineeCode);
        }
        
        if ($nominee) {
            $newVotes = $nominee['votes'] + $votes;
            updateVotes($nominee['db_url'], $nominee['collection'], $nominee['id'], $newVotes);
            
            file_put_contents('payment_success.log', date('Y-m-d H:i:s') . " | {$nomineeCode} +{$votes} votes | Ref: {$reference}\n", FILE_APPEND);
            
            echo "<h2>✅ Payment Successful!</h2>";
            echo "<p>{$votes} votes added for {$nominee['name']} ({$nomineeCode})</p>";
            echo "<p>Total votes now: " . ($nominee['votes'] + $votes) . "</p>";
            echo "<p>Thank you for voting!</p>";
            exit;
        }
    }
    echo "<h2>❌ Payment Verification Failed</h2>";
    exit;
}

// ============ USSD MAIN LOGIC ============
$message = "";
$continueSession = false;

// Parse Arkesel input - handles both "CODE" and "CODE*VOTES" formats
$parts = explode('*', $userData);
$currentLevel = count($parts);

// WELCOME SCREEN
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Event Voting!\n";
    $message .= "Enter Nominee Code:\n";
    $message .= "Eg: FS1, FS2, PG1, BAP1";
    $continueSession = true;
}
// Step 1: User entered nominee code (e.g., "FS1" or "PG1")
elseif ($currentLevel == 1 && !isset($_SESSION['nominee'])) {
    $nomineeCode = strtoupper($userData);
    
    // Search both databases
    $nominee = fetchContestant($nomineeCode);
    if (!$nominee) {
        $nominee = fetchAwardNominee($nomineeCode);
    }
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        
        $categoryText = isset($nominee['category']) ? " - {$nominee['category']}" : "";
        $message = "📊 NOMINEE FOUND:\n";
        $message .= "Name: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current Votes: {$nominee['votes']}\n";
        $message .= "Cost: GHC {$nominee['voteAmount']}/vote\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid Code: {$nomineeCode}\n";
        $message .= "Valid codes: FS1-FS5, PG1, BAP1, etc.\n";
        $message .= "Try again:";
        $continueSession = true;
    }
}
// Step 2: User entered votes (e.g., "5" or coming as "FS1*5")
elseif (($currentLevel == 2 && isset($_SESSION['nominee'])) || 
        ($currentLevel == 1 && is_numeric($userData) && isset($_SESSION['nominee']))) {
    
    // Get votes from either format
    $votes = ($currentLevel == 2) ? intval($parts[1]) : intval($userData);
    $nominee = $_SESSION['nominee'];
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Enter 1-1000 votes:";
        $continueSession = true;
    } else {
        $totalAmount = $votes * $nominee['voteAmount'];
        $_SESSION['pending_votes'] = $votes;
        
        $message = "📝 VOTE SUMMARY:\n";
        $message .= "Nominee: {$nominee['name']} ({$nominee['code']})\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Total: GHC {$totalAmount}\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "1️⃣ Proceed to Pay GHC {$totalAmount}\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// Step 3: User chooses to proceed or cancel
elseif ($currentLevel == 1 && isset($_SESSION['nominee']) && isset($_SESSION['pending_votes'])) {
    
    if ($userData == "1") {
        // PROCEED TO PAYMENT
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $votes * $nominee['voteAmount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Use MSISDN as email fallback
        $customerEmail = $msisdn . "@ussd.voter.com";
        
        $metadata = [
            'msisdn' => $msisdn,
            'nominee_code' => $nominee['code'],
            'votes' => $votes,
            'type' => $nominee['type'],
            'amount' => $totalAmount
        ];
        
        $callbackUrl = $yourDomain . $_SERVER['PHP_SELF'];
        $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, $callbackUrl, $metadata);
        
        if ($paymentUrl) {
            // Log payment initiation
            file_put_contents('payment_init.log', date('Y-m-d H:i:s') . " | {$msisdn} | {$nominee['code']} | {$votes} votes | {$reference}\n", FILE_APPEND);
            
            $message = "💳 PAYMENT REQUIRED: GHC {$totalAmount}\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "Click link to pay:\n";
            $message .= "{$paymentUrl}\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "After payment, votes added automatically\n";
            $message .= "Thank you for supporting {$nominee['name']}!";
            $continueSession = false;
            
            // Clear session to prevent reuse
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
        } else {
            $message = "❌ Payment error. Please try again later.";
            $continueSession = false;
        }
    } 
    elseif ($userData == "2") {
        // CANCEL
        $message = "❌ Vote cancelled.\n";
        $message .= "Enter new Nominee Code to vote:";
        $continueSession = true;
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    }
    else {
        $message = "❌ Invalid option.\n";
        $message .= "1️⃣ Proceed to Pay\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// Reset/Invalid state
else {
    $message = "Welcome to GHartey Event Voting!\n";
    $message .= "Enter Nominee Code:\n";
    $message .= "Eg: FS1, FS2, PG1, BAP1";
    $continueSession = true;
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
}

// Send response back to Arkesel
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
