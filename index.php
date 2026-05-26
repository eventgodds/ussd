<?php
header('Content-Type: application/json');

// ============ CONFIGURATION ============
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

// ============ FUNCTION: Fetch from ANY Firestore database ============
function fetchFromFirestore($url, $collection, $code = null) {
    $fullUrl = $url . "/" . $collection;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        return null;
    }
    
    $data = json_decode($response, true);
    $results = [];
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            
            // Extract code from fields (handles different field names)
            $docCode = '';
            if (isset($fields['code']['stringValue'])) {
                $docCode = $fields['code']['stringValue'];
            } elseif (isset($fields['nomineeCode']['stringValue'])) {
                $docCode = $fields['nomineeCode']['stringValue'];
            } elseif (isset($fields['accessCode']['stringValue'])) {
                $docCode = $fields['accessCode']['stringValue'];
            }
            
            // Extract name
            $docName = '';
            if (isset($fields['stageName']['stringValue'])) {
                $docName = $fields['stageName']['stringValue'];
            } elseif (isset($fields['name']['stringValue'])) {
                $docName = $fields['name']['stringValue'];
            } elseif (isset($fields['nomineeName']['stringValue'])) {
                $docName = $fields['nomineeName']['stringValue'];
            }
            
            // Extract votes
            $docVotes = 0;
            if (isset($fields['votes']['integerValue'])) {
                $docVotes = $fields['votes']['integerValue'];
            }
            
            // Extract vote amount
            $voteAmount = 1;
            if (isset($fields['voteAmount']['integerValue'])) {
                $voteAmount = $fields['voteAmount']['integerValue'];
            }
            
            $item = [
                'id' => basename($doc['name']),
                'code' => $docCode,
                'name' => $docName,
                'votes' => $docVotes,
                'voteAmount' => $voteAmount,
                'collection' => $collection
            ];
            
            // If searching for specific code
            if ($code !== null) {
                if (strtoupper($docCode) === strtoupper($code)) {
                    return $item;
                }
            } else {
                // Return all items with valid codes
                if (!empty($docCode)) {
                    $results[] = $item;
                }
            }
        }
    }
    
    return ($code === null) ? $results : null;
}

// ============ FUNCTION: Update votes in Firestore ============
function updateVotesInFirestore($url, $collection, $documentId, $newVotes) {
    $updateUrl = $url . "/{$collection}/{$documentId}?updateMask.fieldPaths=votes";
    
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

// ============ FUNCTION: Create Paystack Mobile Money Payment ============
function createPaystackPayment($msisdn, $amount, $reference, $callbackUrl) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    // Clean phone number for Ghana
    $phone = preg_replace('/^\+/', '', $msisdn);
    $phone = preg_replace('/^0/', '233', $phone);
    if (substr($phone, 0, 3) != '233') {
        $phone = '233' . ltrim($phone, '0');
    }
    
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
            'collection' => $_SESSION['pending_collection'],
            'firestore_url' => $_SESSION['pending_firestore_url']
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
    
    error_log("Paystack Error: " . $response);
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
        'Authorization: Bearer ' . $paystackSecretKey
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// ============ USSD FLOW LOGIC ============
$message = "";
$continueSession = false;

// Check if this is payment callback
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPaystackPayment($reference);
    
    if ($paymentData && $paymentData['status'] && $paymentData['data']['status'] == 'success') {
        $metadata = $paymentData['data']['metadata'];
        $code = $metadata['code'];
        $votes = intval($metadata['votes']);
        $collection = $metadata['collection'];
        $firestoreUrl = $metadata['firestore_url'];
        
        // Fetch current data
        $item = fetchFromFirestore($firestoreUrl, $collection, $code);
        
        if ($item) {
            $newVotes = $item['votes'] + $votes;
            $updated = updateVotesInFirestore($firestoreUrl, $collection, $item['id'], $newVotes);
            
            if ($updated) {
                // Log success
                $log = date('Y-m-d H:i:s') . " | SUCCESS | Ref: $reference | Code: $code | Votes: $votes\n";
                file_put_contents('payment_success.log', $log, FILE_APPEND);
                
                echo "Payment successful! {$votes} votes added for {$item['name']}";
                exit;
            }
        }
    }
    
    echo "Payment verification failed!";
    exit;
}

// NEW SESSION - Welcome menu
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to Ghartey Event Voting\n";
    $message .= "Enter Nominee Code (FS1-FS5 or Award Code):";
    $continueSession = true;
}
// Step 2: User entered a code - fetch from databases
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'awaiting_code') {
    $code = strtoupper(trim($userData));
    
    // Try Database 1: Contestants (eventgodds-41e4f)
    $item = fetchFromFirestore($firestoreUrl1, 'contestants', $code);
    $usedUrl = $firestoreUrl1;
    $usedCollection = 'contestants';
    
    // If not found, try Database 2: Award Nominees (eventgodds)
    if (!$item) {
        $item = fetchFromFirestore($firestoreUrl2, 'award_nominees', $code);
        $usedUrl = $firestoreUrl2;
        $usedCollection = 'award_nominees';
    }
    
    if ($item && !empty($item['code'])) {
        // Store in session
        $_SESSION['pending_id'] = $item['id'];
        $_SESSION['pending_code'] = $item['code'];
        $_SESSION['pending_name'] = $item['name'];
        $_SESSION['pending_voteAmount'] = $item['voteAmount'];
        $_SESSION['pending_currentVotes'] = $item['votes'];
        $_SESSION['pending_collection'] = $usedCollection;
        $_SESSION['pending_firestore_url'] = $usedUrl;
        $_SESSION['step'] = 'awaiting_votes';
        
        $message = "Vote for: " . $item['name'] . "\n";
        $message .= "Code: " . $item['code'] . "\n";
        $message .= "Price: GHC " . $item['voteAmount'] . "/vote\n";
        $message .= "Current votes: " . $item['votes'] . "\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid Code!\n";
        $message .= "Valid codes: FS1-FS5 or Award Codes\n\n";
        $message .= "Enter Nominee Code:";
        $continueSession = true;
    }
}
// Step 3: User entered number of votes
elseif ($_SESSION['step'] == 'awaiting_votes') {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid! Enter votes (1-1000):";
        $continueSession = true;
    } else {
        $_SESSION['pending_votes'] = $votes;
        $totalAmount = $votes * $_SESSION['pending_voteAmount'];
        
        $message = "=== VOTE SUMMARY ===\n";
        $message .= "Nominee: " . $_SESSION['pending_name'] . "\n";
        $message .= "Code: " . $_SESSION['pending_code'] . "\n";
        $message .= "Votes: " . $votes . "\n";
        $message .= "Amount: GHC " . $totalAmount . "\n";
        $message .= "==================\n\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_payment_choice';
    }
}
// Step 4: User chose to proceed or cancel
elseif ($_SESSION['step'] == 'awaiting_payment_choice') {
    if ($userData == "1") {
        // Proceed with payment
        $totalAmount = $_SESSION['pending_votes'] * $_SESSION['pending_voteAmount'];
        $reference = "VOTE_" . time() . "_" . rand(10000, 99999);
        
        // Get current server URL for callback
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $callbackUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/ussd_handler.php';
        
        $paymentUrl = createPaystackPayment($msisdn, $totalAmount, $reference, $callbackUrl);
        
        if ($paymentUrl) {
            $_SESSION['payment_ref'] = $reference;
            
            $message = "💰 Payment Required: GHC " . $totalAmount . "\n\n";
            $message .= "Check your phone for Mobile Money prompt.\n";
            $message .= "Enter your PIN to authorize payment.\n\n";
            $message .= "After payment, votes will be added automatically.\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            // Log payment initiation
            $log = date('Y-m-d H:i:s') . " | INIT | MSISDN: $msisdn | Ref: $reference | Amount: GHC $totalAmount | Code: " . $_SESSION['pending_code'] . "\n";
            file_put_contents('payment_log.txt', $log, FILE_APPEND);
        } else {
            $message = "⚠️ Payment system error. Please try again later.";
            $continueSession = false;
            $_SESSION = [];
        }
    } 
    elseif ($userData == "2") {
        // Cancel
        $message = "❌ Vote cancelled.\n\nEnter new Nominee Code:";
        $continueSession = true;
        $_SESSION = [];
    }
    else {
        $message = "Invalid option!\n1. Proceed to Pay\n2. Cancel";
        $continueSession = true;
    }
}
// Fallback
else {
    $message = "Welcome to Ghartey Event Voting\nEnter Nominee Code:";
    $continueSession = true;
    $_SESSION = [];
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
