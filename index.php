<?php
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack Configuration
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

// File-based session storage
$sessionFile = sys_get_temp_dir() . '/ussd_vote_' . md5($sessionID) . '.json';
$session = [];
if (file_exists($sessionFile)) {
    $session = json_decode(file_get_contents($sessionFile), true);
}

function saveSession($sessionFile, $session) {
    file_put_contents($sessionFile, json_encode($session));
}

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

function initializePaystackPayment($amount, $msisdn, $reference, $nomineeCode, $votes, $type) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $cleanPhone = preg_replace('/[^0-9]/', '', $msisdn);
    if (strlen($cleanPhone) > 10) {
        $cleanPhone = substr($cleanPhone, -9);
    }
    $email = "user{$cleanPhone}@ussd.vote.com";
    
    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $callbackUrl = $protocol . $_SERVER['HTTP_HOST'] . '/ussd_callback.php';
    
    $data = [
        'email' => $email,
        'amount' => $amount * 100,
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'metadata' => [
            'msisdn' => $msisdn,
            'nominee_code' => $nomineeCode,
            'votes' => $votes,
            'type' => $type
        ],
        'channels' => ['card', 'ussd', 'mobile_money']
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
        if ($result['status'] && isset($result['data']['authorization_url'])) {
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
            }
        } else {
            $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotesInDB($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
        }
        
        echo "Payment successful! {$votes} votes added for {$nomineeCode}";
        exit;
    } else {
        echo "Payment verification failed!";
        exit;
    }
}

// USSD Logic
$message = "";
$continueSession = false;

if ($userData == "0") {
    @unlink($sessionFile);
    $message = "Welcome to GHartey Voting\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
    echo json_encode(["sessionID" => $sessionID, "userID" => $userID, "msisdn" => $msisdn, "message" => $message, "continueSession" => $continueSession]);
    exit;
}

if ($newSession == true) {
    @unlink($sessionFile);
    $session = ['step' => 'welcome'];
    saveSession($sessionFile, $session);
    $message = "Welcome to GHartey Voting\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):";
    $continueSession = true;
}
elseif (isset($session['step']) && $session['step'] == 'get_votes') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if (is_numeric($lastInput) && $lastInput > 0) {
        $votes = intval($lastInput);
        $nominee = $session['nominee'];
        
        if ($votes >= 1 && $votes <= 1000000000000000) {
            $totalAmount = $votes * $nominee['voteAmount'];
            $session['pending_votes'] = $votes;
            $session['total_amount'] = $totalAmount;
            $session['step'] = 'confirm_payment';
            saveSession($sessionFile, $session);
            
            $message = "VOTE SUMMARY\n";
            $message .= "Nominee: {$nominee['name']}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Votes: {$votes}\n";
            $message .= "Total: GHC {$totalAmount}\n\n";
            $message .= "1. Proceed to Vote\n";
            $message .= "2. Cancel\n";
            $message .= "0. Main Menu";
            $continueSession = true;
        } else {
            $message = "Enter number of votes:";
            $continueSession = true;
        }
    } else {
        $message = "Enter number of votes:";
        $continueSession = true;
    }
}
elseif (isset($session['step']) && $session['step'] == 'confirm_payment') {
    $parts = explode('*', $userData);
    $lastInput = end($parts);
    
    if ($lastInput == "1") {
        $nominee = $session['nominee'];
        $votes = $session['pending_votes'];
        $totalAmount = $session['total_amount'];
        
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        $paymentUrl = initializePaystackPayment($totalAmount, $msisdn, $reference, $nominee['code'], $votes, $nominee['type']);
        
        if ($paymentUrl) {
            $message = "PAYMENT REQUIRED\n";
            $message .= "Amount: GHC {$totalAmount}\n";
            $message .= "Nominee: {$nominee['name']}\n\n";
            $message .= "Click this link to pay:\n{$paymentUrl}\n\n";
            $message .= "After payment, votes will be added automatically.\n";
            $message .= "Thank you for voting!";
            $continueSession = false;
            @unlink($sessionFile);
        } else {
            $message = "Payment error. Please try again.\nEnter 0 to go back:";
            $continueSession = true;
            $session['step'] = 'welcome';
            saveSession($sessionFile, $session);
        }
    } 
    elseif ($lastInput == "2") {
        @unlink($sessionFile);
        $message = "Vote cancelled.\n\nEnter Nominee Code to vote:";
        $continueSession = true;
    }
    else {
        $message = "Choose:\n1. Proceed to Vote\n2. Cancel\n0. Main Menu";
        $continueSession = true;
    }
}
else {
    $nomineeCode = strtoupper($userData);
    
    $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $nomineeCode);
    if (!$nominee) {
        $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $nomineeCode);
    }
    
    if ($nominee) {
        $session['nominee'] = $nominee;
        $session['step'] = 'get_votes';
        saveSession($sessionFile, $session);
        
        $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
        $message = "Vote for: {$nominee['name']}{$categoryText}\n";
        $message .= "Code: {$nominee['code']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "Price: GHC {$nominee['voteAmount']} per vote\n\n";
        $message .= "Enter number of votes (1-1000):\n";
        $message .= "0. Main Menu";
        $continueSession = true;
    } else {
        $message = "Invalid code '{$nomineeCode}'\nEnter Nominee Code (FS1, FS2, FS3, FS4, FS5, PG1, etc.):\n0. Exit";
        $continueSession = true;
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
