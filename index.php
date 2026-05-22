<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| GET USSD INPUT
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');
$data = json_decode($json, true);

// For debugging - log what we receive
error_log("USSD Request: " . print_r($data, true));

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$msisdn = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData = trim($data['userData'] ?? '');

/*
|--------------------------------------------------------------------------
| FIREBASE FIRESTORE CONFIGURATION
|--------------------------------------------------------------------------
*/

$projectId = 'eventgodds-41e4f';
$apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';

// Function to get data from Firestore
function getFromFirestore($collection) {
    global $projectId, $apiKey;
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collection}?key={$apiKey}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Firestore Response Code: " . $httpCode);
    error_log("Firestore Response: " . substr($response, 0, 500));
    
    if ($httpCode != 200) {
        return [];
    }
    
    $data = json_decode($response, true);
    $results = [];
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $item = [];
            foreach ($doc['fields'] as $key => $value) {
                if (isset($value['stringValue'])) {
                    $item[$key] = $value['stringValue'];
                } elseif (isset($value['integerValue'])) {
                    $item[$key] = $value['integerValue'];
                } elseif (isset($value['doubleValue'])) {
                    $item[$key] = $value['doubleValue'];
                }
            }
            $results[] = $item;
        }
    }
    
    return $results;
}

/*
|--------------------------------------------------------------------------
| USSD RESPONSE VARIABLES
|--------------------------------------------------------------------------
*/

$message = "";
$continueSession = true;

// For USSD, userData comes as a string like "1" or "1*FS1"
// We need to handle the input properly
$input = $userData;
error_log("User Input: '$input'");
error_log("New Session: " . ($newSession ? 'true' : 'false'));

/*
|--------------------------------------------------------------------------
| USSD MENU LOGIC - SIMPLIFIED
|--------------------------------------------------------------------------
*/

// Case 1: New session - show main menu
if ($newSession == true) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}
// Case 2: User selected 0 to exit
elseif ($input === "0") {
    $message = "Thank you for using Ghartey Event";
    $continueSession = false;
}
// Case 3: User selected 2 - View Contestants
elseif ($input === "2") {
    $contestants = getFromFirestore("contestants");
    
    error_log("Contestants found: " . count($contestants));
    
    if (count($contestants) > 0) {
        $message = "CONTESTANTS:\n";
        $count = 1;
        foreach ($contestants as $c) {
            $name = $c['contestant_name'] ?? $c['name'] ?? 'Unknown';
            $code = $c['code'] ?? $c['contestant_code'] ?? 'N/A';
            $message .= "$count. $name (Code: $code)\n";
            $count++;
            if ($count > 10) break; // Limit for USSD
        }
        $message .= "\n0. Back";
    } else {
        $message = "No contestants found\n0. Back";
    }
}
// Case 4: User selected 1 - Vote (ask for code)
elseif ($input === "1") {
    $message = "Enter contestant code:";
}
// Case 5: User entered code like "1*FS1" - process vote
elseif (strpos($input, "1*") === 0) {
    // Extract the code (everything after "1*")
    $parts = explode("*", $input);
    $contestantCode = strtoupper(trim($parts[1] ?? ''));
    
    error_log("Looking for contestant code: " . $contestantCode);
    
    // Get all contestants
    $contestants = getFromFirestore("contestants");
    $found = null;
    
    foreach ($contestants as $c) {
        $code = $c['code'] ?? $c['contestant_code'] ?? '';
        error_log("Comparing with: " . $code);
        if (strtoupper($code) === $contestantCode) {
            $found = $c;
            break;
        }
    }
    
    if ($found) {
        $name = $found['contestant_name'] ?? $found['name'] ?? 'Unknown';
        $message = "✓ VOTE SUCCESSFUL!\n";
        $message .= "You voted for: $name\n";
        $message .= "Code: $contestantCode\n";
        $message .= "Thank you!\n";
        $message .= "1. Vote Again\n";
        $message .= "0. Menu";
    } else {
        $message = "Code '$contestantCode' not found!\n";
        $message .= "1. Try Again\n";
        $message .= "0. Menu";
    }
}
// Case 6: Vote again
elseif ($input === "1*1") {
    $message = "Enter contestant code:";
}
// Case 7: Back to menu from anywhere
elseif ($input === "00" || $input === "0*0") {
    $message = "Main Menu\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}
// Default: Invalid input
else {
    $message = "Invalid option: '$input'\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}

/*
|--------------------------------------------------------------------------
| SEND RESPONSE BACK TO ARKESEL
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

error_log("Response: " . print_r($response, true));

header('Content-Type: application/json');
echo json_encode($response);
?>
