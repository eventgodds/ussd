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

// ============ FUNCTION: Fetch Contestant from Database 1 (eventgodds-41e4f) ============
function fetchContestant($code) {
    global $firestoreUrl1;
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
            $docCode = $fields['code']['stringValue'] ?? '';
            
            if (strtoupper($docCode) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'fullName' => $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'type' => 'contestant',
                    'database' => 'eventgodds-41e4f'
                ];
            }
        }
    }
    return null;
}

// ============ FUNCTION: Fetch Award Nominee from Database 2 (eventgodds) ============
function fetchAwardNominee($code) {
    global $firestoreUrl2;
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
            
            // Check by nomineeCode
            $docCode = $fields['nomineeCode']['stringValue'] ?? '';
            
            if (strtoupper($docCode) === strtoupper($code)) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['stageName']['stringValue'] ?? $fields['fullName']['stringValue'] ?? '',
                    'fullName' => $fields['fullName']['stringValue'] ?? '',
                    'categoryName' => $fields['categoryName']['stringValue'] ?? '',
                    'categoryCode' => $fields['categoryCode']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => 1, // Default vote amount for awards
                    'type' => 'award',
                    'database' => 'eventgodds'
                ];
            }
        }
    }
    return null;
}

// ============ FUNCTION: Update Votes in Respective Database ============
function updateVotes($item, $newVotes) {
    global $firestoreUrl1, $firestoreUrl2;
    
    // Choose correct database URL
    $url = ($item['database'] == 'eventgodds-41e4f') ? $firestoreUrl1 : $firestoreUrl2;
    
    // Choose correct collection name
    $collection = ($item['type'] == 'contestant') ? 'contestants' : 'award_nominees';
    
    $updateUrl = $url . "/{$collection}/{$item['id']}?updateMask.fieldPaths=votes";
    
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
    
    // Clean phone number for Ghana (MTN, Vodafone, AirtelTigo)
    $phone = preg_replace('/^\+/', '', $msisdn);
    $phone = preg_replace('/^0/', '233', $phone);
    if (substr($phone, 0, 3) != '233') {
        $phone = '233' . ltrim($phone, '0');
    }
    
    // Detect mobile money provider based on prefix
    $provider = 'mtn'; // default
    if (substr($phone, 3, 2) == '20') $provider = 'vodafone';
    if (substr($phone, 3, 2) == '55') $provider = 'mtn';
    if (substr($phone, 3, 2) == '54') $provider = 'mtn';
    if (substr($phone, 3, 2) == '59') $provider = 'mtn';
    if (substr($phone, 3, 2) == '50') $provider = 'vodafone';
    
    $data = [
        'amount' => $amount * 100,
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
            'database' => $_SESSION['pending_database']
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

// ============ CHECK FOR PAYMENT CALLBACK ============
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPaystackPayment($reference);
    
    if ($paymentData && $paymentData['status'] && $paymentData['data']['status'] == 'success') {
        $metadata = $paymentData['data']['metadata'];
        $code = $metadata['code'];
        $votes = intval($metadata['votes']);
        $type = $metadata['type'];
        
        // Fetch current data again
        if ($type == 'contestant') {
            $item = fetchContestant($code);
        } else {
            $item = fetchAwardNominee($code);
        }
        
        if ($item) {
            $newVotes = $item['votes'] + $votes;
            $updated = updateVotes($item, $newVotes);
            
            if ($updated) {
                $log = date('Y-m-d H:i:s') . " | SUCCESS | Ref: $reference | Code: $code | Type: $type | Votes: $votes | New Total: $newVotes\n";
                file_put_contents('payment_success.log', $log, FILE_APPEND);
                
                echo "Payment successful! {$votes} votes added for {$item['name']}";
                exit;
            }
        }
    }
    
    echo "Payment verification failed!";
    exit;
}

// ============ USSD FLOW LOGIC ============
$message = "";
$continueSession = false;

// NEW SESSION - Welcome menu
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to Ghartey Event Voting\n";
    $message .= "Enter Nominee Code:\n";
    $message .= "Eg: FS1, FS2, PG1, BG1, etc.";
    $continueSession = true;
}
// Step 2: User entered a code - search both databases
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'awaiting_code') {
    $code = strtoupper(trim($userData));
    
    // First, try Database 1: Contestants (FS1-FS5)
    $item = fetchContestant($code);
    
    // If not found, try Database 2: Award Nominees (PG1, BG1, etc.)
    if (!$item) {
        $item = fetchAwardNominee($code);
    }
    
    if ($item) {
        // Store in session
        $_SESSION['pending_id'] = $item['id'];
        $_SESSION['pending_code'] = $item['code'];
        $_SESSION['pending_name'] = $item['name'];
        $_SESSION['pending_fullName'] = $item['fullName'] ?? $item['name'];
        $_SESSION['pending_category'] = $item['categoryName'] ?? 'Contestant';
        $_SESSION['pending_voteAmount'] = $item['voteAmount'];
        $_SESSION['pending_currentVotes'] = $item['votes'];
        $_SESSION['pending_type'] = $item['type'];
        $_SESSION['pending_database'] = $item['database'];
        $_SESSION['step'] = 'awaiting_votes';
        
        // Build display message
        $message = "📌 VOTE FOR:\n";
        $message .= "Name: " . $item['name'] . "\n";
        if (isset($item['categoryName'])) {
            $message .= "Category: " . $item['categoryName'] . "\n";
        }
        $message .= "Code: " . $item['code'] . "\n";
        $message .= "Price: GHC " . $item['voteAmount'] . "/vote\n";
        $message .= "Current votes: " . number_format($item['votes']) . "\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid Code!\n\n";
        $message .= "Valid codes:\n";
        $message .= "• FS1, FS2, FS3, FS4, FS5 (Contestants)\n";
        $message .= "• PG1, PG2, BG1, etc. (Award Nominees)\n\n";
        $message .= "Enter Nominee Code:";
        $continueSession = true;
    }
}
// Step 3: User entered number of votes
elseif ($_SESSION['step'] == 'awaiting_votes') {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Enter votes (1-1000):";
        $continueSession = true;
    } else {
        $_SESSION['pending_votes'] = $votes;
        $totalAmount = $votes * $_SESSION['pending_voteAmount'];
        
        $message = "═══════════════════\n";
        $message = "    VOTE SUMMARY    \n";
        $message = "═══════════════════\n";
        $message = "Nominee: " . $_SESSION['pending_name'] . "\n";
        if ($_SESSION['pending_type'] == 'award') {
            $message .= "Category: " . $_SESSION['pending_category'] . "\n";
        }
        $message .= "Code: " . $_SESSION['pending_code'] . "\n";
        $message .= "Votes: " . number_format($votes) . "\n";
        $message .= "Amount: GHC " . number_format($totalAmount, 2) . "\n";
        $message .= "═══════════════════\n\n";
        $message .= "1. 💳 Proceed to Pay\n";
        $message .= "2. ❌ Cancel";
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
            
            $message = "💰 PAYMENT REQUIRED\n";
            $message = "═══════════════════\n";
            $message = "Amount: GHC " . number_format($totalAmount, 2) . "\n";
            $message = "Reference: " . $reference . "\n";
            $message = "═══════════════════\n\n";
            $message = "📱 Check your phone for Mobile Money prompt.\n";
            $message .= "Enter your PIN to authorize payment.\n\n";
            $message .= "✅ After payment, votes will be added automatically.\n";
            $message .= "🙏 Thank you for voting!";
            $continueSession = false;
            
            // Log payment initiation
            $log = date('Y-m-d H:i:s') . " | INIT | MSISDN: $msisdn | Ref: $reference | Amount: GHC $totalAmount | Code: " . $_SESSION['pending_code'] . " | Type: " . $_SESSION['pending_type'] . "\n";
            file_put_contents('payment_log.txt', $log, FILE_APPEND);
        } else {
            $message = "⚠️ Payment system error. Please try again later.";
            $continueSession = false;
            $_SESSION = [];
        }
    } 
    elseif ($userData == "2") {
        // Cancel
        $message = "❌ Vote cancelled.\n\nThank you for using Ghartey Event Voting.\nEnter new Nominee Code:";
        $continueSession = true;
        $_SESSION = [];
    }
    else {
        $message = "❌ Invalid option!\n\n1. Proceed to Pay\n2. Cancel";
        $continueSession = true;
    }
}
// Fallback - reset session
else {
    $message = "Welcome to Ghartey Event Voting\nEnter Nominee Code (FS1, PG1, etc.):";
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
