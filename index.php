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

// ============ FUNCTION: Fetch ALL Awards Nominees (with pagination) ============
function fetchAllAwardNominees($firestoreUrl) {
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
                        
                        $allNominees[$fields['nomineeCode']['stringValue']] = [
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

// ============ FUNCTION: Fetch ALL Contestants ============
function fetchAllContestants($firestoreUrl) {
    $allContestants = [];
    $pageToken = null;
    $url = $firestoreUrl . "/contestants";
    
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
                    
                    if (isset($fields['code']['stringValue'])) {
                        $allContestants[$fields['code']['stringValue']] = [
                            'id' => basename($doc['name']),
                            'code' => $fields['code']['stringValue'],
                            'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
                            'stageName' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                            'votes' => $fields['votes']['integerValue'] ?? 0,
                            'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                            'type' => 'contestant'
                        ];
                    }
                }
            }
            
            $pageToken = $data['nextPageToken'] ?? null;
        } else {
            break;
        }
    } while ($pageToken);
    
    return $allContestants;
}

// ============ FUNCTION: Find Nominee by Code (checks both DBs) ============
function findNomineeByCode($code) {
    global $contestantsFirestoreUrl, $awardsFirestoreUrl;
    
    // Static cache to avoid repeated API calls
    static $allContestants = null;
    static $allAwardNominees = null;
    
    if ($allContestants === null) {
        $allContestants = fetchAllContestants($contestantsFirestoreUrl);
    }
    
    if ($allAwardNominees === null) {
        $allAwardNominees = fetchAllAwardNominees($awardsFirestoreUrl);
    }
    
    // Check contestants first
    if (isset($allContestants[$code])) {
        return $allContestants[$code];
    }
    
    // Check award nominees
    if (isset($allAwardNominees[$code])) {
        return $allAwardNominees[$code];
    }
    
    return null;
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

// ============ FUNCTION: Verify Payment ============
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
        
        // Find the nominee again to get current votes and DB location
        $nominee = findNomineeByCode($nomineeCode);
        
        if ($nominee) {
            $newVotes = $nominee['votes'] + $votes;
            
            if ($nominee['type'] == 'contestant') {
                updateVotes($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            } else {
                updateVotes($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
            
            $logEntry = date('Y-m-d H:i:s') . " | SUCCESS | Ref: {$reference} | Code: {$nomineeCode} | Votes: {$votes}\n";
            file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
            
            echo "Payment successful! {$votes} votes added for {$nomineeCode}";
            exit;
        }
    }
    echo "Payment verification failed!";
    exit;
}

// ============ USSD MENU LOGIC ============
$message = "";
$continueSession = false;

// Pre-fetch all nominees for debugging (optional - remove in production)
// $allNominees = findNomineeByCode(''); // This will trigger the cache

if ($newSession == true) {
    $_SESSION = [];
    $message = "🎉 Welcome to GHartey Voting!\n\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "Examples: FS1, PG3, AOY1, BGE1, SPO4\n";
    $message .= "Try any code from the list!";
    $continueSession = true;
}
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'get_code') {
    $nomineeCode = strtoupper(trim($userData));
    
    // Find nominee in either database
    $nominee = findNomineeByCode($nomineeCode);
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'get_votes';
        
        $displayName = $nominee['stageName'] ?: $nominee['name'] ?: $nominee['fullName'] ?: $nominee['nomineeCode'];
        $categoryText = isset($nominee['categoryName']) && $nominee['categoryName'] ? " ({$nominee['categoryName']})" : "";
        
        $message = "🗳️ VOTE: {$displayName}{$categoryText}\n";
        $message .= "━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📌 Code: {$nominee['nomineeCode']}\n";
        $message .= "💰 Price: GHC {$nominee['voteAmount']}/vote\n";
        $message .= "📊 Current Votes: {$nominee['votes']}\n";
        $message .= "━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid Code: {$nomineeCode}\n\n";
        $message .= "✅ Valid examples:\n";
        $message .= "• FS1, FS2, FS3, FS4, FS5\n";
        $message .= "• PG1, PG2, PG3, PG4\n";
        $message .= "• AOY1, AOY2, AOY3\n";
        $message .= "• BGE1, SPO4, PL1, MPS1\n\n";
        $message .= "Enter a valid code:";
        $continueSession = true;
    }
}
elseif ($_SESSION['step'] == 'get_votes' && is_numeric($userData)) {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Enter number between 1-1000:";
        $continueSession = true;
    } else {
        $nominee = $_SESSION['nominee'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['pending_nominee'] = $nominee;
        
        $displayName = $nominee['stageName'] ?: $nominee['name'] ?: $nominee['fullName'] ?: $nominee['nomineeCode'];
        
        $message = "📋 VOTE SUMMARY\n";
        $message .= "━━━━━━━━━━━━━━━━━━━\n";
        $message .= "👤 Nominee: {$displayName}\n";
        $message .= "🏷️ Code: {$nominee['nomineeCode']}\n";
        if (isset($nominee['categoryName']) && $nominee['categoryName']) {
            $message .= "📂 Category: {$nominee['categoryName']}\n";
        }
        $message .= "🔢 Votes: {$votes}\n";
        $message .= "💰 Total: GHC {$totalAmount}\n";
        $message .= "━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "1️⃣ Proceed to Payment\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
elseif ($userData == "1" && isset($_SESSION['pending_nominee'])) {
    $nominee = $_SESSION['pending_nominee'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $votes * $nominee['voteAmount'];
    $type = $nominee['type'];
    
    $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
    $customerEmail = $msisdn . "@ussd.voter.com";
    
    $metadata = [
        'msisdn' => $msisdn,
        'nominee_code' => $nominee['nomineeCode'],
        'votes' => $votes,
        'type' => $type,
        'amount' => $totalAmount
    ];
    
    // CHANGE THIS TO YOUR ACTUAL DOMAIN
    $callbackUrl = "https://yourdomain.com/ussd_handler.php";
    $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, $callbackUrl, $metadata);
    
    if ($paymentUrl) {
        $message = "💳 Payment Required: GHC {$totalAmount}\n\n";
        $message = "🔗 Click link to pay:\n{$paymentUrl}\n\n";
        $message = "✅ After payment, votes added automatically!\n";
        $message = "🙏 Thank you for voting!";
        $continueSession = false;
        
        $logEntry = date('Y-m-d H:i:s') . " | INITIATED | MSISDN: {$msisdn} | Ref: {$reference} | Code: {$nominee['nomineeCode']} | Votes: {$votes}\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
        
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_nominee']);
        unset($_SESSION['pending_votes']);
    } else {
        $message = "⚠️ Payment system error. Please try again later.";
        $continueSession = false;
    }
}
elseif ($userData == "2" && isset($_SESSION['pending_nominee'])) {
    unset($_SESSION['pending_nominee']);
    unset($_SESSION['pending_votes']);
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    
    $message = "❌ Vote cancelled.\n\nEnter Nominee Code to vote:";
    $continueSession = true;
}
else {
    $message = "🎉 Welcome to GHartey Voting!\n\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "Examples: FS1, PG3, AOY1, BGE1, SPO4";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_nominee']);
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
