<?php
// ussd_webhook.php - For automatic vote updates
header('Content-Type: application/json');

$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Verify webhook signature
$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature !== hash_hmac('sha512', $input, $paystackSecretKey)) {
    http_response_code(401);
    exit;
}

$event = json_decode($input, true);

if ($event['event'] == 'charge.success') {
    $metadata = $event['data']['metadata'];
    
    // Update votes in your database here
    // This runs automatically when payment is successful
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
}
?>
