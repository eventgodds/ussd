<?php
// COMPLETE WORKING USSD VOTING SYSTEM WITH PAYSTACK
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack Configuration (LIVE)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

// Use file-based session storage (since Arkesel doesn't maintain PHP sessions)
$sessionFile = sys_get_temp_dir() . '/ussd_vote_' . md5($sessionID) . '.json';

// Load existing session data
$session = [];
if (file_exists($sessionFile)) {
    $session = json_decode(file_get_contents($sessionFile), true);
}

// Function to save session
function saveSession($sessionFile, $session) {
    file_put_contents($sessionFile, json_encode($session));
}

// Function to fetch from contestants DB
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

// Function to fetch from awards DB
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

// Function to initialize Paystack payment
function initializePaystackPayment($amount, $email, $reference) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100, // Convert to pesewas
        'reference' => $reference,
        'channels' => ['ussd', 'mobile_money']
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
        } else {
            error_log("Paystack Error: " . json_encode($result));
            return false;
        }
    }
    
    error_log("Paystack HTTP Error: " . $httpCode);
    return false;
}

// Check for payment callback
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    
    $verifyUrl = "https://api.paystack.co/transaction/verify/{$reference}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['status'] && $result['data']['status'] == 'success') {
        $metadata = $result['data']['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        $type = $metadata['type'];
        
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
        
        echo "Payment successful! {$votes} votes added!";
        exit;
    } else {
        echo "Payment verification failed!";
        exit;
    }
}

// ============ USSD LOGIC ============
$message = "";
$continueSession = false;

// Handle "0" to reset
if ($userData == "0") {
    @unlink($sessionFile);
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
    echo json_encode(["sessionID" => $sessionID, "userID" => $userID, "msisdn" => $msisdn, "message" => $message, "continueSession" => $continueSession]);
    exit;
}

// NEW SESSION - First time
if ($newSession == true) {
    @unlink($sessionFile);
    $session = ['step' => 'welcome'];
    saveSession($sessionFile, $session);
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
}
// STEP 2: User entered a nominee code, now expecting vote count
elseif (isset($session['step']) && $session['step'] == 'get_votes') {
    // CRITICAL: Extract just the last input (the vote count)
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
            
            $message = "═══════════════════\n";
            $message .= "VOTE SUMMARY\n";
            $message .= "═══════════════════\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: {$votes}\n";
            $message .= "Total: GHC {$totalAmount}\n";
            $message .= "═══════════════════\n\n";
            $message .= "1. Proceed to Pay GHC {$totalAmount}\n";
            $message .= "2. Cancel\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        } else {
            $message = "Invalid! Enter number between 1-1000:\n(Or enter 0 to go back)";
            $continueSession = true;
        }
    } else {
        $message = "Enter number of votes (1-1000):\n(Or enter 0 to go back)";
        $continueSession = true;
    }
}
// STEP 3: User is confirming payment
elseif (isset($session['step']) && $session['step'] == 'confirm_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $nominee = $session['nominee'];
        $votes = $session['pending_votes'];
        $totalAmount = $session['total_amount'];
        
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        $customerEmail = $msisdn . "@ussd.voter.com";
        
        $paymentUrl = initializePaystackPayment($totalAmount, $customerEmail, $reference);
        
        if ($paymentUrl) {
            // Store payment reference for callback
            $session['payment_ref'] = $reference;
            saveSession($sessionFile, $session);
            
            $message = "═══════════════════\n";
            $message .= "AUTHORIZATION REQUIRED\n";
            $message .= "═══════════════════\n\n";
            $message .= "Amount: GHC {$totalAmount}\n";
            $message .= "Nominee: {$nominee['name']}\n\n";
            $message .= "Click this link to pay:\n";
            $message .= "{$paymentUrl}\n\n";
            $message .= "After payment, your votes will\n";
            $message .= "be added automatically.\n";
            $message .= "═══════════════════\n\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            // Clean up session file
            @unlink($sessionFile);
        } else {
            $message = "Payment gateway error. Please try again later.\nEnter 0 to go back:";
            $continueSession = true;
            $session['step'] = 'welcome';
            saveSession($sessionFile, $session);
        }
    } 
    elseif ($lastInput == "2") {
        @unlink($sessionFile);
        $message = "Vote cancelled.\n\nEnter Nominee Code to vote:";
        $continueSession = true;
    }
    else {
        $message = "Choose:\n1. Pay GHC {$session['total_amount']}\n2. Cancel\n0. Main Menu";
        $continueSession = true;
    }
}
// STEP 1: User entering nominee code
else {
    // First input - just the code (no asterisks yet)
    $nomineeCode = strtoupper($userData);
    
    // Try both databases
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
        $message .= "(Or enter 0 to go back)";
        $continueSession = true;
    } else {
        $message = "Invalid code '{$nomineeCode}'!\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):\n(Or enter 0 to exit)";
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
