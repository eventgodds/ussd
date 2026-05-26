<?php
header('Content-Type: application/json');

// ========== TWO FIREBASE DATABASES CONFIGURATION ==========
// Database 1: Contestants (eventgodds-41e4f)
$projectId1 = 'eventgodds-41e4f';
$firestoreUrl1 = "https://firestore.googleapis.com/v1/projects/{$projectId1}/databases/(default)/documents";

// Database 2: Award Nominees (eventgodds)
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

// ========== FUNCTION TO FETCH FROM ANY COLLECTION IN ANY DATABASE ==========
function fetchByCodeFromDatabase($firestoreUrl, $collection, $code) {
    $url = $firestoreUrl . "/" . $collection;
    
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
            $docCode = $fields['code']['stringValue'] ?? '';
            if (strtoupper($docCode) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? $fields['nomineeName']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'collection' => $collection,
                    'database' => $firestoreUrl
                ];
            }
        }
    }
    
    return null;
}

// ========== FUNCTION TO SEARCH BOTH DATABASES ==========
function searchBothDatabases($code, $firestoreUrl1, $firestoreUrl2) {
    // First search in contestants (Database 1)
    $result = fetchByCodeFromDatabase($firestoreUrl1, 'contestants', $code);
    if ($result) {
        $result['type'] = 'contestant';
        $result['projectId'] = 'eventgodds-41e4f';
        return $result;
    }
    
    // Then search in award nominees (Database 2)
    $result = fetchByCodeFromDatabase($firestoreUrl2, 'award_nominees', $code);
    if ($result) {
        $result['type'] = 'award';
        $result['projectId'] = 'eventgodds';
        return $result;
    }
    
    return null;
}

// ========== FUNCTION TO UPDATE VOTES ==========
function updateVotesInDatabase($firestoreUrl, $collection, $documentId, $newVotes) {
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

// ========== FUNCTION TO CREATE PAYSTACK MOMO PAYMENT ==========
function createMobileMoneyPayment($msisdn, $amount, $reference, $callbackUrl) {
    global $paystackSecretKey;
    
    // Format phone number for Ghana (MTN, Vodafone, AirtelTigo)
    $phone = preg_replace('/^\+?233/', '0', $msisdn);
    $phone = preg_replace('/^0/', '233', $phone);
    
    // Detect mobile money provider based on prefix
    $provider = 'mtn'; // default
    if (substr($phone, 0, 4) == '2332') $provider = 'vodafone';
    if (substr($phone, 0, 4) == '2335') $provider = 'mtn';
    if (substr($phone, 0, 4) == '2334') $provider = 'airteltigo';
    
    $data = [
        'amount' => $amount * 100, // Convert to pesewas
        'email' => $msisdn . '@ussd.voter.com',
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'channels' => ['mobile_money'],
        'mobile_money' => [
            'phone' => $phone,
            'provider' => $provider
        ],
        'metadata' => [
            'msisdn' => $msisdn,
            'code' => $_SESSION['pending_code'],
            'votes' => $_SESSION['pending_votes'],
            'type' => $_SESSION['pending_type'],
            'name' => $_SESSION['pending_name']
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/initialize");
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

// ========== USSD MENU LOGIC ==========
$message = "";
$continueSession = false;

// NEW SESSION - Welcome message
if ($newSession == true) {
    $_SESSION = []; // Clear all session data
    $_SESSION['step'] = 'awaiting_code';
    
    $message = "Welcome to Ghartey Event Voting!\n";
    $message .= "Enter Contestant Code (FS1-FS5) or Award Code:";
    $continueSession = true;
}
// STEP 2: User entered number of votes
elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'awaiting_votes') {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid! Enter votes (1-1000):";
        $continueSession = true;
    } else {
        $_SESSION['pending_votes'] = $votes;
        $totalAmount = $votes * $_SESSION['pending_voteAmount'];
        
        $message = "Vote Summary:\n";
        $message .= "________________\n";
        $message .= "Nominee: " . $_SESSION['pending_name'] . "\n";
        $message .= "Code: " . $_SESSION['pending_code'] . "\n";
        $message .= "Votes: " . $votes . "\n";
        $message .= "Rate: GHC " . $_SESSION['pending_voteAmount'] . "/vote\n";
        $message .= "Total: GHC " . $totalAmount . "\n";
        $message .= "________________\n\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_payment_choice';
    }
}
// STEP 3: User chooses payment option
elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'awaiting_payment_choice') {
    if ($userData == "1") {
        // Process payment
        $totalAmount = $_SESSION['pending_votes'] * $_SESSION['pending_voteAmount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        $callbackUrl = "https://yourdomain.com/payment_callback.php"; // CHANGE THIS
        
        $paymentUrl = createMobileMoneyPayment($msisdn, $totalAmount, $reference, $callbackUrl);
        
        if ($paymentUrl) {
            $_SESSION['payment_ref'] = $reference;
            
            $message = "✓ Payment initiated!\n";
            $message .= "Amount: GHC " . $totalAmount . "\n";
            $message .= "Check your phone for payment prompt.\n\n";
            $message .= "Enter your Mobile Money PIN to complete.\n";
            $message .= "After payment, votes will be added automatically.\n\n";
            $message .= "Thank you for voting!";
            $continueSession = false; // End session, wait for callback
            
            // Log payment initiation
            $log = date('Y-m-d H:i:s') . " | PAYMENT | MSISDN: $msisdn | Ref: $reference | Code: {$_SESSION['pending_code']} | Votes: {$_SESSION['pending_votes']} | Amount: GHC $totalAmount\n";
            file_put_contents('payment_log.txt', $log, FILE_APPEND);
        } else {
            $message = "Payment error. Please try again.\n";
            $message .= "Enter code to retry:";
            $continueSession = true;
            $_SESSION['step'] = 'awaiting_code';
        }
    } 
    elseif ($userData == "2") {
        // Cancel
        $message = "Vote cancelled.\n\n";
        $message .= "Enter new code to vote:";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_code';
        unset($_SESSION['pending_code']);
    }
    elseif ($userData == "0") {
        // Back to main menu
        $message = "Welcome to Ghartey Event Voting!\n";
        $message .= "Enter Contestant Code (FS1-FS5) or Award Code:";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_code';
    }
    else {
        $message = "Invalid option!\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel\n";
        $message .= "0. Main Menu";
        $continueSession = true;
    }
}
// STEP 1: User enters a code (FS1-FS5 or award code)
elseif ($_SESSION['step'] == 'awaiting_code') {
    $code = strtoupper(trim($userData));
    
    // Search in both databases
    $item = searchBothDatabases($code, $firestoreUrl1, $firestoreUrl2);
    
    if ($item) {
        // Store in session
        $_SESSION['pending_id'] = $item['id'];
        $_SESSION['pending_code'] = $item['code'];
        $_SESSION['pending_name'] = $item['name'];
        $_SESSION['pending_voteAmount'] = $item['voteAmount'];
        $_SESSION['pending_currentVotes'] = $item['votes'];
        $_SESSION['pending_type'] = $item['type'];
        $_SESSION['pending_collection'] = $item['collection'];
        $_SESSION['pending_database'] = $item['database'];
        $_SESSION['step'] = 'awaiting_votes';
        
        $message = "✓ Found: " . $item['name'] . "\n";
        $message .= "Code: " . $item['code'] . "\n";
        $message .= "Category: " . ucfirst($item['type']) . "\n";
        $message .= "Price: GHC " . $item['voteAmount'] . "/vote\n";
        $message .= "Current votes: " . number_format($item['votes']) . "\n";
        $message .= "________________\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code! '$code' not found.\n\n";
        $message .= "Valid codes:\n";
        $message .= "• Contestants: FS1, FS2, FS3, FS4, FS5\n";
        $message .= "• Award codes from award_nominees\n\n";
        $message .= "Enter valid code:";
        $continueSession = true;
    }
}
// Fallback - reset session
else {
    $_SESSION = [];
    $_SESSION['step'] = 'awaiting_code';
    $message = "Session reset. Enter code to vote:";
    $continueSession = true;
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
