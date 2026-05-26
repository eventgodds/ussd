<?php

header('Content-Type: application/json');

// Read request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get values
$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$newSession = $data['newSession'] ?? false;
$msisdn     = $data['msisdn'] ?? '';
$userData   = trim($data['userData'] ?? '');

// Default values
$message = "";
$continueSession = false;

// MAIN MENU
if ($newSession == true) {

    $message = "Welcome to Ghartey Event\n";
    $message .= "1. Vote";

    $continueSession = true;
}

// CONTESTANT MENU
elseif ($userData == "1") {

    $message = "Select Contestant\n";
    $message .= "1. Nana\n";
    $message .= "2. Ama\n";
    $message .= "3. Kojo";

    $continueSession = true;
}

// VOTE NANA
elseif ($userData == "1*1") {

    $message = "Vote successful for Nana";
    $continueSession = false;
}

// VOTE AMA
elseif ($userData == "1*2") {

    $message = "Vote successful for Ama";
    $continueSession = false;
}

// VOTE KOJO
elseif ($userData == "1*3") {

    $message = "Vote successful for Kojo";
    $continueSession = false;
}

// INVALID INPUT
else {

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