<?php
/*
|--------------------------------------------------------------------------
| COMPLETE USSD APPLICATION WITH FIREBASE FIRESTORE
|--------------------------------------------------------------------------
*/

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Create log file
function logMessage($msg) {
    file_put_contents('ussd_log.txt', date('Y-m-d H:i:s') . " - " . $msg . "\n", FILE_APPEND);
}

logMessage("=== NEW REQUEST ===");

/*
|--------------------------------------------------------------------------
| 1. RECEIVE USSD REQUEST
|--------------------------------------------------------------------------
*/
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$sessionID = $data['sessionID'] ?? 'test123';
$userID = $data['userID'] ?? 'user456';
$msisdn = $data['msisdn'] ?? '233241234567';
$newSession = $data['newSession'] ?? true;
$userData = trim($data['userData'] ?? '');

logMessage("Input received: " . $rawInput);
logMessage("User Data: '$userData'");
logMessage("New Session: " . ($newSession ? 'Yes' : 'No'));

/*
|--------------------------------------------------------------------------
| 2. FIREBASE FIRESTORE SETUP
|--------------------------------------------------------------------------
*/
$projectId = 'eventgodds-41e4f';
$apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';

/**
 * CREATE SAMPLE DATA IN FIRESTORE
 * This will run automatically if no contestants exist
 */
function initializeSampleData() {
    global $projectId, $apiKey;
    
    // Sample contestants data
    $sampleContestants = [
        ['code' => 'FS1', 'contestant_name' => 'John Doe', 'category' => 'Singing', 'votes' => 0],
        ['code' => 'FS2', 'contestant_name' => 'Jane Smith', 'category' => 'Dancing', 'votes' => 0],
        ['code' => 'FS3', 'contestant_name' => 'Mike Johnson', 'category' => 'Comedy', 'votes' => 0],
        ['code' => 'FS4', 'contestant_name' => 'Sarah Williams', 'category' => 'Singing', 'votes' => 0],
        ['code' => 'FS5', 'contestant_name' => 'David Brown', 'category' => 'Dancing', 'votes' => 0]
    ];
    
    $added = 0;
    foreach ($sampleContestants as $contestant) {
        $url = "https://firestore.googleapis.com/v1/projects/eventgodds-41e4f/databases/(default)/documents/contestants?key=AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk";
        
        $firestoreData = ['fields' => []];
        foreach ($contestant as $key => $value) {
            if (is_string($value)) {
                $firestoreData['fields'][$key] = ['stringValue' => $value];
            } else {
                $firestoreData['fields'][$key] = ['integerValue' => $value];
            }
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($firestoreData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) $added++;
    }
    
    return $added;
}

/**
 * FETCH ALL CONTESTANTS FROM FIRESTORE
 */
function getContestants() {
    global $projectId, $apiKey;
    
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/contestants?key={$apiKey}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logMessage("Firestore GET contestants - HTTP Code: " . $httpCode);
    
    if ($httpCode != 200) {
        logMessage("Error fetching: " . $response);
        return [];
    }
    
    $data = json_decode($response, true);
    $contestants = [];
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $contestant = [];
            $contestant['id'] = basename($doc['name']);
            
            if (isset($doc['fields']['contestant_name']['stringValue'])) {
                $contestant['contestant_name'] = $doc['fields']['contestant_name']['stringValue'];
            }
            if (isset($doc['fields']['code']['stringValue'])) {
                $contestant['code'] = $doc['fields']['code']['stringValue'];
            }
            if (isset($doc['fields']['category']['stringValue'])) {
                $contestant['category'] = $doc['fields']['category']['stringValue'];
            }
            if (isset($doc['fields']['votes']['integerValue'])) {
                $contestant['votes'] = $doc['fields']['votes']['integerValue'];
            }
            
            if (!empty($contestant)) {
                $contestants[] = $contestant;
            }
        }
    }
    
    logMessage("Found " . count($contestants) . " contestants");
    return $contestants;
}

/**
 * FIND CONTESTANT BY CODE
 */
function findContestant($code) {
    $contestants = getContestants();
    foreach ($contestants as $c) {
        if (strtoupper($c['code']) == strtoupper($code)) {
            return $c;
        }
    }
    return null;
}

/**
 * SAVE VOTE TO FIRESTORE
 */
function saveVote($msisdn, $contestant, $sessionID) {
    global $projectId, $apiKey;
    
    $voteId = 'vote_' . time() . '_' . rand(1000, 9999);
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/votes/{$voteId}?key={$apiKey}";
    
    $voteData = [
        'msisdn' => $msisdn,
        'contestant_code' => $contestant['code'],
        'contestant_name' => $contestant['contestant_name'],
        'sessionID' => $sessionID,
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
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logMessage("Save vote - HTTP Code: " . $httpCode);
    return $httpCode == 200;
}

/*
|--------------------------------------------------------------------------
| 3. CHECK AND INITIALIZE DATA
|--------------------------------------------------------------------------
*/
// Check if we have contestants, if not, create sample data
$existingContestants = getContestants();
if (empty($existingContestants)) {
    logMessage("No contestants found. Creating sample data...");
    $added = initializeSampleData();
    logMessage("Added $added sample contestants");
    $existingContestants = getContestants();
}

/*
|--------------------------------------------------------------------------
| 4. USSD MENU LOGIC
|--------------------------------------------------------------------------
*/
$message = "";
$continueSession = true;

// Split input for processing
$parts = explode('*', $userData);
$level1 = $parts[0] ?? '';

logMessage("Menu Level: $level1, Parts: " . print_r($parts, true));

// NEW SESSION - Main Menu
if ($newSession == true) {
    $message = "Welcome to Ghartey Event!\n";
    $message .= "====================\n";
    $message .= "1. Vote for Contestant\n";
    $message .= "2. View All Contestants\n";
    $message .= "3. My Voting History\n";
    $message .= "0. Exit\n";
    $message .= "====================\n";
    $message .= "Enter your choice:";
}
// EXIT
elseif ($userData == "0" || $userData == "00") {
    $message = "Thank you for using Ghartey Event Voting System!\n";
    $message .= "Goodbye!";
    $continueSession = false;
}
// VIEW ALL CONTESTANTS
elseif ($userData == "2") {
    $contestants = getContestants();
    
    if (count($contestants) > 0) {
        $message = "📋 ALL CONTESTANTS\n";
        $message .= "=================\n";
        foreach ($contestants as $index => $c) {
            $num = $index + 1;
            $message .= "$num. {$c['contestant_name']}\n";
            $message .= "   Code: {$c['code']}\n";
            $message .= "   Category: {$c['category']}\n";
            $message .= "-----------------\n";
        }
        $message .= "0. Back to Main Menu";
    } else {
        $message = "No contestants available.\n0. Back to Main Menu";
    }
}
// VOTE - Ask for code
elseif ($userData == "1") {
    $message = "Enter contestant code to vote:\n";
    $message .= "(e.g., FS1, FS2, FS3, FS4, FS5)\n";
    $message .= "0. Cancel";
}
// VOTE - Process the vote
elseif (strpos($userData, "1*") === 0) {
    $code = strtoupper(str_replace("1*", "", $userData));
    
    if ($code == "0") {
        $message = "Vote cancelled.\n0. Back to Main Menu";
    } else {
        $contestant = findContestant($code);
        
        if ($contestant) {
            // Save the vote
            $saved = saveVote($msisdn, $contestant, $sessionID);
            
            if ($saved) {
                $message = "✅ VOTE SUCCESSFUL!\n";
                $message .= "=================\n";
                $message .= "You voted for:\n";
                $message .= "{$contestant['contestant_name']}\n";
                $message .= "Code: {$contestant['code']}\n";
                $message .= "Category: {$contestant['category']}\n";
                $message .= "=================\n";
                $message .= "Thank you for voting!\n\n";
                $message .= "1. Vote Again\n";
                $message .= "0. Main Menu";
            } else {
                $message = "❌ Error saving vote.\n";
                $message .= "Please try again.\n";
                $message .= "1. Try Again\n";
                $message .= "0. Main Menu";
            }
        } else {
            $message = "❌ Contestant code '$code' not found!\n";
            $message .= "Valid codes: FS1, FS2, FS3, FS4, FS5\n";
            $message .= "1. Try Again\n";
            $message .= "0. Main Menu";
        }
    }
}
// VOTE AGAIN
elseif ($userData == "1*1") {
    $message = "Enter contestant code:\n";
    $message .= "(e.g., FS1, FS2, FS3, FS4, FS5)\n";
    $message .= "0. Cancel";
}
// VIEW VOTING HISTORY
elseif ($userData == "3") {
    $message = "📊 MY VOTING HISTORY\n";
    $message .= "==================\n";
    $message .= "You haven't voted yet.\n";
    $message .= "Use option 1 to vote!\n\n";
    $message .= "0. Back to Main Menu";
}
// BACK TO MENU
elseif ($userData == "00" || $userData == "1*0") {
    $message = "Main Menu\n";
    $message .= "1. Vote for Contestant\n";
    $message .= "2. View All Contestants\n";
    $message .= "3. My Voting History\n";
    $message .= "0. Exit";
}
// INVALID INPUT
else {
    $message = "Invalid option: '$userData'\n";
    $message .= "Please try again.\n\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}

/*
|--------------------------------------------------------------------------
| 5. SEND RESPONSE
|--------------------------------------------------------------------------
*/
$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

logMessage("Response: " . json_encode($response));
logMessage("=== END REQUEST ===\n");

header('Content-Type: application/json');
echo json_encode($response);
?>
