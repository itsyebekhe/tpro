<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json';
$outputHtmlFile = 'index.html';
$outputJsonFile = 'extracted_proxies.json';
$telegramBaseUrl = 'https://t.me/s/';
$proxyCheckTimeout = 10; // Server-side check timeout.

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
];

// --- Script Logic ---
ob_start();
date_default_timezone_set('Asia/Tehran');
echo "--- Telegram Proxy Extractor v6.0 (Hybrid Check Edition) ---\n";

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)]]);
    curl_multi_add_handle($multiHandle, $ch);
    $urlHandles[$channelUrl] = $ch;
}
$running = null;
do { curl_multi_exec($multiHandle, $running); curl_multi_select($multiHandle); } while ($running > 0);

// --- Phase 3: Process Results and Extract Proxies ---
$allExtractedProxies = [];
$proxyRegex = '/(?:https?:\/\/t\.me\/proxy\?|tg:\/\/proxy\?)[^"\'\s]+/i';
foreach ($urlHandles as $url => $ch) {
    $htmlContent = curl_multi_getcontent($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 && $htmlContent && preg_match_all($proxyRegex, $htmlContent, $matches)) {
        foreach ($matches[0] as $foundUrl) {
            $parsedUrl = parse_url($foundUrl);
            if (!$parsedUrl || !isset($parsedUrl['query'])) continue;
            parse_str(html_entity_decode($parsedUrl['query']), $query);
            if (isset($query['server'], $query['port'], $query['secret'])) {
                $allExtractedProxies[] = ['server' => trim($query['server']), 'port' => (int)trim($query['port']), 'secret' => trim($query['secret'])];
            }
        }
    }
    curl_multi_remove_handle($multiHandle, $ch);
}
curl_multi_close($multiHandle);

// --- Phase 4: RE-INTRODUCED Server-Side Parallel Pre-Check ---
echo "Fetch complete. Found " . count($allExtractedProxies) . " potential proxies. Performing server-side pre-check...\n";
$uniqueRawProxies = [];
foreach ($allExtractedProxies as $proxy) {
    $key = "{$proxy['server']}:{$proxy['port']}";
    if (!isset($uniqueRawProxies[$key])) $uniqueRawProxies[$key] = $proxy;
}
$proxiesToCheck = array_values($uniqueRawProxies);
$totalToCheck = count($proxiesToCheck);
$checkedCount = 0;
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
        $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
        $uniqueProxies[$tgUrl] = array_merge($proxy, ['status' => 'Offline', 'latency' => null, 'tg_url' => $tgUrl]);
    }
}

$globalStartTime = microtime(true);
while (!empty($sockets) && (microtime(true) - $globalStartTime) < $proxyCheckTimeout) {
    $read = $sockets;
    if (stream_select($read, $write, $except, 1) > 0) {
        foreach ($read as $index => $socket) {
            $latency = round((microtime(true) - $startTimeMap[$index]) * 1000);
            $proxy = $proxyDataMap[$index];
            $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
            $uniqueProxies[$tgUrl] = array_merge($proxy, ['status' => 'Online', 'latency' => $latency, 'tg_url' => $tgUrl]);
            fclose($socket);
            unset($sockets[$index]);
        }
    }
}
// Any remaining sockets are offline
foreach ($sockets as $index => $socket) {
    $proxy = $proxyDataMap[$index];
    $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
    $uniqueProxies[$tgUrl] = array_merge($proxy, ['status' => 'Offline', 'latency' => null, 'tg_url' => $tgUrl]);
    fclose($socket);
}
$proxiesWithStatus = array_values($uniqueProxies);
$proxyCount = count($proxiesWithStatus);

// --- Phase 5: Sort Proxies by Server-Side Result ---
usort($proxiesWithStatus, function ($a, $b) {
    if ($a['status'] === 'Online' && $b['status'] === 'Offline') return -1;
    if ($a['status'] === 'Offline' && $b['status'] === 'Online') return 1;
    if ($a['status'] === 'Online' && $b['status'] === 'Online') return $a['latency'] <=> $b['latency'];
    return 0;
});
echo "Server-side check finished. Found " . count(array_filter($proxiesWithStatus, fn($p) => $p['status'] === 'Online')) . " online proxies.\n";

// --- Phase 6: Generate Outputs ---
$jsonOutputContent = json_encode($proxiesWithStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($outputJsonFile, $jsonOutputContent);
echo "Successfully wrote $proxyCount proxies with initial status to '$outputJsonFile'\n";

function renderTemplate(string $templateFile, array $data): string {
    if (!file_exists($templateFile)) return "Error: Template file '$templateFile' not found.";
    extract($data);
    ob_start();
    require $templateFile;
    return ob_get_clean();
}

$htmlOutputContent = renderTemplate('template.phtml', [
    'proxies' => $proxiesWithStatus,
    'proxyCount' => $proxyCount,
]);
file_put_contents($outputHtmlFile, $htmlOutputContent);
echo "Successfully wrote hybrid HTML output to '$outputHtmlFile'\n";

$consoleOutput = ob_get_clean();
echo $consoleOutput;
echo "--- Script Finished ---\n";
?>
