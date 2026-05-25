<?php

function firebaseRequest($method, $path, $data = null)
{
    $firebaseURL = "https://eventgodds-default-rtdb.firebaseio.com/";
    
    // Handle different paths
    if ($path === "awards_nominees") {
        $url = $firebaseURL . "awards_nominees.json";
    } elseif (strpos($path, "awards_nominees/") === 0) {
        $contestantCode = str_replace("awards_nominees/", "", $path);
        $url = $firebaseURL . "awards_nominees/" . $contestantCode . ".json";
    } elseif ($path === "sessions") {
        $url = $firebaseURL . "sessions.json";
    } elseif (strpos($path, "sessions/") === 0) {
        $sessionId = str_replace("sessions/", "", $path);
        $url = $firebaseURL . "sessions/" . $sessionId . ".json";
    } else {
        $url = $firebaseURL . $path . ".json";
    }

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($data)
        );
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        return ["error" => true, "httpCode" => $httpCode, "response" => $response];
    }

    return json_decode($response, true);
}

// Function to initialize all contestants
function initializeAllContestants() {
    $hardcodedContestants = [
        'FS1' => ['fullName' => 'EGYIRWAA', 'votes' => 0, 'voteValue' => 1],
        'FS2' => ['fullName' => 'AGYEKUMWAA', 'votes' => 0, 'voteValue' => 1],
        'FS3' => ['fullName' => 'BOATEMAA', 'votes' => 0, 'voteValue' => 1],
        'FS4' => ['fullName' => 'ABENA', 'votes' => 0, 'voteValue' => 1],
        'FS5' => ['fullName' => 'SEDEM', 'votes' => 0, 'voteValue' => 1]
    ];
    
    foreach ($hardcodedContestants as $code => $data) {
        $existing = firebaseRequest("GET", "awards_nominees/" . $code);
        
        if (!isset($existing['fullName'])) {
            firebaseRequest("PUT", "awards_nominees/" . $code, $data);
        }
    }
}

// Call initialization when needed
// initializeAllContestants();
?>
