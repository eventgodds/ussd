<?php

// Firebase Configuration
define('FIREBASE_PROJECT_ID', 'eventgodds-41e4f');
define('FIREBASE_API_KEY', 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk');

/**
 * Make HTTP request to Firestore REST API
 */
function firestoreRequest($method, $path, $data = null) {
    $url = "https://firestore.googleapis.com/v1/projects/" . FIREBASE_PROJECT_ID . "/databases/(default)/documents/" . $path . "?key=" . FIREBASE_API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    
    error_log("Firestore Error: HTTP $httpCode - $response");
    return null;
}

/**
 * Get all contestants from Firestore
 */
function getAllContestants() {
    $result = firestoreRequest('GET', 'contestants');
    
    if (!$result || !isset($result['documents'])) {
        return [];
    }
    
    $contestants = [];
    foreach ($result['documents'] as $doc) {
        $contestant = [];
        $fields = $doc['fields'];
        
        // Parse all fields
        foreach ($fields as $key => $value) {
            if (isset($value['stringValue'])) {
                $contestant[$key] = $value['stringValue'];
            } elseif (isset($value['integerValue'])) {
                $contestant[$key] = intval($value['integerValue']);
            } elseif (isset($value['doubleValue'])) {
                $contestant[$key] = floatval($value['doubleValue']);
            } elseif (isset($value['booleanValue'])) {
                $contestant[$key] = $value['booleanValue'] === 'true';
            }
        }
        
        $contestant['document_id'] = basename($doc['name']);
        $contestants[] = $contestant;
    }
    
    return $contestants;
}

/**
 * Get contestant by code field
 */
function getContestantByCode($code) {
    // First try: Get all and search (most reliable)
    $allContestants = getAllContestants();
    
    foreach ($allContestants as $contestant) {
        if (isset($contestant['code']) && strtoupper($contestant['code']) == strtoupper($code)) {
            return $contestant;
        }
    }
    
    return null;
}

/**
 * Update contestant votes
 */
function updateContestantVotes($code, $newVotes) {
    // Find the document first
    $allContestants = getAllContestants();
    $documentId = null;
    
    foreach ($allContestants as $contestant) {
        if (isset($contestant['code']) && $contestant['code'] == $code) {
            $documentId = $contestant['document_id'];
            break;
        }
    }
    
    if (!$documentId) {
        error_log("Contestant not found: $code");
        return false;
    }
    
    // Update only the votes field
    $updateData = [
        'fields' => [
            'votes' => ['integerValue' => $newVotes]
        ]
    ];
    
    $result = firestoreRequest('PATCH', "contestants/$documentId", $updateData);
    
    return $result !== null;
}

/**
 * Save vote record
 */
function saveVoteRecord($voteData) {
    $firestoreData = ['fields' => []];
    
    foreach ($voteData as $key => $value) {
        if (is_int($value)) {
            $firestoreData['fields'][$key] = ['integerValue' => $value];
        } else {
            $firestoreData['fields'][$key] = ['stringValue' => (string)$value];
        }
    }
    
    $voteId = 'vote_' . uniqid() . '_' . time();
    $result = firestoreRequest('POST', "vote_history?documentId=" . $voteId, $firestoreData);
    
    return $result !== null;
}

/**
 * Test Firestore connection
 */
function testFirestoreConnection() {
    $result = firestoreRequest('GET', 'contestants?pageSize=1');
    
    if ($result && isset($result['documents'])) {
        return true;
    }
    
    return false;
}
?>
