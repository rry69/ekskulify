<?php
/**
 * api/sre/health.php — Health check live/ready + status page untuk admin.
 * Zero-setup shared hosting: hasil ready mentok di-cache ke temp dir
 * (sys_get_temp_dir()/sre_health_ready.json, flock) supaya tidak menekan
 * DB per-request. Status 'down' TIDAK di-cache lebih dari 5 detik agar
 * pemulihan cepat terdeteksi.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/circuit.php';
require_once __DIR__ . '/backup.php'; // untuk sre_pdo_connect()

/**
 * Liveness: tanpa I/O apa pun — halaman/tooling cek "prosesnya hidup?".
 */
function sre_health_live(): array {
  return ['status' => 'ok', 'ts' => time()];
}

/**
 * Path cache readiness (temp dir, di luar repo).
 */
function sre_health_cache_path(): string {
  return sys_get_temp_dir() . '/sre_health_ready.json';
}

/**
 * Cek ketersediaan DB: koneksi segar SHORT-timeout + SELECT 1. Sengaja TIDAK
 * memakai pdo() project (yang memicu ensureAll/DDL) dan TIDAK memicu backup.
 */
function sre_pdo_available(): bool {
  $pdo = sre_pdo_connect(2, false, false);
  if (!$pdo) return false;
  try {
    $pdo->query('SELECT 1');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Readiness: db + storage (uploads/logs/backups) + sessions writable.
 * Return {status, checks, latency_ms}. Hasil di-cache (flock) dengan aturan:
 * status ok/degraded -> health_cache_sec detik; status down -> maks 5 detik.
 */
function sre_health_ready(): array {
  $tt = max(1, (int)sre_sec('health_cache_sec', 15));
  $cache = sre_health_cache_path();

  // Baca cache (LOCK_SH) — balas langsung bila masih segar.
  $fh = @fopen($cache, 'c+');
  if ($fh) {
    @flock($fh, LOCK_SH);
    $raw = @stream_get_contents($fh);
    @flock($fh, LOCK_UN);
    @fclose($fh);
    $c = $raw ? @json_decode($raw, true) : null;
    if (is_array($c) && isset($c['checks'])) {
      $age   = time() - (int)($c['_cached_at'] ?? 0);
      $maxAge = ($c['status'] === 'down') ? min(5, $tt) : $tt;
      if ($age >= 0 && $age < $maxAge) {
        return ['status' => $c['status'], 'checks' => $c['checks'], 'latency_ms' => (int)($c['latency_ms'] ?? 0), 'cached' => true];
      }
    }
  }

  $t0 = microtime(true);
  $db = sre_pdo_available();
  $storage = true;
  foreach (['uploads', 'logs', 'backups'] as $sub) {
    $p = __DIR__ . '/../' . $sub;
    if (!is_dir($p)) @mkdir($p, 0755, true); // backups boleh belum ada -> auto-mkdir
    if (!is_dir($p) || !is_writable($p)) $storage = false;
  }
  $sessions = is_writable(__DIR__ . '/../sessions');
  $latency  = (int)((microtime(true) - $t0) * 1000);
  $status   = ($db && $storage && $sessions) ? 'ok' : ($db ? 'degraded' : 'down');

  $data = ['status' => $status, 'checks' => ['db' => $db, 'storage' => $storage, 'sessions' => $sessions], 'latency_ms' => $latency, '_cached_at' => time()];
  $fh = @fopen($cache, 'c+');
  if ($fh) {
    @flock($fh, LOCK_EX);
    @ftruncate($fh, 0);
    @rewind($fh);
    @fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @fflush($fh);
    @flock($fh, LOCK_UN);
    @fclose($fh);
  }
  unset($data['_cached_at']); // internal cache: jangan bocor ke body publik
  return $data;
}

/**
 * Halaman status admin: gabung live + ready + metrics + circuit states +
 * info backup terakhir. Sangat ringan (semua file + flock, 1 koneksi DB
 * pendek via ready yang sudah di-cache).
 */
function sre_health_status_page(): array {
  $circuits = [];
  foreach (glob(sre_logs_dir() . '/circuit_*.json') ?: [] as $f) {
    $svc = preg_replace('/^circuit_|\.json$/', '', basename($f));
    if ($svc !== '') $circuits[$svc] = sre_circuit_state($svc);
  }
  $backup = null;
  $bFile  = __DIR__ . '/../backups/last_backup.json';
  if (is_file($bFile) && ($raw = @file_get_contents($bFile)) !== false) {
    $j = @json_decode($raw, true);
    if (is_array($j)) $backup = $j;
  }
  return [
    'status'        => 'ok',
    'generated_at'  => date('c'),
    'request_id'    => sre_log_req_id(),
    'live'          => sre_health_live(),
    'ready'         => sre_health_ready(),
    'metrics'       => sre_metrics_snapshot(),
    'circuits'      => $circuits,
    'last_backup'   => $backup,
  ];
}