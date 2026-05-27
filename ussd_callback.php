<?php
// ussd_callback.php - This handles the redirect after payment
$reference = $_GET['reference'] ?? '';

if ($reference) {
    // Redirect back to USSD or show success page
    echo "<h2>Payment Successful!</h2>";
    echo "<p>Your votes have been recorded. Thank you for voting!</p>";
    echo "<p>Reference: $reference</p>";
    echo "<p>You can now close this page and continue with the USSD menu.</p>";
} else {
    echo "<h2>Payment Error</h2>";
    echo "<p>Something went wrong. Please try again.</p>";
}
?>
