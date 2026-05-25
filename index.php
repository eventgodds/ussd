<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| HARDCODED CONTESTANT DATA
|--------------------------------------------------------------------------
*/
$hardcodedContestants = [
    'FS1' => [
        'fullName' => 'EGYIRWAA',
        'votes' => 0,
        'voteMessage' => 'Vote for EGYIRWAA. Nominee Code: FS1 Enter number of votes Vote: GHC 1'
    ],
    'FS2' => [
        'fullName' => 'AGYEKUMWAA',
        'votes' => 0,
        'voteMessage' => 'Vote for AGYEKUMWAA. Nominee Code: FS2 . Vote: GHC 1 . Enter number of votes'
    ],
    'FS3' => [
        'fullName' => 'BOATEMAA',
        'votes' => 0,
        'voteMessage' => 'Vote for BOATEMAA. Nominee Code: FS3. Vote: GHC1 . Enter the number of votes'
    ],
    'FS4' => [
        'fullName' => 'ABENA',
        'votes' => 0,
        'voteMessage' => 'Vote for ABENA. Nominee Code: FS4. Vote: GHC1 . Enter the number of votes'
    ],
    'FS5' => [
        'fullName' => 'SEDEM',
        'votes' => 0,
        'voteMessage' => 'Vote for SEDEM. Nominee Code: FS5. Vote: GHC1 . Enter the number of votes'
    ]
];

/*
|--------------------------------------------------------------------------
| DATABASE FILE FOR PERSISTENCE
|--------------------------------------------------------------------------
*/
$databaseFile = 'votes_database.json';

// Initialize database file if it doesn't exist
if (!file_exists($databaseFile)) {
    file_put_contents($databaseFile, json_encode($hardcodedContestants));
}

// Function to read current votes from database
function readVotesFromDatabase() {
    global $databaseFile;
    $data = file_get_contents($databaseFile);
    return json_decode($data, true);
}

// Function to save votes to database
function saveVotesToDatabase($contestants) {
    global $databaseFile;
    file_put_contents($databaseFile, json_encode($contestants));
}

/*
|--------------------------------------------------------------------------
| FIREBASE REQUEST (UPDATED FOR HARDCODED DATA)
|--------------------------------------------------------------------------
*/
function firebaseRequest($method, $collection, $docId, $data = null, $mask = null)
{
    // Use hardcoded data instead of Firebase
    $contestants = readVotesFromDatabase();
    
    if ($method == "GET") {
        if ($collection == "awards_nominees" && isset($contestants[$docId])) {
            return [
                'fields' => [
                    'fullName' => ['stringValue' => $contestants[$docId]['fullName']],
                    'votes' => ['integerValue' => $contestants[$docId]['votes']]
                ]
            ];
        } elseif ($collection == "sessions") {
            // Return session data structure
            return ['fields' => []];
        }
        return null;
    } 
    elseif ($method == "PATCH") {
        if ($collection == "awards_nominees" && isset($contestants[$docId])) {
            if (isset($data['fields']['votes']['integerValue'])) {
                $contestants[$docId]['votes'] = $data['fields']['votes']['integerValue'];
                saveVotesToDatabase($contestants);
            }
        }
        return ['success' => true];
    }
    
    return null;
}

/*
|--------------------------------------------------------------------------
| SESSION HELPERS (UPDATED FOR DATABASE)
|--------------------------------------------------------------------------
*/
function saveSession($sessionID, $step, $contestantCode)
{
    $sessionsFile = 'sessions_database.json';
    $sessions = [];
    
    if (file_exists($sessionsFile)) {
        $sessions = json_decode(file_get_contents($sessionsFile), true);
    }
    
    $sessions[$sessionID] = [
        'step' => $step,
        'contestantCode' => $contestantCode
    ];
    
    file_put_contents($sessionsFile, json_encode($sessions));
}

function loadSession($sessionID)
{
    $sessionsFile = 'sessions_database.json';
    
    if (file_exists($sessionsFile)) {
        $sessions = json_decode(file_get_contents($sessionsFile), true);
        if (isset($sessions[$sessionID])) {
            return [
                "step" => $sessions[$sessionID]['step'],
                "contestantCode" => $sessions[$sessionID]['contestantCode']
            ];
        }
    }
    
    return [
        "step" => 0,
        "contestantCode" => ''
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
| FLOW LOGIC WITH HARDCODED DATA
|--------------------------------------------------------------------------
*/
if ($step == 0 && $userData == "1") {
    $step = 1;
    saveSession($sessionID, $step, '');
    $message = "Enter Contestant Code (FS1, FS2, FS3, FS4, or FS5)";
}

elseif ($step == 1) {
    $enteredCode = strtoupper(trim($userData));
    $validCodes = ['FS1', 'FS2', 'FS3', 'FS4', 'FS5'];
    
    if (in_array($enteredCode, $validCodes)) {
        $contestantCode = $enteredCode;
        $contestants = readVotesFromDatabase();
        
        if (isset($contestants[$contestantCode])) {
            $step = 2;
            saveSession($sessionID, $step, $contestantCode);
            
            // Display the specific message for each contestant
            $message = $contestants[$contestantCode]['voteMessage'];
        } else {
            $message = "Contestant Not Found. Please enter FS1, FS2, FS3, FS4, or FS5";
            $continueSession = false;
        }
    } else {
        $message = "Invalid Contestant Code. Please enter FS1, FS2, FS3, FS4, or FS5";
        $continueSession = false;
    }
}

elseif ($step == 2) {
    // Expecting number of votes (1 or more)
    $numberOfVotes = (int)$userData;
    
    if ($numberOfVotes > 0) {
        $contestants = readVotesFromDatabase();
        
        if (isset($contestants[$contestantCode])) {
            // Update votes in database
            $currentVotes = $contestants[$contestantCode]['votes'];
            $newVotes = $currentVotes + $numberOfVotes;
            $contestants[$contestantCode]['votes'] = $newVotes;
            saveVotesToDatabase($contestants);
            
            $totalCost = $numberOfVotes; // GHC 1 per vote
            
            $message = "✓ Vote Successful!\n";
            $message .= "Contestant: " . $contestants[$contestantCode]['fullName'] . "\n";
            $message .= "Votes Cast: " . $numberOfVotes . "\n";
            $message .= "Total Cost: GHC " . $totalCost . "\n";
            $message .= "Total Votes for " . $contestants[$contestantCode]['fullName'] . ": " . $newVotes;
            
            $continueSession = false;
            saveSession($sessionID, 0, '');
        } else {
            $message = "Error: Contestant not found";
            $continueSession = false;
        }
    } else {
        $message = "Please enter a valid number of votes (1 or more)";
        $continueSession = true; // Stay in step 2 to try again
    }
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
