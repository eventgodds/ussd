<?php
// COMPLETE WORKING VERSION - FIXED
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

// ============ USSD LOGIC - COMPLETELY REWRITTEN ============

$message = "";
$continueSession = true;

// Initialize session for new user
if ($newSession == true) {
    $_SESSION = [];
    $_SESSION['state'] = 'GET_CODE';
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code:";
}
else {
    // Get current state from session
    $state = $_SESSION['state'] ?? 'GET_CODE';
    
    // STATE 1: Getting nominee code
    if ($state == 'GET_CODE') {
        $nomineeCode = strtoupper($userData);
        
        // Try both databases
        $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
        if (!$nominee) {
            $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
        }
        
        if ($nominee) {
            $_SESSION['nominee'] = $nominee;
            $_SESSION['state'] = 'GET_VOTES';
            
            $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
            $message = "Vote for: {$nominee['name']}{$categoryText}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Current votes: {$nominee['votes']}\n";
            $message .= "GHC {$nominee['voteAmount']}/vote\n\n";
            $message .= "Enter number of votes:";
        } else {
            $message = "Invalid code '{$nomineeCode}'!\nTry: FS1, FS2, PG1, etc.\nEnter Nominee Code:";
        }
    }
    // STATE 2: Getting number of votes
    elseif ($state == 'GET_VOTES') {
        $votes = intval($userData);
        
        if ($votes >= 1 && $votes <= 1000) {
            $nominee = $_SESSION['nominee'];
            $totalAmount = $votes * $nominee['voteAmount'];
            
            $_SESSION['pending_votes'] = $votes;
            $_SESSION['total_amount'] = $totalAmount;
            $_SESSION['state'] = 'CONFIRM';
            
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
        } else {
            $message = "Invalid! Enter number between 1-1000:";
        }
    }
    // STATE 3: Confirming payment
    elseif ($state == 'CONFIRM') {
        if ($userData == "1") {
            $nominee = $_SESSION['nominee'];
            $votes = $_SESSION['pending_votes'];
            $totalAmount = $_SESSION['total_amount'];
            
            $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
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
                $message .= "AUTHORIZATION REQUIRED\n";
                $message .= "═══════════════════\n\n";
                $message .= "Amount: GHC {$totalAmount}\n\n";
                $message .= "Click this link to pay:\n";
                $message .= "{$paymentUrl}\n\n";
                $message .= "After payment, votes will\n";
                $message .= "be added automatically.\n";
                $message .= "═══════════════════\n\n";
                $message .= "Thank you for voting!";
                $continueSession = false;
                
                // Clear session after payment
                session_destroy();
            } else {
                $message = "Payment error. Please try again.";
                $continueSession = false;
            }
        } 
        elseif ($userData == "2") {
            $message = "Vote cancelled.\n\nEnter Nominee Code:";
            $_SESSION['state'] = 'GET_CODE';
            unset($_SESSION['nominee']);
            unset($_SESSION['pending_votes']);
            unset($_SESSION['total_amount']);
        }
        else {
            $message = "Choose:\n1. Proceed to Pay\n2. Cancel";
        }
    }
    // Fallback
    else {
        $_SESSION['state'] = 'GET_CODE';
        $message = "Enter Nominee Code (FS1, FS2, PG1, etc.):";
    }
}

echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
