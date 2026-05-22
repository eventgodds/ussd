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
    $response = curl_exec($ch);
    curl_close($ch);
    
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
$input = explode('*', $userData);

/*
|--------------------------------------------------------------------------
| USSD MENU LOGIC
|--------------------------------------------------------------------------
*/

// Main Menu
if ($newSession == true) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}
// Exit
elseif ($input[0] == "0") {
    $message = "Thank you for using Ghartey Event Voting System";
    $continueSession = false;
}
// View Contestants
elseif ($input[0] == "2") {
    $contestants = getFromFirestore("contestants");
    
    if (count($contestants) > 0) {
        $message = "CONTESTANTS LIST\n";
        $message .= "----------------\n";
        foreach ($contestants as $c) {
            $name = isset($c['contestant_name']) ? $c['contestant_name'] : (isset($c['name']) ? $c['name'] : 'Unknown');
            $code = isset($c['code']) ? $c['code'] : (isset($c['contestant_code']) ? $c['contestant_code'] : 'N/A');
            $message .= "$name\n";
            $message .= "Code: $code\n";
            $message .= "----------------\n";
        }
        $message .= "0. Back to Menu";
    } else {
        $message = "No contestants found\n0. Back to Menu";
    }
}
// Vote - Ask for code
elseif ($input[0] == "1" && count($input) == 1) {
    $message = "Enter contestant code:";
}
// Vote - Process vote
elseif ($input[0] == "1" && count($input) == 2) {
    $contestantCode = strtoupper($input[1]);
    
    // Get all contestants to find matching code
    $contestants = getFromFirestore("contestants");
    $found = null;
    
    foreach ($contestants as $c) {
        $code = isset($c['code']) ? $c['code'] : (isset($c['contestant_code']) ? $c['contestant_code'] : '');
        if ($code == $contestantCode) {
            $found = $c;
            break;
        }
    }
    
    if ($found) {
        $name = isset($found['contestant_name']) ? $found['contestant_name'] : (isset($found['name']) ? $found['name'] : 'Unknown');
        $message = "✓ VOTE SUCCESSFUL!\n";
        $message .= "You voted for: $name\n";
        $message .= "Code: $contestantCode\n";
        $message .= "Thank you for voting!\n";
        $message .= "1. Vote Again\n";
        $message .= "0. Main Menu";
    } else {
        $message = "Contestant code '$contestantCode' not found!\n";
        $message .= "1. Try Again\n";
        $message .= "0. Main Menu";
    }
}
// Vote again
elseif ($input[0] == "1" && isset($input[1]) && $input[1] == "1") {
    $message = "Enter contestant code:";
}
// Back to main menu
elseif ($input[0] == "00" || (isset($input[1]) && $input[1] == "0")) {
    $message = "Main Menu\n";
    $message .= "1. Vote\n";
    $message .= "2. View Contestants\n";
    $message .= "0. Exit";
}
// Invalid input
else {
    $message = "Invalid option\n";
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

header('Content-Type: application/json');
echo json_encode($response);
?>
