<?php
function processPaystackPayment($msisdn, $amount, $reference) {
    $paystackSecretKey = 'sk_live_'; // Replace with your Paystack secret key
    
    $url = 'https://api.paystack.co/transaction/initialize';
    
    $fields = [
        'amount' => $amount,
        'email' => $msisdn . '@eventvoter.com',
        'reference' => $reference,
        'metadata' => json_encode([
            'msisdn' => $msisdn,
            'custom_fields' => [
                [
                    'display_name' => 'Mobile Number',
                    'variable_name' => 'msisdn',
                    'value' => $msisdn
                ]
            ]
        ]),
        'channels' => ['ussd', 'mobile_money']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $result = json_decode($response, true);
        
        // For USSD, we return success if transaction is initialized
        // In production, you'd need to verify the transaction
        return ['status' => true, 'data' => $result];
    }
    
    return ['status' => false];
}

function verifyPaystackPayment($reference) {
    $paystackSecretKey = 'sk_live_'; // Replace with your Paystack secret key
    
    $url = 'https://api.paystack.co/transaction/verify/' . $reference;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && $result['status'] && $result['data']['status'] == 'success') {
        return true;
    }
    
    return false;
}
?>
