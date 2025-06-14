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
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP Proxy Extractor/1.1; +https://github.com/your-username/your-repo)'); // Identify your script

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
                $queryString = $parsedUrl['query'];

                // Decode HTML entities like & into & before parsing
                $decodedQueryString = html_entity_decode($queryString);

                $query = [];
                // Use the decoded query string
                parse_str($decodedQueryString, $query);

                // Check if server, port, and secret are present
                if (isset($query['server']) && isset($query['port']) && isset($query['secret'])) {
                    $server = $query['server'];
                    $port = $query['port'];
                    $secret = $query['secret'];

                    // Construct the canonical tg:// URL for uniqueness and connection
                    // Ensure the secret is correctly formatted (might contain base64 chars)
                    $tgUrl = "tg://proxy?server={$server}&port={$port}&secret={$secret}";

                    // Store if unique
                    if (!isset($uniqueProxies[$tgUrl])) {
                         $uniqueProxies[$tgUrl] = [
                             'tg_url' => $tgUrl,
                             'server' => $server,
                             'port' => $port,
                             'secret' => $secret,
                             // Optionally store source username
                             // 'source' => $username
                         ];
                         echo " - Extracted: $tgUrl\n";
                    }
                } else {
                     // This warning is less frequent after html_entity_decode,
                     // but could still happen for malformed links
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

// 6. Prepare data for output
$extractedProxyList = array_values($uniqueProxies); // Convert associative array back to indexed array for JSON

echo "\nFinished processing all usernames.\n";
echo "Total unique extracted proxies: " . count($extractedProxyList) . "\n";

// 7. Generate and store JSON output
// Always create the file, even if the list is empty []
$jsonOutputContent = json_encode($extractedProxyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (file_put_contents($outputJsonFile, $jsonOutputContent) === false) {
    echo "Error: Could not write extracted links to '$outputJsonFile'\n";
    // For robustness in CI, you might want to exit if crucial files can't be written
    // exit(1);
} else {
    echo "Successfully wrote " . count($extractedProxyList) . " unique proxies to '$outputJsonFile'\n";
}

// 8. Generate and store HTML output
$htmlOutputContent = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram Proxy List</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: \'Roboto\', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            text-align: center;
            color: #007bff;
            margin-bottom: 20px;
        }
        .proxy-list {
            list-style: none;
            padding: 0;
            margin-top: 20px;
        }
        .proxy-item {
            display: flex;
            flex-direction: column; /* Stack elements on small screens */
            align-items: flex-start; /* Align to the start */
            background-color: #e9f7ef; /* Light green background */
            border: 1px solid #d0e9e1;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
            word-break: break-all; /* Break long URLs */
        }
        .proxy-item .proxy-link {
             flex-grow: 1; /* Allow the link part to take available space */
             margin-bottom: 10px; /* Space between link and buttons on stacked layout */
             font-family: \'Courier New\', Courier, monospace; /* Monospace for technical look */
             font-size: 0.9em;
             color: #0056b3;
             display: block; /* Make the span a block to apply margin-bottom */
        }
        .proxy-item .proxy-actions {
            display: flex;
            gap: 10px; /* Space between buttons */
            flex-shrink: 0; /* Prevent actions from shrinking */
            width: 100%; /* Full width on stacked layout */
        }
        .proxy-item a,
        .proxy-item button {
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9em;
            transition: background-color 0.2s ease;
            flex-grow: 1; /* Grow to fill available space in actions row */
            text-align: center; /* Center text in buttons */
        }
        .proxy-item a.connect-button {
            background-color: #28a745; /* Green */
            color: white;
            border: 1px solid #218838;
        }
        .proxy-item a.connect-button:hover {
            background-color: #218838;
        }
         .proxy-item button.copy-button {
            background-color: #007bff; /* Blue */
            color: white;
            border: 1px solid #0069d9;
         }
         .proxy-item button.copy-button:hover {
            background-color: #0069d9;
         }
         .proxy-item button.copy-button:active {
             background-color: #0056b3;
         }

        .no-proxies {
            text-align: center;
            font-style: italic;
            color: #555;
        }

        .instructions {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .instructions h2 {
            color: #007bff;
            margin-bottom: 15px;
        }
        .instructions p, .instructions ul {
            margin-bottom: 15px;
        }
        .instructions ul {
            padding-left: 20px;
        }
        .instructions li {
            margin-bottom: 8px;
            padding: 0; /* Override proxy-item padding */
            border: none; /* Override proxy-item border */
        }
        .instructions code {
            background-color: #eee;
            padding: 2px 4px;
            border-radius: 4px;
            font-family: \'Courier New\', Courier, monospace;
            font-size: 0.9em;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 0.9em;
            color: #777;
        }

        /* Responsive adjustments */
        @media (min-width: 600px) {
            .proxy-item {
                flex-direction: row; /* Arrange elements in a row on larger screens */
                align-items: center; /* Vertically align in the middle */
            }
            .proxy-item .proxy-link {
                 margin-bottom: 0; /* Remove bottom margin when in a row */
                 margin-right: 15px; /* Add space between link and buttons */
            }
            .proxy-item .proxy-actions {
                 width: auto; /* Auto width when in a row */
                 flex-grow: 0; /* Prevent actions from growing excessively */
            }
             .proxy-item a,
            .proxy-item button {
                flex-grow: 0; /* Don\'t grow in the row layout */
                width: auto; /* Auto width based on content/padding */
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Telegram Proxy List</h1>

        <p>Here are automatically extracted Telegram MTProto proxies. Click "Connect" to add directly via Telegram link, or "Copy" to copy the link to your clipboard.</p>

        <ul class="proxy-list">
';

if (count($extractedProxyList) > 0) {
    foreach ($extractedProxyList as $proxy) {
        // Use the tg_url for the clickable link and data attribute
        $tgUrl = htmlspecialchars($proxy['tg_url']); // Sanitize URL for HTML output
        $htmlOutputContent .= '
            <li>
                <div class="proxy-item">
                    <span class="proxy-link">' . $tgUrl . '</span>
                    <div class="proxy-actions">
                        <a href="' . $tgUrl . '" class="connect-button" target="_blank">Connect</a>
                        <button class="copy-button" data-url="' . $tgUrl . '">Copy</button>
                    </div>
                </div>
            </li>' . "\n";
    }
} else {
    $htmlOutputContent .= '
            <li>
                <p class="no-proxies">No active proxies found in the latest scan.</p>
            </li>' . "\n";
}

$htmlOutputContent .= '
        </ul>

        <div class="instructions">
            <h2>How to Connect</h2>
            <p>Telegram MTProto proxies help bypass censorship and restrictions.</ Here\'s how to use the links:</p>
            <h3>Method 1: Direct Link (Recommended)</h3>
            <ol>
                <li>Ensure you have the official Telegram app installed on your device (mobile or desktop).</li>
                <li>Click the "<span style="color:white; background-color:#28a745; padding: 2px 5px; border-radius: 3px;">Connect</span>" button next to a proxy link.</li>
                <li>If Telegram is installed and configured correctly, it should open and prompt you to add the proxy.</li>
                <li>Confirm adding the proxy within the Telegram app.</li>
            </ol>
             <h3>Method 2: Copy and Paste Manually</h3>
             <p>If clicking the link doesn\'t work (e.g., some desktop browsers, or if you prefer manual setup):</p>
             <ol>
                <li>Click the "<span style="color:white; background-color:#007bff; padding: 2px 5px; border-radius: 3px;">Copy</span>" button next to the proxy link. The link will be copied to your clipboard.</li>
                <li>Open the Telegram app.</li>
                <li>Go to Settings (⚙️).</li>
                <li>Navigate to Data and Storage.</li>
                <li>Scroll down to Proxy Settings.</li>
                <li>Tap "Add Proxy".</li>
                <li>Select "MTProto".</li>
                <li>You should see an option to "Use tg:// link" or similar (this might vary slightly by app version/OS). Paste the copied link there. Alternatively, you can manually enter the server, port, and secret if the app allows.</li>
                <li>Save the proxy.</li>
             </ol>
             <p><strong>Note:</strong> Proxy availability can change. If a proxy doesn\'t work, try another from the list. This list is updated automatically.</p>
        </div>

        <div class="footer">
            <p>Last updated: ' . date('Y-m-d H:i:s') . ' UTC</p>
            <p>Generated automatically by <a href="https://github.com/stefanzweifel/git-auto-commit-action" target="_blank">git-auto-commit-action</a> workflow.</p>
        </div>
    </div>

    <script>
        document.addEventListener(\'DOMContentLoaded\', function() {
            const copyButtons = document.querySelectorAll(\'.copy-button\');

            copyButtons.forEach(button => {
                button.addEventListener(\'click\', function() {
                    const urlToCopy = this.getAttribute(\'data-url\');

                    // Use the modern Clipboard API
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(urlToCopy).then(() => {
                            // Success feedback
                            const originalText = this.textContent;
                            this.textContent = \'Copied!\';
                            this.style.backgroundColor = \'#28a745\'; // Green color for success feedback
                             this.style.borderColor = \'#218838\';
                             // Revert text and color after a few seconds
                            setTimeout(() => {
                                this.textContent = originalText;
                                this.style.backgroundColor = \'\'; // Revert to original blue
                                this.style.borderColor = \'\';
                            }, 2000); // Revert after 2 seconds
                        }).catch(err => {
                            console.error(\'Failed to copy text: \', err);
                            alert(\'Error: Could not copy the link.\');
                        });
                    } else {
                        // Fallback for older browsers (less common now)
                        // This requires a textarea element and is more complex.
                        // For simplicity, we\'ll just show an alert or log a message.
                        console.warn(\'Clipboard API not available.\');
                        alert(\'Please manually select and copy the link: \' + urlToCopy);
                    }
                });
            });
        });
    </script>
</body>
</html>
';

if (file_put_contents($outputHtmlFile, $htmlOutputContent) === false) {
    echo "Error: Could not write HTML output to '$outputHtmlFile'\n";
    // exit(1); // Consider exiting if file writing fails
} else {
    echo "Successfully wrote HTML output to '$outputHtmlFile'\n";
}

echo "--- Script Finished ---\n";

?>
