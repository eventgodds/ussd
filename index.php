<?php
header('Content-Type: application/json');

// ============ FIREBASE CONFIGURATION (TWO DATABASES) ============

// Database 1: Contestants (eventgodds-41e4f) - FS1 to FS5
$projectId1 = 'eventgodds-41e4f';
$firestoreUrl1 = "https://firestore.googleapis.com/v1/projects/{$projectId1}/databases/(default)/documents";

// Database 2: Award Nominees (eventgodds) - ACY, ATY, AOY, etc.
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

// ============ FUNCTION TO FETCH FROM AWARD_NOMINEES COLLECTION ============

function fetchAwardNomineeByCode($code) {
    global $firestoreUrl2;
    
    $code = strtoupper(trim($code));
    $url = $firestoreUrl2 . "/award_nominees";
    
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
            
            // Check if the code matches (case insensitive)
            $docCode = isset($fields['code']['stringValue']) ? strtoupper($fields['code']['stringValue']) : '';
            
            if ($docCode === $code) {
                // Get the nominee name (could be in 'name' or 'nomineeName' field)
                $nomineeName = '';
                if (isset($fields['name']['stringValue'])) {
                    $nomineeName = $fields['name']['stringValue'];
                } elseif (isset($fields['nomineeName']['stringValue'])) {
                    $nomineeName = $fields['nomineeName']['stringValue'];
                } elseif (isset($fields['title']['stringValue'])) {
                    $nomineeName = $fields['title']['stringValue'];
                } else {
                    $nomineeName = $code; // Fallback to code if no name found
                }
                
                return [
                    'found' => true,
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $nomineeName,
                    'awardCategory' => $fields['awardCategory']['stringValue'] ?? $fields['award']['stringValue'] ?? 'Award Category',
                    'votes' => intval($fields['votes']['integerValue'] ?? 0),
                    'voteAmount' => intval($fields['voteAmount']['integerValue'] ?? 1),
                    'database' => 'award_nominees',
                    'project' => 'eventgodds',
                    'collection' => 'award_nominees',
                    'firestoreUrl' => $firestoreUrl2
                ];
            }
        }
    }
    
    return ['found' => false];
}

// ============ FUNCTION TO FETCH FROM CONTESTANTS COLLECTION (FS1-FS5) ============

function fetchContestantByCode($code) {
    global $firestoreUrl1;
    
    $code = strtoupper(trim($code));
    $url = $firestoreUrl1 . "/contestants";
    
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
                strtoupper($fields['code']['stringValue']) === $code) {
                return [
                    'found' => true,
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? $code,
                    'votes' => intval($fields['votes']['integerValue'] ?? 0),
                    'voteAmount' => intval($fields['voteAmount']['integerValue'] ?? 1),
                    'database' => 'contestants',
                    'project' => 'eventgodds-41e4f',
                    'collection' => 'contestants',
                    'firestoreUrl' => $firestoreUrl1
                ];
            }
        }
    }
    
    return ['found' => false];
}

// ============ FUNCTION TO SEARCH BOTH DATABASES ============

function searchByCode($code) {
    // First check contestants (FS1-FS5)
    $result = fetchContestantByCode($code);
    if ($result['found']) {
        return $result;
    }
    
    // Then check award nominees
    $result = fetchAwardNomineeByCode($code);
    if ($result['found']) {
        return $result;
    }
    
    return ['found' => false];
}

// ============ FUNCTION TO UPDATE VOTES ============

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

// ============ FUNCTION TO CREATE PAYSTACK PAYMENT ============

function createPaystackPayment($msisdn, $amount, $reference, $callbackUrl) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    // Format phone number for Ghana (MTN MoMo)
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
            'code' => $_SESSION['pending_code'],
            'votes' => $_SESSION['pending_votes'],
            'type' => $_SESSION['pending_type']
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
            return [
                'url' => $result['data']['authorization_url'],
                'reference' => $reference
            ];
        }
    }
    
    error_log("Paystack Error: " . $response);
    return false;
}

// ============ FUNCTION TO VERIFY PAYMENT ============

function verifyPaystackPayment($reference) {
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

// ============ CHECK FOR PAYMENT CALLBACK ============

if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPaystackPayment($reference);
    
    if ($paymentData && $paymentData['status'] && $paymentData['data']['status'] == 'success') {
        $metadata = $paymentData['data']['metadata'];
        $code = $metadata['code'];
        $votes = intval($metadata['votes']);
        
        // Search for the item in both databases
        $item = searchByCode($code);
        
        if ($item['found']) {
            $newVotes = $item['votes'] + $votes;
            $updated = updateVotesInDatabase(
                $item['firestoreUrl'], 
                $item['collection'], 
                $item['id'], 
                $newVotes
            );
            
            if ($updated) {
                $log = date('Y-m-d H:i:s') . " | SUCCESS | Code: $code | Votes: $votes | New Total: $newVotes\n";
                file_put_contents('payment_success.log', $log, FILE_APPEND);
                
                echo "Payment successful! $votes votes added for {$item['name']}";
                exit;
            }
        }
    }
    
    echo "Payment verification failed!";
    exit;
}

// ============ USSD MAIN LOGIC ============

$message = "";
$continueSession = false;

// NEW SESSION - Welcome screen
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to Ghartey Event Voting!\n";
    $message .= "Enter voting code (e.g., FS1, ACY, ATY, AOY, BCY):";
    $continueSession = true;
    $_SESSION['step'] = 'awaiting_code';
}

// Step 2: User entered a code
elseif ($_SESSION['step'] == 'awaiting_code') {
    $code = strtoupper(trim($userData));
    $item = searchByCode($code);
    
    if ($item['found']) {
        $_SESSION['pending_id'] = $item['id'];
        $_SESSION['pending_code'] = $item['code'];
        $_SESSION['pending_name'] = $item['name'];
        $_SESSION['pending_voteAmount'] = $item['voteAmount'];
        $_SESSION['pending_currentVotes'] = $item['votes'];
        $_SESSION['pending_type'] = $item['database'];
        $_SESSION['pending_firestoreUrl'] = $item['firestoreUrl'];
        $_SESSION['pending_collection'] = $item['collection'];
        $_SESSION['step'] = 'awaiting_votes';
        
        $message = "Vote for: " . $item['name'] . "\n";
        $message .= "Code: " . $item['code'] . "\n";
        $message .= "Price: GHC " . $item['voteAmount'] . "/vote\n";
        $message .= "Current votes: " . $item['votes'] . "\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code! Please enter a valid voting code:\n";
        $message .= "Examples: FS1, FS2, ACY, ATY, AOY, BCY, etc.";
        $continueSession = true;
    }
}

// Step 3: User entered number of votes
elseif ($_SESSION['step'] == 'awaiting_votes') {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid! Enter votes between 1 and 1000:";
        $continueSession = true;
    } else {
        $_SESSION['pending_votes'] = $votes;
        $totalAmount = $votes * $_SESSION['pending_voteAmount'];
        
        $message = "=== VOTE SUMMARY ===\n";
        $message .= "Nominee: " . $_SESSION['pending_name'] . "\n";
        $message .= "Code: " . $_SESSION['pending_code'] . "\n";
        $message .= "Votes: " . $votes . "\n";
        $message .= "Cost: GHC " . $_SESSION['pending_voteAmount'] . "/vote\n";
        $message .= "Total: GHC " . $totalAmount . "\n";
        $message .= "==================\n\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_payment_choice';
    }
}

// Step 4: User chooses to proceed or cancel
elseif ($_SESSION['step'] == 'awaiting_payment_choice') {
    if ($userData == "1") {
        // Proceed with payment
        $totalAmount = $_SESSION['pending_votes'] * $_SESSION['pending_voteAmount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // IMPORTANT: Change this to your actual domain
        $callbackUrl = "https://yourdomain.com/ussd_handler.php";
        
        $payment = createPaystackPayment($msisdn, $totalAmount, $reference, $callbackUrl);
        
        if ($payment) {
            $_SESSION['payment_ref'] = $reference;
            
            $message = "💰 Payment Required: GHC " . $totalAmount . "\n\n";
            $message .= "You will receive a payment prompt on your mobile money.\n";
            $message .= "Enter your MoMo PIN to complete payment.\n\n";
            $message .= "Transaction ID: " . $reference . "\n\n";
            $message .= "After payment, votes will be added automatically.\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            // Log payment initiation
            $log = date('Y-m-d H:i:s') . " | INIT | MSISDN: $msisdn | Code: {$_SESSION['pending_code']} | Votes: {$_SESSION['pending_votes']} | Amount: GHC $totalAmount | Ref: $reference\n";
            file_put_contents('payment_log.txt', $log, FILE_APPEND);
        } else {
            $message = "Payment system error. Please try again later.";
            $continueSession = false;
        }
    } 
    elseif ($userData == "2") {
        // Cancel
        $message = "❌ Vote cancelled.\n\n";
        $message .= "Enter new voting code:";
        $continueSession = true;
        $_SESSION = [];
        $_SESSION['step'] = 'awaiting_code';
    }
    else {
        $message = "Invalid option!\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}

// Fallback - reset session
else {
    $_SESSION = [];
    $message = "Welcome to Ghartey Event Voting!\n";
    $message .= "Enter voting code (e.g., FS1, ACY, ATY, AOY, BCY):";
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
