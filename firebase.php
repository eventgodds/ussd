<?php

// Firebase Firestore Configuration
$firebaseConfig = [
    'apiKey' => 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk',
    'authDomain' => 'eventgodds-41e4f.firebaseapp.com',
    'projectId' => 'eventgodds-41e4f',
    'databaseURL' => 'https://eventgodds-41e4f-default-rtdb.firebaseio.com',
];

// Function to get Firestore access token
function getFirestoreAccessToken() {
    $url = 'https://firebase.googleapis.com/v1beta1/projects/eventgodds-41e4f/defaultLocation:initialize';
    
    // Using OAuth2 for Firebase
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/oauth2/v1/certs');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    // For simplicity, we'll use the REST API with the API key
    return $GLOBALS['firebaseConfig']['apiKey'];
}

// Function to get documents from Firestore collection
function getFirestoreCollection($collectionName) {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    // Firestore REST API URL
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
                
                // Parse Firestore fields
                foreach ($fields as $key => $value) {
                    if (isset($value['stringValue'])) {
                        $document[$key] = $value['stringValue'];
                    } elseif (isset($value['integerValue'])) {
                        $document[$key] = $value['integerValue'];
                    } elseif (isset($value['booleanValue'])) {
                        $document[$key] = $value['booleanValue'];
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
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    // Query Firestore for contestant with matching code
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
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
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
                    $contestant[$key] = $value['integerValue'];
                }
            }
            
            return $contestant;
        }
    }
    
    // If code field doesn't work, try fetching all and matching
    $allContestants = getFirestoreCollection("contestants");
    if ($allContestants) {
        foreach ($allContestants as $contestant) {
            if ((isset($contestant['code']) && $contestant['code'] == $contestantCode) ||
                (isset($contestant['contestant_code']) && $contestant['contestant_code'] == $contestantCode)) {
                return $contestant;
            }
        }
    }
    
    return null;
}

// Function to add document to Firestore collection
function addDocumentToCollection($collectionName, $data) {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionName}?key={$apiKey}&documentId=" . uniqid();
    
    // Convert data to Firestore format
    $firestoreData = ['fields' => []];
    foreach ($data as $key => $value) {
        $firestoreData['fields'][$key] = ['stringValue' => (string)$value];
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

// Function to get single document from Firestore
function getFirestoreDocument($collectionName, $documentId) {
    $projectId = 'eventgodds-41e4f';
    $apiKey = 'AIzaSyD9OEg_1P6b6G1pJUCWofOBXF6l25kpoRk';
    
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionName}/{$documentId}?key={$apiKey}";
    
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
        $document = [];
        
        foreach ($fields as $key => $value) {
            if (isset($value['stringValue'])) {
                $document[$key] = $value['stringValue'];
            } elseif (isset($value['integerValue'])) {
                $document[$key] = $value['integerValue'];
            }
        }
        
        $document['id'] = $documentId;
        return $document;
    }
    
    return null;
}
?>
