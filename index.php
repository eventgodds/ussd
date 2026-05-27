<?php
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack configuration
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

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
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
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
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['nomineeCode']['stringValue'],
                    'name' => $fields['stageName']['stringValue'] ?? $fields['fullName']['stringValue'] ?? '',
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

// Function to update votes
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

// Function to initiate Paystack charge (direct USSD)
function initiatePaystackCharge($email, $amount, $reference, $phone, $metadata) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/charge";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100,
        'reference' => $reference,
        'metadata' => $metadata,
        'channels' => ['ussd'],  // Force USSD channel
        'ussd' => [
            'type' => '539', // MTN Ghana USSD code
            'callback_url' => 'https://yourdomain.com/callback.php'
        ]
    ];
    
    // Add mobile money details
    if ($phone) {
        $data['mobile_money'] = [
            'phone' => $phone,
            'provider' => 'mtn' // or 'vodafone', 'airteltigo'
        ];
    }
    
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
        return json_decode($response, true);
    }
    return false;
}

// USSD Logic
$message = "";
$continueSession = false;

if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, PG1, etc.):";
    $continueSession = true;
}
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'get_code') {
    $nomineeCode = strtoupper($userData);
    
    // Try both databases
    $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
    if (!$nominee) {
        $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
    }
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'get_votes';
        
        $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
        $message = "Vote for: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "GHC {$nominee['voteAmount']} per vote\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code! Try FS1, FS2, PG1, etc.:";
        $continueSession = true;
    }
}
elseif ($_SESSION['step'] == 'get_votes' && is_numeric($userData)) {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Enter valid number (1-1000):";
        $continueSession = true;
    } else {
        $nominee = $_SESSION['nominee'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['total_amount'] = $totalAmount;
        $_SESSION['step'] = 'confirm_payment';
        
        $message = "Vote Summary:\n";
        $message .= "Nominee: {$nominee['name']}\n";
        $message .= "Votes: $votes\n";
        $message .= "Total: GHC $totalAmount\n\n";
        $message .= "1. Proceed to Pay (Mobile Money)\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}
elseif ($_SESSION['step'] == 'confirm_payment' && $userData == "1") {
    $nominee = $_SESSION['nominee'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $_SESSION['total_amount'];
    
    // Generate unique reference
    $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
    $email = $msisdn . "@ussd.voter.com";
    
    // Format phone number (remove +233 or 0 prefix)
    $phone = $msisdn;
    if (substr($phone, 0, 1) == '0') {
        $phone = '233' . substr($phone, 1);
    } elseif (substr($phone, 0, 4) == '+233') {
        $phone = '233' . substr($phone, 4);
    }
    
    $metadata = [
        'msisdn' => $msisdn,
        'nominee_code' => $nominee['code'],
        'nominee_name' => $nominee['name'],
        'votes' => $votes,
        'type' => $nominee['type']
    ];
    
    // Initiate Paystack charge
    $result = initiatePaystackCharge($email, $totalAmount, $reference, $phone, $metadata);
    
    if ($result && $result['status']) {
        if (isset($result['data']['display_text'])) {
            // USSD prompt from Paystack
            $message = $result['data']['display_text'] . "\n";
            $message .= "Enter your PIN to complete payment.";
            $continueSession = true;
            $_SESSION['payment_reference'] = $reference;
            $_SESSION['step'] = 'process_payment';
        } else {
            $message = "Follow the prompt on your screen to complete payment.\n";
            $message .= "Enter *170# to pay with Mobile Money.\n";
            $message .= "Reference: $reference\n";
            $message .= "After payment, call this code again to confirm.";
            $continueSession = false;
        }
    } else {
        $message = "Payment error. Please try again later.";
        $continueSession = false;
        unset($_SESSION['step']);
    }
}
elseif ($_SESSION['step'] == 'process_payment' && isset($_SESSION['payment_reference'])) {
    // Check payment status
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/{$_SESSION['payment_reference']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && $result['status'] && $result['data']['status'] == 'success') {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $newVotes = $nominee['votes'] + $votes;
        
        // Update votes in appropriate database
        if ($nominee['type'] == 'contestant') {
            updateVotes($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
        } else {
            updateVotes($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
        }
        
        $message = "✓ PAYMENT SUCCESSFUL!\n";
        $message .= "$votes votes added for {$nominee['name']}\n";
        $message .= "Total votes: $newVotes\n";
        $message .= "Thank you for voting!";
        $continueSession = false;
        
        // Clear session
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
        unset($_SESSION['payment_reference']);
    } else {
        $message = "Payment pending or failed.\n";
        $message .= "Dial *170# to complete payment.\n";
        $message .= "Reference: {$_SESSION['payment_reference']}\n";
        $message .= "Call again to continue.";
        $continueSession = false;
    }
}
elseif ($userData == "2") {
    $message = "Vote cancelled.\nEnter Nominee Code to vote:";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
}
else {
    $message = "Enter Nominee Code (FS1, PG1, etc.):";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
}

echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
