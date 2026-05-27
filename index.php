<?php
header('Content-Type: application/json');

// ============ DATABASE CONFIGURATIONS ============
// Database 1: Contestants DB (eventgodds-41e4f)
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

// Database 2: Awards DB (eventgodds)
$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack configuration (LIVE)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';
$paystackPublicKey = 'pk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

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

// ============ FUNCTION: Fetch Contestant by Code (from eventgodds-41e4f) ============
function fetchContestantByCode($firestoreUrl, $code) {
    $url = $firestoreUrl . "/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && 
                strtoupper($fields['code']['stringValue']) === strtoupper($code)) {
                
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'type' => 'contestant'
                ];
            }
        }
    }
    return null;
}

// ============ FUNCTION: Fetch Award Nominee by Code (from eventgodds) ============
function fetchAwardNomineeByCode($firestoreUrl, $code) {
    $url = $firestoreUrl . "/awards_nominees";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['nomineeCode']['stringValue']) && 
                strtoupper($fields['nomineeCode']['stringValue']) === strtoupper($code)) {
                
                return [
                    'id' => basename($doc['name']),
                    'nomineeCode' => $fields['nomineeCode']['stringValue'],
                    'fullName' => $fields['fullName']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? '',
                    'categoryName' => $fields['categoryName']['stringValue'] ?? '',
                    'categoryCode' => $fields['categoryCode']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => 1, // Default GHC 1 per vote
                    'type' => 'award'
                ];
            }
        }
    }
    return null;
}

// ============ FUNCTION: Update Votes in Respective Database ============
function updateVotes($firestoreUrl, $collection, $documentId, $newVotes) {
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

// ============ FUNCTION: Create Paystack Payment Link ============
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

// ============ FUNCTION: Verify Paystack Payment ============
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
        $type = $metadata['type'];
        
        if ($type == 'contestant') {
            $contestant = fetchContestantByCode($contestantsFirestoreUrl, $nomineeCode);
            if ($contestant) {
                $newVotes = $contestant['votes'] + $votes;
                updateVotes($contestantsFirestoreUrl, 'contestants', $contestant['id'], $newVotes);
            }
        } else {
            $nominee = fetchAwardNomineeByCode($awardsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotes($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
        }
        
        $logEntry = date('Y-m-d H:i:s') . " | PAYMENT SUCCESS | Ref: {$reference} | Type: {$type} | Code: {$nomineeCode} | Votes: {$votes}\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
        
        echo "Payment successful! {$votes} votes added for {$nomineeCode}";
        exit;
    } else {
        echo "Payment verification failed!";
        exit;
    }
}

// ============ USSD MENU LOGIC ============
$message = "";
$continueSession = false;

// MAIN WELCOME (First time)
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Event Voting System\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "(Examples: FS1, PG1, BAP1, MPS1, etc.)";
    $continueSession = true;
}

// User entered a nominee code
elseif (preg_match('/^[A-Z0-9]+$/i', $userData) && strlen($userData) >= 3) {
    $nomineeCode = strtoupper($userData);
    
    // Try to fetch from contestants database first (FS1-FS5)
    $contestant = fetchContestantByCode($contestantsFirestoreUrl, $nomineeCode);
    
    // If not found, try awards nominees database
    $nominee = null;
    if (!$contestant) {
        $nominee = fetchAwardNomineeByCode($awardsFirestoreUrl, $nomineeCode);
    }
    
    if ($contestant || $nominee) {
        $selected = $contestant ? $contestant : $nominee;
        $_SESSION['selected_nominee'] = $selected;
        $_SESSION['nominee_type'] = $contestant ? 'contestant' : 'award';
        
        $displayName = $selected['stageName'] ?: $selected['fullName'] ?: $selected['name'] ?: $selected['nomineeCode'];
        $categoryInfo = isset($selected['categoryName']) ? " ({$selected['categoryName']})" : '';
        
        $message = "Vote for: {$displayName}{$categoryInfo}\n";
        $message .= "Nominee Code: {$selected['nomineeCode']}\n";
        $message .= "Vote Price: GHC {$selected['voteAmount']} per vote\n";
        $message .= "Current Votes: {$selected['votes']}\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid Nominee Code!\n";
        $message .= "Please enter valid code (FS1-FS5, PG1, BAP1, MPS1, etc.):";
        $continueSession = true;
    }
}

// User entered number of votes
elseif (isset($_SESSION['selected_nominee']) && is_numeric($userData) && $userData > 0) {
    $votes = intval($userData);
    $nominee = $_SESSION['selected_nominee'];
    $type = $_SESSION['nominee_type'];
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid number! Please enter between 1 and 1000 votes:";
        $continueSession = true;
    } else {
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['pending_nominee'] = $nominee;
        $_SESSION['msisdn'] = $msisdn;
        
        $displayName = $nominee['stageName'] ?: $nominee['fullName'] ?: $nominee['name'] ?: $nominee['nomineeCode'];
        
        $message = "Vote Summary:\n";
        $message .= "Nominee: {$displayName} ({$nominee['nomineeCode']})\n";
        if (isset($nominee['categoryName'])) {
            $message .= "Category: {$nominee['categoryName']}\n";
        }
        $message .= "Votes: {$votes}\n";
        $message .= "Total Amount: GHC {$totalAmount}\n\n";
        $message .= "1. Proceed to Payment\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}

// Process payment
elseif ($userData == "1" && isset($_SESSION['pending_nominee'])) {
    $nominee = $_SESSION['pending_nominee'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $votes * $nominee['voteAmount'];
    $type = $_SESSION['nominee_type'];
    
    $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
    $customerEmail = $msisdn . "@ussd.voter.com";
    
    $metadata = [
        'msisdn' => $msisdn,
        'nominee_code' => $nominee['nomineeCode'],
        'votes' => $votes,
        'type' => $type,
        'amount' => $totalAmount
    ];
    
    $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, "https://yourdomain.com/ussd_handler.php", $metadata);
    
    if ($paymentUrl) {
        $_SESSION['payment_reference'] = $reference;
        
        $message = "Payment Required: GHC {$totalAmount}\n";
        $message .= "Click link to pay:\n{$paymentUrl}\n\n";
        $message .= "After payment, votes added automatically.\n";
        $message .= "Thank you for voting!";
        $continueSession = false;
        
        $logEntry = date('Y-m-d H:i:s') . " | PAYMENT INITIATED | MSISDN: {$msisdn} | Ref: {$reference} | Type: {$type} | Code: {$nominee['nomineeCode']} | Votes: {$votes}\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
    } else {
        $message = "Payment system error. Please try again later.";
        $continueSession = false;
    }
}

// Cancel
elseif ($userData == "2" && isset($_SESSION['pending_nominee'])) {
    unset($_SESSION['pending_nominee']);
    unset($_SESSION['pending_votes']);
    unset($_SESSION['nominee_type']);
    
    $message = "Vote cancelled.\n\nEnter Nominee Code to vote:";
    $continueSession = true;
}

// Invalid or restart
else {
    $message = "Welcome to GHartey Event Voting System\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "(Examples: FS1, PG1, BAP1, MPS1, etc.)";
    $continueSession = true;
    unset($_SESSION['selected_nominee']);
    unset($_SESSION['pending_nominee']);
    unset($_SESSION['pending_votes']);
    unset($_SESSION['nominee_type']);
}

// Response to Arkesel
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
