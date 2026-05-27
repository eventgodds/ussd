<?php
header('Content-Type: application/json');

// ============ DATABASE CONFIGURATIONS ============
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack configuration
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// ============ FUNCTIONS ============
function fetchFromContestantsDB($code) {
    global $contestantsFirestoreUrl;
    $url = $contestantsFirestoreUrl . "/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
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
                    'type' => 'contestant',
                    'collection' => 'contestants'
                ];
            }
        }
    }
    return null;
}

function fetchFromAwardsDB($code) {
    global $awardsFirestoreUrl;
    $url = $awardsFirestoreUrl . "/awards_nominees";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
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
                    'type' => 'award',
                    'collection' => 'awards_nominees'
                ];
            }
        }
    }
    return null;
}

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

function initiatePaystackPayment($amount, $reference, $nomineeCode, $votes, $type, $msisdn) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    // Use a default email - Paystack requires email
    $email = $msisdn . "@ussd.voter.com";
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100, // Convert to pesewas
        'reference' => $reference,
        'callback_url' => 'https://yourdomain.com/ussd_callback.php', // Change this
        'metadata' => [
            'msisdn' => $msisdn,
            'nominee_code' => $nomineeCode,
            'votes' => $votes,
            'type' => $type
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

// ============ CHECK FOR PAYMENT VERIFICATION (from webhook) ============
// Paystack will send a POST to this same file for webhook
$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature && hash_hmac('sha512', $input, $paystackSecretKey) === $signature) {
    // This is a webhook from Paystack
    $event = json_decode($input, true);
    if ($event['event'] == 'charge.success') {
        $metadata = $event['data']['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = $metadata['votes'];
        $type = $metadata['type'];
        
        // Get current data and update votes
        if ($type == 'contestant') {
            $nominee = fetchFromContestantsDB($nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            }
        } else {
            $nominee = fetchFromAwardsDB($nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
        }
        
        file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . " - Payment success: $nomineeCode +$votes votes\n", FILE_APPEND);
    }
    http_response_code(200);
    exit;
}

// ============ USSD LOGIC ============
$message = "";
$continueSession = false;

// MAIN MENU (First time)
if ($newSession == true) {
    $_SESSION = [];
    $_SESSION['step'] = 'enter_code';
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, PG1, BAP1, etc.):";
    $continueSession = true;
}

// STEP 1: User entered nominee code
elseif ($_SESSION['step'] == 'enter_code') {
    $nomineeCode = strtoupper($userData);
    
    // Search in both databases
    $nominee = fetchFromContestantsDB($nomineeCode);
    if (!$nominee) {
        $nominee = fetchFromAwardsDB($nomineeCode);
    }
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'enter_votes';
        
        $categoryText = isset($nominee['category']) ? " - {$nominee['category']}" : "";
        $message = "═══════════════════\n";
        $message .= "VOTE FOR: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current Votes: {$nominee['votes']}\n";
        $message .= "Price: GHC {$nominee['voteAmount']}/vote\n";
        $message .= "═══════════════════\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid Code: '$nomineeCode'\n\n";
        $message .= "Valid codes: FS1-FS5, PG1, BAP1, MPS1, etc.\n";
        $message .= "Enter nominee code:";
        $continueSession = true;
    }
}

// STEP 2: User entered number of votes
elseif ($_SESSION['step'] == 'enter_votes') {
    if (!is_numeric($userData)) {
        $message = "Please enter a valid number (1-1000):";
        $continueSession = true;
    } else {
        $votes = intval($userData);
        
        if ($votes < 1 || $votes > 1000) {
            $message = "Votes must be between 1 and 1000.\nEnter number of votes:";
            $continueSession = true;
        } else {
            $nominee = $_SESSION['nominee'];
            $totalAmount = $votes * $nominee['voteAmount'];
            
            $_SESSION['pending_votes'] = $votes;
            $_SESSION['pending_total'] = $totalAmount;
            $_SESSION['step'] = 'confirm_payment';
            
            $message = "═══════════════════\n";
            $message .= "VOTE SUMMARY\n";
            $message .= "═══════════════════\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: $votes\n";
            $message .= "Total: GHC $totalAmount\n";
            $message .= "═══════════════════\n\n";
            $message .= "1. Proceed to Pay (Mobile Money)\n";
            $message .= "2. Cancel\n\n";
            $message .= "Enter option 1 or 2:";
            $continueSession = true;
        }
    }
}

// STEP 3: User chose to proceed with payment
elseif ($_SESSION['step'] == 'confirm_payment' && $userData == '1') {
    $nominee = $_SESSION['nominee'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $_SESSION['pending_total'];
    
    // Generate unique reference
    $reference = 'VOTE_' . time() . '_' . rand(1000, 9999);
    
    // Create Paystack payment link
    $paymentUrl = initiatePaystackPayment($totalAmount, $reference, $nominee['code'], $votes, $nominee['type'], $msisdn);
    
    if ($paymentUrl) {
        $message = "═══════════════════\n";
        $message .= "PAYMENT REQUIRED\n";
        $message .= "═══════════════════\n";
        $message .= "Amount: GHC $totalAmount\n\n";
        $message .= "To complete your vote:\n";
        $message .= "1. Click this link on your phone:\n";
        $message .= "$paymentUrl\n\n";
        $message .= "2. Choose Mobile Money\n";
        $message .= "3. Enter your Momo PIN\n";
        $message .= "4. Votes added automatically!\n\n";
        $message .= "Thank you for voting!";
        $continueSession = false;
        
        // Log payment initiation
        file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . " - Payment initiated: {$nominee['code']} - $votes votes - GHC $totalAmount - Ref: $reference\n", FILE_APPEND);
        
        // Clear session
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
        unset($_SESSION['pending_total']);
    } else {
        $message = "Payment system error. Please try again later.\n";
        $message .= "Enter 1 to restart:";
        $_SESSION['step'] = 'enter_code';
        $continueSession = true;
    }
}

// Cancel payment
elseif ($_SESSION['step'] == 'confirm_payment' && $userData == '2') {
    $message = "Vote cancelled.\n\n";
    $message .= "Enter new nominee code:";
    $_SESSION['step'] = 'enter_code';
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
    unset($_SESSION['pending_total']);
    $continueSession = true;
}

// Restart or invalid
else {
    $message = "Enter nominee code (FS1, PG1, BAP1, etc.):";
    $_SESSION['step'] = 'enter_code';
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
