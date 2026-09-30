<?php
session_start();
if (!file_exists(__DIR__ . '/installed.lock')) {
  $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
  $base = preg_replace('#/admin$#', '', $base);
  header('Location: ' . $base . '/install/'); exit;
}
$CFG = require __DIR__ . '/config.php';

function db(): PDO {
  static $pdo; global $CFG;
  if (!$pdo) $pdo = new PDO("mysql:host={$CFG['db_host']};dbname={$CFG['db_name']};charset=utf8mb4",
    $CFG['db_user'], $CFG['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  return $pdo;
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// AES-256-GCM (নোড ভার্সনের মতোই: key = sha256(secret), iv(12)+cipher+tag, base64)
function enc_key() { global $CFG; return hash('sha256', $CFG['encryption_key'], true); }
function encrypt_secret(string $plain): string {
  $iv = random_bytes(12); $tag = '';
  $c = openssl_encrypt($plain, 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, $iv, $tag);
  return base64_encode($iv . $c . $tag);
}
function decrypt_secret(string $stored): string {
  $raw = base64_decode($stored);
  $iv = substr($raw, 0, 12); $tag = substr($raw, -16); $c = substr($raw, 12, -16);
  $p = openssl_decrypt($c, 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, $iv, $tag);
  if ($p === false) throw new Exception('decrypt_failed');
  return $p;
}

function log_activity($email, $action, $detail = '') {
  db()->prepare('INSERT INTO activity_log (email, action, detail) VALUES (?,?,?)')->execute([$email, $action, $detail]);
}

function rate_limited(): bool {
  global $CFG;
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'x'; $k = date('YmdHi');
  db()->prepare('INSERT INTO rate_limits (ip, minute_key, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits=hits+1')->execute([$ip, $k]);
  $s = db()->prepare('SELECT hits FROM rate_limits WHERE ip=? AND minute_key=?'); $s->execute([$ip, $k]);
  if (mt_rand(1, 50) === 1) db()->exec("DELETE FROM rate_limits WHERE minute_key < '" . date('YmdHi', time() - 600) . "'");
  return (int)$s->fetchColumn() > $CFG['rate_limit_per_min'];
}

function sanitize_messages($raw): array {
  if (!is_array($raw)) return [];
  $out = [];
  foreach ($raw as $i => $m) {
    if (!is_array($m)) continue;
    $code = trim((string)($m['code'] ?? ''));
    $out[] = [
      'uid' => trim((string)($m['uid'] ?? '')) ?: "msg-$i",
      'from' => trim((string)($m['from'] ?? '')) ?: 'Unknown sender',
      'subject' => trim((string)($m['subject'] ?? '')) ?: '(no subject)',
      'date' => trim((string)($m['date'] ?? '')),
      'message' => trim((string)($m['message'] ?? '')),
      'code' => $code !== '' && strtolower($code) !== 'null' ? $code : null,
    ];
  }
  return $out;
}

function fetch_mailgen(array $mb): array {
  global $CFG;
  try {
    $payload = implode('|', [$mb['email'], decrypt_secret($mb['enc_refresh_token']), decrypt_secret($mb['enc_client_id']), decrypt_secret($mb['enc_client_secret'])]);
  } catch (Exception $e) {
    return ['ok' => false, 'reason' => 'Stored credentials could not be read. Please re-enter them in the admin panel.', 'detail' => 'decrypt_failed', 'credential' => true];
  }
  $status = 0; $body = null;
  for ($a = 0; $a < 4; $a++) {
    $ch = curl_init($CFG['mailgen_endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_POSTFIELDS => json_encode(['data' => $payload, 'mode' => 'oauth'])]);
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_errno($ch); curl_close($ch);
    if ($err === 28) return ['ok' => false, 'reason' => 'The mail service took too long to respond. Please try again.', 'detail' => 'timeout'];
    if (!in_array($status, [0, 520, 521, 522, 524, 525, 526])) break;
    usleep(400000 * ($a + 1));
  }
  if ($status < 200 || $status >= 300) return ['ok' => false, 'reason' => 'The mail service is currently unavailable. Please try again shortly.', 'detail' => "http_$status"];
  $j = json_decode($body, true);
  if (!is_array($j)) return ['ok' => false, 'reason' => 'The mail service returned an unreadable response.', 'detail' => 'invalid_json'];
  $perr = trim((string)($j['error'] ?? ''));
  if (($j['success'] ?? null) === false || strtoupper((string)($j['status'] ?? '')) === 'FAILED' || ($perr !== '' && strtolower($perr) !== 'null'))
    return ['ok' => false, 'reason' => 'The mailbox could not be opened. The stored credentials may no longer be valid.', 'detail' => 'provider_error', 'credential' => true];
  return ['ok' => true, 'messages' => sanitize_messages($j['messages'] ?? [])];
}

function load_inbox(string $email): array {
  global $CFG;
  $s = db()->prepare("SELECT * FROM mailboxes WHERE email=? AND status='active'"); $s->execute([strtolower(trim($email))]);
  $mb = $s->fetch();
  if (!$mb) return ['ok' => false, 'reason' => 'Mailbox not available.'];
  $r = fetch_mailgen($mb);
  if ($r['ok']) {
    db()->prepare('REPLACE INTO mail_cache (mailbox_id, messages_json, fetched_at) VALUES (?,?,NOW())')->execute([$mb['id'], json_encode($r['messages'])]);
    db()->prepare('UPDATE mailboxes SET last_checked_at=NOW() WHERE id=?')->execute([$mb['id']]);
    log_activity($mb['email'], 'fetch_ok', count($r['messages']) . ' messages');
    return ['ok' => true, 'email' => $mb['email'], 'messages' => $r['messages'], 'stale' => false];
  }
  log_activity($mb['email'], 'fetch_fail', $r['detail']);
  // সাময়িক সমস্যায় আগের সংরক্ষিত মেইল দেখাও (ক্রেডেনশিয়াল ভুল হলে নয়)
  if (empty($r['credential'])) {
    $c = db()->prepare("SELECT * FROM mail_cache WHERE mailbox_id=? AND fetched_at > NOW() - INTERVAL ? MINUTE");
    $c->execute([$mb['id'], $CFG['cache_minutes']]);
    if ($row = $c->fetch()) return ['ok' => true, 'email' => $mb['email'], 'messages' => json_decode($row['messages_json'], true), 'stale' => true,
      'warning' => 'Mail service unavailable — showing saved mail from ' . $row['fetched_at']];
  }
  return ['ok' => false, 'reason' => $r['reason']];
}

function is_admin(): bool { return !empty($_SESSION['admin_id']); }
function require_admin() { if (!is_admin()) { header('Location: login.php'); exit; } }
function csrf() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_csrf() { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '-')) { http_response_code(403); exit('Bad request'); } }

function page_head($t) { doc_start($t); }
require_once __DIR__ . '/theme.php';
