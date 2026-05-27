<?php
header('Content-Type: application/json');

// ============ DIRECT DATABASE ACCESS ============
// Instead of complex pagination, let's get ALL data directly

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn = $data['msisdn'] ?? '';
$userData = trim($data['userData'] ?? '');

session_start();

// ============ FUNCTION: DIRECT CURL TO GET ALL NOMINEES ============
function getAllNomineesDirect() {
    $allNominees = [];
    
    // FIRST: Get contestants from eventgodds-41e4f (FS1-FS5)
    $contestantsUrl = "https://firestore.googleapis.com/v1/projects/eventgodds-41e4f/databases/(default)/documents/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $contestantsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue'])) {
                $code = $fields['code']['stringValue'];
                if (preg_match('/^FS[1-5]$/', $code)) {
                    $allNominees[$code] = [
                        'code' => $code,
                        'name' => $fields['stageName']['stringValue'] ?? $fields['name']['stringValue'] ?? $code,
                        'votes' => intval($fields['votes']['integerValue'] ?? 0),
                        'price' => 1,
                        'category' => 'Ghartey Event Contestant',
                        'collection' => 'contestants',
                        'project' => 'eventgodds-41e4f',
                        'docId' => basename($doc['name'])
                    ];
                }
            }
        }
    }
    
    // SECOND: Get ALL awards nominees from eventgodds
    // We'll use a simple approach - get all documents at once
    $awardsUrl = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents/awards_nominees";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $awardsUrl);
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
                
                // Check if this is an approved nominee with a nomineeCode
                if (isset($fields['nomineeCode']['stringValue']) && 
                    isset($fields['status']['stringValue']) && 
                    $fields['status']['stringValue'] == 'approved') {
                    
                    $code = $fields['nomineeCode']['stringValue'];
                    $allNominees[$code] = [
                        'code' => $code,
                        'name' => $fields['stageName']['stringValue'] ?? $fields['fullName']['stringValue'] ?? $code,
                        'votes' => intval($fields['votes']['integerValue'] ?? 0),
                        'price' => 1,
                        'category' => $fields['categoryName']['stringValue'] ?? 'Award Nominee',
                        'collection' => 'awards_nominees',
                        'project' => 'eventgodds',
                        'docId' => basename($doc['name'])
                    ];
                }
            }
        }
    }
    
    return $allNominees;
}

// ============ FUNCTION: Update Votes in Firebase ============
function updateVotesDirect($project, $collection, $docId, $newVotes) {
    $url = "https://firestore.googleapis.com/v1/projects/{$project}/databases/(default)/documents/{$collection}/{$docId}?updateMask.fieldPaths=votes";
    
    $updateData = [
        'fields' => [
            'votes' => [
                'integerValue' => (string)$newVotes
            ]
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

// ============ GET ALL NOMINEES ============
$allNominees = getAllNomineesDirect();

// ============ USSD LOGIC ============
$message = "";
$continueSession = false;

// MAIN MENU - First time
if ($newSession == true) {
    $_SESSION = [];
    $message = "Welcome to GHartey Voting!\n";
    $message .= "Enter Nominee Code to vote:\n";
    $message .= "Examples: FS1, AOY1, PG1, BGE1, SPO4";
    $continueSession = true;
}
// User entered a nominee code
elseif (!isset($_SESSION['step'])) {
    $enteredCode = strtoupper(trim($userData));
    
    // Check if code exists in our array
    if (isset($allNominees[$enteredCode])) {
        $_SESSION['nominee'] = $allNominees[$enteredCode];
        $_SESSION['step'] = 'votes';
        
        $nom = $allNominees[$enteredCode];
        $message = "🗳️ VOTE FOR: {$nom['name']}\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "📋 Code: {$nom['code']}\n";
        $message .= "🏆 Category: {$nom['category']}\n";
        $message .= "📊 Current Votes: {$nom['votes']}\n";
        $message .= "💰 Price: GHS {$nom['price']}/vote\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
    } else {
        // Show some valid examples
        $validCodes = array_slice(array_keys($allNominees), 0, 10);
        $message = "❌ Invalid Code: {$enteredCode}\n\n";
        $message .= "✅ Valid examples:\n";
        foreach ($validCodes as $code) {
            $message .= "• {$code}\n";
        }
        $message .= "\nEnter valid nominee code:";
        $continueSession = true;
    }
}
// User entered number of votes
elseif ($_SESSION['step'] == 'votes' && is_numeric($userData)) {
    $votes = intval($userData);
    
    if ($votes < 1 || $votes > 1000) {
        $message = "❌ Invalid! Enter 1-1000 votes:";
        $continueSession = true;
    } else {
        $nom = $_SESSION['nominee'];
        $total = $votes * $nom['price'];
        
        $_SESSION['pending_votes'] = $votes;
        $_SESSION['step'] = 'confirm';
        
        $message = "📝 VOTE SUMMARY\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "Nominee: {$nom['name']}\n";
        $message .= "Code: {$nom['code']}\n";
        $message .= "Votes: {$votes}\n";
        $message .= "Total: GHS {$total}\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "1️⃣ Confirm Vote\n";
        $message .= "2️⃣ Cancel";
        $continueSession = true;
    }
}
// User confirmed
elseif ($_SESSION['step'] == 'confirm' && $userData == "1") {
    $nom = $_SESSION['nominee'];
    $votes = $_SESSION['pending_votes'];
    $total = $votes * $nom['price'];
    
    // Update votes immediately (for testing without Paystack)
    $newVotes = $nom['votes'] + $votes;
    $success = updateVotesDirect($nom['project'], $nom['collection'], $nom['docId'], $newVotes);
    
    if ($success) {
        $message = "✅ VOTE SUCCESSFUL!\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "Nominee: {$nom['name']}\n";
        $message .= "Votes Added: {$votes}\n";
        $message .= "Total Paid: GHS {$total}\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "Thank you for voting!\n";
        $message .= "Dial again to vote more!";
        
        // Log the vote
        $log = date('Y-m-d H:i:s') . " | VOTE | {$nom['code']} | +{$votes} votes | Total: {$newVotes}\n";
        file_put_contents('votes_log.txt', $log, FILE_APPEND);
    } else {
        $message = "❌ Error processing vote. Please try again.";
    }
    
    $continueSession = false;
    session_destroy();
}
// User cancelled
elseif ($_SESSION['step'] == 'confirm' && $userData == "2") {
    $message = "❌ Vote cancelled.\n\nEnter nominee code to vote:";
    $continueSession = true;
    unset($_SESSION['step']);
    unset($_SESSION['nominee']);
    unset($_SESSION['pending_votes']);
}
// Invalid input
else {
    $message = "Enter nominee code (e.g., FS1, AOY1, PG1, BGE1):";
    $continueSession = true;
    unset($_SESSION['step']);
}

// Return response to Arkesel
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>
