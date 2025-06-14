<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json'; // Path to your JSON file with usernames
$outputHtmlFile = 'index.html';     // Path to save the HTML output
$outputJsonFile = 'extracted_proxies.json'; // Path to save the JSON output
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

// Use an associative array to store unique proxies, using the tg:// URL as the key
$uniqueProxies = [];

// Regex pattern to find full Telegram proxy links (tg:// or https://t.me/proxy?)
// This pattern aims to capture the full URL including parameters
// It stops at whitespace or quotes, which usually works within HTML attributes
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

    // 3. Fetch content from the URL using cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $channelUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set a timeout in seconds
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP Proxy Extractor/1.0; +https://github.com/your-username/your-repo)'); // Identify your script

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
         continue; // Skip to the next username
    }

    // 4. Search the content for proxy links
    $matches = [];
    if (preg_match_all($proxyRegex, $htmlContent, $matches)) {
        echo "Found " . count($matches[0]) . " potential proxy URLs.\n";

        // 5. Process found URLs
        foreach ($matches[0] as $foundUrl) {
            // Parse the URL to extract components
            $parsedUrl = parse_url($foundUrl);

            if ($parsedUrl && isset($parsedUrl['query'])) {
                $query = [];
                parse_str($parsedUrl['query'], $query);

                // Check if server, port, and secret are present
                if (isset($query['server']) && isset($query['port']) && isset($query['secret'])) {
                    $server = $query['server'];
                    $port = $query['port'];
                    $secret = $query['secret'];

                    // Construct the canonical tg:// URL for uniqueness and connection
                    $tgUrl = "tg://proxy?server={$server}&port={$port}&secret={$secret}";

                    // Store if unique
                    if (!isset($uniqueProxies[$tgUrl])) {
                         $uniqueProxies[$tgUrl] = [
                             'tg_url' => $tgUrl,
                             'server' => $server,
                             'port' => $port,
                             'secret' => $secret,
                             // You could potentially store the original foundUrl as well if needed
                             // 'original_url' => $foundUrl
                         ];
                         echo " - Extracted: $tgUrl\n";
                    }
                } else {
                     echo " - Warning: Found URL looks like a proxy but missing required params: $foundUrl\n";
                }
            } else {
                 echo " - Warning: Could not parse query from potential URL: $foundUrl\n";
            }
        }

    } else {
        echo "No proxy URLs found in the content for '$username'.\n";
    }

    // Optional: Add a small delay between requests to be polite
    // sleep(1); // Sleep for 1 second

} // End of username loop

// 6. Prepare data for output (already done)
$extractedProxyList = array_values($uniqueProxies); // Convert associative array back to indexed array for JSON

echo "\nFinished processing all usernames.\n";
echo "Total unique extracted proxies: " . count($extractedProxyList) . "\n";

// 7. Generate and store JSON output
// *** CHANGE START ***
// Always create the file, even if the list is empty []
$jsonOutputContent = json_encode($extractedProxyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (file_put_contents($outputJsonFile, $jsonOutputContent) === false) {
    echo "Error: Could not write extracted links to '$outputJsonFile'\n";
    // Decide if this should cause the workflow to fail.
    // For robustness, you might want to exit here: exit(1);
} else {
    echo "Successfully wrote " . count($extractedProxyList) . " unique proxies to '$outputJsonFile'\n";
}
// *** CHANGE END ***


// 8. Generate and store HTML output (This part is already fine)
$htmlOutputContent = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram Proxy List</title>
    <style>
        body { font-family: sans-serif; margin: 20px; line-height: 1.6; }
        h1 { text-align: center; }
        ul { list-style: none; padding: 0; }
        li { margin-bottom: 10px; border: 1px solid #eee; padding: 10px; border-radius: 5px; }
        a { text-decoration: none; color: #007bff; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>Extracted Telegram Proxies</h1>
    <p>Click on a link below to connect directly to the proxy in Telegram.</p>
    <ul>
';

if (count($extractedProxyList) > 0) {
    foreach ($extractedProxyList as $proxy) {
        $htmlOutputContent .= '<li><a href="' . htmlspecialchars($proxy['tg_url']) . '">' . htmlspecialchars($proxy['tg_url']) . '</a></li>' . "\n";
    }
} else {
    $htmlOutputContent .= '<li>No proxies found.</li>' . "\n";
}

$htmlOutputContent .= '
    </ul>
    <p>Last updated: ' . date('Y-m-d H:i:s') . ' UTC</p>
</body>
</html>
';

if (file_put_contents($outputHtmlFile, $htmlOutputContent) === false) {
    echo "Error: Could not write HTML output to '$outputHtmlFile'\n";
    // Decide if this should cause the workflow to fail.
    // For robustness, you might want to exit here: exit(1);
} else {
    echo "Successfully wrote HTML output to '$outputHtmlFile'\n";
}


echo "--- Script Finished ---\n";

?>
