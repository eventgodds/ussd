<?php
// ussd_callback.php - Paystack redirects here after payment
// This file MUST return HTML, NOT JSON

$paystackSecretKey = 'sk_live_6a5b1dbeb60d226092af20f2b5ff151370c1ee1e';

// Database configurations for updating votes
$contestantsProjectId = 'eventgodds-41e4f';
$contestantsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$contestantsProjectId}/databases/(default)/documents";
$awardsProjectId = 'eventgodds';
$awardsFirestoreUrl = "https://firestore.googleapis.com/v1/projects/{$awardsProjectId}/databases/(default)/documents";

// Helper function to fetch nominee
function fetchNominee($url, $code, $type) {
    $collection = ($type == 'contestant') ? 'contestants' : 'awards_nominees';
    $codeField = ($type == 'contestant') ? 'code' : 'nomineeCode';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . "/" . $collection);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['documents'])) {
        foreach ($data['documents'] as $doc) {
            $fields = $doc['fields'];
            if (isset($fields[$codeField]['stringValue']) && $fields[$codeField]['stringValue'] === $code) {
                return [
                    'id' => basename($doc['name']),
                    'votes' => $fields['votes']['integerValue'] ?? 0
                ];
            }
        }
    }
    return null;
}

// Helper function to update votes
function updateVotes($firestoreUrl, $collection, $documentId, $newVotes) {
    $updateUrl = $firestoreUrl . "/{$collection}/{$documentId}?updateMask.fieldPaths=votes";
    $updateData = ['fields' => ['votes' => ['integerValue' => (string)$newVotes]]];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $updateUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
}

// Check if reference is provided
if (!isset($_GET['reference'])) {
    echo "<h1>Invalid Request</h1>";
    echo "<p>No payment reference found.</p>";
    exit;
}

$reference = $_GET['reference'];

// Verify payment with Paystack
$verifyUrl = "https://api.paystack.co/transaction/verify/{$reference}";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $verifyUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $paystackSecretKey]);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);

// HTML Output
echo "<!DOCTYPE html>";
echo "<html>";
echo "<head>";
echo "<title>GHartey Voting - Payment Status</title>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; text-align: center; padding: 40px; background: #f5f5f5; }";
echo ".container { max-width: 500px; margin: 0 auto; background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }";
echo ".success { color: #28a745; }";
echo ".error { color: #dc3545; }";
echo ".btn { display: inline-block; margin-top: 20px; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; }";
echo "</style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";

if ($result['status'] && $result['data']['status'] == 'success') {
    $metadata = $result['data']['metadata'];
    $nomineeCode = $metadata['nominee_code'] ?? '';
    $votes = intval($metadata['votes'] ?? 0);
    $type = $metadata['type'] ?? 'contestant';
    $amount = $result['data']['amount'] / 100;
    
    // Update votes in database
    if ($nomineeCode && $votes > 0) {
        if ($type == 'contestant') {
            $nominee = fetchNominee($contestantsFirestoreUrl, $nomineeCode, 'contestant');
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotes($contestantsFirestoreUrl, 'contestants', $nominee['id'], $newVotes);
            }
        } else {
            $nominee = fetchNominee($awardsFirestoreUrl, $nomineeCode, 'award');
            if ($nominee) {
                $newVotes = $nominee['votes'] + $votes;
                updateVotes($awardsFirestoreUrl, 'awards_nominees', $nominee['id'], $newVotes);
            }
        }
    }
    
    echo "<h1 class='success'>✓ PAYMENT SUCCESSFUL!</h1>";
    echo "<p>Amount paid: <strong>GHC " . number_format($amount, 2) . "</strong></p>";
    echo "<p>Nominee Code: <strong>{$nomineeCode}</strong></p>";
    echo "<p>Votes added: <strong>{$votes}</strong></p>";
    echo "<p>Thank you for voting!</p>";
    echo "<p>You can now close this page and continue with the USSD menu.</p>";
    
} else {
    echo "<h1 class='error'>✗ PAYMENT FAILED</h1>";
    $errorMsg = $result['data']['gateway_response'] ?? $result['message'] ?? 'Payment verification failed';
    echo "<p>Error: {$errorMsg}</p>";
    echo "<p>Please try again from the USSD menu.</p>";
}

echo "<a href='https://ussd-production-eb98.up.railway.app' class='btn'>Return to Voting</a>";
echo "</div>";
echo "</body>";
echo "</html>";
?>
