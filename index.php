<?php
header('Content-Type: application/json');

// Firebase Firestore REST API configuration
$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

$projectId = 'eventgodds';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

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

// Function to fetch contestant by code from Firestore
function fetchContestantByCode($firestoreUrl, $code) {
    $url = $firestoreUrl . "/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
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
                    'engagement' => $fields['engagement']['integerValue'] ?? 0
                ];
            }
        }
    }
    
    return null;
}

// Function to update votes in Firestore
function updateContestantVotes($firestoreUrl, $documentId, $newVotes) {
    $updateUrl = $firestoreUrl . "/contestants/{$documentId}?updateMask.fieldPaths=votes";
    
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Function to create Paystack payment link
function createPaystackPayment($email, $amount, $reference, $callbackUrl) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100, // Paystack uses kobo (GHS 1 = 100 kobo)
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'metadata' => [
            'msisdn' => $_SESSION['msisdn'] ?? '',
            'contestant_code' => $_SESSION['pending_contestant']['code'] ?? '',
            'votes' => $_SESSION['pending_votes'] ?? 0
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

// Function to verify Paystack payment
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

// Default values
$message = "";
$continueSession = false;

// Check for payment callback (from Paystack)
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPaystackPayment($reference);
    
    if ($paymentData) {
        $metadata = $paymentData['metadata'];
        $contestantCode = $metadata['contestant_code'];
        $votes = intval($metadata['votes']);
        
        // Fetch current contestant data
        $contestant = fetchContestantByCode($firestoreUrl, $contestantCode);
        
        if ($contestant) {
            $newVotes = $contestant['votes'] + $votes;
            updateContestantVotes($firestoreUrl, $contestant['id'], $newVotes);
            
            // Log successful payment
            $logEntry = date('Y-m-d H:i:s') . " | PAYMENT SUCCESS | Ref: {$reference} | Contestant: {$contestantCode} | Votes: {$votes} | Amount: GHS " . ($votes * $contestant['voteAmount']) . "\n";
            file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
            
            echo "Payment successful! {$votes} votes added for {$contestant['stageName']}";
            exit;
        }
    } else {
        echo "Payment verification failed!";
        exit;
    }
}

// USSD Menu Logic
$message = "";
$continueSession = false;

// MAIN WELCOME (First time)
if ($newSession == true) {
    $_SESSION = []; // Clear session
    $message = "Welcome to Ghartey Event Voting\n";
    $message .= "Enter Contestant Code:";
    $continueSession = true;
}

// Check if user entered a contestant code (FS1-FS5)
elseif (preg_match('/^FS[1-5]$/i', $userData)) {
    $contestantCode = strtoupper($userData);
    $contestant = fetchContestantByCode($firestoreUrl, $contestantCode);
    
    if ($contestant) {
        $_SESSION['selected_contestant'] = $contestant;
        
        $message = "Vote for " . $contestant['stageName'] . "\n";
        $message .= "Contestant Code: " . $contestant['code'] . "\n";
        $message .= "Vote Price: GHC " . $contestant['voteAmount'] . " per vote\n";
        $message .= "Current Votes: " . $contestant['votes'] . "\n";
        $message .= "\nEnter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid Contestant Code!\n";
        $message .= "Please enter valid code (FS1, FS2, FS3, FS4, FS5):";
        $continueSession = true;
    }
}

// User entered number of votes
elseif (isset($_SESSION['selected_contestant']) && is_numeric($userData) && $userData > 0) {
    $votes = intval($userData);
    $contestant = $_SESSION['selected_contestant'];
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid number! Please enter between 1 and 1000 votes:";
        $continueSession = true;
    } else {
        $totalAmount = $votes * $contestant['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['pending_contestant'] = $contestant;
        $_SESSION['msisdn'] = $msisdn;
        
        $message = "Vote Summary:\n";
        $message .= "Contestant: " . $contestant['stageName'] . " (" . $contestant['code'] . ")\n";
        $message .= "Votes: " . $votes . "\n";
        $message .= "Total Amount: GHC " . $totalAmount . "\n";
        $message .= "\n1. Proceed to Payment\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}

// Process payment selection
elseif ($userData == "1" && isset($_SESSION['pending_contestant'])) {
    $contestant = $_SESSION['pending_contestant'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $votes * $contestant['voteAmount'];
    
    // Generate unique reference
    $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
    
    // Create Paystack payment link
    $customerEmail = $msisdn . "@ussd.voter.com"; // Fallback email
    
    $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, "https://yourdomain.com/ussd_handler.php");
    
    if ($paymentUrl) {
        $_SESSION['payment_reference'] = $reference;
        
        $message = "Payment Required: GHC " . $totalAmount . "\n";
        $message .= "Please click the link to complete payment:\n";
        $message .= $paymentUrl . "\n\n";
        $message .= "After payment, your votes will be added automatically.\n";
        $message .= "Thank you for voting!";
        $continueSession = false;
        
        // Log payment initiation
        $logEntry = date('Y-m-d H:i:s') . " | PAYMENT INITIATED | MSISDN: {$msisdn} | Ref: {$reference} | Contestant: {$contestant['code']} | Votes: {$votes} | Amount: GHC {$totalAmount}\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
    } else {
        $message = "Payment system error. Please try again later.";
        $continueSession = false;
    }
}

// Cancel payment
elseif ($userData == "2" && isset($_SESSION['pending_contestant'])) {
    unset($_SESSION['pending_contestant']);
    unset($_SESSION['pending_votes']);
    
    $message = "Vote cancelled.\n";
    $message .= "Enter Contestant Code (FS1-FS5) to vote:";
    $continueSession = true;
}

// Handle other inputs or go back to start
else {
    $message = "Welcome to Ghartey Event Voting\n";
    $message .= "Enter Contestant Code (FS1, FS2, FS3, FS4, FS5):";
    $continueSession = true;
    unset($_SESSION['selected_contestant']);
    unset($_SESSION['pending_contestant']);
    unset($_SESSION['pending_votes']);
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
