<?php

header('Content-Type: application/json');

$response = [
    "sessionID" => "1",
    "userID" => "1",
    "msisdn" => "233000000000",
    "message" => "Welcome to Ghartey Event Voting",
    "continueSession" => false
];

echo json_encode($response);

?>