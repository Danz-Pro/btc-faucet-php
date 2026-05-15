<?php
/**
 * BTC Faucet Auto Claim 24/7 - Zero Dependency Version
 *
 * GA BUTUH curl, pcntl, posix, readline - pure PHP 7.x+
 *
 * Usage:
 *   php bot.php
 *   php bot.php --lifetime=86400
 */

set_time_limit(0);
ini_set('memory_limit', '64M');
error_reporting(E_ERROR | E_PARSE);

// ============================================================
// CONFIG
// ============================================================
$BASE     = 'https://btc.tonrevenue.space';
$CD_SEC   = 299;
$TICK     = 3;
$MAX_RETR = 5;
$UA       = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

$lifetime = 0;
foreach ($argv ?? [] as $a) {
    if (preg_match('/--lifetime=(\d+)/', $a, $m)) $lifetime = (int)$m[1];
}

// ============================================================
// BANNER
// ============================================================
echo "BTC Faucet Auto Claim 24/7\n";
echo "@fbtc0bot | +0.5 sats/claim\n";
echo "\n";

if (php_sapi_name() !== 'cli') die("CLI only.\n");

// ============================================================
// INPUT INITDATA
// ============================================================
echo "Paste initData: ";
$init = '';

$stdin = fopen('php://stdin', 'r');
if (!$stdin) die("Cannot open stdin.\n");

$read = [$stdin];
$write = $except = null;
$changed = stream_select($read, $write, $except, 0);

if ($changed === false) {
    die("stream_select error.\n");
}

if ($changed > 0) {
    $init = '';
    while (!feof($stdin)) {
        $chunk = fread($stdin, 8192);
        if ($chunk === false || $chunk === '') break;
        $init .= $chunk;
    }
    $init = trim($init);
}

fclose($stdin);

if (empty($init)) {
    echo "Paste initData: ";
    $init = trim(fgets(STDIN) ?: '');
    if (empty($init)) {
        foreach ($argv ?? [] as $a) {
            if (preg_match('/--data=(.+)/', $a, $m)) { $init = $m[1]; break; }
        }
    }
}

if (empty($init)) die("InitData kosong!\n");

if (strpos($init, 'tgWebAppData=') !== false) {
    if (preg_match('/tgWebAppData=(.+?)(?:&tgWebApp|$)/', $init, $m)) {
        $init = urldecode($m[1]);
    }
}

echo "[OK] InitData loaded (" . strlen($init) . " chars)\n";
if ($lifetime > 0) echo "Lifetime: {$lifetime}s (" . round($lifetime/60) . " min)\n";
echo "\n";

// ============================================================
// LOG
// ============================================================
function L(string $msg): void {
    $t = date('H:i:s');
    fwrite(STDERR, "[{$t}] {$msg}\n");
}

// ============================================================
// HTTP POST - tanpa curl, pake file_get_contents
// ============================================================
function post(string $endpoint, array $extra = []): ?array {
    global $BASE, $init, $UA;

    $body = json_encode(array_merge(['initData' => $init], $extra));

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                "Content-Type: application/json",
                "User-Agent: {$UA}",
                'Origin: https://btc.tonrevenue.space',
                'Referer: https://btc.tonrevenue.space/',
                'Accept: application/json',
                'Accept-Encoding: gzip',
                "Content-Length: " . strlen($body),
            ]),
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed'=> true,
        ],
    ];

    $ctx  = stream_context_create($opts);
    $url  = "{$BASE}{$endpoint}";
    $raw  = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        $err = error_get_last();
        $msg = $err['message'] ?? 'connection failed';
        L("  NET: " . substr($msg, 0, 100));
        return null;
    }

    $headers = $http_response_header ?? [];
    foreach ($headers as $h) {
        if (stripos($h, 'Content-Encoding: gzip') !== false) {
            $raw = @gzdecode($raw);
            if ($raw === false) return null;
            break;
        }
    }

    $d = @json_decode($raw, true);
    if (!is_array($d)) {
        $code = '???';
        foreach ($headers as $h) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) { $code = $m[1]; break; }
        }
        L("  JSON: HTTP {$code} | " . substr($raw, 0, 150));
        return null;
    }

    if (isset($d['detail'])) {
        return ['_error' => (string)$d['detail']];
    }

    return $d;
}

// ============================================================
// SLEEP
// ============================================================
function zzz(int $seconds): void {
    $done = 0;
    while ($done < $seconds) {
        sleep(min($TICK, $seconds - $done));
        $done += $TICK;
    }
}

// ============================================================
// CAPTCHA SOLVER
// ============================================================
function solve_captcha(array $challenge): ?string {
    $prompt  = $challenge['prompt'] ?? '';
    $options = $challenge['options'] ?? [];
    if (empty($prompt) || empty($options)) return null;

    $clean = preg_replace('/[^a-zA-Z\s]/', '', strtolower($prompt));
    $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);

    $stops = ['tap','click','the','a','an','select','choose','find','pick','button','icon','image','picture','that','which','is','in','on','of','to','with','this','shown','below','above','and','or'];
    $kws = [];
    foreach ($words as $w) {
        if (strlen($w) > 1 && !in_array($w, $stops)) $kws[] = $w;
    }

    foreach ($options as $opt) {
        $label = strtolower($opt['label'] ?? $opt['id'] ?? '');
        foreach ($kws as $kw) {
            if ($label === $kw || $kw !== '' && strpos($label, $kw) !== false) {
                return $opt['id'] ?? null;
            }
        }
    }

    foreach ($options as $opt) {
        $label = strtolower($opt['label'] ?? '');
        foreach ($kws as $kw) {
            if (function_exists('levenshtein') && $kw !== '' && levenshtein($label, $kw) <= 1) {
                return $opt['id'] ?? null;
            }
        }
    }

    return $options[0]['id'] ?? null;
}

// ============================================================
// MAIN BOT LOOP
// ============================================================
$retries = 0;
$claims  = 0;
$failed  = 0;
$captcha_ok_until = 0;
$t0      = time();

L("Bot started");

while (true) {
    if ($lifetime > 0 && (time() - $t0) >= $lifetime) {
        L("Lifetime reached ({$lifetime}s)");
        break;
    }

    // ---- STEP 1: INIT ----
    L("[1/3] Init...");
    $fp_json = json_encode([
        'version' => '12.5.1', 'platform' => 'android', 'tg_platform' => 'android',
        'language' => 'en', 'theme' => 'dark', 'screen_width' => 1080,
        'screen_height' => 2400, 'online' => true, 'dpr' => 3,
    ]);

    $resp = post('/api/init', ['fingerprint' => $fp_json]);

    if ($resp === null) {
        $retries++;
        $failed++;
        if ($retries >= $MAX_RETR) { L("Max retry, wait 60s..."); $retries = 0; zzz(60); }
        else { $d = 5 * pow(2, min($retries, 5)); L("Retry {$retries}/{$MAX_RETR} in {$d}s"); zzz((int)$d); }
        continue;
    }

    if (isset($resp['_error'])) {
        $msg = $resp['_error'];
        L("  ERR: {$msg}");

        if (stripos($msg, 'cooldown') !== false) {
            if (preg_match('/(\d+)\s*s/', $msg, $m)) {
                $w = (int)$m[1] + 2;
                L("  Cooldown: {$w}s");
                zzz($w);
            } else {
                zzz($CD_SEC);
            }
            $retries = 0;
            continue;
        }

        if (stripos($msg, 'auth') !== false || stripos($msg, 'invalid') !== false || stripos($msg, 'expired') !== false) {
            L("  InitData expired! Bot stop.");
            break;
        }

        $retries++;
        $failed++;
        if ($retries >= $MAX_RETR) { $retries = 0; zzz(60); }
        else { zzz(10); }
        continue;
    }

    // Parse response
    $user     = $resp['user'] ?? [];
    $balance  = (float)($user['balance'] ?? 0);
    $cooldown = (int)($user['cooldown'] ?? 0);
    $risk     = (float)($user['risk_score'] ?? 0);
    $blocked  = !empty($user['is_blocked']);

    if ($blocked) {
        L("  BLOCKED! " . ($user['ban_reason'] ?? ''));
        break;
    }

    L("  OK | Bal: {$balance} | CD: {$cooldown}s | Risk: {$risk}");

    // ---- STEP 2: CAPTCHA ----
    $need_captcha = !empty($user['captcha_required']) && time() >= $captcha_ok_until;

    if ($need_captcha) {
        L("[2/3] Solve captcha...");
        $challenge = $user['captcha_challenge'] ?? null;

        if (!$challenge) {
            $ch_resp = post('/api/captcha/challenge');
            if ($ch_resp && !isset($ch_resp['_error'])) $challenge = $ch_resp;
        }

        if ($challenge) {
            $prompt = $challenge['prompt'] ?? '?';
            $answer = solve_captcha($challenge);
            $cid    = $challenge['challenge_id'] ?? '';

            L("  Q: \"{$prompt}\"");
            if ($answer) {
                L("  A: \"{$answer}\"");
                $v = post('/api/captcha/verify', [
                    'challenge_id' => $cid,
                    'answer'       => $answer,
                ]);

                if ($v && ($v['status'] ?? '') === 'success') {
                    $captcha_ok_until = strtotime($v['captcha_valid_until'] ?? '+6 hours');
                    $vh = round(($captcha_ok_until - time()) / 3600, 1);
                    L("  Captcha OK! Valid {$vh}h");
                } else {
                    $em = $v['_error'] ?? ($v['detail'] ?? 'fail');
                    L("  Captcha fail: {$em}");
                    zzz(30); $failed++; continue;
                }
            } else {
                L("  Cannot solve");
                zzz(30); $failed++; continue;
            }
        } else {
            L("  No challenge");
            zzz(30); $failed++; continue;
        }
    } else {
        $rh = $captcha_ok_until > 0 ? round(($captcha_ok_until - time()) / 3600, 1) : 0;
        L("[2/3] Captcha valid ({$rh}h), skip");
    }

    // ---- STEP 3: CLAIM ----
    if ($cooldown > 0) {
        L("[3/3] CD: {$cooldown}s, wait...");
        zzz($cooldown + 2);
        $retries = 0;
        continue;
    }

    L("[3/3] Claim...");
    $claim = post('/api/claim');

    if ($claim === null) {
        L("  Network error");
        $retries++; $failed++; zzz(15); continue;
    }

    if (isset($claim['_error'])) {
        $msg = $claim['_error'];
        L("  {$msg}");
        if (stripos($msg, 'captcha') !== false) { $captcha_ok_until = 0; zzz(5); continue; }
        if (preg_match('/(\d+)\s*s/', $msg, $m)) { zzz((int)$m[1] + 2); $retries = 0; continue; }
        $retries++; $failed++; zzz(10); continue;
    }

    $session_uid = $claim['session_uid'] ?? '';
    if (empty($session_uid)) {
        L("  No session_uid");
        $retries++; $failed++; zzz(10); continue;
    }

    // ---- STEP 4: CONFIRM ----
    L("[4/4] Confirm...");
    $confirm = post('/api/claim/confirm', [
        'session_uid' => $session_uid,
        'token'       => '',
    ]);

    if ($confirm === null || isset($confirm['_error'])) {
        $msg = $confirm['_error'] ?? 'network error';
        L("  Confirm: {$msg}");
        $failed++; zzz(10); continue;
    }

    // SUCCESS!
    $new_bal = (float)($confirm['new_balance'] ?? $balance);
    $reward  = (float)($confirm['reward'] ?? 0.5);
    $cd      = (int)($confirm['cooldown'] ?? $CD_SEC);
    $claims++;
    $retries = 0;
    $failed  = 0;

    $elapsed = time() - $t0;
    $mem     = round(memory_get_usage(true) / 1048576, 1);
    $rate    = $elapsed > 0 ? round(($claims / $elapsed) * 3600, 1) : 0;

    L("  +{$reward} sats | Bal: {$new_bal} | CD: {$cd}s");
    L("  Stats: {$claims} claims, {$elapsed}s up, {$rate}/hr, {$mem}MB");
    L("  cooldown {$cd}s");
    zzz($cd + 2);

    if ($claims % 10 === 0) @gc_collect_cycles();
}

$elapsed = time() - $t0;
L("Stopped. {$claims} claims, {$elapsed}s\n");
