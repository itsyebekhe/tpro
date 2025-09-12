<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json';
$outputHtmlFile = 'index.html';
$outputJsonFile = 'extracted_proxies.json';
$telegramBaseUrl = 'https://t.me/s/';
$proxyCheckTimeout = 20; // Seconds to wait for a proxy to respond.
$historyLength = 24; // << NEW: Number of past results to store for the stability sparkline.

// --- Script Logic ---
ob_start();
date_default_timezone_set('Asia/Tehran');
echo "--- Telegram Proxy Extractor v4.0 (Sparkline Edition) ---\n";

// --- Phase 0: Read Previous Run's Data ---
$indexedOldProxies = [];
if (file_exists($outputJsonFile)) {
    echo "Reading previous proxy data from '$outputJsonFile'...\n";
    $oldJsonContent = file_get_contents($outputJsonFile);
    $oldProxiesData = json_decode($oldJsonContent, true);
    if (is_array($oldProxiesData)) {
        foreach ($oldProxiesData as $proxy) {
            // Index by tg_url for fast lookups
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
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHP-Proxy-Extractor/4.0)'
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
    }
    curl_multi_remove_handle($multiHandle, $ch);
}
curl_multi_close($multiHandle);

// --- Phase 4: De-duplicate, Check Proxy Status, and Update History ---
echo "Fetch complete. Found " . count($allExtractedProxies) . " potential proxies. De-duplicating and checking status...\n";

function checkProxyStatus(string $server, int $port, int $timeout): array {
    $startTime = microtime(true);
    $socket = @fsockopen("tcp://$server", $port, $errno, $errstr, $timeout);

    if ($socket) {
        $latency = round((microtime(true) - $startTime) * 1000);
        fclose($socket);
        return ['status' => 'Online', 'latency' => $latency];
    }
    return ['status' => 'Offline', 'latency' => null];
}

$uniqueProxies = [];
foreach ($allExtractedProxies as $proxy) {
    $tgUrl = "tg://proxy?server={$proxy['server']}&port={$proxy['port']}&secret={$proxy['secret']}";
    if (!isset($uniqueProxies[$tgUrl])) {
        $statusResult = checkProxyStatus($proxy['server'], $proxy['port'], $proxyCheckTimeout);

        // --- NEW: History Logic ---
        $history = $indexedOldProxies[$tgUrl]['history'] ?? [];
        $newStatusBit = ($statusResult['status'] === 'Online') ? 1 : 0;
        $history[] = $newStatusBit;
        if (count($history) > $historyLength) {
            $history = array_slice($history, -$historyLength);
        }
        // --- End History Logic ---

        $uniqueProxies[$tgUrl] = array_merge($proxy, $statusResult, ['tg_url' => $tgUrl, 'history' => $history]);
        echo " - Checked {$proxy['server']}:{$proxy['port']} -> {$statusResult['status']}" . ($statusResult['latency'] ? " ({$statusResult['latency']}ms)" : "") . " | History: " . count($history) . "/$historyLength\n";
    }
}

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

echo "\nFinished checks. Total unique proxies found: $proxyCount\n";

// --- Phase 6: Generate Outputs ---
$jsonOutputContent = json_encode($proxiesWithStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($outputJsonFile, $jsonOutputContent)) {
    echo "Successfully wrote $proxyCount unique proxies to '$outputJsonFile'\n";
}

// --- HTML Generation ---

$htmlOutputContent = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram Proxy List</title>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #f8f9fa; --card-bg-color: #ffffff; --text-color: #212529; --subtle-text-color: #6c757d;
            --primary-color: #007bff; --primary-hover-color: #0056b3; --secondary-color: #6c757d; --secondary-hover-color: #5a6268;
            --success-color: #28a745; --success-hover-color: #218838; --danger-color: #dc3545; --border-color: #dee2e6; --shadow-color: rgba(0, 0, 0, 0.05);
            --font-main: "Inter", sans-serif; --font-rtl: "Vazirmatn", sans-serif; --font-mono: "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg-color: #121212; --card-bg-color: #1e1e1e; --text-color: #e8e6e3; --subtle-text-color: #adb5bd;
                --primary-color: #0d6efd; --primary-hover-color: #0b5ed7; --border-color: #343a40; --shadow-color: rgba(0, 0, 0, 0.2);
            }
        }
        body { font-family: var(--font-main); margin: 0; padding: 20px; background-color: var(--bg-color); color: var(--text-color); line-height: 1.6; }
        .container { max-width: 800px; margin: 20px auto; }
        header { text-align: center; margin-bottom: 20px; }
        header h1 { font-size: 2.5rem; margin-bottom: 0.5rem; }
        header p { font-size: 1.1rem; color: var(--subtle-text-color); }
        .proxy-card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px var(--shadow-color); position: relative; transition: all 0.3s ease; display: flex; flex-direction: column; gap: 15px; }
        .proxy-card.offline { opacity: 0.6; border-left: 4px solid var(--danger-color); }
        .proxy-card.online { border-left: 4px solid var(--success-color); }
        .proxy-details { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 15px; font-size: 0.9rem; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; font-weight: 500; padding: 4px 10px; border-radius: 20px; font-size: 0.85rem; }
        .status-badge.online { background-color: #28a74520; color: var(--success-color); }
        .status-badge.offline { background-color: #dc354520; color: var(--danger-color); }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .status-dot.online { background-color: var(--success-color); animation: pulse 2s infinite; }
        .status-dot.offline { background-color: var(--danger-color); }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); } 100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); } }
        .proxy-info { font-family: var(--font-mono); background-color: var(--bg-color); padding: 5px 10px; border-radius: 6px; word-break: break-all; }
        
        /* --- NEW: Sparkline Styles --- */
        .proxy-stability { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; padding: 8px 0; border-top: 1px solid var(--border-color); margin-top: 10px; }
        .sparkline-container { width: 100px; height: 25px; }
        .sparkline-container svg { stroke: var(--primary-color); stroke-width: 2; fill: none; }
        .uptime-text { font-weight: 500; }
        .uptime-text.high { color: var(--success-color); }
        .uptime-text.medium { color: #fdc500; }
        .uptime-text.low { color: var(--danger-color); }
        /* --- End Sparkline Styles --- */
        
        .proxy-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .action-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 8px; border: 1px solid transparent; font-size: 0.95rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.2s ease; flex-grow: 1; justify-content: center; }
        .action-btn svg { width: 16px; height: 16px; }
        .connect-btn { background-color: var(--success-color); color: white; } .connect-btn:hover { background-color: var(--success-hover-color); }
        .copy-btn { background-color: var(--primary-color); color: white; } .copy-btn:hover { background-color: var(--primary-hover-color); }
        .qr-btn { background-color: var(--secondary-color); color: white; } .qr-btn:hover { background-color: var(--secondary-hover-color); }
        @media (min-width: 500px) { .action-btn { flex-grow: 0; } }
        .controls-bar { background-color: var(--card-bg-color); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between; margin-bottom: 25px; }
        .controls-bar select { padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 1rem; }
        .list-status { color: var(--subtle-text-color); font-size: 0.9rem; }
        .proxy-list { display: grid; gap: 20px; } .proxy-card.hidden { display: none; }
        .pagination-controls { display: flex; justify-content: space-between; align-items: center; margin-top: 25px; }
        .pagination-btn { padding: 8px 16px; border: 1px solid var(--border-color); background-color: var(--card-bg-color); color: var(--primary-color); border-radius: 8px; cursor: pointer; }
        .pagination-btn:disabled { background-color: var(--bg-color); color: var(--subtle-text-color); cursor: not-allowed; }
        .footer { text-align: center; margin-top: 40px; padding-top: 25px; font-size: 1em; color: var(--subtle-text-color); border-top: 1px solid var(--border-color); }
        .footer p { margin-bottom: 15px; }
        .social-icons { display: flex; justify-content: center; align-items: center; gap: 25px; }
        .social-icons a { color: var(--subtle-text-color); text-decoration: none; transition: color 0.2s ease, transform 0.2s ease; display: inline-block; }
        .social-icons a:hover { color: var(--primary-color); transform: translateY(-2px); }
        .social-icons svg { width: 28px; height: 28px; }
        #qr-modal { position: fixed; z-index: 1000; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; backdrop-filter: blur(5px); }
        #qr-modal.visible { opacity: 1; visibility: visible; }
        .modal-content { background-color: #fff; padding: 25px; border-radius: 16px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Telegram Proxy List</h1>
            <p>Found <strong>' . $proxyCount . '</strong> unique proxies. Last updated: ' . date('Y-m-d H:i:s') . ' Tehran Time</p>
        </header>';

if ($proxyCount > 0) {
    $htmlOutputContent .= '
        <div class="controls-bar">
             <div class="items-per-page">
                <select id="items-per-page-select">
                    <option value="10">10 items per page</option>
                    <option value="25">25 items per page</option>
                    <option value="50">50 items per page</option>
                    <option value="100">100 items per page</option>
                </select>
            </div>
            <div id="list-status" class="list-status"></div>
        </div>
        <div class="proxy-list">';
    foreach ($proxiesWithStatus as $proxy) {
        $tgUrl = htmlspecialchars($proxy['tg_url']);
        $server = htmlspecialchars($proxy['server']);
        $port = htmlspecialchars($proxy['port']);
        $statusClass = strtolower($proxy['status']);
        
        // --- NEW: Calculate Uptime ---
        $history = $proxy['history'] ?? [];
        $uptime = 0;
        $uptimeClass = 'low';
        if (!empty($history)) {
            $onlineCount = array_sum($history);
            $uptime = round(($onlineCount / count($history)) * 100);
            if ($uptime > 90) $uptimeClass = 'high';
            elseif ($uptime > 70) $uptimeClass = 'medium';
        }
        $historyJson = htmlspecialchars(json_encode($history), ENT_QUOTES, 'UTF-8');
        // --- End Uptime Calculation ---

        $htmlOutputContent .= '
            <div class="proxy-card ' . $statusClass . '">
                <div class="proxy-details">
                    <span class="status-badge ' . $statusClass . '">
                        <span class="status-dot ' . $statusClass . '"></span>' . $proxy['status'] . '
                    </span>
                    <span class="proxy-info">Server: <strong>' . $server . '</strong></span>
                    <span class="proxy-info">Port: <strong>' . $port . '</strong></span>';
        if ($proxy['status'] === 'Online') {
            $htmlOutputContent .= '<span class="proxy-info">Ping: <strong>' . $proxy['latency'] . 'ms</strong></span>';
        }
        $htmlOutputContent .= '
                </div>

                <!-- NEW: Stability Sparkline Section -->
                <div class="proxy-stability">
                    <span class="sparkline-container" data-history="' . $historyJson . '"></span>
                    <span class="uptime-text ' . $uptimeClass . '">' . $uptime . '% Uptime</span>
                </div>
                <!-- End Stability Section -->

                <div class="proxy-actions">
                    <a href="' . $tgUrl . '" class="action-btn connect-btn" target="_blank">
                       <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 3.178 4.995.002.002.26.41a.5.5 0 0 0 .886-.083l6-15Zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471-.47 1.178Z"/></svg>
                        <span>Connect</span>
                    </a>
                    <button class="action-btn copy-btn" data-url="' . $tgUrl . '">
                        <svg class="icon-copy" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/></svg>
                        <svg class="icon-check" style="display:none;" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>
                        <span>Copy</span>
                    </button>
                    <button class="action-btn qr-btn" data-url="' . $tgUrl . '">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path d="M0 .5A.5.5 0 0 1 .5 0h3a.5.5 0 0 1 0 1H1v2.5a.5.5 0 0 1-1 0zM12 .5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0V1h-2.5a.5.5 0 0 1-.5-.5M.5 12a.5.5 0 0 1 .5.5V15h2.5a.5.5 0 0 1 0 1h-3a.5.5 0 0 1-.5-.5v-3a.5.5 0 0 1 .5-.5m15 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1 0-1H15v-2.5a.5.5 0 0 1 .5-.5M4 4h1v1H4z"/><path d="M7 2H2v5h5zM3 3h3v3H3zm2 8.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m-2 0a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m-2 0a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m12-4a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5M11 9h1v1h-1zM9 9h1v1H9zm4 4h1v1h-1zm-2 0h1v1h-1zm-2 0h1v1h-1zm4-2h1v1h-1zm-2 0h1v1h-1zm-2 0h1v1h-1zm2-2h1v1h-1zM9 11h1v1H9zm2-2H9v5h5V9h-2zM4 11h1v1H4zm-2 0h1v1H2zm-2 0h1v1H0z"/></svg>
                        <span>Show QR</span>
                    </button>
                </div>
            </div>';
    }
    $htmlOutputContent .= '
        </div>
        <div class="pagination-controls">
            <button id="prev-btn" class="pagination-btn">Previous</button>
            <button id="next-btn" class="pagination-btn">Next</button>
        </div>';
} else {
    $htmlOutputContent .= '<p style="text-align:center;padding:40px;font-size:1.1rem;color:var(--subtle-text-color);">No proxies found in the latest scan.</p>';
}

$htmlOutputContent .= '
        <div class="footer">
            <p>made with ❤️ by YEBEKHE</p>
            <div class="social-icons">
                <a href="https://t.me/yebekhe" target="_blank" title="YEBEKHE on Telegram">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-1.39.2-1.61l16.11-5.71c.78-.27 1.45.16 1.18 1.1l-2.53 11.92c-.33 1.51-1.23 1.84-2.24 1.13l-4.75-3.51-2.35 2.24c-.26.26-.48.47-.93.47z"/></svg>
                </a>
                <a href="https://twitter.com/yebekhe" target="_blank" title="YEBEKHE on Twitter">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                </a>
            </div>
        </div>

    </div>
    <div id="qr-modal"><div class="modal-content"><h3>Scan with Telegram</h3><div id="qrcode-container"></div></div></div>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const proxyCards = Array.from(document.querySelectorAll(".proxy-card"));
            if (proxyCards.length === 0) return;
            const itemsPerPageSelect = document.getElementById("items-per-page-select");
            const listStatus = document.getElementById("list-status");
            const prevBtn = document.getElementById("prev-btn");
            const nextBtn = document.getElementById("next-btn");
            let currentPage = 1;
            let itemsPerPage = parseInt(itemsPerPageSelect.value, 10);
            function renderList() {
                const totalItems = proxyCards.length;
                const totalPages = Math.ceil(totalItems / itemsPerPage);
                currentPage = Math.max(1, Math.min(currentPage, totalPages));
                proxyCards.forEach(card => card.classList.add("hidden"));
                const start = (currentPage - 1) * itemsPerPage;
                const end = start + itemsPerPage;
                const paginatedCards = proxyCards.slice(start, end);
                paginatedCards.forEach(card => card.classList.remove("hidden"));
                const startItem = totalItems > 0 ? start + 1 : 0;
                const endItem = Math.min(end, totalItems);
                listStatus.textContent = `Showing ${startItem}-${endItem} of ${totalItems}`;
                prevBtn.disabled = currentPage === 1;
                nextBtn.disabled = currentPage === totalPages || totalItems === 0;
            }
            itemsPerPageSelect.addEventListener("change", () => {
                itemsPerPage = parseInt(itemsPerPageSelect.value, 10);
                currentPage = 1;
                renderList();
            });
            prevBtn.addEventListener("click", () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderList();
                    window.scrollTo({ top: document.querySelector(".controls-bar").offsetTop - 20, behavior: "smooth" });
                }
            });
            nextBtn.addEventListener("click", () => {
                if (currentPage < Math.ceil(proxyCards.length / itemsPerPage)) {
                    currentPage++;
                    renderList();
                    window.scrollTo({ top: document.querySelector(".controls-bar").offsetTop - 20, behavior: "smooth" });
                }
            });
            renderList();
            const copyButtons = document.querySelectorAll(".copy-btn");
            copyButtons.forEach(button => {
                const iconCopy = button.querySelector(".icon-copy");
                const iconCheck = button.querySelector(".icon-check");
                const buttonText = button.querySelector("span");
                const originalText = buttonText.textContent;
                button.addEventListener("click", () => {
                    const urlToCopy = button.getAttribute("data-url");
                    navigator.clipboard.writeText(urlToCopy).then(() => {
                        iconCopy.style.display = "none";
                        iconCheck.style.display = "inline-block";
                        buttonText.textContent = "Copied!";
                        button.style.backgroundColor = "var(--success-color)";
                        setTimeout(() => {
                            iconCopy.style.display = "inline-block";
                            iconCheck.style.display = "none";
                            buttonText.textContent = originalText;
                            button.style.backgroundColor = "";
                        }, 2000);
                    });
                });
            });
            const qrModal = document.getElementById("qr-modal");
            const qrContainer = document.getElementById("qrcode-container");
            document.querySelectorAll(".qr-btn").forEach(button => {
                button.addEventListener("click", () => {
                    const url = button.getAttribute("data-url");
                    qrContainer.innerHTML = "";
                    new QRCode(qrContainer, { text: url, width: 220, height: 220 });
                    qrModal.classList.add("visible");
                });
            });
            qrModal.addEventListener("click", e => { if (e.target === qrModal) qrModal.classList.remove("visible"); });

            /* --- NEW: Sparkline Generation Logic --- */
            function createSparkline(history) {
                if (!history || history.length < 2) return null;
                
                const svgNS = "http://www.w3.org/2000/svg";
                const svg = document.createElementNS(svgNS, "svg");
                const width = 100;
                const height = 25;
                const padding = 4;
                svg.setAttribute("width", width);
                svg.setAttribute("height", height);
                svg.setAttribute("viewBox", `0 0 ${width} ${height}`);

                const polyline = document.createElementNS(svgNS, "polyline");
                
                let points = "";
                const xStep = width / (history.length - 1);
                
                history.forEach((status, i) => {
                    const x = i * xStep;
                    const y = status === 1 ? padding : height - padding;
                    points += `${x},${y} `;
                });
                
                polyline.setAttribute("points", points.trim());
                svg.appendChild(polyline);
                return svg;
            }

            document.querySelectorAll(".sparkline-container").forEach(container => {
                try {
                    const history = JSON.parse(container.getAttribute("data-history"));
                    const sparklineSvg = createSparkline(history);
                    if (sparklineSvg) {
                        container.appendChild(sparklineSvg);
                    }
                } catch (e) {
                    console.error("Could not parse sparkline history:", e);
                }
            });
            /* --- End Sparkline Logic --- */
        });
    </script>
</body>
</html>';

if (file_put_contents($outputHtmlFile, $htmlOutputContent)) {
    echo "Successfully wrote new HTML output with live status to '$outputHtmlFile'\n";
}

$consoleOutput = ob_get_clean();
echo $consoleOutput;
echo "--- Script Finished ---\n";
?>