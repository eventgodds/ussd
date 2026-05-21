<?php

// Paystack Configuration
$paystackSecretKey = getenv('PAYSTACK_SECRET_KEY') ?: 'sk_live_xxxxxxxxxxxxxxxxxxxxxxxx'; // Replace with your live key
$paystackPublicKey = getenv('PAYSTACK_PUBLIC_KEY') ?: 'pk_live_xxxxxxxxxxxxxxxxxxxxxxxx';

function initializePayment($email, $amount, $callbackUrl) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/initialize";
    
    $fields = [
        'email' => $email,
        'amount' => $amount, // Amount in pesewas (100 = GHS 1.00)
        'callback_url' => $callbackUrl,
        'metadata' => [
            'source' => 'USSD',
            'phone' => $email // Actually MSISDN
        ]
    ];
    
    $fields_string = json_encode($fields);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $paystackSecretKey,
        "Content-Type: application/json",
        "Cache-Control: no-cache"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

function verifyPayment($reference) {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/transaction/verify/" . $reference;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $paystackSecretKey,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

function listBanks() {
    global $paystackSecretKey;
    
    $url = "https://api.paystack.co/bank";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $paystackSecretKey,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

?>
