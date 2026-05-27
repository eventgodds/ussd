<?php
header('Content-Type: application/json');

// ============ DATABASE CONFIGURATIONS ============
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack LIVE Keys
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read USSD request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// ============ FETCH NOMINEE BY CODE FROM BOTH DATABASES ============
function fetchNomineeByCode($awardsUrl, $contestantsUrl, $code) {
    $code = strtoupper(trim($code));
    
    // Check Awards Database (eventgodds)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $awardsUrl . "/awards_nominees");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['nomineeCode']['stringValue']) && 
                strtoupper($fields['nomineeCode']['stringValue']) === $code) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['nomineeCode']['stringValue'],
                    'name' => $fields['stageName']['stringValue'] ?? $fields['fullName']['stringValue'] ?? 'Unknown',
                    'category' => $fields['categoryName']['stringValue'] ?? '',
                    'votes' => intval($fields['votes']['integerValue'] ?? 0),
                    'voteAmount' => 1,
                    'collection' => 'awards_nominees',
                    'dbUrl' => $awardsUrl
                ];
            }
        }
    }
    
    // Check Contestants Database (eventgodds-41e4f)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $contestantsUrl . "/contestants");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && 
                strtoupper($fields['code']['stringValue']) === $code) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? 'Unknown',
                    'category' => 'Contestant',
                    'votes' => intval($fields['votes']['integerValue'] ?? 0),
                    'voteAmount' => intval($fields['voteAmount']['integerValue'] ?? 1),
                    'collection' => 'contestants',
                    'dbUrl' => $contestantsUrl
                ];
            }
        }
    }
    
    return null;
}

// ============ UPDATE VOTES IN FIRESTORE ============
function updateNomineeVotes($dbUrl, $collection, $documentId, $newVotes) {
    $updateUrl = $dbUrl . "/{$collection}/{$documentId}?updateMask.fieldPaths=votes";
    
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

// ============ CREATE PAYSTACK PAYMENT ============
function createPaystackPayment($msisdn, $amount, $reference, $nomineeCode, $votes, $nomineeName) {
    global $paystackSecretKey;
    
    $email = $msisdn . "@ussd.voter.com";
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100,
        'reference' => $reference,
        'callback_url' => "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'],
        'metadata' => [
            'msisdn' => $msisdn,
            'nominee_code' => $nomineeCode,
            'nominee_name' => $nomineeName,
            'votes' => $votes,
            'amount' => $amount
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

// ============ VERIFY PAYSTACK PAYMENT ============
function verifyPaystackPayment($reference) {
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

// ============ CHECK FOR PAYMENT CALLBACK ============
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPaystackPayment($reference);
    
    if ($paymentData) {
        $metadata = $paymentData['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        
        $nominee = fetchNomineeByCode($awardsFirestoreUrl, $contestantsFirestoreUrl, $nomineeCode);
        
        if ($nominee) {
            $newVotes = $nominee['votes'] + $votes;
            updateNomineeVotes($nominee['dbUrl'], $nominee['collection'], $nominee['id'], $newVotes);
            
            $logEntry = date('Y-m-d H:i:s') . " | SUCCESS | Ref: {$reference} | Code: {$nomineeCode} | Votes: {$votes}\n";
            file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
            
            echo "<h2>✅ Payment Successful!</h2>";
            echo "<p><strong>{$votes} votes</strong> added for <strong>{$nominee['name']}</strong> ({$nomineeCode})</p>";
            echo "<p>Total cost: GHC " . ($votes * $nominee['voteAmount']) . "</p>";
            echo "<p>Thank you for voting!</p>";
            echo "<p>You can close this window and continue voting via USSD.</p>";
            exit;
        }
    }
    
    echo "<h2>❌ Payment Verification Failed</h2>";
    echo "<p>Please contact support with your reference number.</p>";
    exit;
}

// ============ USSD MAIN LOGIC WITH PROPER STATE MANAGEMENT ============
$message = "";
$continueSession = false;

// Initialize session state if new session
if ($newSession == true) {
    $_SESSION = [];
    $_SESSION['state'] = 'awaiting_code';
    $message = "Welcome to GHartey Voting!\n\nEnter Nominee Code (e.g., FS1, PG1, AOY1):";
    $continueSession = true;
}
// STATE: Awaiting nominee code
elseif ($_SESSION['state'] == 'awaiting_code') {
    $nomineeCode = strtoupper(trim($userData));
    
    $nominee = fetchNomineeByCode($awardsFirestoreUrl, $contestantsFirestoreUrl, $nomineeCode);
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['state'] = 'awaiting_votes';
        
        $categoryText = $nominee['category'] ? " ({$nominee['category']})" : "";
        $message = "📌 NOMINEE FOUND\n";
        $message .= "Name: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "Price: GHC {$nominee['voteAmount']}/vote\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid code '{$nomineeCode}'\n\n";
        $message .= "Valid codes: FS1-FS5, PG1-PG3, AOY1-AOY3,\n";
        $message .= "BGE1, SPO1-SPO4, PL1, MPS1, etc.\n\n";
        $message .= "Enter Nominee Code:";
        $continueSession = true;
    }
}
// STATE: Awaiting number of votes
elseif ($_SESSION['state'] == 'awaiting_votes') {
    // Check if input is numeric (votes)
    if (is_numeric($userData) && $userData > 0) {
        $votes = intval($userData);
        
        if ($votes >= 1 && $votes <= 1000) {
            $nominee = $_SESSION['nominee'];
            $totalAmount = $votes * $nominee['voteAmount'];
            
            $_SESSION['pending_votes'] = $votes;
            $_SESSION['state'] = 'awaiting_payment_confirmation';
            
            $message = "📊 VOTE SUMMARY\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: {$votes}\n";
            $message .= "Total: GHC {$totalAmount}\n\n";
            $message .= "1️⃣ Proceed to Pay GHC {$totalAmount}\n";
            $message .= "2️⃣ Cancel";
            $continueSession = true;
        } else {
            $message = "❌ Invalid! Enter 1-1000 votes:\n";
            $message .= "Enter number of votes:";
            $continueSession = true;
        }
    } else {
        // If not numeric, they might have entered another code by mistake
        $message = "❌ Please enter NUMBER of votes (1-1000):\n";
        $message .= "Enter votes:";
        $continueSession = true;
    }
}
// STATE: Awaiting payment confirmation (1 or 2)
elseif ($_SESSION['state'] == 'awaiting_payment_confirmation') {
    if ($userData == "1") {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $votes * $nominee['voteAmount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Create Paystack payment link
        $paymentUrl = createPaystackPayment($msisdn, $totalAmount, $reference, $nominee['code'], $votes, $nominee['name']);
        
        if ($paymentUrl) {
            $message = "💰 PAYMENT REQUIRED: GHC {$totalAmount}\n\n";
            $message .= "Click this link to pay with Mobile Money or Card:\n";
            $message .= $paymentUrl . "\n\n";
            $message .= "✅ After successful payment, votes will be added automatically.\n";
            $message .= "Thank you for voting for {$nominee['name']}!";
            $continueSession = false;
            
            // Log payment initiation
            $logEntry = date('Y-m-d H:i:s') . " | INITIATED | MSISDN: {$msisdn} | Code: {$nominee['code']} | Votes: {$votes} | Amount: GHC {$totalAmount} | Ref: {$reference}\n";
            file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
            
            // Clear session after sending payment link
            session_destroy();
        } else {
            $message = "❌ Payment error. Please try again.\n\nEnter Nominee Code:";
            $continueSession = true;
            $_SESSION['state'] = 'awaiting_code';
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
        }
    } 
    elseif ($userData == "2") {
        $message = "❌ Vote cancelled.\n\nEnter Nominee Code to vote:";
        $continueSession = true;
        $_SESSION['state'] = 'awaiting_code';
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    } 
    else {
        $message = "Choose option:\n";
        $message .= "1️⃣ Proceed to Pay\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// Fallback - reset session
else {
    $_SESSION['state'] = 'awaiting_code';
    $message = "Enter Nominee Code (FS1, PG1, AOY1, etc.):";
    $continueSession = true;
}

// Return USSD response
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
