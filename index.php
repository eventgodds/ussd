<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| HARDCODED CONTESTANTS DATA
|--------------------------------------------------------------------------
*/
$hardcodedContestants = [
    'FS1' => [
        'fullName' => 'EGYIRWAA',
        'votes' => 0,
        'voteValue' => 1
    ],
    'FS2' => [
        'fullName' => 'AGYEKUMWAA',
        'votes' => 0,
        'voteValue' => 1
    ],
    'FS3' => [
        'fullName' => 'BOATEMAA',
        'votes' => 0,
        'voteValue' => 1
    ],
    'FS4' => [
        'fullName' => 'ABENA',
        'votes' => 0,
        'voteValue' => 1
    ],
    'FS5' => [
        'fullName' => 'SEDEM',
        'votes' => 0,
        'voteValue' => 1
    ]
];

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
| INITIALIZE HARDCODED DATA IN DATABASE
|--------------------------------------------------------------------------
*/
function initializeContestantsInDatabase($hardcodedContestants)
{
    foreach ($hardcodedContestants as $code => $contestant) {
        // Check if contestant already exists
        $existing = firebaseRequest("GET", "awards_nominees", $code);
        
        if (!isset($existing['fields'])) {
            // Create new contestant record
            $data = [
                "fields" => [
                    "fullName" => ["stringValue" => $contestant['fullName']],
                    "votes" => ["integerValue" => $contestant['votes']],
                    "voteValue" => ["integerValue" => $contestant['voteValue']]
                ]
            ];
            firebaseRequest("PATCH", "awards_nominees", $code, $data, "fullName,votes,voteValue");
        }
    }
}

/*
|--------------------------------------------------------------------------
| SESSION HELPERS
|--------------------------------------------------------------------------
*/
function saveSession($sessionID, $step, $contestantCode, $numberOfVotes = 0)
{
    $data = [
        "fields" => [
            "step" => ["integerValue" => $step],
            "contestantCode" => ["stringValue" => $contestantCode],
            "numberOfVotes" => ["integerValue" => $numberOfVotes]
        ]
    ];
    firebaseRequest("PATCH", "sessions", $sessionID, $data, "step,contestantCode,numberOfVotes");
}

function loadSession($sessionID)
{
    $session = firebaseRequest("GET", "sessions", $sessionID);
    return [
        "step" => $session['fields']['step']['integerValue'] ?? 0,
        "contestantCode" => $session['fields']['contestantCode']['stringValue'] ?? '',
        "numberOfVotes" => $session['fields']['numberOfVotes']['integerValue'] ?? 0
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

$message = "";
$continueSession = true;
$contestantCode = '';
$step = 0;
$numberOfVotes = 0;

// Initialize hardcoded data in database
initializeContestantsInDatabase($hardcodedContestants);

/*
|--------------------------------------------------------------------------
| LOAD OR INIT SESSION
|--------------------------------------------------------------------------
*/
if ($newSession) {
    $step = 0;
    $contestantCode = '';
    $numberOfVotes = 0;
    saveSession($sessionID, $step, $contestantCode, $numberOfVotes);

    $message = "Welcome To Ghartey Events\n";
    $message .= "1. Vote";
} else {
    $sessionState = loadSession($sessionID);
    $step = $sessionState['step'];
    $contestantCode = $sessionState['contestantCode'];
    $numberOfVotes = $sessionState['numberOfVotes'];
}

// Get hardcoded contestants array
global $hardcodedContestants;

/*
|--------------------------------------------------------------------------
| FLOW LOGIC
|--------------------------------------------------------------------------
*/
if ($step == 0 && $userData == "1") {
    $step = 1;
    saveSession($sessionID, $step, '', 0);
    $message = "Enter Contestant Code (FS1, FS2, FS3, FS4, FS5)";
}

elseif ($step == 1 && preg_match('/^FS[1-5]$/', strtoupper($userData))) {
    $contestantCode = strtoupper($userData);
    
    // Check if contestant exists in hardcoded data
    if (isset($hardcodedContestants[$contestantCode])) {
        $contestantName = $hardcodedContestants[$contestantCode]['fullName'];
        $voteValue = $hardcodedContestants[$contestantCode]['voteValue'];
        $step = 2;
        saveSession($sessionID, $step, $contestantCode, 0);
        
        $message  = "Vote for " . $contestantName . "\n";
        $message .= "Nominee Code: " . $contestantCode . "\n";
        $message .= "Vote: GHC " . $voteValue . "\n";
        $message .= "Enter number of votes:";
    } else {
        $message = "Invalid Contestant Code. Please enter FS1, FS2, FS3, FS4, or FS5";
        $continueSession = false;
    }
}

elseif ($step == 2 && is_numeric($userData) && $userData > 0) {
    $numberOfVotes = (int)$userData;
    $contestantName = $hardcodedContestants[$contestantCode]['fullName'];
    $voteValue = $hardcodedContestants[$contestantCode]['voteValue'];
    $totalAmount = $numberOfVotes * $voteValue;
    
    $step = 3;
    saveSession($sessionID, $step, $contestantCode, $numberOfVotes);
    
    $message  = "Confirm Vote:\n";
    $message .= "Candidate: " . $contestantName . "\n";
    $message .= "Nominee Code: " . $contestantCode . "\n";
    $message .= "Number of votes: " . $numberOfVotes . "\n";
    $message .= "Total Amount: GHC " . $totalAmount . "\n";
    $message .= "1. Confirm\n";
    $message .= "2. Cancel";
}

elseif ($step == 3 && $userData == "1") {
    // Update votes in database
    $contestant = firebaseRequest("GET", "awards_nominees", $contestantCode);
    
    if (isset($contestant['fields']['votes']['integerValue'])) {
        $currentVotes = (int)$contestant['fields']['votes']['integerValue'];
        $newVotes = $currentVotes + $numberOfVotes;
        
        $updateData = [
            "fields" => [
                "votes" => ["integerValue" => $newVotes]
            ]
        ];
        firebaseRequest("PATCH", "awards_nominees", $contestantCode, $updateData, "votes");
        
        // Also update the hardcoded array for consistency
        global $hardcodedContestants;
        $hardcodedContestants[$contestantCode]['votes'] = $newVotes;
    }
    
    $totalAmount = $numberOfVotes * $hardcodedContestants[$contestantCode]['voteValue'];
    $message = "Vote Successful!\n";
    $message .= "You voted " . $numberOfVotes . " time(s) for " . $hardcodedContestants[$contestantCode]['fullName'] . "\n";
    $message .= "Total: GHC " . $totalAmount . "\n";
    $message .= "Thank you for voting!";
    
    $continueSession = false;
    saveSession($sessionID, 0, '', 0);
}

elseif ($step == 3 && $userData == "2") {
    $message = "Vote Cancelled. Thank you!";
    $continueSession = false;
    saveSession($sessionID, 0, '', 0);
}

elseif ($step == 1 && $userData != "") {
    $message = "Invalid Contestant Code. Please enter FS1, FS2, FS3, FS4, or FS5";
    $continueSession = false;
}

elseif ($step == 2 && (!is_numeric($userData) || $userData <= 0)) {
    $message = "Please enter a valid number of votes (greater than 0)";
    $continueSession = false;
}

else {
    $message = "Invalid Input. Please try again.";
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
