<?php
/**
 * api/sre/circuit.php — Circuit breaker generik + retry dengan exponential
 * backoff. Zero-setup shared hosting: state per service di api/logs/circuit_{svc}.json
 * (flock request-scope: LOCK_EX untuk write, unlock via fclose; LOCK_SH untuk read).
 * Tidak ada dependensi eksternal — murni file + flock.
 *
 * State flow: closed --(failures>=threshold)--> open --(cooldown)--> half_open
 * --(consecutive_success>=half_open_max)--> closed | --(1 failure)--> open.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/alert.php';

/**
 * Exception khusus saat circuit menolak request (state open / half_open penuh).
 */
class SreCircuitOpenError extends RuntimeException {}

/**
 * Path file state circuit per service (nama service di-sanitasi).
 */
function sre_circuit_file(string $svc): string {
  $s = preg_replace('/[^A-Za-z0-9_\-]/', '', $svc);
  if ($s === '') $s = 'default';
  return sre_logs_dir() . '/circuit_' . $s . '.json';
}

/**
 * State default circuit yang baru (closed, nol analitik).
 */
function sre_circuit_default_state(): array {
  return ['state' => 'closed', 'failures' => 0, 'opened_at' => 0, 'half_open_at' => 0, 'consecutive_success' => 0];
}

/**
 * Baca state circuit (LOCK_SH, file tidak dibuat bila belum ada).
 * Bila korup/rusak -> inisialisasi closed.
 */
function sre_circuit_state(string $svc): array {
  $file = sre_circuit_file($svc);
  $fh   = @fopen($file, 'r');
  if (!$fh) return sre_circuit_default_state();
  @flock($fh, LOCK_SH);
  $raw = @stream_get_contents($fh);
  @flock($fh, LOCK_UN);
  @fclose($fh);
  $j = $raw ? @json_decode($raw, true) : null;
  if (!is_array($j)) return sre_circuit_default_state();
  return array_merge(sre_circuit_default_state(), $j);
}

/**
 * Mutasi state di dalam lock LOCK_EX (buka-tulis-tutup dalam satu fungsi).
 * Fungsi callback menerima [state, now] dan mengembalikan state baru.
 */
function sre_circuit_update(string $svc, callable $fn): array {
  $file = sre_circuit_file($svc);
  $fh   = @fopen($file, 'c+');
  if (!$fh) return sre_circuit_default_state();
  @flock($fh, LOCK_EX);
  $raw = @stream_get_contents($fh);
  $j   = $raw ? @json_decode($raw, true) : null;
  $st  = ((is_array($j) ? $j : []) + sre_circuit_default_state());
  $st  = $fn($st, time());
  @ftruncate($fh, 0);
  @rewind($fh);
  @fwrite($fh, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  @fflush($fh);
  @flock($fh, LOCK_UN);
  @fclose($fh);
  return $st;
}

/**
 * Apakah sebuah request BOLEH masuk? closed=true; open -> false, otomatis
 * transisi ke half_open setelah cooldown tercapai; half_open -> true hanya
 * bila consecutive_success < half_open_max (uji 1 probe saja).
 */
function sre_circuit_allow(string $svc): bool {
  $file = sre_circuit_file($svc);
  $fh   = @fopen($file, 'c+');
  if (!$fh) return true; // gagal baca state => fail-open (jangan blokir traffic)
  @flock($fh, LOCK_EX);
  $raw = @stream_get_contents($fh);
  $j   = $raw ? @json_decode($raw, true) : null;
  $st  = ((is_array($j) ? $j : []) + sre_circuit_default_state());
  $cooldown = (int)sre_sec('circuit_cooldown_sec', 30);
  $halfMax  = (int)sre_sec('circuit_half_open_max', 1);
  $now      = time();
  $changed  = false;
  if ($st['state'] === 'open' && ($now - (int)$st['opened_at']) >= $cooldown) {
    $st['state']             = 'half_open';
    $st['half_open_at']      = $now;
    $st['consecutive_success'] = 0;
    $changed = true;
  }
  if ($changed) {
    @ftruncate($fh, 0);
    @rewind($fh);
    @fwrite($fh, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @fflush($fh);
  }
  @flock($fh, LOCK_UN);
  @fclose($fh);
  if ($st['state'] === 'closed') return true;
  if ($st['state'] === 'open')  return false;
  return (int)$st['consecutive_success'] < $halfMax; // half_open
}

/**
 * Catat keberhasilan: reset failures; di half_open, cukup sukses beruntun
 * menyentuh half_open_max -> kembali closed.
 */
function sre_circuit_success(string $svc): void {
  $halfMax = (int)sre_sec('circuit_half_open_max', 1);
  sre_circuit_update($svc, function (array $st, int $now) use ($halfMax): array {
    $st['failures'] = 0;
    $st['consecutive_success']++;
    if ($st['state'] === 'half_open' && (int)$st['consecutive_success'] >= $halfMax) {
      $st['state'] = 'closed';
      $st['opened_at'] = 0;
      $st['half_open_at'] = 0;
      $st['consecutive_success'] = 0;
    }
    return $st;
  });
}

/**
 * Catat kegagalan: increment failures; closed & failures>=threshold -> open;
 * half_open yang gagal -> buka lagi. Saat open -> trigger alert circuit_open.
 */
function sre_circuit_failure(string $svc): void {
  $threshold = (int)sre_sec('circuit_fail_threshold', 5);
  $opened    = false;
  sre_circuit_update($svc, function (array $st, int $now) use ($threshold, &$opened): array {
    $st['failures'] = (int)$st['failures'] + 1;
    if ($st['state'] === 'closed' && (int)$st['failures'] >= $threshold) {
      $st['state'] = 'open';
      $st['opened_at'] = $now;
      $st['half_open_at'] = 0;
      $st['consecutive_success'] = 0;
      $opened = true;
    } elseif ($st['state'] === 'half_open') {
      $st['state'] = 'open';
      $st['opened_at'] = $now;
      $st['half_open_at'] = 0;
      $st['consecutive_success'] = 0;
      $opened = true;
    }
    return $st;
  });
  if ($opened) sre_alert_on_circuit_open($svc);
}

/**
 * Guarded execution: allow -> jalankan; sukses -> sre_circuit_success + return;
 * exception -> sre_circuit_failure + rethrow (pemanggil memutuskan fallback).
 */
function sre_circuit_run(string $svc, callable $fn) {
  if (!sre_circuit_allow($svc)) {
    sre_log(2, 'sre_circuit_run blocked', ['service' => $svc]);
    throw new SreCircuitOpenError("Circuit open untuk service '{$svc}'");
  }
  try {
    $v = $fn();
    sre_circuit_success($svc);
    return $v;
  } catch (Throwable $e) {
    sre_circuit_failure($svc);
    throw $e;
  }
}

/**
 * Apakah kegagalan "transien" (layak retry)? Hanya PDOException dengan
 * driver code / SQLSTATE di daftar kontan [2002,2006,2013,1205,1213] atau
 * HY000 + pola pesan "server has gone away" / time out / lock/deadlock.
 * Non-transien -> langsung di-rethrow tanpa retry.
 */
function sre_circuit_is_transient(Throwable $e): bool {
  if (!$e instanceof PDOException) return false;
  $codes   = [2002, 2006, 2013, 1205, 1213];
  $needles = ['server has gone away', 'gone away', 'connection lost', 'lost connection', 'timed out', 'wait timeout', 'deadlock'];
  $pairs   = [
    $e->getCode(),
    is_array($e->errorInfo) ? ($e->errorInfo[0] ?? '') : '',
    is_array($e->errorInfo) ? ($e->errorInfo[1] ?? '') : '',
  ];
  foreach ($pairs as $c) {
    if ($c === null || $c === '') continue;
    foreach ($codes as $code) {
      if ((string)$c === (string)$code) return true;
    }
  }
  $sqlstate = is_array($e->errorInfo) ? (string)($e->errorInfo[0] ?? '') : (string)$e->getCode();
  if ($sqlstate === 'HY000') {
    $msg = strtolower($e->getMessage());
    foreach ($needles as $n) {
      if (strpos($msg, strtolower($n)) !== false) return true;
    }
  }
  return false;
}

/**
 * Eksekusi dengan retry (default 3x) + exponential backoff 200/400/800ms.
 * Retry hanya bila circuit ALLOWED tiap attempt DAN kegagalan transien
 * (sre_circuit_is_transient). Non-transien di-rethrow segera. Return nilai fn.
 */
function sre_with_retry(string $svc, callable $fn, int $retries = 3) {
  $attempt = 0;
  while (true) {
    if (!sre_circuit_allow($svc)) {
      throw new SreCircuitOpenError("Circuit open untuk service '{$svc}' (retry #{$attempt})");
    }
    try {
      $v = $fn();
      sre_circuit_success($svc);
      return $v;
    } catch (Throwable $e) {
      sre_circuit_failure($svc);
      if (!sre_circuit_is_transient($e) || $attempt >= $retries) {
        throw $e;
      }
      $delayMs = 200 * (2 ** min($attempt, 2)); // 200 / 400 / 800 ms
      sre_log(1, 'sre_with_retry backoff', ['service' => $svc, 'attempt' => $attempt + 1, 'delay_ms' => $delayMs, 'error' => $e->getMessage()]);
      usleep($delayMs * 1000);
      $attempt++;
    }
  }
}