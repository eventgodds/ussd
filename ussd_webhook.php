<?php
// ussd_webhook.php - Paystack sends background notifications here
header('Content-Type: application/json');

$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Verify webhook signature
$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature !== hash_hmac('sha512', $input, $paystackSecretKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

$event = json_decode($input, true);

if ($event['event'] == 'charge.success') {
    $data = $event['data'];
    $metadata = $data['metadata'];
    
    // Log successful payment
    $logEntry = date('Y-m-d H:i:s') . " - Webhook: Payment successful - Reference: {$data['reference']} - Nominee: {$metadata['nominee_code']} - Votes: {$metadata['votes']}\n";
    file_put_contents('payment_webhook.log', $logEntry, FILE_APPEND);
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
}
?>
