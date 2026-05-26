<?php

header('Content-Type: application/json');

// Read JSON request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get values safely
$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn     = $data['msisdn'] ?? '';
$userData   = trim($data['userData'] ?? '');

// Menu Logic
if ($newSession == true) {

    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote";

    $continueSession = true;

} elseif ($newSession == false && $userData == "1") {

    $message = "Voting starts soon";
    $continueSession = false;

} else {

    $message = "Invalid option";
    $continueSession = false;
}

// Response
echo json_encode([
    "sessionID" => $sessionID,
    "userID" => $userID,
    "msisdn" => $msisdn,
    "message" => $message,
    "continueSession" => $continueSession
]);
?>