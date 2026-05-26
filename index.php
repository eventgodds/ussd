<?php
header('Content-Type: application/json');

// Firebase REST API configuration
$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

// Read request from Arkesel
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get values
$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn     = $data['msisdn'] ?? '';
$userData   = trim($data['userData'] ?? '');

// Function to fetch contestants from Firestore
function fetchContestants($firestoreUrl) {
    $url = $firestoreUrl . "/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        $contestants = [];
        
        if (isset($data['documents'])) {
            foreach ($data['documents'] as $doc) {
                $docId = basename($doc['name']);
                $fields = $doc['fields'];
                
                // Only include contestants with code FS1-FS5
                if (isset($fields['code']['stringValue']) && 
                    preg_match('/^FS[1-5]$/', $fields['code']['stringValue'])) {
                    
                    $contestants[] = [
                        'id' => $docId,
                        'code' => $fields['code']['stringValue'] ?? '',
                        'name' => $fields['name']['stringValue'] ?? $fields['stageName']['stringValue'] ?? '',
                        'stageName' => $fields['stageName']['stringValue'] ?? '',
                        'votes' => $fields['votes']['integerValue'] ?? 0,
                        'voteAmount' => $fields['voteAmount']['integerValue'] ?? 1,
                        'bio' => $fields['bio']['stringValue'] ?? '',
                        'imageUrl' => $fields['imageUrl']['stringValue'] ?? '',
                        'engagement' => $fields['engagement']['integerValue'] ?? 0
                    ];
                }
            }
        }
        
        // Sort by code (FS1, FS2, etc.)
        usort($contestants, function($a, $b) {
            return strcmp($a['code'], $b['code']);
        });
        
        return $contestants;
    }
    
    return [];
}

// Function to update votes in Firestore
function updateVotes($firestoreUrl, $contestantCode, $currentVotes, $additionalVotes) {
    $newVotes = $currentVotes + $additionalVotes;
    
    // First, find the document ID for this contestant code
    $url = $firestoreUrl . "/contestants";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    $documentId = null;
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && $fields['code']['stringValue'] == $contestantCode) {
                $documentId = basename($doc['name']);
                break;
            }
        }
    }
    
    if ($documentId) {
        // Update the votes
        $updateUrl = $firestoreUrl . "/contestants/{$documentId}?updateMask.fieldPaths=votes";
        
        $updateData = [
            'fields' => [
                'votes' => [
                    'integerValue' => (string)$newVotes
                ]
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $updateUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode == 200;
    }
    
    return false;
}

// Fetch contestants from Firestore
$contestants = fetchContestants($firestoreUrl);

// Create a map for easy lookup
$contestantMap = [];
foreach ($contestants as $contestant) {
    $contestantMap[$contestant['code']] = $contestant;
}

// Default values
$message = "";
$continueSession = false;

// MAIN MENU
if ($newSession == true) {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Vote Counts";
    $continueSession = true;
}

// CONTESTANT MENU (after selecting Vote option)
elseif ($userData == "1") {
    $message = "Select Contestant:\n";
    $counter = 1;
    foreach ($contestants as $contestant) {
        $message .= $counter . ". " . $contestant['stageName'] . " (" . $contestant['code'] . ") - GHC " . $contestant['voteAmount'] . "/vote\n";
        $counter++;
    }
    $message .= "0. Back to Main Menu";
    $continueSession = true;
}

// Handle specific contestant selection (FS1, FS2, etc.)
elseif (preg_match('/^1\*(\d+)$/', $userData, $matches)) {
    $selection = intval($matches[1]);
    
    if ($selection >= 1 && $selection <= count($contestants)) {
        $selectedContestant = $contestants[$selection - 1];
        
        $message = "Vote for " . $selectedContestant['stageName'] . "\n";
        $message .= "Nominee Code: " . $selectedContestant['code'] . "\n";
        $message .= "Vote: GHC " . $selectedContestant['voteAmount'] . " per vote\n";
        $message .= "Current votes: " . $selectedContestant['votes'] . "\n";
        $message .= "Enter number of votes (1-1000):";
        $continueSession = true;
        
        // Store selected contestant in session
        session_start();
        $_SESSION['selected_contestant'] = $selectedContestant;
    } else {
        $message = "Invalid selection. Please try again.\n";
        $message .= "1. Vote\n";
        $message .= "0. Back to Main Menu";
        $continueSession = true;
    }
}

// Process votes
elseif (preg_match('/^1\*(\d+)\*(\d+)$/', $userData, $matches)) {
    session_start();
    
    if (isset($_SESSION['selected_contestant'])) {
        $contestant = $_SESSION['selected_contestant'];
        $numberOfVotes = intval($matches[2]);
        
        // Validate vote count
        if ($numberOfVotes < 1 || $numberOfVotes > 1000) {
            $message = "Invalid number of votes. Please enter a number between 1 and 1000.";
            $continueSession = false;
        } else {
            // Calculate total cost
            $totalCost = $numberOfVotes * $contestant['voteAmount'];
            
            // Update votes in Firestore
            $success = updateVotes($firestoreUrl, $contestant['code'], $contestant['votes'], $numberOfVotes);
            
            if ($success) {
                $message = "Vote successful!\n";
                $message .= "You voted for: " . $contestant['stageName'] . " (" . $contestant['code'] . ")\n";
                $message .= "Number of votes: " . $numberOfVotes . "\n";
                $message .= "Total cost: GHC " . $totalCost . "\n";
                $message .= "Thank you for voting!";
                
                // Log the vote (optional - for your records)
                logVote($msisdn, $contestant['code'], $numberOfVotes, $totalCost);
            } else {
                $message = "Sorry, there was an error processing your vote. Please try again.";
            }
            
            $continueSession = false;
            unset($_SESSION['selected_contestant']);
        }
    } else {
        $message = "Session expired. Please start over.";
        $continueSession = false;
    }
}

// Check vote counts
elseif ($userData == "2") {
    $message = "Current Vote Counts:\n";
    foreach ($contestants as $contestant) {
        $message .= $contestant['stageName'] . " (" . $contestant['code'] . "): " . $contestant['votes'] . " votes\n";
    }
    $message .= "\n0. Back to Main Menu";
    $continueSession = true;
}

// Back to main menu
elseif ($userData == "0") {
    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Vote Counts";
    $continueSession = true;
}

// Invalid option
else {
    $message = "Invalid option. Please try again.\n";
    $message .= "1. Vote\n";
    $message .= "2. Check Vote Counts\n";
    $message .= "0. Exit";
    $continueSession = true;
}

// Function to log votes (optional)
function logVote($msisdn, $contestantCode, $votes, $totalCost) {
    $logEntry = date('Y-m-d H:i:s') . " | MSISDN: $msisdn | Contestant: $contestantCode | Votes: $votes | Cost: GHC $totalCost\n";
    file_put_contents('vote_log.txt', $logEntry, FILE_APPEND);
}

// Response
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);

?>
