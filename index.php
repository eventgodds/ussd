<?php
header('Content-Type: application/json');

// Database configurations
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";

$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Paystack configuration (TEST FIRST, then change to LIVE)
$paystackSecretKey = 'sk_test_...'; // REPLACE with your test key first
$paystackPublicKey = 'pk_test_...';  // REPLACE with your test key first

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// ============ FUNCTION: Fetch ALL Award Nominees (No filters) ============
function fetchAllAwardNominees($firestoreUrl) {
    $url = $firestoreUrl . "/awards_nominees";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        return [];
    }
    
    $data = json_decode($response, true);
    $nominees = [];
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            
            // Get nominee code from different possible field names
            $nomineeCode = '';
            if (isset($fields['nomineeCode']['stringValue'])) {
                $nomineeCode = $fields['nomineeCode']['stringValue'];
            } elseif (isset($fields['code']['stringValue'])) {
                $nomineeCode = $fields['code']['stringValue'];
            }
            
            // Skip if no code found
            if (empty($nomineeCode)) {
                continue;
            }
            
            // Get status - only include if approved or no status field
            $status = $fields['status']['stringValue'] ?? '';
            if ($status != '' && $status != 'approved') {
                continue; // Skip non-approved nominees
            }
            
            $nominees[] = [
                'id' => basename($doc['name']),
                'nomineeCode' => $nomineeCode,
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
    
    return $nominees;
}

// ============ FUNCTION: Fetch Single Award Nominee by Code ============
function fetchAwardNomineeByCode($firestoreUrl, $code) {
    $allNominees = fetchAllAwardNominees($firestoreUrl);
    
    foreach ($allNominees as $nominee) {
        if (strtoupper($nominee['nomineeCode']) === strtoupper($code)) {
            return $nominee;
        }
    }
    return null;
}

// ============ FUNCTION: Fetch Contestant by Code ============
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
                    'fullName' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
                    'stageName' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? '',
                    'categoryName' => 'Contestant',
                    'votes' => $fields['votes']['integerValue'] ?? 0,
                    'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
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

// ============ CHECK FOR PAYMENT CALLBACK ============
if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    
    // Verify payment here (simplified for demo)
    echo "Payment verified! Votes will be added.";
    exit;
}

// ============ DEBUG: Cache all nominees for quick lookup ============
if (!isset($_SESSION['all_nominees'])) {
    $_SESSION['all_nominees'] = fetchAllAwardNominees($awardsFirestoreUrl);
    error_log("Loaded " . count($_SESSION['all_nominees']) . " nominees from awards database");
}

// ============ USSD MENU LOGIC ============
$message = "";
$continueSession = false;

// MAIN WELCOME
if ($newSession == true) {
    $_SESSION = [];
    $_SESSION['all_nominees'] = fetchAllAwardNominees($awardsFirestoreUrl);
    
    $message = "🎉 Welcome to GHartey Voting!\n\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "Examples: FS1, PG1, AOY1, BAP1\n";
    $message .= "Type HELP for list of codes";
    $continueSession = true;
}

// HELP - Show sample codes
elseif (strtoupper($userData) == "HELP") {
    $message = "📋 Sample Nominee Codes:\n";
    $message .= "Contestants: FS1, FS2, FS3, FS4, FS5\n";
    $message .= "Awards: PG1, PG2, PG3, AOY1, AOY2, AOY3\n";
    $message .= "Awards: BAP1, SMS1, SJY1, MPS1, MPS2\n";
    $message .= "Awards: SPO1, SPO2, SPO3, SPO4\n\n";
    $message .= "Enter your code to vote:";
    $continueSession = true;
}

// User entered a nominee code
elseif (!isset($_SESSION['step']) || $_SESSION['step'] == 'get_code') {
    $nomineeCode = strtoupper(trim($userData));
    
    // Search in contestants first
    $nominee = fetchContestantByCode($contestantsFirestoreUrl, $nomineeCode);
    
    // If not found, search in awards nominees
    if (!$nominee) {
        $nominee = fetchAwardNomineeByCode($awardsFirestoreUrl, $nomineeCode);
    }
    
    // If still not found, check cached list
    if (!$nominee && isset($_SESSION['all_nominees'])) {
        foreach ($_SESSION['all_nominees'] as $cached) {
            if ($cached['nomineeCode'] === $nomineeCode) {
                $nominee = $cached;
                break;
            }
        }
    }
    
    if ($nominee) {
        $_SESSION['nominee'] = $nominee;
        $_SESSION['step'] = 'get_votes';
        
        $displayName = $nominee['stageName'] ?: $nominee['fullName'] ?: $nominee['nomineeCode'];
        $categoryText = $nominee['categoryName'] ? " ({$nominee['categoryName']})" : "";
        
        $message = "🗳️ VOTE FOR: {$displayName}{$categoryText}\n";
        $message .= "━━━━━━━━━━━━━━━━\n";
        $message .= "Code: {$nominee['nomineeCode']}\n";
        $message .= "Current Votes: {$nominee['votes']}\n";
        $message .= "Cost: GHC {$nominee['voteAmount']}/vote\n";
        $message .= "━━━━━━━━━━━━━━━━\n\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        $message = "❌ Invalid Code: '{$nomineeCode}'\n\n";
        $message .= "✅ Valid Examples:\n";
        $message .= "• FS1, FS2, FS3, FS4, FS5 (Contestants)\n";
        $message .= "• PG1, PG2, PG3 (Perfect Gentleman)\n";
        $message .= "• AOY1, AOY2, AOY3 (Artist of Year)\n";
        $message .= "• BAP1, SMS1, MPS1, SPO1\n\n";
        $message .= "Enter valid code or HELP:";
        $continueSession = true;
    }
}

// User entered number of votes
elseif ($_SESSION['step'] == 'get_votes' && is_numeric($userData)) {
    $votes = intval($userData);
    $nominee = $_SESSION['nominee'];
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Enter 1-1000 votes:";
        $continueSession = true;
    } else {
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $_SESSION['pending_votes'] = $votes;
        
        $displayName = $nominee['stageName'] ?: $nominee['fullName'] ?: $nominee['nomineeCode'];
        
        $message = "📊 VOTE SUMMARY\n";
        $message .= "━━━━━━━━━━━━━━━━\n";
        $message .= "Nominee: {$displayName}\n";
        $message .= "Code: {$nominee['nomineeCode']}\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Total: GHC {$totalAmount}\n";
        $message .= "━━━━━━━━━━━━━━━━\n\n";
        $message .= "1️⃣ Proceed to Payment\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
        $_SESSION['step'] = 'confirm';
    }
}

// Process payment or cancel
elseif ($_SESSION['step'] == 'confirm') {
    if ($userData == "1") {
        $nominee = $_SESSION['nominee'];
        $votes = $_SESSION['pending_votes'];
        $totalAmount = $votes * $nominee['voteAmount'];
        
        $reference = "VOTE_" . time() . "_" . rand(1000, 9999);
        $customerEmail = $msisdn . "@ussd.voter.com";
        
        $metadata = [
            'msisdn' => $msisdn,
            'nominee_code' => $nominee['nomineeCode'],
            'votes' => $votes,
            'type' => $nominee['type']
        ];
        
        // For testing without Paystack - uncomment this section
        /*
        // TEST MODE - Skip actual payment
        $newVotes = $nominee['votes'] + $votes;
        if ($nominee['type'] == 'contestant') {
            updateVotes($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
        } else {
            updateVotes($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
        }
        
        $message = "✅ VOTE SUCCESSFUL (TEST)!\n\n";
        $message .= "{$votes} votes added for {$nominee['nomineeCode']}\n";
        $message .= "Total: GHC {$totalAmount}\n\n";
        $message .= "Thank you for voting!";
        $continueSession = false;
        */
        
        // REAL Paystack integration
        $paymentUrl = createPaystackPayment($customerEmail, $totalAmount, $reference, "https://yourdomain.com/ussd_handler.php", $metadata);
        
        if ($paymentUrl) {
            $message = "💳 Payment Required: GHC {$totalAmount}\n\n";
            $message .= "🔗 Click link to pay:\n{$paymentUrl}\n\n";
            $message .= "✅ After payment, votes added automatically!\n";
            $message .= "Thank you for voting! 🙏";
            $continueSession = false;
            
            // Log payment initiation
            $logEntry = date('Y-m-d H:i:s') . " | PAYMENT | MSISDN: {$msisdn} | Code: {$nominee['nomineeCode']} | Votes: {$votes}\n";
            file_put_contents('vote_log.txt', $logEntry, FILE_APPEND);
        } else {
            $message = "❌ Payment error. Please try again later.";
            $continueSession = false;
        }
        
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
        
    } elseif ($userData == "2") {
        $message = "❌ Vote cancelled.\n\nEnter Nominee Code to vote:";
        $continueSession = true;
        unset($_SESSION['step']);
        unset($_SESSION['nominee']);
        unset($_SESSION['pending_votes']);
    } else {
        $message = "Select 1 to pay or 2 to cancel:";
        $continueSession = true;
    }
}

// Default / Error recovery
else {
    $message = "🎉 Welcome to GHartey Voting!\n\nEnter Nominee Code to vote:\n(Examples: FS1, PG1, AOY1)";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
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
