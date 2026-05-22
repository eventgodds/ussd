<?php
require 'firebase.php';

echo "<h2>Testing Firebase Firestore Connection</h2>";

// Test 1: Connection
echo "<h3>1. Testing Connection...</h3>";
$connected = testFirestoreConnection();
echo $connected ? "✅ Connected successfully<br>" : "❌ Connection failed<br>";

// Test 2: Get all contestants
echo "<h3>2. Fetching All Contestants...</h3>";
$contestants = getAllContestants();

if ($contestants) {
    echo "✅ Found " . count($contestants) . " contestants<br>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Stage Name</th><th>Code</th><th>Votes</th><th>Vote Amount</th><th>Bio</th></tr>";
    foreach ($contestants as $c) {
        echo "<tr>";
        echo "<td>" . ($c['stageName'] ?? $c['name'] ?? 'N/A') . "</td>";
        echo "<td>" . ($c['code'] ?? 'N/A') . "</td>";
        echo "<td>" . ($c['votes'] ?? 0) . "</td>";
        echo "<td>" . ($c['voteAmount'] ?? 1) . "</td>";
        echo "<td>" . substr($c['bio'] ?? '', 0, 50) . "...</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ No contestants found<br>";
}

// Test 3: Find by code
echo "<h3>3. Testing Find by Code (FS1)...</h3>";
$contestant = getContestantByCode("FS1");
if ($contestant) {
    echo "✅ Found: " . ($contestant['stageName'] ?? $contestant['name']) . "<br>";
    echo "<pre>";
    print_r($contestant);
    echo "</pre>";
} else {
    echo "❌ Contestant not found<br>";
}

// Test 4: Update votes
echo "<h3>4. Testing Vote Update (FS1)...</h3>";
$currentVotes = $contestant['votes'] ?? 0;
$newVotes = $currentVotes + 1;
$updated = updateContestantVotes("FS1", $newVotes);
echo $updated ? "✅ Votes updated from $currentVotes to $newVotes<br>" : "❌ Update failed<br>";
?>
