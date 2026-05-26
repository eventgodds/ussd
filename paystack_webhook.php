<?php
header('Content-Type: application/json');

$paystackSecretKey = "sk_live_b8d6b1eba856a6da4d891482e1324c55a05c69cc"; // Your live secret key
$projectId = 'eventgodds-41e4f';
$firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";

// Verify webhook signature
$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if (!$signature) {
    http_response_code(401);
    exit;
}

// Verify signature (using hash_hmac)
$computed = hash_hmac('sha512', $input, $paystackSecretKey);
if (!hash_equals($computed, $signature)) {
    http_response_code(401);
    exit;
}

$event = json_decode($input, true);

if ($event['event'] == 'charge.success') {
    $reference = $event['data']['reference'];
    $amount = $event['data']['amount'] / 100;
    $metadata = $event['data']['metadata'];
    
    // Here you would update your database with the successful payment
    // You can store session ID in metadata to retrieve pending vote
    
    file_put_contents('paystack_log.txt', json_encode($event) . PHP_EOL, FILE_APPEND);
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
}
?>
