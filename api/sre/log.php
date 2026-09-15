<?php
/**
 * api/sre/log.php — Structured JSONL logging + rotasi + config accessor.
 * Zero-setup shared hosting: semua append memakai flock (fopen c+ LOCK_EX
 * + fseek 0 END), TANPA cron. Baris log: {"ts","level","req_id","message","ctx"}.
 */
require_once __DIR__ . '/config.php';

/**
 * Direktori log SRE (api/logs). Relatif terhadap __DIR__.
 */
function sre_logs_dir(): string {
  return __DIR__ . '/../logs';
}

/**
 * Pastikan direktori log ada & writable (auto-mkdir, 0775).
 */
function sre_log_ensure_dir(): bool {
  $d = sre_logs_dir();
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return is_dir($d) && is_writable($d);
}

/**
 * Konversi level int 0..4 ke nama (DEBUG..CRITICAL) untuk baris JSON.
 */
function sre_log_level_name(int $level): string {
  $m = [0 => 'DEBUG', 1 => 'INFO', 2 => 'WARNING', 3 => 'ERROR', 4 => 'CRITICAL'];
  return $m[$level] ?? 'LEVEL' . $level;
}

/**
 * req_id dari bootstrap (sre_req_id) bila tersedia, else null.
 */
function sre_log_req_id(): ?string {
  return function_exists('sre_req_id') ? sre_req_id() : null;
}

/**
 * Timestamp + tanggal log dalam timezone Jakarta eksplisit (DateTimeZone),
 * TANPA mengubah timezone global — CLI tanpa boot tetap menulis +07:00.
 * Fallback ke date() bila data timezone tidak tersedia.
 */
function sre_log_now(): array {
  try {
    $d = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return ['ts' => $d->format('Y-m-d\TH:i:sP'), 'day' => $d->format('Y-m-d')];
  } catch (Throwable $e) {
    return ['ts' => date('c'), 'day' => date('Y-m-d')];
  }
}

/**
 * Penulis baris JSON ke file log dengan flock (fopen c+ LOCK_EX + fseek END).
 * Dipakai oleh sre_log() dan pipeline alert (alert.php). Tidak melempar.
 */
function sre_log_append(array $record, string $file): bool {
  if (!sre_log_ensure_dir()) return false;
  $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($line === false) return false;
  $fh = @fopen($file, 'c+');
  if (!$fh) return false;
  @flock($fh, LOCK_EX);
  @fseek($fh, 0, SEEK_END);
  $ok = @fwrite($fh, $line . "\n");
  @fflush($fh);
  @flock($fh, LOCK_UN);
  @fclose($fh);
  return $ok !== false;
}

/**
 * Log satu baris JSONL ke api/logs/sre-YYYY-MM-DD.log.
 * level: 0 DEBUG, 1 INFO, 2 WARNING, 3 ERROR, 4 CRITICAL.
 */
function sre_log(int $level, string $message, array $ctx = []): void {
  $n = sre_log_now();
  $rec = [
    'ts'      => $n['ts'],
    'level'   => sre_log_level_name($level),
    'req_id'  => sre_log_req_id(),
    'message' => $message,
    'ctx'     => $ctx,
  ];
  sre_log_append($rec, sre_logs_dir() . '/sre-' . $n['day'] . '.log');
}

/**
 * Rotasi log harian sre-*.log. Bila ukuran > log_max_bytes: geser ke
 * sre-YYYY-MM-DD.1.log, `.1`->`.2`, ..., hapus yang melebihi log_keep.
 * Dipanggil dari sre_tick(). Return ringkasan untuk audit.
 */
function sre_rotate_logs(?string $prefix = null): array {
  $dir     = sre_logs_dir();
  $max     = (int)sre_sec('log_max_bytes', 5242880);
  $keep    = (int)sre_sec('log_keep', 5);
  $rotated = [];
  $deleted = [];
  $patterns = $prefix !== null ? [$dir.'/'.$prefix.'-*.log'] : [$dir.'/sre-*.log', $dir.'/slow-*.log'];
  foreach ($patterns as $pat) {
  foreach (glob($pat) ?: [] as $f) {
    if ($prefix !== null) {
      if (!preg_match('~'.preg_quote($prefix,'~').'-(\d{4}-\d{2}-\d{2})\.log$~', $f, $m)) continue;
      $base = $prefix.'-'.$m[1];
    } else {
      if (preg_match('~sre-(\d{4}-\d{2}-\d{2})\.log$~', $f, $m)) $base = 'sre-'.$m[1];
      elseif (preg_match('~slow-(\d{4}-\d{2}-\d{2})\.log$~', $f, $m)) $base = 'slow-'.$m[1];
      else continue;
    }
    $date = $m[1];
    if ((@filesize($f) ?: 0) <= $max) continue;
    // hapus slot paling tua, lalu geser .N -> .N+1 dari yang terbesar dulu
    $oldest = $dir . '/' . $base . '.' . $keep . '.log';
    if (is_file($oldest)) { @unlink($oldest); $deleted[] = basename($oldest); }
    for ($n = $keep - 1; $n >= 1; $n--) {
      $src = $dir . '/' . $base . '.' . $n . '.log';
      $dst = $dir . '/' . $base . '.' . ($n + 1) . '.log';
      if (is_file($src)) @rename($src, $dst);
    }
    @rename($f, $dir . '/' . $base . '.1.log');
    $rotated[] = $date;
  }
  }
  if ($rotated) sre_log(1, 'log rotated', ['dates' => $rotated, 'deleted' => $deleted]);
  return ['rotated' => $rotated, 'deleted' => $deleted];
}