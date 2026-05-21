<?php

// Firebase configuration
$firebaseBaseUrl = 'https://eventgodds-41e4f-default-rtdb.firebaseio.com/';
$firebaseAuthToken = ''; // Add if you have authentication enabled

function firebaseRequest($method, $path, $data = null) {
    global $firebaseBaseUrl, $firebaseAuthToken;
    
    $url = rtrim($firebaseBaseUrl, '/') . '/' . ltrim($path, '/') . '.json';
    
    if ($firebaseAuthToken) {
        $url .= '?auth=' . $firebaseAuthToken;
    }
    
    $ch = curl_init();
    
    switch ($method) {
        case 'GET':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log('Firebase Curl error: ' . curl_error($ch));
        return null;
    }
    
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        return $decoded;
    }
    
    error_log('Firebase error: HTTP ' . $httpCode . ' - ' . $response);
    return null;
}

// Initialize sample contestants
function initializeContestants() {
    $existing = firebaseRequest("GET", "contestants");
    
    if (!$existing) {
        $sampleContestants = [
            "CONT001" => [
                "contestant_name" => "John Mensah",
                "votes" => 0,
                "code" => "CONT001",
                "description" => "Talented vocalist"
            ],
            "CONT002" => [
                "contestant_name" => "Mary Asante",
                "votes" => 0,
                "code" => "CONT002",
                "description" => "Amazing dancer"
            ],
            "CONT003" => [
                "contestant_name" => "David Boateng",
                "votes" => 0,
                "code" => "CONT003",
                "description" => "Skilled instrumentalist"
            ],
            "CONT004" => [
                "contestant_name" => "Sarah Owusu",
                "votes" => 0,
                "code" => "CONT004",
                "description" => "Creative poet"
            ]
        ];
        
        foreach ($sampleContestants as $code => $contestant) {
            firebaseRequest("PUT", "contestants/" . $code, $contestant);
        }
        
        return true;
    }
    
    return false;
}

// Initialize on first run
initializeContestants();

?>
