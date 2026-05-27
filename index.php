<?php
// COMPLETE WORKING VERSION WITH PAYMENT INTEGRATION
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack Configuration (LIVE)
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

// Function to fetch from contestants DB (use actual name, not stage name)
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
                // Use actual name, fallback to stage name if name not available
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

// Function to fetch from awards DB (use actual full name)
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
                // Use fullName (actual name), fallback to stageName
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

// Function to create Paystack payment
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

// Check for payment callback
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    
    // Verify payment with Paystack
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
        
        // Update votes in the appropriate database
        if ($type == 'contestant') {
            $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
                echo "Payment successful! {$votes} votes added for {$nominee['name']}";
            }
        } else {
            $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
                echo "Payment successful! {$votes} votes added for {$nominee['name']}";
            }
        }
        exit;
    } else {
        echo "Payment verification failed!";
        exit;
    }
}

// USSD Logic
$message = "";
$continueSession = false;

if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, PG1, BAP1, etc.):";
    $continueSession = true;
}
// Step 1: Get nominee code
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
        
        $categoryText = isset($nominee['category']) ? "\nCategory: {$nominee['category']}" : "";
        $message = "Vote for: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "Price: GHC {$nominee['voteAmount']} per vote\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code '{$nomineeCode}'!\nPlease try again.\nEnter Nominee Code (FS1, PG1, BAP1, etc.):";
        $continueSession = true;
    }
}
// Step 2: Get number of votes
elseif ($_SESSION['step'] == 'get_votes' && is_numeric($userData)) {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid! Enter number between 1-1000:";
        $continueSession = true;
    } else {
        $nominee = $_SESSION['nominee'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['step'] = 'confirm_payment';
        
        $message = "═══════════════════\n";
        $message .= "VOTE SUMMARY\n";
        $message .= "═══════════════════\n";
        $message .= "Nominee: {$nominee['name']}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Total: GHC {$totalAmount}\n";
        $message .= "═══════════════════\n\n";
        $message .= "1. Proceed to Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}
// Step 3: Process payment or cancel
elseif ($_SESSION['step'] == 'confirm_payment') {
    if ($userData == "1") {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        // Generate unique reference
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Create payment link (update YOUR_DOMAIN_HERE)
        $callbackUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
        $customerEmail = $msisdn . "@ussd.voter.com";
        
        $metadata = [
            'msisdn' => $msisdn,
            'nominee_code' => $nominee['code'],
            'votes' => $votes,
            'type' => $nominee['type'],
            'amount' => $totalAmount
        ];
        
        $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, $callbackUrl, $metadata);
        
        if ($paymentUrl) {
            $message = "═══════════════════\n";
            $message .= "PAYMENT REQUIRED\n";
            $message .= "═══════════════════\n";
            $message .= "Amount: GHC {$totalAmount}\n\n";
            $message .= "Click link to pay:\n";
            $message .= "{$paymentUrl}\n\n";
            $message .= "After payment, votes will be\n";
            $message .= "added automatically.\n";
            $message .= "═══════════════════\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            
            // Clear session
            unset($_SESSION['step']);
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
        } else {
            $message = "Payment error. Please try again later.";
            $continueSession = false;
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
        $message = "Choose option:\n1. Proceed to Pay\n2. Cancel";
        $continueSession = true;
    }
}
// Handle any invalid state - restart gracefully
else {
    $message = "Session refreshed.\nEnter Nominee Code (FS1, PG1, BAP1, etc.):";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
}

echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
