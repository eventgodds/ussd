<?php
// ============================================
// COMPLETE USSD VOTING SYSTEM - SINGLE FILE
// ============================================

// Get USSD input from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sessionID = $data['sessionID'] ?? '';
$userID = $data['userID'] ?? '';
$msisdn = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData = trim($data['userData'] ?? '');

// ============================================
// FIREBASE FIRESTORE DIRECT ACCESS
// ============================================

$FIREBASE_PROJECT = 'eventgodds-41e4f';
$FIREBASE_KEY = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';

// Function to get all contestants from Firestore
function getAllContestants() {
    global $FIREBASE_PROJECT, $FIREBASE_KEY;
    
    $url = "https://firestore.googleapis.com/v1/projects/{$FIREBASE_PROJECT}/databases/(default)/documents/contestants?key={$FIREBASE_KEY}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        return [];
    }
    
    $data = json_decode($response, true);
    $contestants = [];
    
    if (isset($data['documents']) && is_array($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $contestant = [];
            
            // Get contestant name
            if (isset($doc['fields']['contestant_name']['stringValue'])) {
                $contestant['name'] = $doc['fields']['contestant_name']['stringValue'];
            } elseif (isset($doc['fields']['name']['stringValue'])) {
                $contestant['name'] = $doc['fields']['name']['stringValue'];
            } else {
                $contestant['name'] = 'Unknown';
            }
            
            // Get contestant code
            if (isset($doc['fields']['code']['stringValue'])) {
                $contestant['code'] = $doc['fields']['code']['stringValue'];
            } elseif (isset($doc['fields']['contestant_code']['stringValue'])) {
                $contestant['code'] = $doc['fields']['contestant_code']['stringValue'];
            } else {
                $contestant['code'] = '';
            }
            
            // Only add if we have a code
            if (!empty($contestant['code'])) {
                $contestants[] = $contestant;
            }
        }
    }
    
    return $contestants;
}

// Function to find contestant by code
function findContestantByCode($searchCode) {
    $contestants = getAllContestants();
    foreach ($contestants as $contestant) {
        if (strtoupper($contestant['code']) == strtoupper($searchCode)) {
            return $contestant;
        }
    }
    return null;
}

// Function to save vote to Firestore
function saveVote($msisdn, $contestantCode, $contestantName) {
    global $FIREBASE_PROJECT, $FIREBASE_KEY;
    
    $voteId = uniqid('vote_');
    $timestamp = date('Y-m-d H:i:s');
    
    $url = "https://firestore.googleapis.com/v1/projects/{$FIREBASE_PROJECT}/databases/(default)/documents/votes/{$voteId}?key={$FIREBASE_KEY}";
    
    $voteData = [
        'fields' => [
            'msisdn' => ['stringValue' => $msisdn],
            'contestant_code' => ['stringValue' => $contestantCode],
            'contestant_name' => ['stringValue' => $contestantName],
            'timestamp' => ['stringValue' => $timestamp],
            'status' => ['stringValue' => 'completed']
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($voteData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode == 200);
}

// ============================================
// USSD MENU SYSTEM
// ============================================

$message = "";
$continueSession = true;

// Store user state in session
session_start();
$stateKey = "ussd_state_{$sessionID}";
$currentState = $_SESSION[$stateKey] ?? 'main';

// NEW SESSION - Reset everything
if ($newSession == true) {
    $_SESSION[$stateKey] = 'main';
    $currentState = 'main';
}

// Handle USSD input based on state
if ($currentState == 'main') {
    if ($userData == "") {
        // Show main menu
        $message = "Welcome to Ghartey Event\n";
        $message .= "========================\n";
        $message .= "1. Vote for Contestant\n";
        $message .= "2. View All Contestants\n";
        $message .= "0. Exit";
        $continueSession = true;
        $_SESSION[$stateKey] = 'main';
    }
    elseif ($userData == "1") {
        // Go to vote menu
        $_SESSION[$stateKey] = 'vote_ask_code';
        $message = "Enter contestant code:\n";
        $message .= "(Example: FS1, FS2, etc.)\n";
        $message .= "0. Back to Menu";
        $continueSession = true;
    }
    elseif ($userData == "2") {
        // Show all contestants
        $contestants = getAllContestants();
        
        if (count($contestants) > 0) {
            $message = "📋 CONTESTANTS LIST\n";
            $message .= "========================\n";
            foreach ($contestants as $index => $c) {
                $num = $index + 1;
                $message .= "$num. {$c['name']}\n";
                $message .= "   Code: {$c['code']}\n";
                $message .= "------------------------\n";
            }
            $message .= "\n0. Back to Menu\n";
            $message .= "1. Vote Now";
        } else {
            $message = "No contestants found.\n0. Back to Menu";
        }
        
        $_SESSION[$stateKey] = 'main';
        $continueSession = true;
    }
    elseif ($userData == "0") {
        // Exit
        $message = "Thank you for using Ghartey Event Voting System!\n";
        $message .= "Goodbye!";
        $continueSession = false;
        session_destroy();
    }
    else {
        // Invalid input
        $message = "Invalid option: '$userData'\n";
        $message .= "Please try again:\n";
        $message .= "1. Vote\n";
        $message .= "2. View Contestants\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
}
elseif ($currentState == 'vote_ask_code') {
    if ($userData == "0") {
        // Go back to main menu
        $_SESSION[$stateKey] = 'main';
        $message = "Main Menu\n";
        $message .= "1. Vote\n";
        $message .= "2. View Contestants\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
    else {
        // Process the vote
        $contestantCode = strtoupper(trim($userData));
        $contestant = findContestantByCode($contestantCode);
        
        if ($contestant) {
            // Save vote to Firestore
            $voteSaved = saveVote($msisdn, $contestantCode, $contestant['name']);
            
            if ($voteSaved) {
                $message = "✅ VOTE SUCCESSFUL!\n";
                $message .= "========================\n";
                $message .= "You voted for:\n";
                $message .= "{$contestant['name']}\n";
                $message .= "Code: {$contestantCode}\n";
                $message .= "========================\n";
                $message .= "Thank you for voting!\n";
                $message .= "\n1. Vote Again\n";
                $message .= "0. Main Menu";
            } else {
                $message = "❌ ERROR: Could not save vote.\n";
                $message .= "Please try again.\n";
                $message .= "1. Try Again\n";
                $message .= "0. Main Menu";
            }
        } else {
            $message = "❌ Contestant code '$contestantCode' not found!\n";
            $message .= "Please check the code and try again.\n";
            $message .= "\n1. Try Again\n";
            $message .= "0. Main Menu";
        }
        
        $_SESSION[$stateKey] = 'vote_result';
        $continueSession = true;
    }
}
elseif ($currentState == 'vote_result') {
    if ($userData == "1") {
        // Vote again
        $_SESSION[$stateKey] = 'vote_ask_code';
        $message = "Enter contestant code:\n";
        $message .= "(Example: FS1, FS2, etc.)\n";
        $message .= "0. Back to Menu";
        $continueSession = true;
    }
    elseif ($userData == "0") {
        // Back to main menu
        $_SESSION[$stateKey] = 'main';
        $message = "Main Menu\n";
        $message .= "1. Vote\n";
        $message .= "2. View Contestants\n";
        $message .= "0. Exit";
        $continueSession = true;
    }
    else {
        $message = "Invalid option.\n";
        $message .= "1. Vote Again\n";
        $message .= "0. Main Menu";
        $continueSession = true;
    }
}

// ============================================
// SEND RESPONSE BACK TO ARKESEL
// ============================================

$response = [
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
];

// Save session
session_write_close();

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
