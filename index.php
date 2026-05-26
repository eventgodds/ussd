<?php
header('Content-Type: application/json');

// ============ CONFIGURATION ============
// Paystack configuration
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

// ============ FUNCTION: Search BOTH databases for a code ============
function findNominee($code) {
    $code = strtoupper($code);
    
    // DATABASE 1: Contestants (FS1-FS5)
    $url1 = "https://firestore.googleapis.com/v1/projects/eventgodds-41e4f/databases/(default)/documents/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response1 = curl_exec($ch);
    curl_close($ch);
    
    $data1 = json_decode($response1, true);
    
    if (isset($data1['documents'])) {
        foreach ($data1['documents'] as $doc) {
            $fields = $doc['fields'];
            $docCode = $fields['code']['stringValue'] ?? '';
            if (strtoupper($docCode) === $code) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'price' => $fields['voteAmount']['integerValue'] ?? 1,
                    'type' => 'contestant',
                    'collection' => 'contestants',
                    'project' => 'eventgodds-41e4f'
                ];
            }
        }
    }
    
    // DATABASE 2: Award Nominees (PG2, etc.)
    $url2 = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents/award_nominees";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response2 = curl_exec($ch);
    curl_close($ch);
    
    $data2 = json_decode($response2, true);
    
    if (isset($data2['documents'])) {
        foreach ($data2['documents'] as $doc) {
            $fields = $doc['fields'];
            // Use nomineeCode for award nominees
            $docCode = $fields['nomineeCode']['stringValue'] ?? '';
            if (strtoupper($docCode) === $code) {
                return [
                    'id' => basename($doc['name']),
                    'code' => $docCode,
                    'name' => $fields['stageName']['stringValue'] ?? $fields['fullName']['stringValue'] ?? '',
                    'category' => $fields['categoryName']['stringValue'] ?? '',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'price' => 1,
                    'type' => 'award',
                    'collection' => 'award_nominees',
                    'project' => 'eventgodds'
                ];
            }
        }
    }
    
    return null;
}

// ============ FUNCTION: Update votes in the correct database ============
function updateVotes($nominee, $newVotes) {
    if ($nominee['project'] == 'eventgodds-41e4f') {
        $url = "https://firestore.googleapis.com/v1/projects/eventgodds-41e4f/databases/(default)/documents/contestants/{$nominee['id']}?updateMask.fieldPaths=votes";
    } else {
        $url = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents/award_nominees/{$nominee['id']}?updateMask.fieldPaths=votes";
    }
    
    $updateData = [
        'fields' => [
            'votes' => ['integerValue' => (string)$newVotes]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
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

// ============ FUNCTION: Create Paystack payment ============
function createPayment($msisdn, $amount, $reference) {
    global $paystackSecretKey;
    
    // Clean phone number
    $phone = $msisdn;
    if (substr($phone, 0, 1) == '0') $phone = '233' . substr($phone, 1);
    if (substr($phone, 0, 4) == '+233') $phone = '233' . substr($phone, 4);
    
    $data = [
        'amount' => $amount * 100,
        'email' => $msisdn . '@voter.com',
        'reference' => $reference,
        'channels' => ['mobile_money'],
        'mobile_money' => ['phone' => $phone, 'provider' => 'mtn'],
        'metadata' => [
            'code' => $_SESSION['code'],
            'votes' => $_SESSION['votes']
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paystack.co/transaction/initialize');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    return ($result['status'] ?? false) ? $result['data']['authorization_url'] : false;
}

// ============ USSD MENU FLOW ============
$message = "";
$continueSession = false;

// NEW SESSION
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to Ghartey Event\nEnter Nominee Code:";
    $continueSession = true;
}
// Step 1: User entered code
elseif (!isset($_SESSION['step'])) {
    $code = strtoupper(trim($userData));
    $nominee = findNominee($code);
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'votes';
        
        $message = $nominee['name'] . "\n";
        if (isset($nominee['category'])) $message .= "Category: " . $nominee['category'] . "\n";
        $message .= "Code: " . $nominee['code'] . "\n";
        $message .= "Price: GHC " . $nominee['price'] . "/vote\n";
        $message .= "Current votes: " . $nominee['votes'] . "\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "Invalid code!\nTry FS1-FS5 or PG2, etc.\n\nEnter Nominee Code:";
        $continueSession = true;
    }
}
// Step 2: User entered votes
elseif ($_SESSION['step'] == 'votes') {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "Enter 1-1000 votes:";
        $continueSession = true;
    } else {
        $_SESSION['votes'] = $votes;
        $total = $votes * $_SESSION['nominee']['price'];
        
        $message = "Summary:\n";
        $message .= $_SESSION['nominee']['name'] . "\n";
        $message .= "Votes: " . $votes . "\n";
        $message .= "Total: GHC " . $total . "\n\n";
        $message .= "1. Pay\n2. Cancel";
        $continueSession = true;
        $_SESSION['step'] = 'payment';
    }
}
// Step 3: User chose pay or cancel
elseif ($_SESSION['step'] == 'payment') {
    if ($userData == '1') {
        $total = $_SESSION['votes'] * $_SESSION['nominee']['price'];
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        
        $paymentUrl = createPayment($msisdn, $total, $reference);
        
        if ($paymentUrl) {
            $message = "Payment of GHC " . $total . " initiated.\n";
            $message .= "Check your phone for the payment prompt.\n";
            $message .= "Enter your PIN to complete payment.\n\n";
            $message .= "Votes will be added after payment.";
            $continueSession = false;
            
            // Log
            file_put_contents('votes.log', date('Y-m-d H:i:s') . " - $msisdn - {$_SESSION['nominee']['code']} - {$_SESSION['votes']} votes\n", FILE_APPEND);
        } else {
            $message = "Payment failed. Try again.";
            $continueSession = false;
        }
    } 
    elseif ($userData == '2') {
        $message = "Cancelled. Enter new code:";
        $continueSession = true;
        $_SESSION = [];
    }
    else {
        $message = "1. Pay\n2. Cancel";
        $continueSession = true;
    }
}

// Send response
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
