<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| FIREBASE REQUEST
|--------------------------------------------------------------------------
*/
function firebaseRequest($method, $collection, $docId, $data = null, $mask = null)
{
    $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";
    $url = $baseURL . "/" . $collection . "/" . $docId;

    if ($method == "PATCH" && $mask !== null) {
        $url .= "?updateMask.fieldPaths=" . $mask;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

/*
|--------------------------------------------------------------------------
| HARDCODED NOMINEES DATA
|--------------------------------------------------------------------------
*/
function initializeNominees() {
    $nominees = [
        "FS1" => ["fullName" => "EGYIRWAA", "voteAmount" => 1],
        "FS2" => ["fullName" => "AGYEKUMWAA", "voteAmount" => 1],
        "FS3" => ["fullName" => "BOATEMAA", "voteAmount" => 1],
        "FS4" => ["fullName" => "ABENA", "voteAmount" => 1],
        "FS5" => ["fullName" => "SEDEM", "voteAmount" => 1]
    ];
    
    foreach ($nominees as $code => $data) {
        // Check if nominee exists
        $existing = firebaseRequest("GET", "awards_nominees", $code);
        
        if (!isset($existing['fields'])) {
            // Create new nominee with initial votes = 0
            $nomineeData = [
                "fields" => [
                    "fullName" => ["stringValue" => $data['fullName']],
                    "votes" => ["integerValue" => 0],
                    "voteAmount" => ["integerValue" => $data['voteAmount']]
                ]
            ];
            firebaseRequest("PATCH", "awards_nominees", $code, $nomineeData);
        }
    }
}

// Initialize hardcoded nominees
initializeNominees();

/*
|--------------------------------------------------------------------------
| SESSION HELPERS
|--------------------------------------------------------------------------
*/
function saveSession($sessionID, $step, $contestantCode)
{
    $data = [
        "fields" => [
            "step" => ["integerValue" => $step],
            "contestantCode" => ["stringValue" => $contestantCode]
        ]
    ];
    firebaseRequest("PATCH", "sessions", $sessionID, $data, "step,contestantCode");
}

function loadSession($sessionID)
{
    $session = firebaseRequest("GET", "sessions", $sessionID);
    return [
        "step" => isset($session['fields']['step']['integerValue']) ? (int)$session['fields']['step']['integerValue'] : 0,
        "contestantCode" => $session['fields']['contestantCode']['stringValue'] ?? ''
    ];
}

/*
|--------------------------------------------------------------------------
| GET REQUEST
|--------------------------------------------------------------------------
*/
$json = file_get_contents("php://input");
$data = json_decode($json, true);

/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/
$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$msisdn     = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData   = trim($data['userData'] ?? '');
$voteCount  = 1; // Default vote count

$message = "";
$continueSession = true;
$contestantCode = '';
$step = 0;

/*
|--------------------------------------------------------------------------
| LOAD OR INIT SESSION
|--------------------------------------------------------------------------
*/
if ($newSession) {
    $step = 0;
    $contestantCode = '';
    saveSession($sessionID, $step, $contestantCode);

    $message = "Welcome To Ghartey Events\n";
    $message .= "1. Vote";
} else {
    $sessionState = loadSession($sessionID);
    $step = $sessionState['step'];
    $contestantCode = $sessionState['contestantCode'];
}

/*
|--------------------------------------------------------------------------
| FLOW LOGIC
|--------------------------------------------------------------------------
*/
if ($step == 0 && $userData == "1") {
    $step = 1;
    saveSession($sessionID, $step, '');
    $message = "Enter Contestant Code (FS1, FS2, FS3, FS4, or FS5)";
}

elseif ($step == 1 && preg_match('/^FS[1-5]$/i', strtoupper($userData))) {
    $contestantCode = strtoupper($userData);
    
    // Get hardcoded nominee data
    $nominees = [
        "FS1" => "EGYIRWAA",
        "FS2" => "AGYEKUMWAA", 
        "FS3" => "BOATEMAA",
        "FS4" => "ABENA",
        "FS5" => "SEDEM"
    ];
    
    if (isset($nominees[$contestantCode])) {
        $contestantName = $nominees[$contestantCode];
        $step = 2;
        saveSession($sessionID, $step, $contestantCode);
        
        // Check current votes from database
        $contestant = firebaseRequest("GET", "awards_nominees", $contestantCode);
        $currentVotes = isset($contestant['fields']['votes']['integerValue']) ? (int)$contestant['fields']['votes']['integerValue'] : 0;
        
        $message  = "Vote for " . $contestantName . "\n";
        $message .= "Nominee Code: " . $contestantCode . "\n";
        $message .= "Vote: GHC 1 per vote\n";
        $message .= "Current Votes: " . $currentVotes . "\n";
        $message .= "Enter number of votes (1-10):";
    }
}

elseif ($step == 2 && is_numeric($userData) && $userData >= 1 && $userData <= 10) {
    $voteCount = (int)$userData;
    $contestant = firebaseRequest("GET", "awards_nominees", $contestantCode);
    
    if (isset($contestant['fields']['votes']['integerValue'])) {
        $currentVotes = (int)$contestant['fields']['votes']['integerValue'];
        $totalAmount = $voteCount * 1; // GHC 1 per vote
        
        // Update votes in database
        $updateData = [
            "fields" => [
                "votes" => ["integerValue" => $currentVotes + $voteCount]
            ]
        ];
        firebaseRequest("PATCH", "awards_nominees", $contestantCode, $updateData, "votes");
        
        // Get updated contestant name
        $nominees = [
            "FS1" => "EGYIRWAA",
            "FS2" => "AGYEKUMWAA", 
            "FS3" => "BOATEMAA",
            "FS4" => "ABENA",
            "FS5" => "SEDEM"
        ];
        $contestantName = $nominees[$contestantCode];
        
        $message = "✓ Vote Successful!\n";
        $message .= "Nominee: " . $contestantName . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "Votes Cast: " . $voteCount . "\n";
        $message .= "Total Cost: GHC " . $totalAmount . "\n";
        $message .= "New Total Votes: " . ($currentVotes + $voteCount) . "\n";
        $message .= "\nThank you for voting!";
    } else {
        $message = "Error: Contestant not found in database";
    }
    $continueSession = false;
    saveSession($sessionID, 0, '');
}

else {
    if ($step == 1) {
        $message = "Invalid Contestant Code. Please enter FS1, FS2, FS3, FS4, or FS5";
    } elseif ($step == 2) {
        $message = "Invalid input. Please enter number of votes (1-10)";
    } else {
        $message = "Invalid Option. Please select 1 to Vote";
    }
    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/
$response = [
    "sessionID"       => $sessionID,
    "userID"          => $userID,
    "msisdn"          => $msisdn,
    "message"         => $message,
    "continueSession" => $continueSession
];

header("Content-Type: application/json");
echo json_encode($response);
?>
