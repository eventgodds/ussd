<?php

header("Content-Type: application/json");

$response = "Welcome to Ghartey Event\n";
$response .= "1. Vote";

echo json_encode([
    "response" => $response,
    "continueSession" => true
]);

?>