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

// Add this function for mobile money payments
function initiateMobileMoneyPayment($phone, $amount, $reference) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/charge";
    
    $fields = [
        'amount' => $amount * 100,
        'email' => $phone . "@user.ussd.com",
        'currency' => 'GHS',
        'mobile_money' => [
            'phone' => $phone,
            'provider' => 'mtn' // or 'vodafone', 'airteltigo'
        ],
        'reference' => $reference
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
?>
