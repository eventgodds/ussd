<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| FIRESTORE CONFIG
|--------------------------------------------------------------------------
*/

$projectId = "eventgodds-41e4f";

/*
|--------------------------------------------------------------------------
| FIRESTORE FUNCTION
|--------------------------------------------------------------------------
*/

function firestoreRequest($method, $url, $data = null)
{
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {

        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($data)
        );
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    return json_decode($response, true);
}

/*
|--------------------------------------------------------------------------
| GET REQUEST DATA
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');

$data = json_decode($json, true);

$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$msisdn     = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData   = trim($data['userData'] ?? '');

$message = "";
$continueSession = true;

$input = explode('*', $userData);

/*
|--------------------------------------------------------------------------
| MAIN MENU
|--------------------------------------------------------------------------
*/

if ($newSession == true) {

    $message  = "Welcome to Ghartey Event\n";
    $message .= "1. Vote";
}

/*
|--------------------------------------------------------------------------
| STEP 1
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "1") {

    $message = "Enter contestant code";
}

/*
|--------------------------------------------------------------------------
| STEP 2 - FIND CONTESTANT BY CODE
|--------------------------------------------------------------------------
*/

elseif (count($input) == 2 && $input[0] == "1") {

    $contestantCode = strtoupper(trim($input[1]));

    /*
    |--------------------------------------------------------------------------
    | FIRESTORE QUERY
    |--------------------------------------------------------------------------
    */

    $url = "https://firestore.googleapis.com/v1/projects/"
        . $projectId
        . "/databases/(default)/documents:runQuery";

    $query = [
        "structuredQuery" => [
            "from" => [
                [
                    "collectionId" => "contestants"
                ]
            ],
            "where" => [
                "fieldFilter" => [
                    "field" => [
                        "fieldPath" => "code"
                    ],
                    "op" => "EQUAL",
                    "value" => [
                        "stringValue" => $contestantCode
                    ]
                ]
            ],
            "limit" => 1
        ]
    ];

    $result = firestoreRequest(
        "POST",
        $url,
        $query
    );

    /*
    |--------------------------------------------------------------------------
    | CHECK RESULT
    |--------------------------------------------------------------------------
    */

    if (
        isset($result[0]['document'])
    ) {

        $doc = $result[0]['document'];

        $fields = $doc['fields'];

        $contestantName =
            $fields['name']['stringValue'] ?? '';

        $engagement =
            $fields['engagement']['integerValue']
            ?? 0;

        $message  = "Vote For:\n";
        $message .= $contestantName . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel";

    } else {

        $message = "Contestant not found";

        $continueSession = false;
    }
}

/*
|--------------------------------------------------------------------------
| STEP 3 - CONFIRM VOTE
|--------------------------------------------------------------------------
*/

elseif (
    count($input) == 3 &&
    $input[0] == "1" &&
    $input[2] == "1"
) {

    $contestantCode = strtoupper(trim($input[1]));

    /*
    |--------------------------------------------------------------------------
    | FIND DOCUMENT
    |--------------------------------------------------------------------------
    */

    $url = "https://firestore.googleapis.com/v1/projects/"
        . $projectId
        . "/databases/(default)/documents:runQuery";

    $query = [
        "structuredQuery" => [
            "from" => [
                [
                    "collectionId" => "contestants"
                ]
            ],
            "where" => [
                "fieldFilter" => [
                    "field" => [
                        "fieldPath" => "code"
                    ],
                    "op" => "EQUAL",
                    "value" => [
                        "stringValue" => $contestantCode
                    ]
                ]
            ],
            "limit" => 1
        ]
    ];

    $result = firestoreRequest(
        "POST",
        $url,
        $query
    );

    /*
    |--------------------------------------------------------------------------
    | UPDATE VOTE
    |--------------------------------------------------------------------------
    */

    if (
        isset($result[0]['document'])
    ) {

        $doc = $result[0]['document'];

        $documentName = $doc['name'];

        $fields = $doc['fields'];

        $contestantName =
            $fields['name']['stringValue'] ?? '';

        $engagement =
            intval(
                $fields['engagement']['integerValue']
                ?? 0
            );

        $newVotes = $engagement + 1;

        /*
        |--------------------------------------------------------------------------
        | PATCH UPDATE
        |--------------------------------------------------------------------------
        */

        $updateData = [
            "fields" => [
                "engagement" => [
                    "integerValue" => $newVotes
                ]
            ]
        ];

        firestoreRequest(
            "PATCH",
            "https://firestore.googleapis.com/v1/"
            . $documentName
            . "?updateMask.fieldPaths=engagement",
            $updateData
        );

        $message  = "Vote Successful!\n";
        $message .= $contestantName . "\n";
        $message .= "Total Votes: "
            . $newVotes;

    } else {

        $message = "Vote failed";
    }

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| CANCEL VOTE
|--------------------------------------------------------------------------
*/

elseif (
    count($input) == 3 &&
    $input[2] == "2"
) {

    $message = "Vote Cancelled";

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT
|--------------------------------------------------------------------------
*/

else {

    $message = "Invalid input";

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID"       => $sessionID,
    "userID"          => $userID,
    "msisdn"          => $msisdn,
    "message"         => $message,
    "continueSession" => $continueSession
];

header('Content-Type: application/json');

echo json_encode($response);

?><?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| FIRESTORE CONFIG
|--------------------------------------------------------------------------
*/

$projectId = "eventgodds-41e4f";

/*
|--------------------------------------------------------------------------
| FIRESTORE FUNCTION
|--------------------------------------------------------------------------
*/

function firestoreRequest($method, $url, $data = null)
{
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {

        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($data)
        );
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    return json_decode($response, true);
}

/*
|--------------------------------------------------------------------------
| GET REQUEST DATA
|--------------------------------------------------------------------------
*/

$json = file_get_contents('php://input');

$data = json_decode($json, true);

$sessionID  = $data['sessionID'] ?? '';
$userID     = $data['userID'] ?? '';
$msisdn     = $data['msisdn'] ?? '';
$newSession = $data['newSession'] ?? false;
$userData   = trim($data['userData'] ?? '');

$message = "";
$continueSession = true;

$input = explode('*', $userData);

/*
|--------------------------------------------------------------------------
| MAIN MENU
|--------------------------------------------------------------------------
*/

if ($newSession == true) {

    $message  = "Welcome to Ghartey Event\n";
    $message .= "1. Vote";
}

/*
|--------------------------------------------------------------------------
| STEP 1
|--------------------------------------------------------------------------
*/

elseif (count($input) == 1 && $input[0] == "1") {

    $message = "Enter contestant code";
}

/*
|--------------------------------------------------------------------------
| STEP 2 - FIND CONTESTANT BY CODE
|--------------------------------------------------------------------------
*/

elseif (count($input) == 2 && $input[0] == "1") {

    $contestantCode = strtoupper(trim($input[1]));

    /*
    |--------------------------------------------------------------------------
    | FIRESTORE QUERY
    |--------------------------------------------------------------------------
    */

    $url = "https://firestore.googleapis.com/v1/projects/"
        . $projectId
        . "/databases/(default)/documents:runQuery";

    $query = [
        "structuredQuery" => [
            "from" => [
                [
                    "collectionId" => "contestants"
                ]
            ],
            "where" => [
                "fieldFilter" => [
                    "field" => [
                        "fieldPath" => "code"
                    ],
                    "op" => "EQUAL",
                    "value" => [
                        "stringValue" => $contestantCode
                    ]
                ]
            ],
            "limit" => 1
        ]
    ];

    $result = firestoreRequest(
        "POST",
        $url,
        $query
    );

    /*
    |--------------------------------------------------------------------------
    | CHECK RESULT
    |--------------------------------------------------------------------------
    */

    if (
        isset($result[0]['document'])
    ) {

        $doc = $result[0]['document'];

        $fields = $doc['fields'];

        $contestantName =
            $fields['name']['stringValue'] ?? '';

        $engagement =
            $fields['engagement']['integerValue']
            ?? 0;

        $message  = "Vote For:\n";
        $message .= $contestantName . "\n";
        $message .= "Code: " . $contestantCode . "\n";
        $message .= "1. Confirm Vote\n";
        $message .= "2. Cancel";

    } else {

        $message = "Contestant not found";

        $continueSession = false;
    }
}

/*
|--------------------------------------------------------------------------
| STEP 3 - CONFIRM VOTE
|--------------------------------------------------------------------------
*/

elseif (
    count($input) == 3 &&
    $input[0] == "1" &&
    $input[2] == "1"
) {

    $contestantCode = strtoupper(trim($input[1]));

    /*
    |--------------------------------------------------------------------------
    | FIND DOCUMENT
    |--------------------------------------------------------------------------
    */

    $url = "https://firestore.googleapis.com/v1/projects/"
        . $projectId
        . "/databases/(default)/documents:runQuery";

    $query = [
        "structuredQuery" => [
            "from" => [
                [
                    "collectionId" => "contestants"
                ]
            ],
            "where" => [
                "fieldFilter" => [
                    "field" => [
                        "fieldPath" => "code"
                    ],
                    "op" => "EQUAL",
                    "value" => [
                        "stringValue" => $contestantCode
                    ]
                ]
            ],
            "limit" => 1
        ]
    ];

    $result = firestoreRequest(
        "POST",
        $url,
        $query
    );

    /*
    |--------------------------------------------------------------------------
    | UPDATE VOTE
    |--------------------------------------------------------------------------
    */

    if (
        isset($result[0]['document'])
    ) {

        $doc = $result[0]['document'];

        $documentName = $doc['name'];

        $fields = $doc['fields'];

        $contestantName =
            $fields['name']['stringValue'] ?? '';

        $engagement =
            intval(
                $fields['engagement']['integerValue']
                ?? 0
            );

        $newVotes = $engagement + 1;

        /*
        |--------------------------------------------------------------------------
        | PATCH UPDATE
        |--------------------------------------------------------------------------
        */

        $updateData = [
            "fields" => [
                "engagement" => [
                    "integerValue" => $newVotes
                ]
            ]
        ];

        firestoreRequest(
            "PATCH",
            "https://firestore.googleapis.com/v1/"
            . $documentName
            . "?updateMask.fieldPaths=engagement",
            $updateData
        );

        $message  = "Vote Successful!\n";
        $message .= $contestantName . "\n";
        $message .= "Total Votes: "
            . $newVotes;

    } else {

        $message = "Vote failed";
    }

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| CANCEL VOTE
|--------------------------------------------------------------------------
*/

elseif (
    count($input) == 3 &&
    $input[2] == "2"
) {

    $message = "Vote Cancelled";

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| INVALID INPUT
|--------------------------------------------------------------------------
*/

else {

    $message = "Invalid input";

    $continueSession = false;
}

/*
|--------------------------------------------------------------------------
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/

$response = [
    "sessionID"       => $sessionID,
    "userID"          => $userID,
    "msisdn"          => $msisdn,
    "message"         => $message,
    "continueSession" => $continueSession
];

header('Content-Type: application/json');

echo json_encode($response);

?>
