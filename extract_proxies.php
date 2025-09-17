<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json';
$outputHtmlFile = 'index.html';
$outputJsonFile = 'extracted_proxies.json'; // This will now be a raw list
$telegramBaseUrl = 'https://t.me/s/';

// --- NEW: User Agents remain for robust fetching ---
$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
];

// --- Script Logic ---
ob_start();
date_default_timezone_set('Asia/Tehran');
echo "--- Telegram Proxy Extractor v5.0 (Client-Side Check Edition) ---\n";

// --- Phase 1: Read Input ---
if (!file_exists($inputJsonFile)) die("Error: Input JSON file not found at '$inputJsonFile'\n");
$jsonContent = file_get_contents($inputJsonFile);
$usernames = json_decode($jsonContent, true);
if ($usernames === null) die("Error: Could not decode JSON. Details: " . json_last_error_msg() . "\n");

echo "Read " . count($usernames) . " usernames. Starting parallel fetch...\n";

// --- Phase 2: Parallel Fetching of Channel Content ---
$multiHandle = curl_multi_init();
$urlHandles = [];
foreach ($usernames as $username) {
    if (!is_string($username) || empty(trim($username))) continue;
    $channelUrl = $telegramBaseUrl . urlencode(trim($username));

    $ch = curl_init($channelUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)]
    ]);
    curl_multi_add_handle($multiHandle, $ch);
    $urlHandles[$channelUrl] = $ch;
}

$running = null;
do {
    curl_multi_exec($multiHandle, $running);
    curl_multi_select($multiHandle);
} while ($running > 0);

// --- Phase 3: Process Results, Extract, and De-duplicate Proxies ---
$uniqueProxies = [];
$proxyRegex = '/(?:https?:\/\/t\.me\/proxy\?|tg:\/\/proxy\?)[^"\'\s]+/i';

foreach ($urlHandles as $url => $ch) {
    $htmlContent = curl_multi_getcontent($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode == 200 && $htmlContent) {
        if (preg_match_all($proxyRegex, $htmlContent, $matches)) {
            foreach ($matches[0] as $foundUrl) {
                $parsedUrl = parse_url($foundUrl);
                if (!$parsedUrl || !isset($parsedUrl['query'])) continue;

                parse_str(html_entity_decode($parsedUrl['query']), $query);

                if (isset($query['server'], $query['port'], $query['secret'])) {
                    $proxy = [
                        'server' => trim($query['server']),
                        'port' => (int)trim($query['port']),
                        'secret' => trim($query['secret'])
                    ];
                    $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
                    
                    // De-duplicate here
                    if (!isset($uniqueProxies[$tgUrl])) {
                        $uniqueProxies[$tgUrl] = array_merge($proxy, ['tg_url' => $tgUrl]);
                    }
                }
            }
        }
    } else {
        echo " - [WARN] Failed to fetch '$url' (HTTP Code: $httpCode)\n";
    }
    curl_multi_remove_handle($multiHandle, $ch);
}
curl_multi_close($multiHandle);

$finalProxyList = array_values($uniqueProxies);
$proxyCount = count($finalProxyList);
echo "Fetch complete. Found $proxyCount unique potential proxies.\n";

// --- Phase 4 & 5 (Checking & Sorting) are now REMOVED from the server ---

// --- Phase 6: Generate Outputs ---
// The JSON file now contains the raw, unchecked list of proxies
$jsonOutputContent = json_encode($finalProxyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($outputJsonFile, $jsonOutputContent)) {
    echo "Successfully wrote $proxyCount unique proxies to '$outputJsonFile'\n";
}

// Generate HTML using the template, passing the raw proxy list
function renderTemplate(string $templateFile, array $data): string {
    if (!file_exists($templateFile)) {
        return "Error: Template file '$templateFile' not found.";
    }
    extract($data);
    ob_start();
    require $templateFile;
    return ob_get_clean();
}

echo "Generating dynamic HTML output from template...\n";
$htmlOutputContent = renderTemplate('template.phtml', [
    'proxies' => $finalProxyList, // Pass the unchecked list
    'proxyCount' => $proxyCount,
]);

if (file_put_contents($outputHtmlFile, $htmlOutputContent)) {
    echo "Successfully wrote new HTML output to '$outputHtmlFile'\n";
}

$consoleOutput = ob_get_clean();
echo $consoleOutput;
echo "--- Script Finished ---\n";
?>
