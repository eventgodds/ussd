<?php

define('PAYSTACK_SECRET_KEY', 'sk_live_b8d6b1eba856a6da4d891482e1324c55a05c69cc');

// Verify webhook signature
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');

if ($signature && hash_hmac('sha512', $payload, PAYSTACK_SECRET_KEY) === $signature) {
    $event = json_decode($payload, true);
    
    if ($event['event'] == 'charge.success') {
        $transactionRef = $event['data']['reference'];
        $metadata = $event['data']['metadata'];
        
        // Update Firestore with successful payment
        function firebaseRequest($method, $collection, $docId, $data = null) {
            $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";
            $url = $baseURL . "/" . $collection . "/" . $docId;
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            return json_decode($response, true);
        }
        
        // Update transaction status
        $updateData = [
            "fields" => [
                "webhook_status" => ["stringValue" => "confirmed"],
                "webhook_time" => ["stringValue" => date('Y-m-d H:i:s')]
            ]
        ];
        firebaseRequest("PATCH", "transactions", $transactionRef, $updateData);
        
        // Log webhook receipt
        file_put_contents('paystack_webhook.log', date('Y-m-d H:i:s') . " - " . $transactionRef . " - Confirmed\n", FILE_APPEND);
    }
}

http_response_code(200);
?>
