<?php

// Firebase Firestore configuration
$firebaseProjectId = 'eventgodds-41e4f';
$firebaseApiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';

function firestoreRequest($method, $collection, $documentId = null, $data = null) {
    global $firebaseProjectId, $firebaseApiKey;
    
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
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
        
        if ($method == 'GET' && isset($result['documents'])) {
            $converted = [];
            foreach ($result['documents'] as $doc) {
                $pathParts = explode('/', $doc['name']);
                $docId = end($pathParts);
                $converted[$docId] = convertFromFirestoreDocument($doc);
            }
            return $converted;
        }
        elseif ($method == 'GET' && isset($result['fields'])) {
            return convertFromFirestoreDocument($result);
        }
        
        return $result;
    }
    
    error_log('Firestore error: HTTP ' . $httpCode . ' - ' . $response);
    return null;
}

function convertToFirestoreFields($data) {
    $fields = [];
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            $fields[$key] = ['stringValue' => $value];
        } elseif (is_int($value)) {
            $fields[$key] = ['integerValue' => $value];
        } elseif (is_bool($value)) {
            $fields[$key] = ['booleanValue' => $value];
        } else {
            $fields[$key] = ['stringValue' => (string)$value];
        }
    }
    return $fields;
}

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
        }
    }
    return $data;
}

// FIRESTORE DATABASE FUNCTIONS
function getContestants() {
    return firestoreRequest('GET', 'contestants');
}

function getContestant($code) {
    return firestoreRequest('GET', 'contestants', $code);
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
    $transactionId = $data['msisdn'] . '_' . time();
    return firestoreRequest('POST', 'transactions', $transactionId, $data);
}

// INITIALIZE CONTESTANTS IN FIRESTORE (ONLY IF EMPTY)
function initializeContestants() {
    $existing = getContestants();
    
    if (!$existing || empty($existing)) {
        $contestants = [
            "FS1" => ["contestant_name" => "Contestant FS1", "votes" => 0, "code" => "FS1"],
            "FS2" => ["contestant_name" => "Contestant FS2", "votes" => 0, "code" => "FS2"],
            "FS3" => ["contestant_name" => "Contestant FS3", "votes" => 0, "code" => "FS3"],
            "FS4" => ["contestant_name" => "Contestant FS4", "votes" => 0, "code" => "FS4"],
            "FS5" => ["contestant_name" => "Contestant FS5", "votes" => 0, "code" => "FS5"]
        ];
        
        foreach ($contestants as $code => $contestant) {
            firestoreRequest('POST', 'contestants', $code, $contestant);
        }
        return true;
    }
    return false;
}

// Run initialization
initializeContestants();

?>
