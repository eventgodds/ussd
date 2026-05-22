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
        error_log("No documents found in contestants collection");
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
    
    error_log("Found " . count($contestants) . " contestants");
    return $contestants;
}

/**
 * Get contestant by code field
 */
function getContestantByCode($code) {
    // Get all contestants and search
    $allContestants = getAllContestants();
    
    foreach ($allContestants as $contestant) {
        if (isset($contestant['code']) && strtoupper(trim($contestant['code'])) == strtoupper(trim($code))) {
            error_log("Found contestant: " . ($contestant['stageName'] ?? $contestant['name']));
            return $contestant;
        }
    }
    
    error_log("No contestant found with code: " . $code);
    return null;
}

/**
 * Update contestant votes
 */
function updateContestantVotes($code, $newVotes) {
    // Find the document ID first
    $allContestants = getAllContestants();
    $documentId = null;
    
    foreach ($allContestants as $contestant) {
        if (isset($contestant['code']) && $contestant['code'] == $code) {
            $documentId = $contestant['document_id'];
            break;
        }
    }
    
    if (!$documentId) {
        error_log("Cannot find document ID for code: $code");
        return false;
    }
    
    // Update only the votes field
    $updateData = [
        'fields' => [
            'votes' => ['integerValue' => $newVotes]
        ]
    ];
    
    $result = firestoreRequest('PATCH', "contestants/$documentId", $updateData);
    
    if ($result) {
        error_log("Successfully updated votes for $code to $newVotes");
        return true;
    }
    
    error_log("Failed to update votes for $code");
    return false;
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
    
    if ($result) {
        error_log("Vote record saved with ID: $voteId");
        return true;
    }
    
    error_log("Failed to save vote record");
    return false;
}

/**
 * Test Firestore connection (for debugging)
 */
function testFirestoreConnection() {
    $result = firestoreRequest('GET', 'contestants?pageSize=1');
    
    if ($result && isset($result['documents'])) {
        error_log("Firestore connection successful");
        return true;
    }
    
    error_log("Firestore connection failed");
    return false;
}

// Run connection test on load
testFirestoreConnection();
?>
