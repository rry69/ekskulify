<?php
/**
 * api/sre/index.php — Router mini untuk modul SRE. Bukan entry point publik:
 * dipakai integrasi via `require` dari index.php utama ATAU dipanggil saat
 * route `/api/...` cocok. sre_handle_route() mengembalikan null bila path
 * bukan milik SRE (biarkan router utama yang menangani).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/circuit.php';
require_once __DIR__ . '/backup.php';

/**
 * Normalisasi path: buang prefix /api (sama dengan index.php utama), jadikan
 * "/path" kanonik. Contoh: "/api/health/live" menjadi "/health/live".
 */
function sre_normalize_path(string $path): string {
  $p = (string)$path;
  if ($p === '') $p = '/';
  if (strpos($p, '/api') === 0) $p = substr($p, 4);
  $p = '/' . trim($p, '/');
  return $p === '/' ? '/' : $p;
}

/**
 * Handle route SRE. Return:
 *   null — path bukan milik SRE (lanjutkan ke router utama),
 *   true — sudah di-handle + respons terkirim (fungsi exit internal).
 * Route:
 *   GET /health/live  -> 200 sre_health_live()
 *   GET /health/ready -> 200|503 sre_health_ready() (503 bila tidak ok)
 *   GET /sre/status   -> 401 tidak login, 403 bukan admin, else status page
 */
function sre_handle_route(string $method, string $path): ?bool {
  $method = strtoupper($method);
  $p = sre_normalize_path($path);

  if ($method === 'GET' && $p === '/health/live') {
    sre_http_json(200, sre_health_live());
    return true;
  }
  if ($method === 'GET' && $p === '/health/ready') {
    if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'admin')) {
      sre_http_json(($_SESSION['user'] ?? false) ? 403 : 401, ['error' => ($_SESSION['user'] ?? false) ? 'Akses ditolak' : 'Login dulu', 'code' => ($_SESSION['user'] ?? false) ? 'FORBIDDEN' : 'UNAUTHORIZED', 'request_id' => sre_req_id()]);
    }
    $r = sre_health_ready();
    sre_http_json($r['status'] === 'ok' ? 200 : 503, $r);
    return true;
  }
  if ($method === 'GET' && $p === '/sre/status') {
    if (empty($_SESSION['user'])) {
      sre_http_json(401, ['error' => 'Login dulu', 'code' => 'UNAUTHORIZED', 'request_id' => sre_req_id()]);
    }
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
      sre_http_json(403, ['error' => 'Akses ditolak', 'code' => 'FORBIDDEN', 'request_id' => sre_req_id()]);
    }
    sre_http_json(200, sre_health_status_page());
    return true;
  }
  // POST /sre/backup — manual trigger backup (admin only, CSRF + rate limit)
  if ($method === 'POST' && $p === '/sre/backup') {
    if (empty($_SESSION['user'])) {
      sre_http_json(401, ['error' => 'Login dulu', 'code' => 'UNAUTHORIZED', 'request_id' => sre_req_id()]);
    }
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
      sre_http_json(403, ['error' => 'Akses ditolak', 'code' => 'FORBIDDEN', 'request_id' => sre_req_id()]);
    }
    if (function_exists('csrfCheck')) {
      csrfCheck(); // dari helpers.php (index.php) — 403 CSRF_INVALID bila token salah
    } else {
      sre_http_json(403, ['error' => 'CSRF tidak dapat diverifikasi', 'code' => 'CSRF_INVALID', 'request_id' => sre_req_id()]);
    }
    if (!sre_backup_rate_limit((int)($_SESSION['user']['id'] ?? 0))) {
      sre_http_json(429, ['error' => 'Backup manual dibatasi 1x per 10 menit', 'code' => 'RATE_LIMITED', 'request_id' => sre_req_id()]);
    }
    $result = sre_backup_run();
    $result['retention'] = sre_backup_retention();
    if (function_exists('jsonOut')) {
      jsonOut(['success' => true, 'data' => $result]);
    }
    sre_http_json(200, ['success' => true, 'data' => $result]);
    return true;
  }
  return null;
}

/**
 * Rate limit manual backup: 1x per 10 menit per user. State file di
 * sys_get_temp_dir() (di luar repo). Menggunakan flock; fail-open bila
 * temp dir tidak writable (tidak memblokir admin).
 */
function sre_backup_rate_limit(int $userId): bool {
  $file = sys_get_temp_dir() . '/sre_backup_manual_' . $userId . '.json';
  $fh   = @fopen($file, 'c+');
  if (!$fh) return true; // fail-open
  @flock($fh, LOCK_EX);
  $raw = @stream_get_contents($fh);
  $j   = $raw ? @json_decode($raw, true) : null;
  $last = (int)(is_array($j) ? ($j['last_ts'] ?? 0) : 0);
  $now  = time();
  if ($now - $last < 600) {
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return false;
  }
  @ftruncate($fh, 0);
  @rewind($fh);
  @fwrite($fh, json_encode(['last_ts' => $now], JSON_UNESCAPED_UNICODE));
  @fflush($fh);
  @flock($fh, LOCK_UN);
  @fclose($fh);
  return true;
}