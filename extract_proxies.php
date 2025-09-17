<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json';
$outputHtmlFile = 'index.html';

// This is the RAW list of proxies we find for the Python script to use
$rawOutputJsonFile = 'extracted_proxies.json'; 

// This is the FINAL list, verified by Python, that we will display on the webpage
$verifiedInputJsonFile = 'verified_proxies.json';

$telegramBaseUrl = 'https://t.me/s/';
$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
];

// --- Script Logic ---
ob_start();
date_default_timezone_set('Asia/Tehran');
echo "--- Telegram Proxy Harvester v7.0 (PHP + Python Pipeline) ---\n";
echo "--- STAGE 1: Finding potential proxies with PHP ---\n";

// --- Phase 1: Read Input ---
if (!file_exists($inputJsonFile)) die("Error: Input JSON file not found at '$inputJsonFile'\n");
$jsonContent = file_get_contents($inputJsonFile);
$usernames = json_decode($jsonContent, true);

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
}
$running = null;
do { curl_multi_exec($multiHandle, $running); curl_multi_select($multiHandle); } while ($running > 0);

// --- Phase 3: Extract and De-duplicate Proxies ---
$uniqueProxies = [];
$proxyRegex = '/(?:https?:\/\/t\.me\/proxy\?|tg:\/\/proxy\?)[^"\'\s]+/i';
foreach ($urlHandles as $url => $ch) {
    $htmlContent = curl_multi_getcontent($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 && $htmlContent && preg_match_all($proxyRegex, $htmlContent, $matches)) {
        foreach ($matches[0] as $foundUrl) {
            $parsedUrl = parse_url($foundUrl);
            if (!$parsedUrl || !isset($parsedUrl['query'])) continue;
            parse_str(html_entity_decode($parsedUrl['query']), $query);
            if (isset($query['server'], $query['port'], $query['secret'])) {
                $proxy = ['server' => trim($query['server']), 'port' => (int)trim($query['port']), 'secret' => trim($query['secret'])];
                $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
                if (!isset($uniqueProxies[$tgUrl])) {
                    $uniqueProxies[$tgUrl] = array_merge($proxy, ['tg_url' => $tgUrl]);
                }
            }
        }
    }
    curl_multi_remove_handle($multiHandle, $ch);
}
curl_multi_close($multiHandle);

$finalProxyList = array_values($uniqueProxies);
$proxyCount = count($finalProxyList);
echo "Fetch complete. Found $proxyCount unique potential proxies.\n";

// --- Phase 4: Save Raw List for Python Verifier ---
$jsonOutputContent = json_encode($finalProxyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($rawOutputJsonFile, $jsonOutputContent);
echo "Successfully wrote $proxyCount raw proxies to '$rawOutputJsonFile' for the Python script to use.\n";

// --- STAGE 2: Generating the Dashboard from Python's verified list ---\n";
echo "\n--- STAGE 2: Generating dashboard from verified list ---\n";
$proxiesForDashboard = [];
if (file_exists($verifiedInputJsonFile)) {
    $verifiedContent = file_get_contents($verifiedInputJsonFile);
    $proxiesForDashboard = json_decode($verifiedContent, true);
    if (!is_array($proxiesForDashboard)) {
        $proxiesForDashboard = [];
        echo "[WARN] Could not read the verified proxy file '$verifiedInputJsonFile'. Dashboard may be empty.\n";
    } else {
        echo "Successfully loaded " . count($proxiesForDashboard) . " proxies from the verified list.\n";
    }
} else {
    echo "[INFO] Verified proxy file '$verifiedInputJsonFile' not found. Run the Python verifier script.\n";
    echo "[INFO] The dashboard will be generated using the raw, unverified list as a fallback.\n";
    // Fallback to the unverified list if the verified one doesn't exist yet
    foreach($finalProxyList as &$proxy) {
        $proxy['status'] = 'Unknown';
        $proxy['latency'] = null;
    }
    $proxiesForDashboard = $finalProxyList;
}

function renderTemplate(string $templateFile, array $data): string {
    if (!file_exists($templateFile)) return "Error: Template file '$templateFile' not found.";
    extract($data);
    ob_start();
    require $templateFile;
    return ob_get_clean();
}

$htmlOutputContent = renderTemplate('template.phtml', [
    'proxies' => $proxiesForDashboard,
    'proxyCount' => count($proxiesForDashboard),
]);
file_put_contents($outputHtmlFile, $htmlOutputContent);
echo "Successfully generated '$outputHtmlFile'.\n";

$consoleOutput = ob_get_clean();
echo $consoleOutput;
echo "--- Script Finished ---\n";
?>
