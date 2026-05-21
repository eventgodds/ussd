<?php

$firebaseProjectId = 'eventgodds-41e4f';
$firebaseApiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';

function firestoreRequest($method, $collection, $documentId = null, $data = null) {
    global $firebaseProjectId, $firebaseApiKey;
    
    // Firestore REST API endpoint
    $baseUrl = "https://firestore.googleapis.com/v1/projects/{$firebaseProjectId}/databases/(default)/documents";
    
    if ($documentId) {
        $url = $baseUrl . "/{$collection}/{$documentId}";
    } else {
        $url = $baseUrl . "/{$collection}";
    }
    
    $url .= "?key={$firebaseApiKey}";
    
    $ch = curl_init();
    
    switch ($method) {
        case 'GET':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            if ($data) {
                $firestoreData = ['fields' => convertToFirestoreFields($data)];
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($firestoreData));
            }
            break;
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                $firestoreData = ['fields' => convertToFirestoreFields($data)];
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($firestoreData));
            }
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
        error_log('Firestore Curl error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $result = json_decode($response, true);
        // Convert Firestore format back to simple array
        if ($method == 'GET' && isset($result['documents'])) {
            return convertFromFirestoreList($result['documents']);
        } elseif ($method == 'GET' && isset($result['fields'])) {
            return convertFromFirestoreDocument($result);
        }
        return $result;
    }
    
    error_log('Firestore error: HTTP ' . $httpCode . ' - ' . $response);
    return null;
}

// Convert simple PHP array to Firestore format
function convertToFirestoreFields($data) {
    $fields = [];
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            $fields[$key] = ['stringValue' => $value];
        } elseif (is_int($value)) {
            $fields[$key] = ['integerValue' => $value];
        } elseif (is_bool($value)) {
            $fields[$key] = ['booleanValue' => $value];
        } elseif (is_float($value)) {
            $fields[$key] = ['doubleValue' => $value];
        } elseif (is_array($value)) {
            $fields[$key] = ['arrayValue' => ['values' => array_values($value)]];
        } else {
            $fields[$key] = ['stringValue' => (string)$value];
        }
    }
    return $fields;
}

// Convert Firestore document to simple array
function convertFromFirestoreDocument($document) {
    if (!isset($document['fields'])) {
        return null;
    }
    
    $data = [];
    foreach ($document['fields'] as $key => $field) {
        if (isset($field['stringValue'])) {
            $data[$key] = $field['stringValue'];
        } elseif (isset($field['integerValue'])) {
            $data[$key] = (int)$field['integerValue'];
        } elseif (isset($field['booleanValue'])) {
            $data[$key] = (bool)$field['booleanValue'];
        } elseif (isset($field['doubleValue'])) {
            $data[$key] = (float)$field['doubleValue'];
        }
    }
    return $data;
}

// Convert Firestore collection list to simple array
function convertFromFirestoreList($documents) {
    $result = [];
    foreach ($documents as $doc) {
        $pathParts = explode('/', $doc['name']);
        $docId = end($pathParts);
        $result[$docId] = convertFromFirestoreDocument($doc);
    }
    return $result;
}

// Helper functions for USSD app
function getContestants() {
    return firestoreRequest('GET', 'contestants');
}

function getContestant($code) {
    $result = firestoreRequest('GET', 'contestants', $code);
    return $result;
}

function updateContestantVotes($code, $votes) {
    return firestoreRequest('PATCH', 'contestants', $code, ['votes' => $votes]);
}

function saveSession($msisdn, $data) {
    return firestoreRequest('POST', 'sessions', $msisdn, $data);
}

function getSession($msisdn) {
    return firestoreRequest('GET', 'sessions', $msisdn);
}

function deleteSession($msisdn) {
    return firestoreRequest('DELETE', 'sessions', $msisdn);
}

function saveTransaction($data) {
    return firestoreRequest('POST', 'transactions', null, $data);
}

// Initialize contestants FS1 to FS5
function initializeContestants() {
    $existing = getContestants();
    
    if (!$existing || empty($existing)) {
        $contestants = [
            "FS1" => [
                "contestant_name" => "Contestant FS1",
                "votes" => 0,
                "code" => "FS1"
            ],
            "FS2" => [
                "contestant_name" => "Contestant FS2",
                "votes" => 0,
                "code" => "FS2"
            ],
            "FS3" => [
                "contestant_name" => "Contestant FS3",
                "votes" => 0,
                "code" => "FS3"
            ],
            "FS4" => [
                "contestant_name" => "Contestant FS4",
                "votes" => 0,
                "code" => "FS4"
            ],
            "FS5" => [
                "contestant_name" => "Contestant FS5",
                "votes" => 0,
                "code" => "FS5"
            ]
        ];
        
        foreach ($contestants as $code => $contestant) {
            firestoreRequest('POST', 'contestants', $code, $contestant);
        }
        return true;
    }
    return false;
}

?>
