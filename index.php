<?php
header('Content-Type: application/json');

// Configuration
$paystackSecretKey = "sk_live_b8d6b1eba856a6da4d891482e1324c55a05c69cc"; // REPLACE WITH YOUR ACTUAL SECRET KEY
$paystackPublicKey = "pk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e";
$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

// Start session for USSD state tracking
session_start();

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get values
$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

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
        
        // Sort by code (FS1, FS2, FS3, FS4, FS5)
        usort($contestants, function($a, $b) {
            return strcmp($a['code'], $b['code']);
        });
    }
    
    return $contestants;
}

// Function to update votes in Firestore
function updateVotesInFirestore($firestoreUrl, $contestantId, $newVotes) {
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

// Function to initiate Paystack payment
function initiatePaystackPayment($email, $amount, $reference, $callbackUrl) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $fields = [
        'email' => $email,
        'amount' => $amount * 100, // Convert to pesewas
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'metadata' => json_encode([
            'session_id' => session_id(),
            'phone' => $email
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

// Fetch contestants
$contestants = fetchContestants($firestoreUrl);

// Initialize USSD session for new users
if ($newSession) {
    $_SESSION['step'] = 'main_menu';
    $_SESSION['msisdn'] = $msisdn;
    $_SESSION['selected_contestant'] = null;
    $_SESSION['vote_count'] = null;
}

// USSD Menu Logic
$message = "";
$continueSession = true;

// Handle main menu
if ($_SESSION['step'] == 'main_menu') {
    if (empty($userData)) {
        $message = "Welcome to Ghartey Event\n";
        $message .= "1. Vote\n";
        $message .= "2. Check Vote Counts\n";
        $message .= "0. Exit";
        $continueSession = true;
    } 
    elseif ($userData == "1") {
        $_SESSION['step'] = 'select_contestant';
        $message = "Select Contestant:\n";
        foreach ($contestants as $index => $contestant) {
            $num = $index + 1;
            $message .= "{$num}. {$contestant['name']} ({$contestant['code']}) - GHC {$contestant['voteAmount']}/vote\n";
        }
        $message .= "0. Back";
        $continueSession = true;
    }
    elseif ($userData == "2") {
        $message = "Current Vote Counts:\n";
        foreach ($contestants as $contestant) {
            $message .= "{$contestant['name']} ({$contestant['code']}): {$contestant['votes']} votes\n";
        }
        $message .= "\n0. Back to Main Menu";
        $continueSession = true;
        $_SESSION['step'] = 'view_votes';
    }
    elseif ($userData == "0") {
        $message = "Thank you for using Ghartey Event. Goodbye!";
        $continueSession = false;
        session_destroy();
    }
    else {
        $message = "Invalid option. Please try again.\n";
        $message .= "1. Vote\n";
        $message .= "2. Check Vote Counts\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
}

// Handle contestant selection
elseif ($_SESSION['step'] == 'select_contestant') {
    if ($userData == "0") {
        $_SESSION['step'] = 'main_menu';
        $message = "Welcome to Ghartey Event\n";
        $message .= "1. Vote\n";
        $message .= "2. Check Vote Counts\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
    elseif (is_numeric($userData) && $userData >= 1 && $userData <= count($contestants)) {
        $index = intval($userData) - 1;
        $_SESSION['selected_contestant'] = $contestants[$index];
        $_SESSION['step'] = 'enter_votes';
        
        $contestant = $_SESSION['selected_contestant'];
        $message = "Vote for {$contestant['name']}\n";
        $message .= "Nominee Code: {$contestant['code']}\n";
        $message .= "Vote: GHC {$contestant['voteAmount']} per vote\n";
        $message .= "Current votes: {$contestant['votes']}\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    }
    else {
        $message = "Invalid selection. Please choose 1-" . count($contestants) . ":\n";
        foreach ($contestants as $index => $contestant) {
            $num = $index + 1;
            $message .= "{$num}. {$contestant['name']} ({$contestant['code']})\n";
        }
        $message .= "0. Back";
        $continueSession = true;
    }
}

// Handle vote count entry
elseif ($_SESSION['step'] == 'enter_votes') {
    if (is_numeric($userData) && $userData >= 1 && $userData <= 1000) {
        $_SESSION['vote_count'] = intval($userData);
        $_SESSION['step'] = 'confirm_vote';
        
        $contestant = $_SESSION['selected_contestant'];
        $totalCost = $_SESSION['vote_count'] * $contestant['voteAmount'];
        
        $message = "Vote Summary:\n";
        $message .= "Contestant: {$contestant['name']}\n";
        $message .= "Nominee Code: {$contestant['code']}\n";
        $message .= "Number of votes: {$_SESSION['vote_count']}\n";
        $message .= "Total cost: GHC {$totalCost}\n\n";
        $message .= "1. Proceed to Payment\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
    else {
        $contestant = $_SESSION['selected_contestant'];
        $message = "Invalid number. Please enter votes (1-1000):\n";
        $message .= "Vote for {$contestant['name']} - GHC {$contestant['voteAmount']} per vote";
        $continueSession = true;
    }
}

// Handle vote confirmation and payment
elseif ($_SESSION['step'] == 'confirm_vote') {
    if ($userData == "1") {
        // Process payment
        $contestant = $_SESSION['selected_contestant'];
        $voteCount = $_SESSION['vote_count'];
        $totalCost = $voteCount * $contestant['voteAmount'];
        $email = $msisdn . "@ussd.voter.com";
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        $callbackUrl = "https://your-domain.com/payment_callback.php"; // Update with your actual domain
        
        $payment = initiatePaystackPayment($email, $totalCost, $reference, $callbackUrl);
        
        if ($payment['success']) {
            // Store payment info for callback
            $_SESSION['payment_reference'] = $payment['reference'];
            $_SESSION['pending_vote'] = [
                'contestant_id' => $contestant['id'],
                'contestant_code' => $contestant['code'],
                'contestant_name' => $contestant['name'],
                'votes' => $voteCount,
                'current_votes' => $contestant['votes'],
                'new_votes' => $contestant['votes'] + $voteCount
            ];
            
            $message = "Payment required: GHC {$totalCost}\n";
            $message .= "Please visit this link to complete payment:\n";
            $message .= $payment['authorization_url'] . "\n\n";
            $message .= "After payment, dial *123# again and your votes will be recorded.\n";
            $message .= "Reference: {$reference}";
            $continueSession = false;
            
            // Log payment initiation
            file_put_contents('payments.log', date('Y-m-d H:i:s') . " - Payment initiated: $reference for $msisdn - GHC $totalCost\n", FILE_APPEND);
        }
        else {
            $message = "Payment system error. Please try again later.\n";
            $message .= "1. Try Again\n";
            $message .= "2. Main Menu";
            $continueSession = true;
            $_SESSION['step'] = 'payment_error';
        }
    }
    elseif ($userData == "2") {
        // Cancel vote
        $message = "Vote cancelled.\n";
        $message .= "1. Vote Again\n";
        $message .= "2. Main Menu";
        $continueSession = true;
        $_SESSION['step'] = 'vote_cancelled';
        unset($_SESSION['selected_contestant']);
        unset($_SESSION['vote_count']);
    }
    else {
        $contestant = $_SESSION['selected_contestant'];
        $totalCost = $_SESSION['vote_count'] * $contestant['voteAmount'];
        $message = "Vote Summary:\n";
        $message .= "Contestant: {$contestant['name']}\n";
        $message .= "Votes: {$_SESSION['vote_count']}\n";
        $message .= "Total: GHC {$totalCost}\n\n";
        $message .= "1. Proceed to Payment\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}

// Handle payment error retry
elseif ($_SESSION['step'] == 'payment_error') {
    if ($userData == "1") {
        $_SESSION['step'] = 'confirm_vote';
        $contestant = $_SESSION['selected_contestant'];
        $totalCost = $_SESSION['vote_count'] * $contestant['voteAmount'];
        $message = "Vote Summary:\n";
        $message .= "Contestant: {$contestant['name']}\n";
        $message .= "Votes: {$_SESSION['vote_count']}\n";
        $message .= "Total: GHC {$totalCost}\n\n";
        $message .= "1. Proceed to Payment\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
    elseif ($userData == "2") {
        $_SESSION['step'] = 'main_menu';
        $message = "Welcome to Ghartey Event\n";
        $message .= "1. Vote\n";
        $message .= "2. Check Vote Counts\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
}

// Handle vote cancelled
elseif ($_SESSION['step'] == 'vote_cancelled') {
    if ($userData == "1") {
        $_SESSION['step'] = 'select_contestant';
        $message = "Select Contestant:\n";
        foreach ($contestants as $index => $contestant) {
            $num = $index + 1;
            $message .= "{$num}. {$contestant['name']} ({$contestant['code']}) - GHC {$contestant['voteAmount']}/vote\n";
        }
        $message .= "0. Back";
        $continueSession = true;
    }
    elseif ($userData == "2") {
        $_SESSION['step'] = 'main_menu';
        $message = "Welcome to Ghartey Event\n";
        $message .= "1. Vote\n";
        $message .= "2. Check Vote Counts\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
    else {
        $message = "1. Vote Again\n";
        $message .= "2. Main Menu";
        $continueSession = true;
    }
}

// Handle view votes
elseif ($_SESSION['step'] == 'view_votes') {
    if ($userData == "0") {
        $_SESSION['step'] = 'main_menu';
        $message = "Welcome to Ghartey Event\n";
        $message .= "1. Vote\n";
        $message .= "2. Check Vote Counts\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
    else {
        $message = "Current Vote Counts:\n";
        foreach ($contestants as $contestant) {
            $message .= "{$contestant['name']} ({$contestant['code']}): {$contestant['votes']} votes\n";
        }
        $message .= "\n0. Back to Main Menu";
        $continueSession = true;
    }
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
