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
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    
    error_log("Firestore Error: HTTP $httpCode - " . substr($response, 0, 500));
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
        
        // Parse all fields from Firestore
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
        
        // Get document ID
        $contestant['document_id'] = basename($doc['name']);
        $contestants[] = $contestant;
    }
    
    error_log("Found " . count($contestants) . " contestants");
    return $contestants;
}

/**
 * Get contestant by code field (case insensitive)
 */
function getContestantByCode($code) {
    error_log("Searching for contestant with code: " . $code);
    
    // Get all contestants
    $allContestants = getAllContestants();
    
    if (empty($allContestants)) {
        error_log("No contestants found in database");
        return null;
    }
    
    // Search for matching code
    foreach ($allContestants as $contestant) {
        if (isset($contestant['code'])) {
            $contestantCode = strtoupper(trim($contestant['code']));
            $searchCode = strtoupper(trim($code));
            
            if ($contestantCode === $searchCode) {
                error_log("Found contestant: " . ($contestant['stageName'] ?? $contestant['name']));
                return $contestant;
            }
        }
    }
    
    error_log("No contestant found with code: " . $code);
    return null;
}

/**
 * Update contestant votes
 */
function updateContestantVotes($code, $newVotes) {
    error_log("Updating votes for code: $code to $newVotes");
    
    // Find the document ID first
    $allContestants = getAllContestants();
    $documentId = null;
    
    foreach ($allContestants as $contestant) {
        if (isset($contestant['code']) && strtoupper($contestant['code']) === strtoupper($code)) {
            $documentId = $contestant['document_id'];
            break;
        }
    }
    
    if (!$documentId) {
        error_log("Cannot find document ID for code: $code");
        return false;
    }
    
    // Update only the votes field using PATCH
    $updateData = [
        'fields' => [
            'votes' => ['integerValue' => $newVotes]
        ]
    ];
    
    $result = firestoreRequest('PATCH', "contestants/$documentId", $updateData);
    
    if ($result !== null) {
        error_log("Successfully updated votes for $code");
        return true;
    }
    
    error_log("Failed to update votes for $code");
    return false;
}

/**
 * Save vote history
 */
function saveVoteHistory($voteData) {
    $firestoreData = ['fields' => []];
    
    foreach ($voteData as $key => $value) {
        if (is_int($value)) {
            $firestoreData['fields'][$key] = ['integerValue' => $value];
        } elseif (is_bool($value)) {
            $firestoreData['fields'][$key] = ['booleanValue' => $value];
        } else {
            $firestoreData['fields'][$key] = ['stringValue' => (string)$value];
        }
    }
    
    $voteId = 'vote_' . uniqid() . '_' . time();
    $result = firestoreRequest('POST', "vote_history?documentId=" . $voteId, $firestoreData);
    
    if ($result !== null) {
        error_log("Vote history saved with ID: $voteId");
        return true;
    }
    
    error_log("Failed to save vote history");
    return false;
}

/**
 * Test Firestore connection and data
 */
function testFirestoreConnection() {
    error_log("Testing Firestore connection...");
    
    // Test getting contestants
    $contestants = getAllContestants();
    
    if (!empty($contestants)) {
        error_log("SUCCESS: Found " . count($contestants) . " contestants");
        foreach ($contestants as $c) {
            error_log("Contestant: " . ($c['stageName'] ?? 'No name') . " - Code: " . ($c['code'] ?? 'No code'));
        }
        return true;
    }
    
    error_log("FAILED: No contestants found or connection error");
    return false;
}

// Run test on script load
testFirestoreConnection();

?>
