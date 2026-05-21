<?php

// Firebase configuration - These should be set as Environment Variables
$firebaseBaseUrl = getenv('FIREBASE_BASE_URL') ?: 'https://eventgodds-41e4f-default-rtdb.firebaseio.com/';
$firebaseAuthToken = getenv('FIREBASE_AUTH_TOKEN') ?: '';

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
        return json_decode($response, true);
    }
    
    error_log('Firebase error: HTTP ' . $httpCode . ' - ' . $response);
    return null;
}

// Initialize contestants (FS1 to FS5)
function initializeContestants() {
    $existing = firebaseRequest("GET", "contestants");
    
    if (!$existing || empty($existing)) {
        $contestants = [
            "FS1" => [
                "contestant_name" => "John Mensah",
                "votes" => 0,
                "code" => "FS1",
                "description" => "Vocalist Extraordinaire"
            ],
            "FS2" => [
                "contestant_name" => "Mary Asante",
                "votes" => 0,
                "code" => "FS2",
                "description" => "Dance Sensation"
            ],
            "FS3" => [
                "contestant_name" => "David Boateng",
                "votes" => 0,
                "code" => "FS3",
                "description" => "Master Instrumentalist"
            ],
            "FS4" => [
                "contestant_name" => "Sarah Owusu",
                "votes" => 0,
                "code" => "FS4",
                "description" => "Poetry & Spoken Word"
            ],
            "FS5" => [
                "contestant_name" => "Michael Kofi",
                "votes" => 0,
                "code" => "FS5",
                "description" => "Comedy King"
            ]
        ];
        
        foreach ($contestants as $code => $contestant) {
            firebaseRequest("PUT", "contestants/" . $code, $contestant);
        }
        
        return true;
    }
    
    return false;
}

// Call initialization
initializeContestants();

?>
