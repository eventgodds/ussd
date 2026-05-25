<?php

function firebaseRequest($method, $collection, $docId, $data = null)
{
    $baseURL = "https://firestore.googleapis.com/v1/projects/eventgodds/databases/(default)/documents";
    $url = $baseURL . "/" . $collection . "/" . $docId;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Hardcoded nominees data
$nominees = [
    "FS1" => ["fullName" => "EGYIRWAA", "voteAmount" => 1],
    "FS2" => ["fullName" => "AGYEKUMWAA", "voteAmount" => 1],
    "FS3" => ["fullName" => "BOATEMAA", "voteAmount" => 1],
    "FS4" => ["fullName" => "ABENA", "voteAmount" => 1],
    "FS5" => ["fullName" => "SEDEM", "voteAmount" => 1]
];

echo "Initializing Database with Hardcoded Nominees...\n\n";

foreach ($nominees as $code => $data) {
    $nomineeData = [
        "fields" => [
            "fullName" => ["stringValue" => $data['fullName']],
            "votes" => ["integerValue" => 0],
            "voteAmount" => ["integerValue" => $data['voteAmount']]
        ]
    ];
    
    $result = firebaseRequest("PATCH", "awards_nominees", $code, $nomineeData);
    
    if (isset($result['name'])) {
        echo "✓ Successfully added/updated: " . $code . " - " . $data['fullName'] . "\n";
    } else {
        echo "✗ Error adding: " . $code . "\n";
        print_r($result);
    }
}

echo "\nDatabase initialization complete!\n";
echo "\nNominees available:\n";
echo "FS1 - EGYIRWAA (GHC 1 per vote)\n";
echo "FS2 - AGYEKUMWAA (GHC 1 per vote)\n";
echo "FS3 - BOATEMAA (GHC 1 per vote)\n";
echo "FS4 - ABENA (GHC 1 per vote)\n";
echo "FS5 - SEDEM (GHC 1 per vote)\n";
?>
