<?php
function ipInCidr($ip, $cidr)
{
    if (strpos($cidr, "/") === false) {
        return $ip === $cidr;
    }

    [$subnet, $bits] = explode("/", $cidr, 2);

    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if (
        $ipBin === false ||
        $subnetBin === false ||
        strlen($ipBin) !== strlen($subnetBin)
    ) {
        return false;
    }

    $bits = (int) $bits;
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($bits, 8);
    $remainingBits = $bits % 8;

    if (
        $fullBytes > 0 &&
        substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)
    ) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    $ipByte = ord($ipBin[$fullBytes]);
    $subnetByte = ord($subnetBin[$fullBytes]);

    return ($ipByte & $mask) === ($subnetByte & $mask);
}

function isTrustedProxy($remoteAddr, $trustedProxyCidrs)
{
    foreach ($trustedProxyCidrs as $cidr) {
        if ($cidr !== "" && ipInCidr($remoteAddr, $cidr)) {
            return true;
        }
    }
    return false;
}

function firstValidIpFromCsv($value)
{
    foreach (explode(",", $value) as $part) {
        $candidate = trim($part);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return null;
}

function resolveClientIp($trustedProxyCidrs)
{
    $remoteAddr = $_SERVER["REMOTE_ADDR"] ?? "";
    if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return "";
    }

    if (!isTrustedProxy($remoteAddr, $trustedProxyCidrs)) {
        return $remoteAddr;
    }

    $cfConnectingIp = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? "";
    if (filter_var($cfConnectingIp, FILTER_VALIDATE_IP)) {
        return $cfConnectingIp;
    }

    $trueClientIp = $_SERVER["HTTP_TRUE_CLIENT_IP"] ?? "";
    if (filter_var($trueClientIp, FILTER_VALIDATE_IP)) {
        return $trueClientIp;
    }

    $xForwardedFor = $_SERVER["HTTP_X_FORWARDED_FOR"] ?? "";
    $xffIp = firstValidIpFromCsv($xForwardedFor);
    if ($xffIp !== null) {
        return $xffIp;
    }

    $xRealIp = $_SERVER["HTTP_X_REAL_IP"] ?? "";
    if (filter_var($xRealIp, FILTER_VALIDATE_IP)) {
        return $xRealIp;
    }

    return $remoteAddr;
}

$trustedProxyConfig = getenv("TRUSTED_PROXIES") ?: "";
$trustedProxyCidrs = array_values(
    array_filter(array_map("trim", explode(",", $trustedProxyConfig))),
);
$ip = resolveClientIp($trustedProxyCidrs);

$ua = $_SERVER["HTTP_USER_AGENT"] ?? "";
$is_cli = preg_match("/curl|wget|httpie|fetch/i", $ua);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Surrogate-Control: no-store");

if ($is_cli) {
    header("Content-Type: text/plain");
    echo $ip . "\n";
    exit();
}

$host = gethostbyaddr($ip);
$show_host = $host !== $ip;

function extractPrefix($str)
{
    if (filter_var($str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $blocks = explode(":", $str);
        $blocks = array_pad($blocks, 8, "0000");
        return implode(":", array_slice($blocks, 0, 4));
    } elseif (filter_var($str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $octets = explode(".", $str);
        return implode(".", array_slice($octets, 0, 3));
    }
}

function hueFromString($str, $offset = 0)
{
    $prefix = extractPrefix($str);
    $hash = sha1($prefix . $offset);
    return hexdec(substr($hash, 0, 6)) % 360;
}

$hue1 = hueFromString($ip, 0);
$hue2 = ($hue1 + 60) % 360;
$hueMid = round(($hue1 + $hue2) / 2);

$gradStart = "hsl($hue1, 100%, 60%)";
$gradMid = "hsl($hueMid, 100%, 60%)";
$gradEnd = "hsl($hue2, 100%, 60%)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Your IP Address</title>
<style>
:root {
  --grad-start: <?= $gradStart ?>;
  --grad-mid: <?= $gradMid ?>;
  --grad-end: <?= $gradEnd ?>;
}
html, body {
  height: 100%;
  margin: 0;
  background: #111;
  display: flex;
  justify-content: center;
  align-items: center;
  font-family: Impact, 'Arial Black', sans-serif;
  overflow: hidden;
  color: #fff;
}
.info {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1em;
}
.fit-wrapper {
  width: 90vw;
  display: flex;
  justify-content: center;
  overflow: hidden;
}
.gradient-text {
  background: linear-gradient(270deg, var(--grad-start), var(--grad-mid), var(--grad-end), var(--grad-mid), var(--grad-start));
  background-size: 800% 800%;
  animation: moveGradient 20s ease-in-out infinite;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  text-fill-color: transparent;
  white-space: nowrap;
}
.copy-control {
  cursor: pointer;
}
.copy-control:focus-visible {
  outline: 2px solid var(--grad-mid);
  outline-offset: 8px;
  border-radius: 6px;
}
.host-style { font-size: 5em; }
.ip-style   { font-size: 2em; }
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (prefers-reduced-motion: reduce) {
  .gradient-text,
  .help-box {
    animation: none !important;
  }
  .progress-bar {
    transition: none !important;
  }
}

@keyframes moveGradient {
  0%   { background-position: 0% 50%; }
  50%  { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

/* Progress Bars */
.progress-bar {
  position: fixed;
  z-index: 9999;
  transition: width 0.4s ease, height 0.4s ease, opacity 0.4s ease;
  animation-fill-mode: forwards;
}
#progress-bar-top {
  top: 0; left: 0;
  width: 0%; height: 4px;
  background: linear-gradient(to right, var(--grad-start), var(--grad-end));
}
#progress-bar-bottom {
  bottom: 0; right: 0;
  width: 0%; height: 4px;
  background: linear-gradient(to left, var(--grad-start), var(--grad-end));
}
#progress-bar-left {
  bottom: 0; left: 0;
  width: 4px; height: 0%;
  background: linear-gradient(to top, var(--grad-start), var(--grad-end));
}
#progress-bar-right {
  top: 0; right: 0;
  width: 4px; height: 0%;
  background: linear-gradient(to bottom, var(--grad-start), var(--grad-end));
}

/* Help Overlay */
#help-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(12px);
  display: none;
  justify-content: center;
  align-items: center;
  z-index: 1000;
}
#help-overlay.show {
  display: flex;
}
.help-box {
  background: #111c;
  padding: 2em 3em;
  border-radius: 12px;
  border: 2px solid;
  border-image: linear-gradient(to right, var(--grad-start), var(--grad-mid), var(--grad-end)) 1;
  font-family: sans-serif;
  color: transparent;
  background-clip: text;
  -webkit-background-clip: text;
  background-image: linear-gradient(270deg, var(--grad-start), var(--grad-mid), var(--grad-end), var(--grad-mid), var(--grad-start));
  background-size: 800% 800%;
  animation: moveGradient 20s ease-in-out infinite;
}
.help-box h2 {
  font-size: 1.5em;
  margin-top: 0;
  text-align: center;
}
.help-box ul {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5em;
}
.help-box li {
  font-size: 1em;
  display: flex;
  align-items: center;
}
kbd {
  background: #222;
  padding: 0.2em 0.6em;
  border-radius: 5px;
  margin-right: 0.6em;
  font-weight: bold;
  color: #fff;
}
</style>
</head>
<body>

<!-- Progress Bars -->
<div id="progress-bar-top" class="progress-bar"></div>
<div id="progress-bar-bottom" class="progress-bar"></div>
<div id="progress-bar-left" class="progress-bar"></div>
<div id="progress-bar-right" class="progress-bar"></div>

<div class="info">
<?php if ($show_host): ?>
  <div class="fit-wrapper">
    <div
      id="host"
      class="gradient-text host-style copy-control"
      data-copy="<?= htmlspecialchars($host, ENT_QUOTES) ?>"
      role="button"
      tabindex="0"
      aria-label="Copy hostname to clipboard"
      onclick="copyWithFeedback(this)"
      onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); copyWithFeedback(this); }">
      <?= htmlspecialchars($host) ?>
    </div>
  </div>
  <div class="fit-wrapper">
    <div
      id="ip"
      class="gradient-text ip-style copy-control"
      data-copy="<?= htmlspecialchars($ip, ENT_QUOTES) ?>"
      role="button"
      tabindex="0"
      aria-label="Copy IP address to clipboard"
      onclick="copyWithFeedback(this)"
      onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); copyWithFeedback(this); }">
      <?= htmlspecialchars($ip) ?>
    </div>
  </div>
<?php else: ?>
  <div class="fit-wrapper">
    <div
      id="host"
      class="gradient-text host-style copy-control"
      data-copy="<?= htmlspecialchars($ip, ENT_QUOTES) ?>"
      role="button"
      tabindex="0"
      aria-label="Copy IP address to clipboard"
      onclick="copyWithFeedback(this)"
      onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); copyWithFeedback(this); }">
      <?= htmlspecialchars($ip) ?>
    </div>
  </div>
<?php endif; ?>
</div>
<div id="copy-status" class="sr-only" aria-live="polite"></div>

<!-- Help Overlay -->
<div id="help-overlay">
  <div class="help-box">
    <h2>Keyboard Shortcuts</h2>
    <ul>
      <li><kbd>w</kbd> - iplocation.io whois</li>
      <li><kbd>p</kbd> - iplocation.io ping</li>
      <li><kbd>l</kbd> - networksdb.io location</li>
      <li><kbd>s</kbd> - Cloudflare Speed Test</li>
      <li><kbd>b</kbd> - MX Toolbox Blacklist Test</li>
      <li><kbd>j</kbd> - JavaScript Browser Information</li>
      <li><kbd>t</kbd> - Test IPv6</li>
      <li><kbd>r</kbd> - Reload</li>
      <li><kbd>?</kbd> or <kbd>h</kbd> - Toggle Help</li>
    </ul>
  </div>
</div>

<script>
function fallbackCopyText(text) {
  const ta = document.createElement("textarea");
  ta.value = text;
  ta.setAttribute("readonly", "");
  ta.style.position = "fixed";
  ta.style.top = "-9999px";
  ta.style.left = "-9999px";
  document.body.appendChild(ta);
  ta.focus();
  ta.select();

  let ok = false;
  try {
    ok = document.execCommand("copy");
  } catch (_) {
    ok = false;
  }

  document.body.removeChild(ta);
  return ok;
}

async function writeClipboardText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (_) {
      return fallbackCopyText(text);
    }
  }
  return fallbackCopyText(text);
}

function announceStatus(message) {
  const status = document.getElementById("copy-status");
  if (!status) return;
  status.textContent = "";
  requestAnimationFrame(() => {
    status.textContent = message;
  });
}

function showCopyFeedback(el, success) {
  const original = el.innerText;
  el.innerText = success ? "Copied" : "Copy Failed";
  setTimeout(() => {
    el.innerText = original;
  }, 1000);
}

async function copyWithFeedback(el) {
  const text = el.getAttribute("data-copy") || el.innerText;
  const success = await writeClipboardText(text);
  showCopyFeedback(el, success);
  announceStatus(success ? "Copied to clipboard" : "Failed to copy to clipboard");
}

function scaleToFit(el) {
  const parent = el.parentElement;
  const scale = parent.offsetWidth / el.scrollWidth;
  el.style.transform = `scale(${Math.min(scale, 1)})`;
}

// Progress bar animation
const bars = ["top", "bottom", "left", "right"].map(pos =>
  document.getElementById("progress-bar-" + pos)
);
const increment = 1;
const maxProgress = 100;
let progress = 0;

const interval = setInterval(() => {
  progress += increment;
  if (progress >= maxProgress) {
    progress = maxProgress;
    clearInterval(interval);
  }

  bars[0].style.width = progress + '%';
  bars[1].style.width = progress + '%';
  bars[2].style.height = progress + '%';
  bars[3].style.height = progress + '%';
}, 100);

window.addEventListener("load", () => {
  clearInterval(interval);
  bars[0].style.width = "100%";
  bars[1].style.width = "100%";
  bars[2].style.height = "100%";
  bars[3].style.height = "100%";
  setTimeout(() => bars.forEach(b => b.style.opacity = "0"), 300);
  setTimeout(() => bars.forEach(b => b.remove()), 800);

  const hostEl = document.getElementById("host");
  const ipEl = document.getElementById("ip");
  if (hostEl) scaleToFit(hostEl);
  if (ipEl) scaleToFit(ipEl);
});

const ipValue = "<?= htmlspecialchars($ip) ?>";
document.addEventListener("keydown", function(e) {
  const key = e.key.toLowerCase();
  if (key === "w") {
    window.open(`https://iplocation.io/ip-whois-lookup/${ipValue}`, "_blank");
  } else if (key === "p") {
    window.open(`https://iplocation.io/ping/${ipValue}`, "_blank");
  } else if (key === "l") {
    window.open(`https://networksdb.io/ip/${ipValue}`, "_blank");
  } else if (key === "s") {
    window.open("https://speed.cloudflare.com/", "_blank");
  } else if (key === "b") {
    window.open(`https://mxtoolbox.com/SuperTool.aspx?action=blacklist%3a${ipValue}&run=toolpage`, "_blank");
  } else if (key === "j") {
    window.open("https://browserleaks.com/javascript", "_blank");
  } else if (key === "t") {
    window.open("https://test-ipv6.com/", "_blank");
  } else if (key === "r") {
    location.reload();
  } else if (key === "?" || key === "h") {
    const help = document.getElementById("help-overlay");
    if (help) help.classList.toggle("show");
  }
});
</script>
</body>
</html>
