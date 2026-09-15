<?php
/**
 * api/sre/tick.php — Housekeeping traffic-triggered (Opsi A, TANPA cron).
 * Dipanggil dari index.php pada sebagian request (sampled). Tiap error yang
 * terjadi di sini DITANGKAP & di-log — tidak boleh pernah throw ke request.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/health.php';

/**
 * Path state last-tick (api/logs/last_tick.json).
 */
function sre_tick_file(): string {
  return sre_logs_dir() . '/last_tick.json';
}

/**
 * File lock non-blocking untuk backup (mencegah backup dobel saat konkuren).
 */
function sre_tick_guard_file(): string {
  return sre_logs_dir() . '/.sre_backup.lock';
}

/**
 * Baca state last_tick: {ts, backup_ts} (LOCK_SH) atau [0,0] bila absent.
 */
function sre_tick_read_state(): array {
  $f = sre_tick_file();
  $ts = 0; $backupTs = 0;
  if (is_file($f)) {
    $fh = @fopen($f, 'r');
    if ($fh) {
      @flock($fh, LOCK_SH);
      $raw = @stream_get_contents($fh);
      @flock($fh, LOCK_UN);
      @fclose($fh);
      $j = $raw ? @json_decode($raw, true) : null;
      if (is_array($j)) { $ts = (int)($j['ts'] ?? 0); $backupTs = (int)($j['backup_ts'] ?? 0); }
    }
  }
  return [$ts, $backupTs];
}

/**
 * Jalankan housekeeping: (1) gate acak OR selalu saat last_tick > 300 detik;
 * (2) rotasi log; (3) backup bila due (+retention, guard non-blocking);
 * (4) invalidasi health cache bila backup berubah. Semua error di-log.
 */
function sre_tick(): array {
  // Jangan jalankan housekeeping saat CLI — skrip cron/standalone bisa memicu
  // backup dobel. Skip diam-diam di sini (guard dipasang dari sre_boot()).
  if (php_sapi_name() === 'cli') {
    return ['ran' => false, 'reason' => 'cli'];
  }
  $now = time();
  try {
    [$last, $prevBackupTs] = sre_tick_read_state();
    $divisor = max(1, (int)sre_sec('tick_sample_divisor', 20));
    $stale   = ($now - $last) > 300;
    $sample  = (mt_rand(1, $divisor) === 1);
    if (!$stale && !$sample) {
      return ['ran' => false, 'reason' => 'gate', 'stale' => $stale, 'sample' => $sample];
    }

    $result = ['ran' => true, 'rotated' => sre_rotate_logs()];

    if (sre_backup_due()) {
      $lk = @fopen(sre_tick_guard_file(), 'c+');
      if ($lk && @flock($lk, LOCK_EX | LOCK_NB)) { // non-blocking: cegah backup dobel
        try {
          $result['backup']     = sre_backup_run();
          $result['retention']  = sre_backup_retention();
        } catch (Throwable $e) {
          sre_log(3, 'sre_tick backup gagal', ['error' => $e->getMessage()]);
          $result['backup_error'] = $e->getMessage();
        } finally {
          @flock($lk, LOCK_UN);
          @fclose($lk);
        }
      } else {
        if ($lk) @fclose($lk);
        $result['backup'] = ['skipped' => 'locked'];
      }
    }

    // update last_tick
    $backupTs = 0;
    $lb = sre_backup_last();
    if (is_array($lb)) $backupTs = (int)($lb['ts'] ?? 0);
    $fh = @fopen(sre_tick_file(), 'c+');
    if ($fh) {
      @flock($fh, LOCK_EX);
      @ftruncate($fh, 0);
      @rewind($fh);
      @fwrite($fh, json_encode(['ts' => $now, 'backup_ts' => $backupTs], JSON_UNESCAPED_UNICODE));
      @fflush($fh);
      @flock($fh, LOCK_UN);
      @fclose($fh);
    }

    // invalidasi health cache bila backup berubah
    if ($backupTs !== $prevBackupTs) {
      $hc = sre_health_cache_path();
      if (is_file($hc)) @unlink($hc);
    }
    return $result;
  } catch (Throwable $e) {
    sre_log(3, 'sre_tick error', ['error' => $e->getMessage()]);
    return ['ran' => false, 'error' => $e->getMessage()];
  }
}