<?php
header('Content-Type: application/json');

// ============ DATABASE CONFIGURATIONS ============
// Database 1: Awards DB (eventgodds) - YOUR MAIN AWARDS DATABASE
$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Database 2: Contestants DB (eventgodds-41e4f) - FOR FS1-FS5
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

// Paystack configuration (LIVE)
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

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

// ============ FUNCTION: Fetch ALL Award Nominees from eventgodds ============
function fetchAllAwardNominees($firestoreUrl) {
    $url = $firestoreUrl . "/awards_nominees";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    $nominees = [];
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            
            // Only include approved nominees with valid nomineeCode
            if (isset($fields['status']['stringValue']) && 
                $fields['status']['stringValue'] === 'approved' &&
                isset($fields['nomineeCode']['stringValue'])) {
                
                $nominees[] = [
                    'id' => basename($doc['name']),
                    'nomineeCode' => $fields['nomineeCode']['stringValue'],
                    'fullName' => $fields['fullName']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? '',
                    'categoryName' => $fields['categoryName']['stringValue'] ?? '',
                    'categoryCode' => $fields['categoryCode']['stringValue'] ?? '',
                    'votes' => intval($fields['votes']['integerValue'] ?? 0),
                    'voteAmount' => 1,
                    'photoUrl' => $fields['photoUrl']['stringValue'] ?? '',
                    'gender' => $fields['gender']['stringValue'] ?? '',
                    'type' => 'award'
                ];
            }
        }
    }
    
    return $nominees;
}

// ============ FUNCTION: Fetch Contestant by Code (FS1-FS5) ============
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
                    'nomineeCode' => $fields['code']['stringValue'],
                    'fullName' => $fields['name']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'categoryName' => 'Contestant',
                    'votes' => intval($fields['votes']['integerValue'] ?? 0),
                    'voteAmount' => intval($fields['voteAmount']['integerValue'] ?? 1),
                    'type' => 'contestant'
                ];
            }
        }
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

// ============ CHECK FOR PAYMENT CALLBACK ============
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    $paymentData = verifyPaystackPayment($reference);
    
    if ($paymentData) {
        $metadata = $paymentData['metadata'];
        $nomineeCode = $metadata['nominee_code'];
        $votes = intval($metadata['votes']);
        $type = $metadata['type'];
        
        if ($type == 'contestant') {
            $nominee = fetchContestantByCode($contestantsFirestoreUrl, $nomineeCode);
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotes($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            }
        } else {
            // Search through all award nominees to find the right one
            $allNominees = fetchAllAwardNominees($awardsFirestoreUrl);
            foreach ($allNominees as $nominee) {
                if ($nominee['nomineeCode'] === $nomineeCode) {
                    $newVotes = $nominee['votes'] + $votes;
                    updateVotes($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
                    break;
                }
            }
        }
        
        $logEntry = date('Y-m-d H:i:s') . " | PAYMENT SUCCESS | Ref: {$reference} | Code: {$nomineeCode} | Votes: {$votes}\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
        
        echo "Payment successful! {$votes} votes added for {$nomineeCode}";
        exit;
    } else {
        echo "Payment verification failed!";
        exit;
    }
}

// ============ USSD MENU LOGIC ============
$message = "";
$continueSession = false;

// MAIN WELCOME
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Awards Voting!\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "(Examples: PG1, BAP1, FS1, SMS1, etc.)";
    $continueSession = true;
}

// User entered a nominee code
elseif (preg_match('/^[A-Z0-9]+$/i', $userData) && strlen($userData) >= 2) {
    $nomineeCode = strtoupper($userData);
    
    // First check contestants DB (FS1-FS5)
    $contestant = fetchContestantByCode($contestantsFirestoreUrl, $nomineeCode);
    
    // If not found, check awards DB
    $awardNominee = null;
    if (!$contestant) {
        $allNominees = fetchAllAwardNominees($awardsFirestoreUrl);
        foreach ($allNominees as $nom) {
            if ($nom['nomineeCode'] === $nomineeCode) {
                $awardNominee = $nom;
                break;
            }
        }
    }
    
    $selected = $contestant ?: $awardNominee;
    
    if ($selected) {
        $_SESSION['selected_nominee'] = $selected;
        $_SESSION['nominee_type'] = $contestant ? 'contestant' : 'award';
        
        $displayName = $selected['stageName'] ?: $selected['fullName'] ?: $selected['nomineeCode'];
        $categoryInfo = $selected['categoryName'] ? " - {$selected['categoryName']}" : '';
        
        $message = "Vote for: {$displayName}{$categoryInfo}\n";
        $message .= "Code: {$selected['nomineeCode']}\n";
        $message .= "Price: GHC {$selected['voteAmount']}/vote\n";
        $message .= "Current Votes: {$selected['votes']}\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid Code! Valid codes: PG1, PG2, PG3, BAP1, SMS1, SJY1, HSO1, MPG1, MPG2, BSC2, BVY1, MSS1, BFW1, TSO1, SPO1, MPS1, MPS2, MFF2, MHE1, BFA1, SPY1, MFS3, MDS1, MDS2, MDS3, BGE1, IOY1, IOY3, CRP1, MHS2, PL1, MVS1, BRO1, AOY3, BWL2, GEY1, SBY1, SMY1, SMY2, FS1, FS2, FS3, FS4, FS5\n\nEnter code:";
        $continueSession = true;
    }
}

// User entered number of votes
elseif (isset($_SESSION['selected_nominee']) && is_numeric($userData) && $userData > 0) {
    $votes = intval($userData);
    $nominee = $_SESSION['selected_nominee'];
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Invalid! Enter 1-1000 votes:";
        $continueSession = true;
    } else {
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['pending_nominee'] = $nominee;
        
        $displayName = $nominee['stageName'] ?: $nominee['fullName'] ?: $nominee['nomineeCode'];
        
        $message = "SUMMARY:\n";
        $message .= "Nominee: {$displayName}\n";
        $message .= "Code: {$nominee['nomineeCode']}\n";
        if ($nominee['categoryName'] && $nominee['categoryName'] != 'Contestant') {
            $message .= "Category: {$nominee['categoryName']}\n";
        }
        $message .= "Votes: {$votes}\n";
        $message .= "Total: GHC {$totalAmount}\n\n";
        $message .= "1. PAY NOW\n";
        $message .= "2. Cancel";
        $continueSession = true;
    }
}

// Process payment
elseif ($userData == "1" && isset($_SESSION['pending_nominee'])) {
    $nominee = $_SESSION['pending_nominee'];
    $votes = $_SESSION['pending_votes'];
    $totalAmount = $votes * $nominee['voteAmount'];
    $type = $_SESSION['nominee_type'];
    
    $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
    $customerEmail = $msisdn . "@ussd.voter.com";
    
    $metadata = [
        'msisdn' => $msisdn,
        'nominee_code' => $nominee['nomineeCode'],
        'votes' => $votes,
        'type' => $type,
        'amount' => $totalAmount
    ];
    
    $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, "https://YOUR_DOMAIN.com/ussd_handler.php", $metadata);
    
    if ($paymentUrl) {
        $message = "Pay GHC {$totalAmount}:\n{$paymentUrl}\n\nAfter payment, votes added automatically.\nThank you!";
        $continueSession = false;
        
        $logEntry = date('Y-m-d H:i:s') . " | PAYMENT | MSISDN: {$msisdn} | Code: {$nominee['nomineeCode']} | Votes: {$votes}\n";
        file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
    } else {
        $message = "Payment error. Try again later.";
        $continueSession = false;
    }
}

// Cancel
elseif ($userData == "2" && isset($_SESSION['pending_nominee'])) {
    unset($_SESSION['pending_nominee']);
    unset($_SESSION['pending_votes']);
    unset($_SESSION['selected_nominee']);
    
    $message = "Vote cancelled.\n\nEnter Nominee Code:";
    $continueSession = true;
}

// Invalid or restart
else {
    $message = "Welcome to GHartey Awards Voting!\nEnter Nominee Code to vote:";
    $continueSession = true;
    unset($_SESSION['selected_nominee']);
    unset($_SESSION['pending_nominee']);
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
