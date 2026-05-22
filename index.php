<?php
/*
|--------------------------------------------------------------------------
| COMPLETE USSD APPLICATION - WORKS OUT OF THE BOX
|--------------------------------------------------------------------------
*/

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| 1. RECEIVE USSD REQUEST
|--------------------------------------------------------------------------
*/
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$sessionID = $data['sessionID'] ?? 'test_' . time();
$userID = $data['userID'] ?? 'user_' . rand(1000, 9999);
$msisdn = $data['msisdn'] ?? '233241234567';
$newSession = $data['newSession'] ?? true;
$userData = trim($data['userData'] ?? '');

/*
|--------------------------------------------------------------------------
| 2. LOCAL DATABASE (JSON FILE) - FALLBACK IF FIREBASE FAILS
|--------------------------------------------------------------------------
*/
$dataFile = 'contestants.json';

// Initialize local database if not exists
if (!file_exists($dataFile)) {
    $sampleData = [
        'contestants' => [
            ['code' => 'FS1', 'name' => 'John Doe', 'category' => 'Singing', 'votes' => 0],
            ['code' => 'FS2', 'name' => 'Jane Smith', 'category' => 'Dancing', 'votes' => 0],
            ['code' => 'FS3', 'name' => 'Mike Johnson', 'category' => 'Comedy', 'votes' => 0],
            ['code' => 'FS4', 'name' => 'Sarah Williams', 'category' => 'Singing', 'votes' => 0],
            ['code' => 'FS5', 'name' => 'David Brown', 'category' => 'Dancing', 'votes' => 0]
        ],
        'votes' => []
    ];
    file_put_contents($dataFile, json_encode($sampleData, JSON_PRETTY_PRINT));
}

// Read local database
$db = json_decode(file_get_contents($dataFile), true);

/*
|--------------------------------------------------------------------------
| 3. FIREBASE FIRESTORE FUNCTIONS (OPTIONAL - FOR REAL DATA)
|--------------------------------------------------------------------------
*/
function firebaseGetContestants() {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/contestants?key={$apiKey}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        $contestants = [];
        
        if (isset($data['documents'])) {
            foreach ($data['documents'] as $doc) {
                $contestant = [];
                if (isset($doc['fields']['code']['stringValue'])) {
                    $contestant['code'] = $doc['fields']['code']['stringValue'];
                }
                if (isset($doc['fields']['contestant_name']['stringValue'])) {
                    $contestant['name'] = $doc['fields']['contestant_name']['stringValue'];
                }
                if (!empty($contestant)) {
                    $contestants[] = $contestant;
                }
            }
        }
        return $contestants;
    }
    
    return null;
}

function firebaseSaveVote($msisdn, $contestantCode, $contestantName) {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    $voteId = 'vote_' . time() . '_' . rand(1000, 9999);
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/votes/{$voteId}?key={$apiKey}";
    
    $voteData = [
        'msisdn' => $msisdn,
        'contestant_code' => $contestantCode,
        'contestant_name' => $contestantName,
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'completed'
    ];
    
    $firestoreData = ['fields' => []];
    foreach ($voteData as $key => $value) {
        $firestoreData['fields'][$key] = ['stringValue' => $value];
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($firestoreData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

/*
|--------------------------------------------------------------------------
| 4. GET CONTESTANTS (TRY FIREBASE FIRST, THEN LOCAL)
|--------------------------------------------------------------------------
*/
$contestants = firebaseGetContestants();

if ($contestants === null || empty($contestants)) {
    // Use local database if Firebase fails
    $contestants = $db['contestants'];
    $usingFirebase = false;
} else {
    $usingFirebase = true;
}

/*
|--------------------------------------------------------------------------
| 5. USSD MENU LOGIC
|--------------------------------------------------------------------------
*/
$message = "";
$continueSession = true;
$inputParts = explode('*', $userData);
$mainOption = $inputParts[0] ?? '';

// NEW SESSION - Show Main Menu
if ($newSession == true) {
    $message = "=== GHARTEY EVENT ===\n";
    $message .= "1️⃣ Vote for Contestant\n";
    $message .= "2️⃣ View Contestants\n";
    $message .= "3️⃣ About\n";
    $message .= "0️⃣ Exit\n";
    $message .= "═══════════════════\n";
    $message .= "Enter choice:";
}
// EXIT
elseif ($userData == "0" || $userData == "00") {
    $message = "Thank you for using Ghartey Event!\nGoodbye! 👋";
    $continueSession = false;
}
// VIEW CONTESTANTS
elseif ($userData == "2") {
    if (count($contestants) > 0) {
        $message = "📋 CONTESTANTS LIST\n";
        $message .= "═══════════════════\n";
        foreach ($contestants as $index => $c) {
            $num = $index + 1;
            $message .= "$num. {$c['name']}\n";
            $message .= "   Code: {$c['code']}\n";
            if (isset($c['category'])) {
                $message .= "   {$c['category']}\n";
            }
            $message .= "───────────────────\n";
        }
        $message .= "0️⃣ Back to Menu";
    } else {
        $message = "No contestants available.\n0️⃣ Back to Menu";
    }
}
// ABOUT
elseif ($userData == "3") {
    $message = "📌 ABOUT GHARTEY EVENT\n";
    $message .= "═══════════════════\n";
    $message .= "Vote for your favorite\n";
    $message .= "contestant by entering\n";
    $message .= "their code.\n";
    $message .= "═══════════════════\n";
    $message .= "0️⃣ Back to Menu";
}
// VOTE - Ask for code
elseif ($userData == "1") {
    $message = "🗳️ ENTER CONTESTANT CODE\n";
    $message .= "═══════════════════\n";
    $message .= "Valid codes:\n";
    foreach ($contestants as $c) {
        $message .= "• {$c['code']}\n";
    }
    $message .= "═══════════════════\n";
    $message .= "Code:";
}
// VOTE - Process the vote
elseif (strpos($userData, "1*") === 0) {
    $enteredCode = strtoupper(str_replace("1*", "", $userData));
    
    // Find contestant
    $found = null;
    foreach ($contestants as $c) {
        if ($c['code'] == $enteredCode) {
            $found = $c;
            break;
        }
    }
    
    if ($found) {
        // Save to Firebase (if available)
        $firebaseSaved = false;
        if ($usingFirebase) {
            $firebaseSaved = firebaseSaveVote($msisdn, $found['code'], $found['name']);
        }
        
        // Save to local database
        $db['votes'][] = [
            'msisdn' => $msisdn,
            'contestant_code' => $found['code'],
            'contestant_name' => $found['name'],
            'timestamp' => date('Y-m-d H:i:s'),
            'sessionID' => $sessionID
        ];
        
        // Update vote count
        foreach ($db['contestants'] as &$c) {
            if ($c['code'] == $found['code']) {
                $c['votes']++;
                break;
            }
        }
        file_put_contents($dataFile, json_encode($db, JSON_PRETTY_PRINT));
        
        $message = "✅ VOTE SUCCESSFUL!\n";
        $message .= "═══════════════════\n";
        $message .= "You voted for:\n";
        $message .= "{$found['name']}\n";
        $message .= "Code: {$found['code']}\n";
        $message .= "═══════════════════\n";
        $message .= "Thank you! 🙏\n\n";
        $message .= "1️⃣ Vote Again\n";
        $message .= "0️⃣ Main Menu";
    } else {
        $message = "❌ INVALID CODE!\n";
        $message .= "═══════════════════\n";
        $message .= "'$enteredCode' not found\n";
        $message .= "═══════════════════\n";
        $message .= "Valid codes:\n";
        foreach ($contestants as $c) {
            $message .= "• {$c['code']}\n";
        }
        $message .= "═══════════════════\n";
        $message .= "1️⃣ Try Again\n";
        $message .= "0️⃣ Main Menu";
    }
}
// VOTE AGAIN
elseif ($userData == "1*1") {
    $message = "Enter contestant code:";
}
// BACK TO MAIN MENU
elseif ($userData == "00" || $userData == "1*0") {
    $message = "=== GHARTEY EVENT ===\n";
    $message .= "1️⃣ Vote for Contestant\n";
    $message .= "2️⃣ View Contestants\n";
    $message .= "3️⃣ About\n";
    $message .= "0️⃣ Exit\n";
    $message .= "═══════════════════\n";
    $message .= "Enter choice:";
}
// INVALID INPUT
else {
    $message = "❌ Invalid: '$userData'\n";
    $message .= "═══════════════════\n";
    $message .= "1️⃣ Vote\n";
    $message .= "2️⃣ View Contestants\n";
    $message .= "0️⃣ Exit";
}

/*
|--------------------------------------------------------------------------
| 6. SEND RESPONSE
|--------------------------------------------------------------------------
*/
$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

header('Content-Type: application/json');
echo json_encode($response);
?>
