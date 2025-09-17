<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json';
$outputHtmlFile = 'index.html';
$outputJsonFile = 'extracted_proxies.json';
$telegramBaseUrl = 'https://t.me/s/';
$proxyCheckTimeout = 10; // Seconds to wait for a proxy to respond.
$historyLength = 24; // Number of past results to store for the stability sparkline.

// --- NEW: Robustness - Array of User Agents to rotate ---
$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:107.0) Gecko/20100101 Firefox/107.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.1 Safari/605.1.15',
];

// --- Script Logic ---
ob_start();
date_default_timezone_set('Asia/Tehran');
echo "--- Telegram Proxy Extractor v4.1 (Enhanced Edition) ---\n";

// --- Phase 0: Read Previous Run's Data ---
$indexedOldProxies = [];
if (file_exists($outputJsonFile)) {
    echo "Reading previous proxy data from '$outputJsonFile'...\n";
    $oldJsonContent = file_get_contents($outputJsonFile);
    $oldProxiesData = json_decode($oldJsonContent, true);
    if (is_array($oldProxiesData)) {
        foreach ($oldProxiesData as $proxy) {
            if (isset($proxy['tg_url'])) {
                $indexedOldProxies[$proxy['tg_url']] = $proxy;
            }
        }
        echo "Loaded history for " . count($indexedOldProxies) . " previous proxies.\n";
    }
}

// --- Phase 1: Read Input ---
if (!file_exists($inputJsonFile)) die("Error: Input JSON file not found at '$inputJsonFile'\n");
$jsonContent = file_get_contents($inputJsonFile);
if ($jsonContent === false) die("Error: Could not read input JSON file '$inputJsonFile'\n");
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
        CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)] // <-- ENHANCEMENT: Use a random user agent
    ]);
    curl_multi_add_handle($multiHandle, $ch);
    $urlHandles[$channelUrl] = $ch;
}

$running = null;
do {
    curl_multi_exec($multiHandle, $running);
    curl_multi_select($multiHandle);
} while ($running > 0);

// --- Phase 3: Process Results and Extract Proxies ---
$allExtractedProxies = [];
$proxyRegex = '/(?:https?:\/\/t\.me\/proxy\?|tg:\/\/proxy\?)[^"\'\s]+/i';

foreach ($urlHandles as $url => $ch) {
    $htmlContent = curl_multi_getcontent($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode == 200 && $htmlContent) {
        if (preg_match_all($proxyRegex, $htmlContent, $matches)) {
            foreach ($matches[0] as $foundUrl) {
                $parsedUrl = parse_url($foundUrl);
                if (!$parsedUrl || !isset($parsedUrl['query'])) continue;

                $decodedQueryString = html_entity_decode($parsedUrl['query'], ENT_QUOTES | ENT_HTML5);
                parse_str($decodedQueryString, $query);

                if (isset($query['server'], $query['port'], $query['secret'])) {
                    $allExtractedProxies[] = [
                        'server' => trim($query['server']),
                        'port' => (int)trim($query['port']),
                        'secret' => trim($query['secret'])
                    ];
                }
            }
        }
    } else { // <-- ENHANCEMENT: Better error logging
        echo " - [WARN] Failed to fetch '$url' (HTTP Code: $httpCode)\n";
    }
    curl_multi_remove_handle($multiHandle, $ch);
}
curl_multi_close($multiHandle);


// --- Phase 4: De-duplicate, PARALLEL Check Proxy Status, and Update History ---
echo "Fetch complete. Found " . count($allExtractedProxies) . " potential proxies. De-duplicating and checking status in parallel...\n";

$uniqueRawProxies = [];
foreach ($allExtractedProxies as $proxy) {
    $key = "{$proxy['server']}:{$proxy['port']}";
    if (!isset($uniqueRawProxies[$key])) {
        $uniqueRawProxies[$key] = $proxy;
    }
}
$proxiesToCheck = array_values($uniqueRawProxies);
$totalToCheck = count($proxiesToCheck);
$checkedCount = 0;
$onlineCount = 0;
$uniqueProxies = [];

$sockets = [];
$proxyDataMap = [];
$startTimeMap = [];
$write = $except = null;

foreach ($proxiesToCheck as $index => $proxy) {
    $socket = @stream_socket_client("tcp://{$proxy['server']}:{$proxy['port']}", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);

    if ($socket) {
        $sockets[$index] = $socket;
        $proxyDataMap[$index] = $proxy;
        $startTimeMap[$index] = microtime(true);
    } else {
        $checkedCount++;
        $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
        $history = $indexedOldProxies[$tgUrl]['history'] ?? [];
        $history[] = 0;
        if (count($history) > $historyLength) $history = array_slice($history, -$historyLength);
        $uniqueProxies[$tgUrl] = array_merge($proxy, ['status' => 'Offline', 'latency' => null, 'tg_url' => $tgUrl, 'history' => $history]);
    }
}

$globalStartTime = microtime(true);
while (!empty($sockets) && (microtime(true) - $globalStartTime) < $proxyCheckTimeout) {
    $read = $sockets;
    // ENHANCEMENT: UX - Progress bar
    $percent = $totalToCheck > 0 ? round(($checkedCount / $totalToCheck) * 100) : 0;
    echo "\rChecking proxies: [$checkedCount/$totalToCheck] ($percent%) | Online: $onlineCount ";

    if (stream_select($read, $write, $except, 1) > 0) {
        foreach ($read as $index => $socket) {
            $checkedCount++;
            $onlineCount++;
            $latency = round((microtime(true) - $startTimeMap[$index]) * 1000);
            $proxy = $proxyDataMap[$index];
            
            $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
            $history = $indexedOldProxies[$tgUrl]['history'] ?? [];
            $history[] = 1;
            if (count($history) > $historyLength) $history = array_slice($history, -$historyLength);
            
            $uniqueProxies[$tgUrl] = array_merge($proxy, ['status' => 'Online', 'latency' => $latency, 'tg_url' => $tgUrl, 'history' => $history]);

            fclose($socket);
            unset($sockets[$index], $proxyDataMap[$index], $startTimeMap[$index]);
        }
    }
}

// Any remaining sockets are considered offline due to timeout
foreach ($sockets as $index => $socket) {
    $checkedCount++;
    $proxy = $proxyDataMap[$index];
    $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
    $history = $indexedOldProxies[$tgUrl]['history'] ?? [];
    $history[] = 0;
    if (count($history) > $historyLength) $history = array_slice($history, -$historyLength);
    $uniqueProxies[$tgUrl] = array_merge($proxy, ['status' => 'Offline', 'latency' => null, 'tg_url' => $tgUrl, 'history' => $history]);
    fclose($socket);
}
echo "\r" . str_repeat(' ', 80) . "\r"; // Clear progress line

$proxiesWithStatus = array_values($uniqueProxies);
$proxyCount = count($proxiesWithStatus);

// --- Phase 5: Sort Proxies (Best First) ---
usort($proxiesWithStatus, function ($a, $b) {
    if ($a['status'] === 'Online' && $b['status'] === 'Offline') return -1;
    if ($a['status'] === 'Offline' && $b['status'] === 'Online') return 1;
    if ($a['status'] === 'Online' && $b['status'] === 'Online') {
        return $a['latency'] <=> $b['latency'];
    }
    return 0;
});

echo "Finished checks. Total unique proxies found: $proxyCount\n";

// --- Phase 6: Generate Outputs ---
$jsonOutputContent = json_encode($proxiesWithStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($outputJsonFile, $jsonOutputContent)) {
    echo "Successfully wrote $proxyCount unique proxies to '$outputJsonFile'\n";
}

// --- NEW: HTML Generation using a Template ---
function renderTemplate(string $templateFile, array $data): string {
    if (!file_exists($templateFile)) {
        return "Error: Template file '$templateFile' not found.";
    }
    extract($data);
    ob_start();
    require $templateFile;
    return ob_get_clean();
}

echo "Generating HTML output from template...\n";
$htmlOutputContent = renderTemplate('template.phtml', [
    'proxiesWithStatus' => $proxiesWithStatus,
    'proxyCount' => count($proxiesWithStatus),
]);

if (file_put_contents($outputHtmlFile, $htmlOutputContent)) {
    echo "Successfully wrote new HTML output to '$outputHtmlFile'\n";
}

$consoleOutput = ob_get_clean();
echo $consoleOutput;
echo "--- Script Finished ---\n";
?>
