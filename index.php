<?php

header('Content-Type: application/json');

$response = [
    "sessionID" => "123",
    "userID" => "123",
    "msisdn" => "233000000000",
    "message" => "USSD Working",
    "continueSession" => false
];

echo json_encode($response);

?>