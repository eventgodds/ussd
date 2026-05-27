<?php
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Read request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

// Use sessionID as key for storing state (USSD doesn't maintain PHP session across requests reliably)
$stateFile = sys_get_temp_dir() . "/ussd_" . md5($sessionID) . ".json";

// Load state
if ($newSession || !file_exists($stateFile)) {
    $state = ['step' => 'GET_CODE'];
} else {
    $state = json_decode(file_get_contents($stateFile), true);
}

// Function to save state
function saveState($state, $file) {
    file_put_contents($file, json_encode($state));
}

// Fetch functions (same as before)
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
    curl_close($ch);
    if ($httpCode == 200) {
        $result = json_decode($response, true);
        if ($result['status']) return $result['data']['authorization_url'];
    }
    return false;
}

// USSD Logic
$message = "";
$continueSession = true;

if ($newSession) {
    // New session: reset state
    $state = ['step' => 'GET_CODE'];
    saveState($state, $stateFile);
    $message = "Welcome to GHartey Voting!\nEnter Nominee Code (FS1, FS2, PG1, etc.):";
} else {
    // Existing session
    $step = $state['step'];
    
    if ($step == 'GET_CODE') {
        // User entered a code
        $code = strtoupper($userData);
        $nominee = fetchFromContestantsDB($contestantsFirestoreUrl, $code);
        if (!$nominee) $nominee = fetchFromAwardsDB($awardsFirestoreUrl, $code);
        
        if ($nominee) {
            $state['nominee'] = $nominee;
            $state['step'] = 'GET_VOTES';
            saveState($state, $stateFile);
            
            $categoryText = isset($nominee['category']) ? " ({$nominee['category']})" : "";
            $message = "Vote for: {$nominee['name']}{$categoryText}\n";
            $message .= "Code: {$nominee['code']}\n";
            $message .= "Current votes: {$nominee['votes']}\n";
            $message .= "GHC {$nominee['voteAmount']}/vote\n\n";
            $message .= "Enter number of votes (1-1000):";
        } else {
            $message = "Invalid code '{$code}'!\nTry FS1, FS2, PG1, etc.\nEnter Nominee Code:";
            // stay in GET_CODE
        }
    } 
    elseif ($step == 'GET_VOTES') {
        // User entered number of votes
        if (!is_numeric($userData)) {
            $message = "Please enter a valid number (1-1000):";
        } else {
            $votes = intval($userData);
            if ($votes < 1 || $ votes > 1000) {
                $message = "Number must be between 1 and 1000. Try again:";
            } else {
                $nominee = $state['nominee'];
                $total = $votes * $nominee['voteAmount'];
                $state['pending_votes'] = $votes;
                $state['total_amount'] = $total;
                $state['step'] = 'CONFIRM';
                saveState($state, $stateFile);
                
                $message = "═══════════════════\n";
                $message .= "VOTE SUMMARY\n";
                $message .= "═══════════════════\n";
                $message .= "Nominee: {$nominee['name']}\n";
                $message .= "Code: {$nominee['code']}\n";
                $message .= "Votes: {$votes}\n";
                $message .= "Total: GHC {$total}\n";
                $message .= "═══════════════════\n\n";
                $message .= "1. Proceed to Pay\n2. Cancel";
            }
        }
    }
    elseif ($step == 'CONFIRM') {
        if ($userData == '1') {
            $nominee = $state['nominee'];
            $votes = $state['pending_votes'];
            $total = $state['total_amount'];
            $reference = "VOTE_" . time() . "_" . rand(1000,9999);
            $callbackUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
            $email = $msisdn . "@ussd.voter.com";
            $metadata = [
                'msisdn' => $msisdn,
                'nominee_code' => $nominee['code'],
                'votes' => $votes,
                'type' => $nominee['type']
            ];
            $paymentUrl = createPaystackPayment($email, $total, $reference, $callbackUrl, $metadata);
            if ($paymentUrl) {
                $message = "═══════════════════\n";
                $message .= "AUTHORIZATION REQUIRED\n";
                $message .= "═══════════════════\n\n";
                $message .= "Amount: GHC {$total}\n\n";
                $message .= "Click link to pay:\n{$paymentUrl}\n\n";
                $message .= "After payment, votes added.\nThank you!";
                $continueSession = false;
                // Delete state file after completion
                unlink($stateFile);
            } else {
                $message = "Payment error. Please try again.";
                $continueSession = false;
            }
        } elseif ($userData == '2') {
            $message = "Vote cancelled.\n\nEnter Nominee Code:";
            $state = ['step' => 'GET_CODE'];
            saveState($state, $stateFile);
        } else {
            $message = "Choose 1 to pay or 2 to cancel:";
        }
    }
    else {
        // fallback
        $state = ['step' => 'GET_CODE'];
        saveState($state, $stateFile);
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
