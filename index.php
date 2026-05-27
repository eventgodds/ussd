<?php
header('Content-Type: application/json');

// Database configurations
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

// ============ FUNCTION: Fetch ALL Awards Nominees (with pagination) ============
function fetchAllAwardsNominees($firestoreUrl) {
    $allNominees = [];
    $pageToken = null;
    $url = $firestoreUrl . "/awards_nominees";
    
    do {
        $pagedUrl = $url;
        if ($pageToken) {
            $pagedUrl .= "?pageToken=" . urlencode($pageToken);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $pagedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $data = json_decode($response, true);
            
            if (isset($data['documents'])) {
                foreach ($data['documents'] as $doc) {
                    $fields = $doc['fields'];
                    
                    // Only include approved nominees with valid nomineeCode
                    if (isset($fields['nomineeCode']['stringValue']) && 
                        isset($fields['status']['stringValue']) && 
                        $fields['status']['stringValue'] == 'approved') {
                        
                        $allNominees[] = [
                            'id' => basename($doc['name']),
                            'nomineeCode' => $fields['nomineeCode']['stringValue'],
                            'fullName' => $fields['fullName']['stringValue'] ?? '',
                            'stageName' => $fields['stageName']['stringValue'] ?? '',
                            'categoryName' => $fields['categoryName']['stringValue'] ?? '',
                            'categoryCode' => $fields['categoryCode']['stringValue'] ?? '',
                            'votes' => $fields['votes']['integerValue'] ?? 0,
                            'voteAmount' => 1,
                            'type' => 'award'
                        ];
                    }
                }
            }
            
            $pageToken = $data['nextPageToken'] ?? null;
        } else {
            break;
        }
    } while ($pageToken);
    
    return $allNominees;
}

// ============ FUNCTION: Fetch Contestants (FS1-FS5) ============
function fetchAllContestants($firestoreUrl) {
    $allContestants = [];
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
                preg_match('/^FS[1-5]$/', $fields['code']['stringValue'])) {
                
                $allContestants[] = [
                    'id' => basename($doc['name']),
                    'nomineeCode' => $fields['code']['stringValue'],
                    'fullName' => $fields['name']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? '',
                    'categoryName' => 'Ghartey Event Contestant',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                    'type' => 'contestant'
                ];
            }
        }
    }
    
    return $allContestants;
}

// ============ Create a cached index of all nominees ============
function getAllNomineesIndex() {
    global $contestantsFirestoreUrl, $awardsFirestoreUrl;
    
    // Check if we have cached data (refresh every 5 minutes)
    $cacheFile = 'nominees_cache.json';
    $cacheTime = 300; // 5 seconds for testing, change to 300 for production
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) {
            return $cached;
        }
    }
    
    // Fetch fresh data
    $contestants = fetchAllContestants($contestantsFirestoreUrl);
    $awardsNominees = fetchAllAwardsNominees($awardsFirestoreUrl);
    
    $allNominees = array_merge($contestants, $awardsNominees);
    
    // Create index by nomineeCode
    $index = [];
    foreach ($allNominees as $nominee) {
        $index[$nominee['nomineeCode']] = $nominee;
    }
    
    // Save to cache
    file_put_contents($cacheFile, json_encode($index));
    
    return $index;
}

// ============ FUNCTION: Update Votes ============
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

// ============ FUNCTION: Create Paystack Payment ============
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

// ============ FUNCTION: Verify Paystack Payment ============
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

// ============ CHECK PAYMENT CALLBACK ============
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPaystackPayment($reference);
    
    if ($paymentData) {
        $metadata = $paymentData['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        
        // Get fresh nominee data
        $allNominees = getAllNomineesIndex();
        
        if (isset($allNominees[$nomineeCode])) {
            $nominee = $allNominees[$nomineeCode];
            $newVotes = $nominee['votes'] + $votes;
            
            $collection = ($nominee['type'] == 'contestant') ? 'contestants' : 'awards_nominees';
            $firestoreUrl = ($nominee['type'] == 'contestant') ? 
                "https://firestore.googleapis.com/v1/projects/eventgodds-41e4f/databases/(default)/documents" :
                "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";
            
            updateVotes($firestoreUrl, $collection, $nominee['id'], $newVotes);
            
            $logEntry = date('Y-m-d H:i:s') . " | SUCCESS | Ref: {$reference} | {$nomineeCode} | +{$votes} votes\n";
            file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
            
            echo "Payment successful! {$votes} votes added for {$nomineeCode}";
            exit;
        }
    }
    echo "Payment verification failed!";
    exit;
}

// ============ USSD MAIN LOGIC ============
$message = "";
$continueSession = false;

// Load all nominees into memory
$allNominees = getAllNomineesIndex();

if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Voting!\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "(Examples: FS1, PG1, AOY1, BGE1, etc.)";
    $continueSession = true;
}
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'get_code') {
    $nomineeCode = strtoupper($userData);
    
    if (isset($allNominees[$nomineeCode])) {
        $_SESSION['nominee'] = $allNominees[$nomineeCode];
        $_SESSION['step'] = 'get_votes';
        
        $nominee = $allNominees[$nomineeCode];
        $displayName = $nominee['stageName'] ?: $nominee['fullName'] ?: $nominee['nomineeCode'];
        
        $message = "Vote for: {$displayName}\n";
        $message .= "Category: {$nominee['categoryName']}\n";
        $message .= "Code: {$nominee['nomineeCode']}\n";
        $message .= "Current votes: {$nominee['votes']}\n";
        $message .= "GHC {$nominee['voteAmount']} per vote\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code '{$nomineeCode}'\n";
        $message .= "Valid codes: FS1-FS5, or award codes like:\n";
        $message .= "AOY1, PG1, BGE1, SPO4, etc.\n\n";
        $message .= "Enter nominee code:";
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
        
        $displayName = $nominee['stageName'] ?: $nominee['fullName'] ?: $nominee['nomineeCode'];
        
        $message = "Vote Summary:\n";
        $message .= "Nominee: {$displayName}\n";
        $message .= "Code: {$nominee['nomineeCode']}\n";
        $message .= "Category: {$nominee['categoryName']}\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Amount: GHC {$totalAmount}\n\n";
        $message .= "1. Confirm & Pay\n";
        $message .= "2. Cancel";
        $continueSession = true;
        $_SESSION['step'] = 'confirm';
    }
}
elseif ($_SESSION['step'] == 'confirm' && $userData == "1") {
    $nominee = $_SESSION['nominee'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $votes * $nominee['voteAmount'];
    
    $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
    $customerEmail = $msisdn . "@ussd.voter.com";
    
    $metadata = [
        'msisdn' => $msisdn,
        'nominee_code' => $nominee['nomineeCode'],
        'votes' => $votes,
        'type' => $nominee['type'],
        'amount' => $totalAmount
    ];
    
    $callbackUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, $callbackUrl, $metadata);
    
    if ($paymentUrl) {
        $message = "Pay GHC {$totalAmount} to complete vote:\n";
        $message .= "{$paymentUrl}\n\n";
        $message .= "After payment, votes added automatically.\n";
        $message .= "Thank you!";
        $continueSession = false;
        
        $logEntry = date('Y-m-d H:i:s') . " | INITIATED | {$msisdn} | {$nominee['nomineeCode']} | {$votes} votes\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
        
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    } else {
        $message = "Payment error. Please try again later.";
        $continueSession = false;
    }
}
elseif ($_SESSION['step'] == 'confirm' && $userData == "2") {
    $message = "Vote cancelled.\n\nEnter nominee code to vote:";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
}
else {
    $message = "Enter nominee code (FS1, AOY1, PG1, BGE1, etc.):";
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
