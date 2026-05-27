<?php
// ussd_callback.php - Paystack payment callback handler
header('Content-Type: text/html');

$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    
    // Verify payment
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/{$reference}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['status'] && $result['data']['status'] == 'success') {
        $metadata = $result['data']['metadata'];
        
        // Update your database here
        // You can also log the successful payment
        
        echo "<h1>Payment Successful!</h1>";
        echo "<p>" . $metadata['votes'] . " votes added for " . $metadata['nominee_code'] . "</p>";
        echo "<p>Thank you for voting!</p>";
    } else {
        echo "<h1>Payment Failed</h1>";
        echo "<p>Please try again.</p>";
    }
} else {
    echo "<h1>Invalid Request</h1>";
}
?>
