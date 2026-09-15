<?php
/**
 * api/sre/metrics.php — Metrik agregat ringan berbasis file + flock.
 * Zero-setup shared hosting: state disimpan di api/logs/metrics.json
 * (window berjalan + history maks 60 window), dibaca/ditulis dengan flock.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';

/**
 * Path file metrik (api/logs/metrics.json).
 */
function sre_metrics_file(): string {
  return sre_logs_dir() . '/metrics.json';
}

/**
 * Bin histogram 100ms: 0-100, 100-300, 300-600, 600-1000, >1000.
 */
function sre_metrics_bucket_for(float $ms): string {
  if ($ms < 100)    return 'lt100';
  if ($ms < 300)    return 'lt300';
  if ($ms < 600)    return 'lt600';
  if ($ms < 1000)   return 'lt1000';
  return 'gte1000';
}

/**
 * Keranjang histogram kosong (struktur tetap, supaya JSON konsisten).
 */
function sre_metrics_empty_buckets(): array {
  return ['lt100' => 0, 'lt300' => 0, 'lt600' => 0, 'lt1000' => 0, 'gte1000' => 0];
}

/**
 * Struktur window baru yang bersih.
 */
function sre_metrics_new_window(int $now): array {
  return [
    'window_start' => $now,
    'requests'     => 0,
    'by_status'    => ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0],
    'sum_ms'       => 0,
    'buckets'      => sre_metrics_empty_buckets(),
    'bytes'        => 0,
    'history'      => [],
  ];
}

/**
 * Normalisasi status class: '200'->'2xx', '4xx'->'4xx'. Nilai tak dikenal
 * dianggap kelas 5xx (lebih baik terlihat daripada hilang dari metrik).
 */
function sre_metrics_status_class(string $status_class): string {
  $sc = preg_replace('/^(\d)\d{0,2}$/', '$1xx', trim($status_class));
  $sc = strtolower($sc);
  if (!isset(['2xx' => 1, '3xx' => 1, '4xx' => 1, '5xx' => 1][$sc])) $sc = '5xx';
  return $sc;
}

/**
 * Buka file metrik dengan flock (LOCK_EX untuk write, LOCK_SH untuk read).
 * Return [fh|null, window|null, path]. Destructure lalu cek $fh.
 */
function sre_metrics_lock(bool $forWrite): array {
  $file = sre_metrics_file();
  $fh   = @fopen($file, 'c+');
  if (!$fh) return [null, null, null];
  @flock($fh, $forWrite ? LOCK_EX : LOCK_SH);
  $raw = @stream_get_contents($fh);
  $j   = $raw ? @json_decode($raw, true) : null;
  if (!is_array($j)) $j = sre_metrics_new_window(time());
  return [$fh, $j, $file];
}

/**
 * Tutup lock (tulis ulang bila $write) + fclose (unlock).
 */
function sre_metrics_unlock($fh, array $j, string $file, bool $write): void {
  if ($write) {
    @ftruncate($fh, 0);
    @rewind($fh);
    @fwrite($fh, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @fflush($fh);
  }
  @flock($fh, LOCK_UN);
  @fclose($fh);
}

/**
 * Bila window berjalan sudah lebih dari 60 detik: simpan ke history
 * (maks 60 entry, hapus yang tertua) lalu mulai window baru.
 */
function sre_metrics_reset_if_stale(array &$j, int $now): void {
  $start = (int)($j['window_start'] ?? 0);
  if ($start > 0 && $now - $start <= 60) return;
  $hist  = (array)($j['history'] ?? []);
  $hist[] = [
    'window_start' => $start > 0 ? $start : $now - 60,
    'end'          => $now,
    'requests'     => (int)($j['requests'] ?? 0),
    'error_5xx'    => (int)(($j['by_status'] ?? [])['5xx'] ?? 0),
    'sum_ms'       => (int)($j['sum_ms'] ?? 0),
    'bytes'        => (float)($j['bytes'] ?? 0),
    'buckets'      => $j['buckets'] ?? sre_metrics_empty_buckets(),
  ];
  if (count($hist) > 60) $hist = array_slice($hist, -60);
  $j = sre_metrics_new_window($now);
  $j['history'] = $hist;
}

/**
 * Increment metrik satu request. Dipanggil dari integrasi index.php
 * setelah respons dikirim (atau sebelum exit) — biaya kecil ~1 file flock.
 */
function sre_metrics_incr(string $status_class, int $duration_ms, float $bytes): void {
  [$fh, $j, $file] = sre_metrics_lock(true);
  if (!$fh) return;
  $now = time();
  sre_metrics_reset_if_stale($j, $now);
  $sc = sre_metrics_status_class($status_class);
  $j['requests']++;
  $j['by_status'][$sc]++;
  $j['sum_ms'] += $duration_ms;
  $j['bytes']  += $bytes;
  $j['buckets'][sre_metrics_bucket_for($duration_ms)]++;
  sre_metrics_unlock($fh, $j, $file, true);
}

/**
 * Ringkasan window berjalan. Bila window sudah kedaluwarsa, statistik window
 * yang baru selesai dikembalikan, lantas disimpan ke history & window direset.
 */
function sre_metrics_snapshot(): array {
  [$fh, $j, $file] = sre_metrics_lock(false);
  if (!$fh) {
    return ['requests' => 0, 'error_rate_5xx' => 0.0, 'avg_ms' => 0.0, 'p95_ms' => null, 'window_sec' => 0];
  }
  $now         = time();
  $start       = (int)($j['window_start'] ?? 0);
  $stale       = ($start <= 0 || $now - $start > 60);
  $req         = (int)$j['requests'];
  $stats       = [
    'requests'      => $req,
    'error_rate_5xx'=> $req > 0 ? round(((int)($j['by_status']['5xx'] ?? 0)) / $req, 4) : 0.0,
    'avg_ms'        => $req > 0 ? round(((int)($j['sum_ms'] ?? 0)) / $req, 2) : 0.0,
    'p95_ms'        => sre_metrics_p95($j['buckets'] ?? sre_metrics_empty_buckets(), $req),
    'window_sec'    => max(1, $now - $start),
  ];
  if ($stale) {
    sre_metrics_reset_if_stale($j, $now);
    sre_metrics_unlock($fh, $j, $file, true);
  } else {
    sre_metrics_unlock($fh, $j, $file, false);
  }
  return $stats;
}

/**
 * Estimasi p95 (ms) via interpolasi linear di histogram. Untuk bin terbuka
 * (>1000) estimasi lebar bin memakai lebar bin sebelumnya (atau 300).
 */
function sre_metrics_p95(array $buckets, int $total): ?float {
  if ($total <= 0) return null;
  $order = [
    ['lt100', 0, 100],
    ['lt300', 100, 300],
    ['lt600', 300, 600],
    ['lt1000', 600, 1000],
    ['gte1000', 1000, null],
  ];
  $target = $total * 0.95;
  $cum    = 0;
  foreach ($order as [$key, $lo, $hi]) {
    $n = (int)($buckets[$key] ?? 0);
    if ($n <= 0) continue;
    $cum += $n;
    if ($cum >= $target || $key === 'gte1000') {
      $frac = $n > 0 ? ($target - ($cum - $n)) / $n : 0.0;
      $frac = max(0.0, min(1.0, $frac));
      if ($hi === null) {
        $width = $lo > 300 ? $lo : 300;
        $hi    = $lo + $width;
      }
      return round($lo + ($hi - $lo) * $frac, 2);
    }
  }
  return 1000.0;
}

/**
 * Helper alert: jumlah 5xx di window berjalan (reset bila window kedaluwarsa).
 */
function sre_metrics_hist_5xx(): int {
  [$fh, $j, $file] = sre_metrics_lock(false);
  if (!$fh) return 0;
  $now   = time();
  $start = (int)($j['window_start'] ?? 0);
  if ($start > 0 && $now - $start > 60) {
    sre_metrics_reset_if_stale($j, $now);
    sre_metrics_unlock($fh, $j, $file, true);
    $cnt = 0;
  } else {
    $cnt = (int)($j['by_status']['5xx'] ?? 0);
    sre_metrics_unlock($fh, $j, $file, false);
  }
  return $cnt;
}

/**
 * Catat metrik satu request (status class + durasi) pada SHUTDOWN.
 * Didaftarkan dari sre_boot() via register_shutdown_function (urutan LIFO:
 * jalan PALING AWAL sebelum sre_tick). Skip saat CLI; tidak pernah throw —
 * metrik gagal tidak boleh mematikan request.
 */
function sre_request_log(): void {
  if (php_sapi_name() === 'cli') return;
  try {
    $start = defined('SRE_REQ_START') ? SRE_REQ_START : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $dur   = (microtime(true) - $start) * 1000;
    $status = http_response_code() ?: 200;
    $statusClass = sre_metrics_status_class((string)$status);
    sre_metrics_incr($statusClass, (int)$dur, 0);
    if ($statusClass === '5xx') {
      $count = sre_metrics_hist_5xx();
      if ($count >= 10) sre_alert_on_5xx_spike($count);
    }
  } catch (Throwable $e) {
    // JANGAN mematikan request karena metrik/alert gagal — cukup log debug.
    sre_log(1, 'sre_request_log skipped', ['error' => $e->getMessage()]);
  }
}