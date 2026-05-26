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

    $message = "Select Contestant\n";
    $message .= "1. Nana\n";
    $message .= "2. Ama";

    $continueSession = true;

} elseif ($newSession == false && $userData == "1*1") {

    $message = "You voted for Nana";
    $continueSession = false;

} elseif ($newSession == false && $userData == "1*2") {

    $message = "You voted for Ama";
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