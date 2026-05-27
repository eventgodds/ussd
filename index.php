<?php
header('Content-Type: application/json');

// ============ DATABASE CONFIGURATIONS ============
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack LIVE Keys
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';
$paystackPublicKey = 'pk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read USSD request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// ============ FETCH ANY NOMINEE BY CODE FROM EITHER DATABASE ============
function fetchNomineeByCode($awardsUrl, $contestantsUrl, $code) {
    $code = strtoupper($code);
    
    // FIRST: Check Awards Database (eventgodds)
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
    
    // SECOND: Check Contestants Database (eventgodds-41e4f)
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
function createPaystackPayment($msisdn, $amount, $reference, $nomineeCode, $votes, $callbackUrl) {
    global $paystackSecretKey;
    
    // Generate email from MSISDN
    $email = $msisdn . "@ussd.voter.com";
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100, // Convert to pesewas
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'metadata' => [
            'msisdn' => $msisdn,
            'nominee_code' => $nomineeCode,
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
        
        // Fetch nominee to update votes
        $nominee = fetchNomineeByCode($awardsFirestoreUrl, $contestantsFirestoreUrl, $nomineeCode);
        
        if ($nominee) {
            $newVotes = $nominee['votes'] + $votes;
            updateNomineeVotes($nominee['dbUrl'], $nominee['collection'], $nominee['id'], $newVotes);
            
            $logEntry = date('Y-m-d H:i:s') . " | SUCCESS | Ref: {$reference} | Code: {$nomineeCode} | Votes: {$votes} | Total: GHC " . ($votes * $nominee['voteAmount']) . "\n";
            file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
            
            echo "<h1>Payment Successful!</h1>";
            echo "<p>{$votes} votes added for {$nominee['name']} ({$nomineeCode})</p>";
            echo "<p>Total cost: GHC " . ($votes * $nominee['voteAmount']) . "</p>";
            echo "<p>Thank you for voting!</p>";
            exit;
        }
    }
    
    echo "<h1>Payment Verification Failed</h1>";
    echo "<p>Please contact support.</p>";
    exit;
}

// ============ USSD MAIN LOGIC ============
$message = "";
$continueSession = false;

// INITIAL MENU
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Voting!\n";
    $message .= "Enter Nominee Code (e.g., FS1, PG1, AOY1, BGE1):";
    $continueSession = true;
}
// STEP 1: User entered nominee code
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'get_code') {
    $nomineeCode = strtoupper(trim($userData));
    
    $nominee = fetchNomineeByCode($awardsFirestoreUrl, $contestantsFirestoreUrl, $nomineeCode);
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'get_votes';
        
        $categoryText = $nominee['category'] ? " ({$nominee['category']})" : "";
        $message = "Vote for: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "Vote price: GHC {$nominee['voteAmount']}/vote\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code '{$nomineeCode}'!\n";
        $message .= "Try: FS1-FS5, PG1-PG3, AOY1-AOY3, BGE1, etc.\n\n";
        $message .= "Enter Nominee Code:";
        $continueSession = true;
    }
}
// STEP 2: User entered number of votes
elseif ($_SESSION['step'] == 'get_votes' && is_numeric($userData)) {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid! Enter 1-1000 votes:";
        $continueSession = true;
    } else {
        $nominee = $_SESSION['nominee'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['step'] = 'confirm_payment';
        
        $message = "✓ VOTE SUMMARY\n";
        $message .= "Nominee: {$nominee['name']}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Total: GHC {$totalAmount}\n\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}
// STEP 3: User selects payment or cancel
elseif ($_SESSION['step'] == 'confirm_payment') {
    if ($userData == "1") {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $votes * $nominee['voteAmount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Create Paystack payment link
        $callbackUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
        $paymentUrl = createPaystackPayment($msisdn, $totalAmount, $reference, $nominee['code'], $votes, $callbackUrl);
        
        if ($paymentUrl) {
            $message = "💰 Payment Required: GHC {$totalAmount}\n\n";
            $message .= "Click link to pay with Mobile Money or Card:\n";
            $message .= $paymentUrl . "\n\n";
            $message .= "After payment, votes will be added automatically.\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            // Clear session after payment
            unset($_SESSION['step']);
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
            
            $logEntry = date('Y-m-d H:i:s') . " | INITIATED | MSISDN: {$msisdn} | Code: {$nominee['code']} | Votes: {$votes} | Amount: GHC {$totalAmount}\n";
            file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
        } else {
            $message = "Payment error. Please try again later.\n";
            $message .= "Enter Nominee Code to restart:";
            $continueSession = true;
            unset($_SESSION['step']);
        }
    } 
    elseif ($userData == "2") {
        $message = "Vote cancelled.\n\nEnter Nominee Code to vote:";
        $continueSession = true;
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    } 
    else {
        $message = "Choose 1 to pay or 2 to cancel:\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}
// Default fallback
else {
    $message = "Enter Nominee Code (FS1, PG1, AOY1, BGE1, etc.):";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
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
