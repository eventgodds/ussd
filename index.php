<?php
header('Content-Type: application/json');

// Firebase Firestore REST API configuration
$projectId = 'eventgodds';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

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

// Function to fetch all contestants from Firestore
function fetchAllContestants($firestoreUrl) {
    $url = $firestoreUrl . "/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    $contestants = [];
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && 
                preg_match('/^FS[1-5]$/', $fields['code']['stringValue'])) {
                $contestants[] = [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'category' => 'contestant'
                ];
            }
        }
    }
    
    return $contestants;
}

// Function to fetch award nominees from Firestore
function fetchAwardNominees($firestoreUrl) {
    $url = $firestoreUrl . "/award_nominees";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    $nominees = [];
    
    if (isset($data['documents']) && !empty($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            $nominees[] = [
                'id' => basename($doc['name']),
                'code' => $fields['code']['stringValue'] ?? '',
                'name' => $fields['name']['stringValue'] ?? $fields['nomineeName']['stringValue'] ?? '',
                'award' => $fields['award']['stringValue'] ?? $fields['awardCategory']['stringValue'] ?? '',
                'votes' => $fields['votes']['integerValue'] ?? 0,
                'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                'category' => 'award'
            ];
        }
    }
    
    return $nominees;
}

// Function to fetch specific item by code
function fetchByCode($firestoreUrl, $code, $type = 'contestant') {
    $collection = ($type == 'contestant') ? 'contestants' : 'award_nominees';
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
                    'type' => $type
                ];
            }
        }
    }
    
    return null;
}

// Function to update votes
function updateVotes($firestoreUrl, $documentId, $newVotes, $collection) {
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

// Function to create Paystack payment link for Mobile Money
function createPaystackPayment($msisdn, $amount, $reference, $callbackUrl) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    // Format phone number (remove + or 0 prefix)
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
            'provider' => 'mtn' // or 'vodafone', 'airteltigo'
        ],
        'metadata' => [
            'msisdn' => $msisdn,
            'contestant_code' => $_SESSION['pending_code'],
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
    
    return false;
}

// Function to verify payment
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
    
    return json_decode($response, true);
}

// USSD Menu Flow
$message = "";
$continueSession = false;

// Check if this is a new session
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to Ghartey Event Voting\n";
    $message .= "Enter contestant code (FS1-FS5) or award code:";
    $continueSession = true;
}
// User entered a code (FS1-FS5 or any award code)
elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'awaiting_votes') {
    // Process votes
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid number! Enter votes (1-1000):";
        $continueSession = true;
    } else {
        $_SESSION['pending_votes'] = $votes;
        $totalAmount = $votes * $_SESSION['pending_voteAmount'];
        
        $message = "Vote Summary:\n";
        $message .= "Nominee: " . $_SESSION['pending_name'] . "\n";
        $message .= "Code: " . $_SESSION['pending_code'] . "\n";
        $message .= "Votes: " . $votes . "\n";
        $message .= "Total: GHC " . $totalAmount . "\n\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
        $_SESSION['step'] = 'awaiting_payment_choice';
    }
}
elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'awaiting_payment_choice') {
    if ($userData == "1") {
        // Proceed with payment
        $totalAmount = $_SESSION['pending_votes'] * $_SESSION['pending_voteAmount'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        $callbackUrl = "https://yourdomain.com/ussd_callback.php"; // CHANGE THIS
        
        $payment = createPaystackPayment($msisdn, $totalAmount, $reference, $callbackUrl);
        
        if ($payment) {
            $_SESSION['payment_ref'] = $reference;
            
            $message = "Complete payment on your mobile money:\n";
            $message .= "Amount: GHC " . $totalAmount . "\n";
            $message .= "You'll receive a payment prompt on your phone.\n";
            $message .= "Enter your PIN to confirm.\n\n";
            $message .= "After payment, your votes will be added.\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            // Log payment initiation
            $log = date('Y-m-d H:i:s') . " | PAYMENT INIT | MSISDN: $msisdn | Ref: $reference | Amount: GHC $totalAmount\n";
            file_put_contents('payments.log', $log, FILE_APPEND);
        } else {
            $message = "Payment system error. Try again later.";
            $continueSession = false;
        }
    } 
    elseif ($userData == "2") {
        // Cancel
        $message = "Vote cancelled. Enter new code:";
        $continueSession = true;
        $_SESSION = [];
    }
    else {
        $message = "Invalid option. Choose 1 to pay or 2 to cancel:";
        $continueSession = true;
    }
}
// First step - get the code
else {
    $code = strtoupper(trim($userData));
    
    // Try to find in contestants first
    $item = fetchByCode($firestoreUrl, $code, 'contestant');
    $type = 'contestant';
    
    if (!$item) {
        // Try award nominees
        $item = fetchByCode($firestoreUrl, $code, 'award');
        $type = 'award';
    }
    
    if ($item) {
        $_SESSION['pending_id'] = $item['id'];
        $_SESSION['pending_code'] = $item['code'];
        $_SESSION['pending_name'] = $item['name'];
        $_SESSION['pending_voteAmount'] = $item['voteAmount'];
        $_SESSION['pending_currentVotes'] = $item['votes'];
        $_SESSION['pending_type'] = $type;
        $_SESSION['step'] = 'awaiting_votes';
        
        $message = "Vote for: " . $item['name'] . "\n";
        $message .= "Code: " . $item['code'] . "\n";
        $message .= "Price: GHC " . $item['voteAmount'] . "/vote\n";
        $message .= "Current votes: " . $item['votes'] . "\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code! Try FS1-FS5 or valid award code.\n";
        $message .= "Enter code:";
        $continueSession = true;
    }
}

// Send response
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);

?>
