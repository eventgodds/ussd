<?php
header('Content-Type: application/json');

// Load environment variables (create this function or use vlucas/phpdotenv)
$paystackSecretKey = "sk_live_b8d6b1eba856a6da4d891482e1324c55a05c69cc"; // REPLACE WITH YOUR LIVE SECRET KEY
$paystackPublicKey = "pk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e";
$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

// Start session for tracking USSD state
session_start();

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get values
$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn     = $data['msisdn'] ?? '';
$userData   = trim($data['userData'] ?? '');

// Initialize session for new USSD session
if ($newSession) {
    $_SESSION = [];
    $_SESSION['msisdn'] = $msisdn;
    $_SESSION['step'] = 'main_menu';
}

// Function to fetch contestants from Firestore
function fetchContestants($firestoreUrl) {
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
            if (isset($fields['code']['stringValue']) && preg_match('/^FS[1-5]$/', $fields['code']['stringValue'])) {
                // Determine which field contains the name
                $name = $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '';
                
                $contestants[] = [
                    'id' => basename($doc['name']),
                    'code' => $fields['code']['stringValue'],
                    'name' => $name,
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1
                ];
            }
        }
        
        // Sort by code
        usort($contestants, function($a, $b) {
            return strcmp($a['code'], $b['code']);
        });
    }
    
    return $contestants;
}

// Function to create Paystack payment intent
function createPaystackPayment($email, $amount, $reference, $callbackUrl) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $fields = [
        'email' => $email,
        'amount' => $amount * 100, // Convert to kobo/pesewas
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'metadata' => json_encode([
            'custom_fields' => [
                ['display_name' => 'USSD Session', 'variable_name' => 'session_id', 'value' => session_id()]
            ]
        ])
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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
                'success' => true,
                'authorization_url' => $result['data']['authorization_url'],
                'reference' => $result['data']['reference']
            ];
        }
    }
    
    return ['success' => false, 'message' => 'Payment initialization failed'];
}

// Function to verify Paystack payment
function verifyPayment($reference) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/verify/" . $reference;
    
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
        return [
            'success' => true,
            'amount' => $result['data']['amount'] / 100,
            'reference' => $reference
        ];
    }
    
    return ['success' => false];
}

// Function to update votes in Firestore
function updateVotesInFirestore($firestoreUrl, $contestantId, $currentVotes, $additionalVotes) {
    $newVotes = $currentVotes + $additionalVotes;
    $updateUrl = $firestoreUrl . "/contestants/{$contestantId}?updateMask.fieldPaths=votes";
    
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Fetch contestants
$contestants = fetchContestants($firestoreUrl);

// USSD State Machine
$message = "";
$continueSession = false;

// Handle payment verification callback (webhook)
if (isset($_GET['paystack_callback'])) {
    $reference = $_GET['reference'] ?? '';
    if ($reference) {
        $verification = verifyPayment($reference);
        if ($verification['success']) {
            // Update votes in database
            if (isset($_SESSION['pending_vote'])) {
                $pending = $_SESSION['pending_vote'];
                $success = updateVotesInFirestore($firestoreUrl, $pending['contestant_id'], $pending['current_votes'], $pending['votes']);
                
                if ($success) {
                    echo "Payment successful! Your vote has been recorded.";
                } else {
                    echo "Payment successful but vote recording failed. Please contact support.";
                }
                unset($_SESSION['pending_vote']);
            }
        } else {
            echo "Payment verification failed.";
        }
    }
    exit;
}

// USSD Menu Logic
if ($newSession || $_SESSION['step'] == 'main_menu') {
    $message = "Welcome to Ghartey Events\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Vote Counts";
    $_SESSION['step'] = 'main_menu';
    $continueSession = true;
    
    if (!$newSession && $userData == "0") {
        $message = "Thank you for using Ghartey Event. Goodbye!";
        $continueSession = false;
        session_destroy();
    }
}
elseif ($userData == "1" || $_SESSION['step'] == 'selecting_contestant') {
    if ($userData == "1") {
        $_SESSION['step'] = 'selecting_contestant';
    }
    
    $message = "Select Contestant:\n";
    foreach ($contestants as $index => $contestant) {
        $num = $index + 1;
        $message .= "{$num}. {$contestant['name']} ({$contestant['code']}) - GHC {$contestant['voteAmount']}/vote\n";
    }
    $message .= "0. Back to Main Menu";
    $continueSession = true;
}
elseif ($_SESSION['step'] == 'selecting_contestant' && preg_match('/^[1-5]$/', $userData)) {
    $selection = intval($userData) - 1;
    if (isset($contestants[$selection])) {
        $_SESSION['selected_contestant'] = $contestants[$selection];
        $_SESSION['step'] = 'entering_votes';
        
        $contestant = $contestants[$selection];
        $message = "Vote for {$contestant['name']}\n";
        $message .= "Nominee Code: {$contestant['code']}\n";
        $message .= "Vote: GHC {$contestant['voteAmount']} per vote\n";
        $message .= "Current votes: {$contestant['votes']}\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    }
}
elseif ($_SESSION['step'] == 'entering_votes' && is_numeric($userData) && $userData >= 1 && $userData <= 1000) {
    $numberOfVotes = intval($userData);
    $contestant = $_SESSION['selected_contestant'];
    $totalCost = $numberOfVotes * $contestant['voteAmount'];
    
    $_SESSION['pending_vote'] = [
        'contestant_id' => $contestant['id'],
        'contestant_code' => $contestant['code'],
        'contestant_name' => $contestant['name'],
        'votes' => $numberOfVotes,
        'total_cost' => $totalCost,
        'current_votes' => $contestant['votes']
    ];
    
    $_SESSION['step'] = 'confirming_payment';
    
    $message = "Vote Summary:\n";
    $message .= "Contestant: {$contestant['name']}\n";
    $message .= "Votes: {$numberOfVotes}\n";
    $message .= "Total: GHC {$totalCost}\n\n";
    $message .= "1. Proceed to Payment\n";
    $message .= "2. Cancel";
    $continueSession = true;
}
elseif ($_SESSION['step'] == 'confirming_payment' && $userData == "1") {
    $pending = $_SESSION['pending_vote'];
    $email = $_SESSION['msisdn'] . "@user.ussd.com"; // Generate email from phone number
    $reference = "VOTE_" . time() . "_" . session_id();
    $callbackUrl = "https://your-domain.com/ussd_handler.php?paystack_callback=1";
    
    $payment = createPaystackPayment($email, $pending['total_cost'], $reference, $callbackUrl);
    
    if ($payment['success']) {
        // For USSD, we need to provide a short URL or payment code
        // Paystack USSD is limited, so we'll provide payment link
        $message = "To complete payment of GHC {$pending['total_cost']}:\n";
        $message .= "Visit: " . shortenUrl($payment['authorization_url']) . "\n";
        $message .= "Or use Paystack USSD: *402*" . $payment['reference'] . "#\n";
        $message .= "After payment, dial *123# again to confirm.";
        $continueSession = false;
        
        // Store reference for verification
        $_SESSION['payment_reference'] = $payment['reference'];
    } else {
        $message = "Payment initialization failed. Please try again.";
        $continueSession = false;
        unset($_SESSION['pending_vote']);
        $_SESSION['step'] = 'main_menu';
    }
}
elseif ($_SESSION['step'] == 'confirming_payment' && $userData == "2") {
    $message = "Vote cancelled.";
    $continueSession = false;
    unset($_SESSION['pending_vote']);
    $_SESSION['step'] = 'main_menu';
}
elseif ($userData == "2") {
    $message = "Current Vote Counts:\n";
    foreach ($contestants as $contestant) {
        $message .= "{$contestant['name']} ({$contestant['code']}): {$contestant['votes']} votes\n";
    }
    $message .= "\n0. Back to Main Menu";
    $continueSession = true;
    $_SESSION['step'] = 'viewing_counts';
}
elseif ($_SESSION['step'] == 'viewing_counts' && $userData == "0") {
    $_SESSION['step'] = 'main_menu';
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Vote Counts";
    $continueSession = true;
}
else {
    $message = "Invalid option. Please try again.\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Vote Counts\n";
    $message .= "0. Exit";
    $continueSession = true;
    $_SESSION['step'] = 'main_menu';
}

// Function to shorten URL (for better USSD display)
function shortenUrl($url) {
    // You can use a URL shortener API here
    // For now, return the full URL (USSD supports it)
    return $url;
}

// Response
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
