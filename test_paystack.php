<?php
$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

echo "<h2>Paystack Connection Test</h2>";

// Test 1: Check if cURL is enabled
echo "<h3>Test 1: cURL Check</h3>";
if (function_exists('curl_version')) {
    $version = curl_version();
    echo "✅ cURL is enabled<br>";
    echo "Version: " . $version['version'] . "<br>";
} else {
    echo "❌ cURL is NOT enabled - Contact Railway support to enable cURL<br>";
}

// Test 2: Test Paystack API connection
echo "<h3>Test 2: Paystack API Connection</h3>";
$url = "https://api.paystack.co/transaction/initialize";

$data = [
    'email' => 'test@example.com',
    'amount' => 100,
    'reference' => 'TEST_' . time()
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $paystackSecretKey,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo "❌ Connection failed: " . $curlError . "<br>";
} else {
    echo "✅ Connection successful!<br>";
    echo "HTTP Code: " . $httpCode . "<br>";
    $result = json_decode($response, true);
    if ($result && $result['status']) {
        echo "✅ Paystack API is working!<br>";
    } else {
        echo "Response: " . htmlspecialchars($response) . "<br>";
    }
}

// Test 3: Check if outbound HTTPS works
echo "<h3>Test 3: Outbound HTTPS Check</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://www.google.com");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo "❌ Outbound HTTPS failed: " . $curlError . "<br>";
} else {
    echo "✅ Outbound HTTPS works!<br>";
}
?>
