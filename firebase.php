<?php

// Function to get all documents from Firestore collection
function getFirestoreCollection($collectionName) {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionName}?key={$apiKey}";
    
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
        $documents = [];
        
        if (isset($data['documents'])) {
            foreach ($data['documents'] as $doc) {
                $fields = $doc['fields'];
                $document = [];
                
                // Parse Firestore fields (handles different data types)
                foreach ($fields as $key => $value) {
                    if (isset($value['stringValue'])) {
                        $document[$key] = $value['stringValue'];
                    } elseif (isset($value['integerValue'])) {
                        $document[$key] = intval($value['integerValue']);
                    } elseif (isset($value['doubleValue'])) {
                        $document[$key] = floatval($value['doubleValue']);
                    } elseif (isset($value['booleanValue'])) {
                        $document[$key] = $value['booleanValue'] === 'true';
                    }
                }
                
                $document['id'] = basename($doc['name']);
                $documents[] = $document;
            }
        }
        
        return $documents;
    }
    
    return null;
}

// Function to find contestant by code
function findContestantByCode($contestantCode) {
    // First try direct query
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    // Firestore query for exact code match
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:runQuery?key={$apiKey}";
    
    $query = [
        'structuredQuery' => [
            'from' => [
                ['collectionId' => 'contestants']
            ],
            'where' => [
                'fieldFilter' => [
                    'field' => ['fieldPath' => 'code'],
                    'op' => 'EQUAL',
                    'value' => ['stringValue' => $contestantCode]
                ]
            ],
            'limit' => 1
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' => 'application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        
        if (isset($data[0]['document'])) {
            $fields = $data[0]['document']['fields'];
            $contestant = [];
            
            foreach ($fields as $key => $value) {
                if (isset($value['stringValue'])) {
                    $contestant[$key] = $value['stringValue'];
                } elseif (isset($value['integerValue'])) {
                    $contestant[$key] = intval($value['integerValue']);
                } elseif (isset($value['doubleValue'])) {
                    $contestant[$key] = floatval($value['doubleValue']);
                }
            }
            
            return $contestant;
        }
    }
    
    // Fallback: Get all and search
    $allContestants = getFirestoreCollection("contestants");
    if ($allContestants) {
        foreach ($allContestants as $contestant) {
            if (isset($contestant['code']) && $contestant['code'] == $contestantCode) {
                return $contestant;
            }
        }
    }
    
    return null;
}

// Function to update contestant votes
function updateContestantVotes($contestantCode, $newVotes) {
    // First find the document ID for this contestant
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    // Get all contestants to find document ID
    $contestants = getFirestoreCollection("contestants");
    $documentId = null;
    
    foreach ($contestants as $contestant) {
        if ($contestant['code'] == $contestantCode) {
            $documentId = $contestant['id'];
            break;
        }
    }
    
    if (!$documentId) {
        return false;
    }
    
    // Update the votes field
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/contestants/{$documentId}?key={$apiKey}";
    
    $updateData = [
        'fields' => [
            'votes' => ['integerValue' => $newVotes]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Function to add document to collection
function addDocumentToCollection($collectionName, $data) {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionName}?key={$apiKey}&documentId=" . uniqid();
    
    // Convert data to Firestore format
    $firestoreData = ['fields' => []];
    foreach ($data as $key => $value) {
        if (is_int($value)) {
            $firestoreData['fields'][$key] = ['integerValue' => $value];
        } else {
            $firestoreData['fields'][$key] = ['stringValue' => (string)$value];
        }
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($firestoreData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Function to get a single contestant by document ID
function getContestantByDocumentId($documentId) {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/contestants/{$documentId}?key={$apiKey}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        $fields = $data['fields'];
        $contestant = [];
        
        foreach ($fields as $key => $value) {
            if (isset($value['stringValue'])) {
                $contestant[$key] = $value['stringValue'];
            } elseif (isset($value['integerValue'])) {
                $contestant[$key] = intval($value['integerValue']);
            }
        }
        
        return $contestant;
    }
    
    return null;
}
?>
