<?php
// webhook.php - Handle Paystack payment confirmations

require 'firebase.php';

$input = file_get_contents('php://input');
$event = json_decode($input, true);

if ($event['event'] == 'charge.success') {
    $reference = $event['data']['reference'];
    $email = $event['data']['customer']['email'];
    $amount = $event['data']['amount'];
    
    // Find pending transaction
    $users = firebaseRequest("GET", "user_sessions");
    if ($users) {
        foreach ($users as $msisdn => $session) {
            if ($session['payment_reference'] == $reference) {
                // Record vote
                $contestant = firebaseRequest("GET", "contestants/" . $session['selected_contestant']);
                
                if ($contestant) {
                    $voteData = [
                        "msisdn" => $msisdn,
                        "contestant_code" => $session['selected_contestant'],
                        "contestant_name" => $contestant['contestant_name'],
                        "timestamp" => time(),
                        "date" => date('Y-m-d H:i:s'),
                        "payment_reference" => $reference,
                        "amount" => $amount / 100
                    ];
                    
                    firebaseRequest("PUT", "votes/" . $msisdn, $voteData);
                    
                    $currentVotes = isset($contestant['votes']) ? $contestant['votes'] : 0;
                    firebaseRequest("PATCH", "contestants/" . $session['selected_contestant'], 
                        ["votes" => $currentVotes + 1]
                    );
                    
                    firebaseRequest("DELETE", "user_sessions/" . $msisdn);
                }
                break;
            }
        }
    }
}

http_response_code(200);
echo json_encode(["status" => "success"]);
?>
