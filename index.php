<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION (SQLite for hardcoded data)
|--------------------------------------------------------------------------
*/

// Create SQLite database file
$db = new SQLite3('voting_database.db');

// Create table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nominee_code TEXT NOT NULL,
    nominee_name TEXT NOT NULL,
    votes INTEGER DEFAULT 0,
    vote_amount TEXT DEFAULT 'GHC 1',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Insert hardcoded data if table is empty
$check = $db->querySingle("SELECT COUNT(*) FROM votes");
if ($check == 0) {
    $hardcodedData = [
        ['FS1', 'EGYIRWAA'],
        ['FS2', 'AGYEKUMWAA'],
        ['FS3', 'BOATEMAA'],
        ['FS4', 'ABENA'],
        ['FS5', 'SEDEM']
    ];
    
    foreach ($hardcodedData as $nominee) {
        $stmt = $db->prepare("INSERT INTO votes (nominee_code, nominee_name, votes) VALUES (:code, :name, 0)");
        $stmt->bindValue(':code', $nominee[0], SQLITE3_TEXT);
        $stmt->bindValue(':name', $nominee[1], SQLITE3_TEXT);
        $stmt->execute();
    }
}

/*
|--------------------------------------------------------------------------
| FIREBASE REQUEST (placeholder - kept for compatibility)
|--------------------------------------------------------------------------
*/

function firebaseRequest($method, $collection, $docId, $data = null)
{
    // This function is kept for compatibility but we're using SQLite
    return ['fields' => ['fullName' => ['stringValue' => '']]];
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

// Store user progress in session
session_start();
if (!isset($_SESSION['step'])) {
    $_SESSION['step'] = 0;
}
if (!isset($_SESSION['contestant_code'])) {
    $_SESSION['contestant_code'] = '';
}

/*
|--------------------------------------------------------------------------
| STEP 0 - NEW SESSION
|--------------------------------------------------------------------------
*/

if ($newSession == true) {
    $_SESSION['step'] = 0;
    $message = "Welcome To Ghartey Events\n";
    $message .= "1. Vote";
    $_SESSION['step'] = 1;
}

/*
|--------------------------------------------------------------------------
| STEP 1 - MENU SELECTION
|--------------------------------------------------------------------------
*/

elseif ($userData == "1" && $_SESSION['step'] == 1) {
    $message = "Enter Contestant Code (FS1, FS2, FS3, FS4, or FS5):";
    $_SESSION['step'] = 2;
}

/*
|--------------------------------------------------------------------------
| STEP 2 - CONTESTANT CODE ENTRY
|--------------------------------------------------------------------------
*/

elseif ($_SESSION['step'] == 2) {
    $contestantCode = strtoupper(trim($userData));
    
    // Hardcoded contestant data
    $contestants = [
        'FS1' => 'EGYIRWAA',
        'FS2' => 'AGYEKUMWAA',
        'FS3' => 'BOATEMAA',
        'FS4' => 'ABENA',
        'FS5' => 'SEDEM'
    ];
    
    if (array_key_exists($contestantCode, $contestants)) {
        $_SESSION['contestant_code'] = $contestantCode;
        
        // Display specific message based on code
        switch($contestantCode) {
            case 'FS1':
                $message = "Vote for EGYIRWAA\n";
                break;
            case 'FS2':
                $message = "Vote for AGYEKUMWAA\n";
                break;
            case 'FS3':
                $message = "Vote for BOATEMAA\n";
                break;
            case 'FS4':
                $message = "Vote for ABENA\n";
                break;
            case 'FS5':
                $message = "Vote for SEDEM\n";
                break;
        }
        
        $message .= "Nominee Code: " . $contestantCode . "\n";
        $message .= "Vote: GHC 1\n";
        $message .= "Enter number of votes:";
        $_SESSION['step'] = 3;
        
    } else {
        $message = "Invalid Contestant Code! Please enter FS1, FS2, FS3, FS4, or FS5";
        $continueSession = true;
    }
}

/*
|--------------------------------------------------------------------------
| STEP 3 - ENTER NUMBER OF VOTES
|--------------------------------------------------------------------------
*/

elseif ($_SESSION['step'] == 3) {
    $numberOfVotes = intval($userData);
    
    if ($numberOfVotes > 0) {
        // Update database with votes
        $contestantCode = $_SESSION['contestant_code'];
        
        // Get current votes
        $result = $db->querySingle("SELECT votes, nominee_name FROM votes WHERE nominee_code = '$contestantCode'", true);
        
        if ($result) {
            $currentVotes = $result['votes'];
            $newVotes = $currentVotes + $numberOfVotes;
            $nomineeName = $result['nominee_name'];
            
            // Update votes in database
            $stmt = $db->prepare("UPDATE votes SET votes = :votes WHERE nominee_code = :code");
            $stmt->bindValue(':votes', $newVotes, SQLITE3_INTEGER);
            $stmt->bindValue(':code', $contestantCode, SQLITE3_TEXT);
            $stmt->execute();
            
            $message = "Vote Successful!\n";
            $message .= "You voted " . $numberOfVotes . " time(s) for " . $nomineeName . "\n";
            $message .= "Total votes for " . $nomineeName . ": " . $newVotes;
            
            // Reset session
            session_destroy();
            session_start();
            $_SESSION['step'] = 0;
            $continueSession = false;
        } else {
            $message = "Error processing vote";
            $continueSession = false;
        }
    } else {
        $message = "Invalid number! Please enter a positive number";
        $_SESSION['step'] = 3;
        $continueSession = true;
    }
}

/*
|--------------------------------------------------------------------------
| INVALID
|--------------------------------------------------------------------------
*/

else {
    $message = "Invalid Input. Please try again.";
    $_SESSION['step'] = 1;
    $continueSession = true;
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
