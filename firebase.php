<?php

// Firebase configuration
$firebaseBaseUrl = getenv('FIREBASE_BASE_URL') ?: 'https://your-firebase-project.firebaseio.com/';
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

// Helper function to initialize sample data
function initializeFirebaseData() {
    $sampleContestants = [
        "CONT001" => [
            "contestant_name" => "John Mensah",
            "votes" => 0,
            "code" => "CONT001"
        ],
        "CONT002" => [
            "contestant_name" => "Mary Asante",
            "votes" => 0,
            "code" => "CONT002"
        ],
        "CONT003" => [
            "contestant_name" => "David Boateng",
            "votes" => 0,
            "code" => "CONT003"
        ],
        "CONT004" => [
            "contestant_name" => "Sarah Owusu",
            "votes" => 0,
            "code" => "CONT004"
        ]
    ];
    
    foreach ($sampleContestants as $code => $contestant) {
        $existing = firebaseRequest("GET", "contestants/" . $code);
        if (!$existing) {
            firebaseRequest("PUT", "contestants/" . $code, $contestant);
        }
    }
}

?>
