<?php

header("Content-Type: application/json");

echo json_encode([
    "response" => "Welcome to Ghartey Event Voting",
    "continueSession" => false
]);

?>