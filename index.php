<?php
/*
|--------------------------------------------------------------------------
| SINGLE FILE USSD APPLICATION WITH FIREBASE FIRESTORE
|--------------------------------------------------------------------------
|
| This file handles USSD requests, interacts with Firebase Firestore,
| and returns the appropriate JSON response for Arkesel.
|
*/

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| 1. RECEIVE AND PARSE USSD REQUEST
|--------------------------------------------------------------------------
*/
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

// Arkesel sends these fields
$sessionID   = $data['sessionID'] ?? '';
$userID      = $data['userID'] ?? '';
$msisdn      = $data['msisdn'] ?? '';
$newSession  = $data['newSession'] ?? false;
$userData    = trim($data['userData'] ?? '');

// For debugging (logs to a file)
file_put_contents('ussd_debug.log', date('Y-m-d H:i:s') . " - Input: " . $rawInput . PHP_EOL, FILE_APPEND);

/*
|--------------------------------------------------------------------------
| 2. FIREBASE FIRESTORE CONFIGURATION
|--------------------------------------------------------------------------
*/
const FB_PROJECT_ID = 'eventgodds-41e4f';
const FB_API_KEY    = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';

/**
 * Fetch all documents from a Firestore collection
 */
function firestoreGetCollection(string $collection): array {
    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s?key=%s',
        FB_PROJECT_ID,
        $collection,
        FB_API_KEY
    );
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        file_put_contents('ussd_debug.log', "Firestore error: HTTP $httpCode - $response" . PHP_EOL, FILE_APPEND);
        return [];
    }
    
    $data = json_decode($response, true);
    $documents = [];
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $item = [];
            foreach ($doc['fields'] as $key => $value) {
                // Handle different Firestore value types
                if (isset($value['stringValue'])) {
                    $item[$key] = $value['stringValue'];
                } elseif (isset($value['integerValue'])) {
                    $item[$key] = (int)$value['integerValue'];
                } elseif (isset($value['doubleValue'])) {
                    $item[$key] = (float)$value['doubleValue'];
                } elseif (isset($value['booleanValue'])) {
                    $item[$key] = (bool)$value['booleanValue'];
                }
            }
            // Add document ID if needed
            $item['_id'] = basename($doc['name']);
            $documents[] = $item;
        }
    }
    
    return $documents;
}

/**
 * Find a contestant by their code (case-insensitive)
 */
function findContestantByCode(string $searchCode): ?array {
    $contestants = firestoreGetCollection('contestants');
    
    foreach ($contestants as $contestant) {
        // Check multiple possible field names for the code
        $code = $contestant['code'] ?? $contestant['contestant_code'] ?? $contestant['id'] ?? '';
        if (strtoupper(trim($code)) === strtoupper(trim($searchCode))) {
            return $contestant;
        }
    }
    
    return null;
}

/**
 * Save a vote to Firestore
 */
function saveVote(array $voteData): bool {
    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/votes?key=%s',
        FB_PROJECT_ID,
        FB_API_KEY
    );
    
    // Convert to Firestore document format
    $firestoreDoc = ['fields' => []];
    foreach ($voteData as $key => $value) {
        $firestoreDoc['fields'][$key] = ['stringValue' => (string)$value];
    }
    // Add a timestamp if not present
    if (!isset($voteData['timestamp'])) {
        $firestoreDoc['fields']['timestamp'] = ['stringValue' => date('Y-m-d H:i:s')];
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($firestoreDoc),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $success = ($httpCode === 200);
    file_put_contents('ussd_debug.log', "Save vote: " . ($success ? 'OK' : 'FAILED') . " - HTTP $httpCode" . PHP_EOL, FILE_APPEND);
    
    return $success;
}

/*
|--------------------------------------------------------------------------
| 3. USSD APPLICATION LOGIC
|--------------------------------------------------------------------------
*/
$message = '';
$continueSession = true;

// Split user input for multi-level menus (e.g., "1*FS1")
$inputParts = explode('*', $userData);
$mainOption = $inputParts[0] ?? '';

// NEW SESSION: Show Main Menu
if ($newSession === true) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}
// EXIT
elseif ($userData === '0' || $userData === '00') {
    $message = "Thank you for using Ghartey Event Voting System";
    $continueSession = false;
}
// VIEW CONTESTANTS (Option 2)
elseif ($mainOption === '2') {
    $contestants = firestoreGetCollection('contestants');
    
    if (empty($contestants)) {
        $message = "No contestants found at this time.\n0. Back to Menu";
    } else {
        $message = "📋 CONTESTANTS LIST\n";
        $message .= "━━━━━━━━━━━━━━━━\n";
        foreach ($contestants as $idx => $c) {
            $name = $c['contestant_name'] ?? $c['name'] ?? 'Unknown';
            $code = $c['code'] ?? $c['contestant_code'] ?? 'N/A';
            $message .= ($idx + 1) . ". $name\n";
            $message .= "   Code: $code\n";
            $message .= "━━━━━━━━━━━━━━━━\n";
            if ($idx >= 9) break; // USSD screen limit
        }
        $message .= "0. Back to Main Menu";
    }
}
// VOTE - Ask for contestant code (Option 1, first level)
elseif ($mainOption === '1' && count($inputParts) === 1) {
    $message = "Enter contestant code (e.g., FS1, FS2, etc.):";
}
// VOTE - Process the vote (Option 1 with code, e.g., "1*FS1")
elseif ($mainOption === '1' && count($inputParts) === 2) {
    $contestantCode = strtoupper(trim($inputParts[1]));
    
    // Find the contestant in Firestore
    $contestant = findContestantByCode($contestantCode);
    
    if ($contestant) {
        $contestantName = $contestant['contestant_name'] ?? $contestant['name'] ?? 'Unknown';
        
        // Prepare vote data
        $voteRecord = [
            'msisdn' => $msisdn,
            'userID' => $userID,
            'sessionID' => $sessionID,
            'contestant_code' => $contestantCode,
            'contestant_name' => $contestantName,
            'status' => 'completed',
            'voted_at' => date('Y-m-d H:i:s')
        ];
        
        // Save vote to Firestore
        $saved = saveVote($voteRecord);
        
        if ($saved) {
            $message = "✅ VOTE SUCCESSFUL!\n";
            $message .= "━━━━━━━━━━━━━━━━\n";
            $message .= "You voted for: $contestantName\n";
            $message .= "Contestant Code: $contestantCode\n";
            $message .= "━━━━━━━━━━━━━━━━\n";
            $message .= "Thank you for participating!\n\n";
            $message .= "1. Vote Again\n";
            $message .= "0. Main Menu";
        } else {
            $message = "❌ Error saving your vote.\n";
            $message .= "Please try again.\n\n";
            $message .= "1. Try Again\n";
            $message .= "0. Main Menu";
        }
    } else {
        $message = "❌ Contestant code '$contestantCode' not found!\n";
        $message .= "Please check the code and try again.\n\n";
        $message .= "1. Try Again\n";
        $message .= "2. View Contestants\n";
        $message .= "0. Main Menu";
    }
}
// VOTE AGAIN (Option 1 from confirmation menu)
elseif ($userData === '1*1') {
    $message = "Enter contestant code (e.g., FS1, FS2, etc.):";
}
// HANDLE "TRY AGAIN" or any other navigation
elseif ($mainOption === '1' && isset($inputParts[1]) && $inputParts[1] === '1') {
    // This catches the "1. Try Again" option from error menus
    $message = "Enter contestant code:";
}
// BACK TO MAIN MENU from any sub-menu
elseif ($userData === '0' || $userData === '00' || $userData === '1*0') {
    $message = "Main Menu\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}
// DEFAULT / INVALID INPUT
else {
    $message = "Invalid selection.\n\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}

/*
|--------------------------------------------------------------------------
| 4. SEND RESPONSE BACK TO ARKESEL
|--------------------------------------------------------------------------
*/
$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

// Log outgoing response for debugging
file_put_contents('ussd_debug.log', date('Y-m-d H:i:s') . " - Response: " . json_encode($response) . PHP_EOL . PHP_EOL, FILE_APPEND);

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response);
