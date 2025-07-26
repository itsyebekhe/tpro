<?php

// --- Configuration ---
$inputJsonFile = 'usernames.json';
$outputHtmlFile = 'index.html';
$outputJsonFile = 'extracted_proxies.json';
$telegramBaseUrl = 'https://t.me/s/';

// --- Script Logic ---
ob_start();
echo "--- Telegram Proxy Link Extractor ---\n";

if (!file_exists($inputJsonFile)) die("Error: Input JSON file not found at '$inputJsonFile'\n");
$jsonContent = file_get_contents($inputJsonFile);
if ($jsonContent === false) die("Error: Could not read input JSON file '$inputJsonFile'\n");
$usernames = json_decode($jsonContent, true);
if ($usernames === null) die("Error: Could not decode JSON from '$inputJsonFile'. Details: " . json_last_error_msg() . "\n");
if (!is_array($usernames)) die("Error: JSON content in '$inputJsonFile' is not an array.\n");

echo "Read " . count($usernames) . " usernames from '$inputJsonFile'.\n";

$uniqueProxies = [];
$proxyRegex = '/(?:https?:\/\/t\.me\/proxy\?|tg:\/\/proxy\?)[^"\'\s]+/i';

foreach ($usernames as $username) {
    if (!is_string($username) || empty(trim($username))) continue;
    $username = trim($username);
    $channelUrl = $telegramBaseUrl . urlencode($username);
    echo "Processing: '$username'...\n";

    $ch = curl_init($channelUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHP-Proxy-Extractor/2.2)'
    ]);
    $htmlContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($htmlContent === false || $httpCode != 200) {
        echo " - Warning: Failed to fetch '$username' (HTTP: $httpCode). Skipping.\n";
        continue;
    }

    if (preg_match_all($proxyRegex, $htmlContent, $matches)) {
        foreach ($matches[0] as $foundUrl) {
            $parsedUrl = parse_url($foundUrl);
            if (!$parsedUrl || !isset($parsedUrl['query'])) continue;
            
            $decodedQueryString = html_entity_decode($parsedUrl['query'], ENT_QUOTES | ENT_HTML5);
            parse_str($decodedQueryString, $query);

            if (isset($query['server'], $query['port'], $query['secret'])) {
                $server = trim($query['server']);
                $port = trim($query['port']);
                $secret = trim($query['secret']);
                $tgUrl = "tg://proxy?server={$server}&port={$port}&secret={$secret}";

                if (!isset($uniqueProxies[$tgUrl])) {
                    $uniqueProxies[$tgUrl] = ['tg_url' => $tgUrl, 'server' => $server, 'port' => (int)$port, 'secret' => $secret];
                    echo "   - Extracted: server={$server}\n";
                }
            }
        }
    }
}

$extractedProxyList = array_values($uniqueProxies);
$proxyCount = count($extractedProxyList);
echo "\nFinished. Total unique proxies found: $proxyCount\n";

$jsonOutputContent = json_encode($extractedProxyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($outputJsonFile, $jsonOutputContent)) {
    echo "Successfully wrote $proxyCount unique proxies to '$outputJsonFile'\n";
} else {
    echo "Error: Could not write to '$outputJsonFile'\n";
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
            --success-color: #28a745; --success-hover-color: #218838; --border-color: #dee2e6; --shadow-color: rgba(0, 0, 0, 0.05);
            --font-main: "Inter", sans-serif; --font-rtl: "Vazirmatn", "Inter", sans-serif; --font-mono: "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
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
        .controls-bar {
            background-color: var(--card-bg-color); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color);
            display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between; margin-bottom: 25px;
        }
        .controls-bar select {
            padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);
            background-color: var(--bg-color); color: var(--text-color); font-size: 1rem;
        }
        .list-status { color: var(--subtle-text-color); font-size: 0.9rem; }
        .proxy-list { display: grid; gap: 20px; }
        .proxy-card {
            background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 12px;
            padding: 20px; box-shadow: 0 4px 12px var(--shadow-color);
        }
        .proxy-card.hidden { display: none; }
        .proxy-details { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 20px; margin-bottom: 20px; font-family: var(--font-mono); font-size: 0.9rem; word-break: break-all; }
        .proxy-details span { background-color: var(--bg-color); padding: 5px 10px; border-radius: 6px; }
        .proxy-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .action-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 8px; border: 1px solid transparent; font-size: 0.95rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.2s ease; flex-grow: 1; justify-content: center; }
        .action-btn svg { width: 16px; height: 16px; }
        .connect-btn { background-color: var(--success-color); color: white; } .connect-btn:hover { background-color: var(--success-hover-color); }
        .copy-btn { background-color: var(--primary-color); color: white; } .copy-btn:hover { background-color: var(--primary-hover-color); }
        .qr-btn { background-color: var(--secondary-color); color: white; } .qr-btn:hover { background-color: var(--secondary-hover-color); }
        @media (min-width: 500px) { .action-btn { flex-grow: 0; } }
        .pagination-controls { display: flex; justify-content: space-between; align-items: center; margin-top: 25px; }
        .pagination-btn { padding: 8px 16px; border: 1px solid var(--border-color); background-color: var(--card-bg-color); color: var(--primary-color); border-radius: 8px; cursor: pointer; }
        .pagination-btn:disabled { background-color: var(--bg-color); color: var(--subtle-text-color); cursor: not-allowed; }
        .instructions { margin-top: 50px; background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 12px; font-size: 0.95rem; }
        .instructions summary { font-size: 1.2rem; font-weight: 700; padding: 20px; cursor: pointer; outline: none; display: flex; justify-content: space-between; align-items: center; }
        .instructions summary::after { content: "+"; font-size: 1.5rem; transition: transform 0.2s ease; }
        .instructions[open] summary::after { transform: rotate(45deg); }
        .instructions-content { padding: 0 20px 20px; border-top: 1px solid var(--border-color); }
        .instructions-content h3 { margin-top: 25px; margin-bottom: 10px; }
        .instructions-content ol { padding-left: 20px; }
        .instructions-content code { background-color: var(--bg-color); padding: 2px 6px; border-radius: 4px; font-family: var(--font-mono); }
        [dir="rtl"] { font-family: var(--font-rtl); text-align: right; }
        [dir="rtl"] .instructions summary { flex-direction: row-reverse; }
        [dir="rtl"] .instructions-content ol { padding-left: 0; padding-right: 20px; }
        #qr-modal { position: fixed; z-index: 1000; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; backdrop-filter: blur(5px); }
        #qr-modal.visible { opacity: 1; visibility: visible; }
        .modal-content { background-color: #fff; padding: 25px; border-radius: 16px; text-align: center; }
        .footer { text-align: center; margin-top: 40px; font-size: 0.9em; color: var(--subtle-text-color); }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Telegram Proxy List</h1>
            <p>Found <strong>' . $proxyCount . '</strong> unique proxies. Last updated: ' . date('Y-m-d H:i:s') . ' UTC</p>
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
    foreach ($extractedProxyList as $proxy) {
        $tgUrl = htmlspecialchars($proxy['tg_url'], ENT_QUOTES, 'UTF-8');
        $server = htmlspecialchars($proxy['server'], ENT_QUOTES, 'UTF-8');
        $port = htmlspecialchars($proxy['port'], ENT_QUOTES, 'UTF-8');
        $htmlOutputContent .= '
            <div class="proxy-card">
                <div class="proxy-details"><span>Server: <strong>' . $server . '</strong></span><span>Port: <strong>' . $port . '</strong></span></div>
                <div class="proxy-actions">
                    <a href="' . $tgUrl . '" class="action-btn connect-btn" target="_blank"><svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 3.178 4.995.002.002.26.41a.5.5 0 0 0 .886-.083l6-15Zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471-.47 1.178Z"/></svg><span>Connect</span></a>
                    <button class="action-btn copy-btn" data-url="' . $tgUrl . '"><svg class="icon-copy" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/></svg><svg class="icon-check" style="display:none;" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg><span>Copy</span></button>
                    <button class="action-btn qr-btn" data-url="' . $tgUrl . '"><svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16"><path d="M0 .5A.5.5 0 0 1 .5 0h3a.5.5 0 0 1 0 1H1v2.5a.5.5 0 0 1-1 0zM12 .5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0V1h-2.5a.5.5 0 0 1-.5-.5M.5 12a.5.5 0 0 1 .5.5V15h2.5a.5.5 0 0 1 0 1h-3a.5.5 0 0 1-.5-.5v-3a.5.5 0 0 1 .5-.5m15 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1 0-1H15v-2.5a.5.5 0 0 1 .5-.5M4 4h1v1H4z"/><path d="M7 2H2v5h5zM3 3h3v3H3zm2 8.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m-2 0a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m-2 0a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m12-4a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5M11 9h1v1h-1zM9 9h1v1H9zm4 4h1v1h-1zm-2 0h1v1h-1zm-2 0h1v1h-1zm4-2h1v1h-1zm-2 0h1v1h-1zm-2 0h1v1h-1zm2-2h1v1h-1zM9 11h1v1H9zm2-2H9v5h5V9h-2zM4 11h1v1H4zm-2 0h1v1H2zm-2 0h1v1H0z"/></svg><span>Show QR</span></button>
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
    $htmlOutputContent .= '<p class="no-proxies" style="text-align: center; padding: 40px; font-size: 1.1rem; color: var(--subtle-text-color);">No active proxies found in the latest scan.</p>';
}

$htmlOutputContent .= '
        <details class="instructions">
            <summary>How to Connect</summary>
            <div class="instructions-content">
                <p>Telegram MTProto proxies help bypass censorship. Here’s how to use them:</p>
                <h3>Method 1: Direct Link (Desktop/Mobile)</h3>
                <ol>
                    <li>Click the green "Connect" button.</li>
                    <li>Your browser will ask to open Telegram. Allow it.</li>
                    <li>Telegram will open and show a confirmation screen. Tap "Connect Proxy".</li>
                </ol>
                <h3>Method 2: QR Code (Best for Mobile)</h3>
                <ol>
                    <li>Click the gray "Show QR" button. A QR code will appear.</li>
                    <li>On your phone, open Telegram and go to <strong>Settings > Data and Storage > Proxy Settings</strong>.</li>
                    <li>Tap "Add Proxy" and then tap the QR code icon to scan the code on your screen.</li>
                </ol>
                <h3>Method 3: Copy and Paste</h3>
                <ol>
                    <li>Click the blue "Copy" button. The full <code>tg://</code> link is now in your clipboard.</li>
                    <li>In Telegram, go to <strong>Settings > Data and Storage > Proxy Settings</strong>.</li>
                    <li>Tap "Add Proxy" and paste the link.</li>
                </ol>
            </div>
        </details>
        
        <details class="instructions" dir="rtl">
            <summary>راهنمای اتصال</summary>
            <div class="instructions-content">
                 <p>پراکسی‌های MTProto تلگرام به عبور از محدودیت‌ها کمک می‌کنند:</p>
                <h3>روش ۱: لینک مستقیم (دسکتاپ/موبایل)</h3>
                <ol>
                    <li>روی دکمه سبز رنگ «Connect» کلیک کنید.</li>
                    <li>مرورگر شما اجازه باز کردن تلگرام را می‌خواهد. تایید کنید.</li>
                    <li>تلگرام باز شده و با نمایش صفحه تایید، روی «Connect Proxy» ضربه بزنید.</li>
                </ol>
                <h3>روش ۲: کد QR (بهترین روش برای موبایل)</h3>
                <ol>
                    <li>روی دکمه خاکستری «Show QR» کلیک کنید تا کد QR نمایش داده شود.</li>
                    <li>در گوشی خود، به مسیر <strong>تنظیمات > داده و ذخیره‌سازی > تنظیمات پراکسی</strong> بروید.</li>
                    <li>گزینه «افزودن پراکسی» را زده و سپس روی آیکون کد QR ضربه بزنید تا کد را از روی صفحه اسکن کنید.</li>
                </ol>
                <h3>روش ۳: کپی و جای‌گذاری</h3>
                <ol>
                    <li>روی دکمه آبی «Copy» کلیک کنید تا لینک کامل <code>tg://</code> در حافظه کپی شود.</li>
                    <li>در تلگرام، به <strong>تنظیمات > داده و ذخیره‌سازی > تنظیمات پراکسی</strong> بروید.</li>
                    <li>«افزودن پراکسی» را زده و لینک را جای‌گذاری کنید.</li>
                </ol>
            </div>
        </details>

        <div class="footer"><p>Generated by a script. Not affiliated with Telegram.</p></div>
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

        // --- Event Listeners ---
        itemsPerPageSelect.addEventListener("change", () => {
            itemsPerPage = parseInt(itemsPerPageSelect.value, 10);
            currentPage = 1; // Reset to first page
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

        // Initial render
        renderList();
        
        // --- Existing QR and Copy Logic (no changes needed) ---
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
    });
    </script>
</body>
</html>';

if (file_put_contents($outputHtmlFile, $htmlOutputContent)) {
    echo "Successfully wrote new HTML output with pagination to '$outputHtmlFile'\n";
} else {
    echo "Error: Could not write HTML to '$outputHtmlFile'\n";
}

$consoleOutput = ob_get_clean();
echo $consoleOutput;
echo "--- Script Finished ---\n";
?>