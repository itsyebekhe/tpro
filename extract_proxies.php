<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json'; // Path to your JSON file with usernames
$outputTxtFile = 'extracted_proxy_links.txt'; // Path to save the extracted links
$telegramBaseUrl = 'https://t.me/s/'; // Base URL for channel archives

// --- Script Logic ---

echo "--- Telegram Proxy Link Extractor ---\n";

// 1. Read and decode the JSON file
if (!file_exists($inputJsonFile)) {
    die("Error: Input JSON file not found at '$inputJsonFile'\n");
}

$jsonContent = file_get_contents($inputJsonFile);

if ($jsonContent === false) {
    die("Error: Could not read input JSON file '$inputJsonFile'\n");
}

$usernames = json_decode($jsonContent, true);

if ($usernames === null) {
    die("Error: Could not decode JSON from '$inputJsonFile'. Check for syntax errors.\nError details: " . json_last_error_msg() . "\n");
}

if (!is_array($usernames)) {
    die("Error: JSON content in '$inputJsonFile' is not an array.\n");
}

echo "Successfully read " . count($usernames) . " usernames from '$inputJsonFile'.\n";

// Array to store unique extracted links
$allExtractedLinks = [];

// Regex pattern to find Telegram proxy links
// It looks for:
// - https?://t.me/proxy? OR tg://proxy?
// - followed by characters that are valid in URLs but *not* quotes or whitespace (to stop at the end of an attribute or simple text)
$proxyRegex = '/(?:https?:\/\/t\.me\/proxy\?|tg:\/\/proxy\?)[^"\'\s]+/i';

// 2. Loop through each username
foreach ($usernames as $username) {
    if (!is_string($username) || empty(trim($username))) {
        echo "Skipping invalid or empty username entry.\n";
        continue;
    }
    $username = trim($username); // Clean up whitespace

    $channelUrl = $telegramBaseUrl . urlencode($username); // Build the URL
    echo "Processing username: '$username' (URL: $channelUrl)\n";

    // 3. Fetch content from the URL using cURL for better control
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $channelUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set a timeout in seconds
    // Optional: Set a user agent to mimic a browser, might help avoid blocking
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

    $htmlContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($htmlContent === false) {
        echo "Warning: Failed to fetch content for '$username'. cURL error: " . $curlError . "\n";
        continue; // Skip to the next username
    }

    if ($httpCode != 200) {
         echo "Warning: Received HTTP status code $httpCode for '$username'. Skipping.\n";
         // You might want to check for 404 specifically if needed
         continue; // Skip to the next username
    }

    // 4. Search the content for proxy links
    $matches = [];
    if (preg_match_all($proxyRegex, $htmlContent, $matches)) {
        echo "Found " . count($matches[0]) . " potential proxy links.\n";
        // Add found links to the main storage array
        $allExtractedLinks = array_merge($allExtractedLinks, $matches[0]);
    } else {
        echo "No proxy links found in the content for '$username'.\n";
    }

    // Optional: Add a small delay between requests to be polite
    // sleep(1); // Sleep for 1 second

} // End of username loop

// 5. Make the list of links unique
$uniqueExtractedLinks = array_unique($allExtractedLinks);
echo "\nFinished processing all usernames.\n";
echo "Total potential links found (including duplicates): " . count($allExtractedLinks) . "\n";
echo "Unique extracted links: " . count($uniqueExtractedLinks) . "\n";

// 6. Store unique links in the output file
if (count($uniqueExtractedLinks) > 0) {
    $outputContent = implode("\n", $uniqueExtractedLinks);

    if (file_put_contents($outputTxtFile, $outputContent) === false) {
        echo "Error: Could not write extracted links to '$outputTxtFile'\n";
    } else {
        echo "Successfully wrote " . count($uniqueExtractedLinks) . " unique links to '$outputTxtFile'\n";
    }
} else {
    echo "No unique proxy links were found to save.\n";
    // Optional: remove the output file if it exists and is empty
    // if (file_exists($outputTxtFile)) {
    //     unlink($outputTxtFile);
    // }
}

echo "--- Script Finished ---\n";

?>
