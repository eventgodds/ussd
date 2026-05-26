<?php
header('Content-Type: application/json');

$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

// Function to update votes (same as above)
function updateContestantVotes($firestoreUrl, $documentId, $newVotes) {
    // ... (copy the update function from main code)
}

// Verify Paystack webhook signature
$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

// Your Paystack secret key
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Verify webhook
if ($signature !== hash_hmac('sha512', $input, $paystackSecretKey)) {
    http_response_code(401);
    exit;
}

$event = json_decode($input, true);

if ($event['event'] == 'charge.success') {
    $metadata = $event['data']['metadata'];
    $contestantCode = $metadata['contestant_code'];
    $votes = $metadata['votes'];
    
    // Fetch contestant and update votes
    // ... (add logic to find and update contestant)
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
}
?>
