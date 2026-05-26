<?php
header('Content-Type: application/json');

$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Function to update votes
function updateContestantVotes($firestoreUrl, $documentId, $newVotes) {
    $updateUrl = $firestoreUrl . "/contestants/{$documentId}?updateMask.fieldPaths=votes";
    
    $updateData = [
        'fields' => [
            'votes' => [
                'integerValue' => (string)$newVotes
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $updateUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Verify webhook signature
$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature !== hash_hmac('sha512', $input, $paystackSecretKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($input, true);

if ($event['event'] == 'charge.success') {
    $metadata = $event['data']['metadata'];
    $contestantCode = $metadata['contestant_code'];
    $votes = intval($metadata['votes']);
    
    // Fetch contestant from Firestore
    $url = $firestoreUrl . "/contestants";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields['code']['stringValue']) && $fields['code']['stringValue'] == $contestantCode) {
                $documentId = basename($doc['name']);
                $currentVotes = $fields['votes']['integerValue'] ?? 0;
                $newVotes = $currentVotes + $votes;
                
                updateContestantVotes($firestoreUrl, $documentId, $newVotes);
                
                // Log success
                $logEntry = date('Y-m-d H:i:s') . " | WEBHOOK | Contestant: {$contestantCode} | Votes: {$votes} | New Total: {$newVotes}\n";
                file_put_contents('payment_log.txt', $logEntry, FILE_APPEND);
                
                break;
            }
        }
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
}
?>
