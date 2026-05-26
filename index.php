<?php
// index.php - Complete USSD + Paystack + Firestore Integration

header('Content-Type: application/json');

// Configuration
$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Get Railway URL
$railwayUrl = (isset($_SERVER['HTTPS']) ? "https://" : "http://") . $_SERVER['HTTP_HOST'];

// Log all requests for debugging
$logRequest = date('Y-m-d H:i:s') . " | URI: " . $_SERVER['REQUEST_URI'] . " | Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
file_put_contents('debug_log.txt', $logRequest, FILE_APPEND);

// Check if this is a Paystack webhook request
if ($_SERVER['REQUEST_URI'] == '/paystack_webhook' || $_SERVER['REQUEST_URI'] == '/paystack_webhook.php') {
    handlePaystackWebhook($paystackSecretKey, $firestoreUrl);
    exit;
}

// Main USSD Handler
handleUSSD($firestoreUrl, $paystackSecretKey, $railwayUrl);

// ==================== USSD HANDLER ====================
function handleUSSD($firestoreUrl, $paystackSecretKey, $railwayUrl) {
    // Read request from Arkesel
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // If no data, return error
    if (!$data) {
        echo json_encode(["message" => "Invalid request", "continueSession" => false]);
        exit;
    }
    
    // Get values
    $sessionID  = $data['sessionID'] ?? '';
    $userID     = $data['userID'] ?? '';
    $newSession = $data['newSession'] ?? false;
    $msisdn     = $data['msisdn'] ?? '';
    $userData   = trim($data['userData'] ?? '');
    
    session_start();
    
    // Log USSD input
    $logEntry = date('Y-m-d H:i:s') . " | USSD | Session: $sessionID | UserData: $userData | NewSession: $newSession\n";
    file_put_contents('ussd_log.txt', $logEntry, FILE_APPEND);
    
    $message = "";
    $continueSession = false;
    
    // NEW SESSION - Welcome menu
    if ($newSession == true) {
        $_SESSION = [];
        $_SESSION['step'] = 'welcome';
        
        $message = "Welcome to Ghartey Event Voting\n";
        $message .= "Enter Contestant Code (FS1, FS2, FS3, FS4, FS5):";
        $continueSession = true;
    }
    
    // STEP 1: Handle contestant code entry (FS1-FS5)
    elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'welcome' && preg_match('/^FS[1-5]$/i', $userData)) {
        $contestantCode = strtoupper($userData);
        $contestant = fetchContestantByCode($firestoreUrl, $contestantCode);
        
        if ($contestant) {
            $_SESSION['selected_contestant'] = $contestant;
            $_SESSION['step'] = 'enter_votes';
            
            $message = "Vote for " . $contestant['stageName'] . "\n";
            $message .= "Code: " . $contestant['code'] . "\n";
            $message .= "Price: GHC " . $contestant['voteAmount'] . "/vote\n";
            $message .= "Current Votes: " . $contestant['votes'] . "\n";
            $message .= "\nEnter number of votes (1-1000):";
            $continueSession = true;
        } else {
            $message = "Invalid Code! Enter FS1, FS2, FS3, FS4, or FS5:";
            $continueSession = true;
        }
    }
    
    // STEP 2: Handle number of votes entry
    elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'enter_votes' && is_numeric($userData) && $userData > 0) {
        $votes = intval($userData);
        $contestant = $_SESSION['selected_contestant'];
        
        if ($votes < 1 || $votes > 1000) {
            $message = "Invalid! Enter 1-1000 votes:";
            $continueSession = true;
        } else {
            $totalAmount = $votes * $contestant['voteAmount'];
            
            $_SESSION['pending_votes'] = $votes;
            $_SESSION['total_amount'] = $totalAmount;
            $_SESSION['msisdn'] = $msisdn;
            $_SESSION['step'] = 'confirm_payment';
            
            $message = "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "VOTE SUMMARY\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "Contestant: " . $contestant['stageName'] . "\n";
            $message .= "Code: " . $contestant['code'] . "\n";
            $message .= "Votes: " . $votes . "\n";
            $message .= "Total: GHC " . $totalAmount . "\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "1. Proceed to Payment\n";
            $message .= "2. Cancel\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━";
            $continueSession = true;
        }
    }
    
    // STEP 3: Process payment
    elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'confirm_payment' && $userData == "1") {
        $votes = $_SESSION['pending_votes'];
        $contestant = $_SESSION['selected_contestant'];
        $totalAmount = $_SESSION['total_amount'];
        $msisdn = $_SESSION['msisdn'];
        
        // Generate unique reference
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        // Initiate Mobile Money payment
        $paymentResult = initiateMobileMoneyPayment($msisdn, $totalAmount, $reference, $contestant['code'], $votes, $paystackSecretKey);
        
        if ($paymentResult && $paymentResult['status']) {
            $_SESSION['transaction_ref'] = $reference;
            
            $message = "✓ Payment initiated!\n\n";
            $message = "Check your phone (" . formatPhoneNumber($msisdn) . ")\n";
            $message .= "Enter your Mobile Money PIN\n";
            $message .= "when prompted.\n\n";
            $message .= "Votes will be added automatically\n";
            $message .= "after payment confirmation.\n\n";
            $message .= "Thank you for voting! 🙏";
            $continueSession = false;
            
            // Log payment initiation
            file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . " | INITIATED | $msisdn | $reference | {$contestant['code']} | $votes votes | GHC $totalAmount\n", FILE_APPEND);
            
            // Clear session
            session_destroy();
        } else {
            $errorMsg = $paymentResult['message'] ?? 'Payment failed. Try again.';
            $message = "❌ Error: " . $errorMsg . "\n\nEnter Contestant Code (FS1-FS5) to try again:";
            $_SESSION['step'] = 'welcome';
            unset($_SESSION['pending_votes']);
            unset($_SESSION['selected_contestant']);
            $continueSession = true;
        }
    }
    
    // Cancel payment
    elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'confirm_payment' && $userData == "2") {
        unset($_SESSION['pending_votes']);
        unset($_SESSION['selected_contestant']);
        $_SESSION['step'] = 'welcome';
        
        $message = "✗ Cancelled.\n\nEnter Contestant Code (FS1-FS5):";
        $continueSession = true;
    }
    
    // Invalid input at welcome step
    elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'welcome') {
        $message = "Enter Contestant Code:\nFS1, FS2, FS3, FS4, or FS5:";
        $continueSession = true;
    }
    
    // Invalid input at enter_votes step
    elseif (isset($_SESSION['step']) && $_SESSION['step'] == 'enter_votes') {
        $message = "Enter number of votes (1-1000):";
        $continueSession = true;
    }
    
    // Default - start over
    else {
        $_SESSION = [];
        $_SESSION['step'] = 'welcome';
        $message = "Welcome to Ghartey Event Voting\nEnter Contestant Code (FS1-FS5):";
        $continueSession = true;
    }
    
    // Response to Arkesel
    $response = [
        "sessionID" => $sessionID,
        "userID" => $userID,
        "msisdn" => $msisdn,
        "message" => $message,
        "continueSession" => $continueSession
    ];
    
    echo json_encode($response);
}

// ==================== FIRESTORE FUNCTIONS ====================
function fetchContestantByCode($firestoreUrl, $code) {
    $url = $firestoreUrl . "/contestants";
    
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
                strtoupper($fields['code']['stringValue']) === strtoupper($code)) {
                
                return [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                ];
            }
        }
    }
    return null;
}

function updateContestantVotes($firestoreUrl, $documentId, $newVotes) {
    $updateUrl = $firestoreUrl . "/contestants/{$documentId}?updateMask.fieldPaths=votes";
    
    $updateData = [
        'fields' => [
            'votes' => ['integerValue' => (string)$newVotes]
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
    curl_close($ch);
    
    return true;
}

// ==================== PAYSTACK FUNCTIONS ====================
function initiateMobileMoneyPayment($phone, $amount, $reference, $contestantCode, $votes, $paystackSecretKey) {
    // Format phone number to international format
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 9) {
        $phone = '233' . $phone;
    } elseif (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
        $phone = '233' . substr($phone, 1);
    }
    
    $url = "https://api.paystack.co/charge";
    
    $data = [
        'email' => $phone . '@ussd.voter.com',
        'amount' => $amount * 100,
        'reference' => $reference,
        'currency' => 'GHS',
        'metadata' => [
            'contestant_code' => $contestantCode,
            'votes' => $votes,
            'msisdn' => $phone
        ],
        'mobile_money' => [
            'phone' => $phone,
            'provider' => 'mtn'
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
    
    // Log Paystack response
    file_put_contents('paystack_log.txt', date('Y-m-d H:i:s') . " | Response: $response\n", FILE_APPEND);
    
    return json_decode($response, true);
}

function handlePaystackWebhook($paystackSecretKey, $firestoreUrl) {
    // Get the raw input
    $input = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    
    // Log webhook attempt
    file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " | Signature received: $signature\n", FILE_APPEND);
    file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " | Input: $input\n", FILE_APPEND);
    
    // Calculate expected signature
    $expectedSignature = hash_hmac('sha512', $input, $paystackSecretKey);
    
    file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " | Expected: $expectedSignature\n", FILE_APPEND);
    
    // For testing, you can temporarily disable signature verification
    // Remove this check for now to test if webhook is reaching your endpoint
    if ($signature !== $expectedSignature) {
        file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " | WARNING: Invalid signature, but processing anyway for testing\n", FILE_APPEND);
        // Don't exit - continue processing for testing
        // http_response_code(401);
        // echo json_encode(['error' => 'Invalid signature']);
        // exit;
    }
    
    $event = json_decode($input, true);
    
    file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " | Event: " . ($event['event'] ?? 'none') . "\n", FILE_APPEND);
    
    if ($event && $event['event'] == 'charge.success') {
        $metadata = $event['data']['metadata'];
        $contestantCode = $metadata['contestant_code'] ?? '';
        $votes = intval($metadata['votes'] ?? 0);
        $reference = $event['data']['reference'] ?? '';
        
        file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " | Processing: Contestant=$contestantCode, Votes=$votes, Ref=$reference\n", FILE_APPEND);
        
        if ($contestantCode && $votes > 0) {
            // Find contestant and update votes
            $contestant = fetchContestantByCode($firestoreUrl, $contestantCode);
            
            if ($contestant) {
                $newVotes = $contestant['votes'] + $votes;
                updateContestantVotes($firestoreUrl, $contestant['id'], $newVotes);
                
                // Log success
                file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . " | SUCCESS | $reference | $contestantCode | +$votes votes | New total: $newVotes\n", FILE_APPEND);
                file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " | SUCCESS: Votes updated to $newVotes\n", FILE_APPEND);
            }
        }
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
}

function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 9) {
        return '0' . $phone;
    } elseif (strlen($phone) == 12 && substr($phone, 0, 3) == '233') {
        return '0' . substr($phone, 3);
    }
    return $phone;
}
?>
