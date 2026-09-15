<?php
/**
 * api/sre/alert.php — Pipeline alert berbasis file + flock.
 * Beberapa Prinsip (zero-setup shared hosting):
 *  - Pipeline TETAP jalan walau alert_email KOSONG: deteksi, throttle, dan
 *    tulis api/logs/alerts.log tetap dieksekusi; hanya mail() yang di-skip.
 *  - Throttle per tipe via api/logs/alert_state_{type}.json (cooldown).
 *  - Tidak melempar exception (selalu aman meski folder/kirim nonaktif).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/metrics.php';

/**
 * Path file state throttle alert per tipe (type di-sanitasi nama file).
 */
function sre_alert_state_file(string $type): string {
  $t = preg_replace('/[^A-Za-z0-9_\-]/', '', $type);
  if ($t === '') $t = 'misc';
  return sre_logs_dir() . '/alert_state_' . $t . '.json';
}

/**
 * Pipeline alert: (1) throttle, (2) tulis api/logs/alerts.log, (3) kirim email.
 * Return ['status'=>'sent'|'throttled'|'error', ...] untuk pemanggil/debug.
 */
function sre_alert(string $type, string $title, string $message, array $ctx = []): array {
  $cooldown = (int)sre_sec('alert_cooldown_sec', 3600);
  $now      = time();
  $stateFile = sre_alert_state_file($type);

  // (1) Throttle: baca last_ts di dalam lock (fopen c+ + LOCK_EX).
  // Bila `now - last_ts < cooldown` -> SKIP (dicatat, bukan dibuang diam-diam).
  $fh = @fopen($stateFile, 'c+');
  if (!$fh) {
    sre_log(3, 'sre_alert state file gagal dibuka', ['file' => $stateFile, 'type' => $type]);
    return ['status' => 'error', 'reason' => 'state_file_open_failed'];
  }
  @flock($fh, LOCK_EX);
  $raw  = @stream_get_contents($fh);
  $st   = $raw ? @json_decode($raw, true) : null;
  $last = (int)(is_array($st) ? ($st['last_ts'] ?? 0) : 0);
  if ($now - $last < $cooldown) {
    @flock($fh, LOCK_UN);
    @fclose($fh);
    sre_log(0, 'sre_alert throttled', ['type' => $type, 'last_ts' => $last, 'cooldown' => $cooldown, 'title' => $title]);
    return ['status' => 'throttled', 'reason' => 'cooldown', 'next_at' => $last + $cooldown];
  }
  @ftruncate($fh, 0);
  @rewind($fh);
  @fwrite($fh, json_encode(['last_ts' => $now], JSON_UNESCAPED_UNICODE));
  @fflush($fh);
  @flock($fh, LOCK_UN);
  @fclose($fh);

  // (2) Append baris alert ke api/logs/alerts.log (format sama log.php + alert_type).
  $n = sre_log_now();
  $rec = [
    'ts'         => $n['ts'],
    'level'      => 'CRITICAL',
    'req_id'     => sre_log_req_id(),
    'message'    => $title,
    'alert_type' => $type,
    'ctx'        => array_merge(['detail' => $message], $ctx),
  ];
  sre_log_append($rec, sre_logs_dir() . '/alerts.log');

  // (3) Kirim email (otomatis di-skip bila alert_email kosong).
  $mail = sre_send_email($title, $message);
  sre_log(1, 'sre_alert dispatched', ['type' => $type, 'send' => $mail['sent'] ?? false, 'reason' => $mail['reason'] ?? null]);
  return ['status' => 'sent', 'type' => $type, 'email' => $mail];
}

/**
 * Kirim email alert via mail(). Bila alert_email KOSONG -> return
 * ['sent'=>false,'reason'=>'email_not_configured'] (mail() tidak dipanggil).
 * Gagal kirim tidak pernah melempar; hanya di-log.
 */
function sre_send_email(string $subject, string $body): array {
  $to = (string)sre_sec('alert_email', '');
  if ($to === '') {
    sre_log(2, 'ALERT_QUEUED send_disabled=true email_not_configured', ['subject' => substr($subject, 0, 120)]);
    return ['sent' => false, 'reason' => 'email_not_configured'];
  }
  // Timeout socket diperketat (scoped) supaya request tidak menggantung.
  $oldTO = ini_get('default_socket_timeout');
  if ($oldTO !== false && $oldTO !== '') @ini_set('default_socket_timeout', '5');
  $ok = false;
  try {
    if (function_exists('mail')) {
      $headers = 'From: ' . $to . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "MIME-Version: 1.0\r\n";
      $ok = @mail($to, $subject, $body, $headers);
    }
  } catch (Throwable $e) {
    $ok = false;
  }
  if ($oldTO !== false && $oldTO !== '') @ini_set('default_socket_timeout', $oldTO);
  if (!$ok) {
    sre_log(3, 'sre_send_email gagal via mail()', ['to' => $to, 'subject' => substr($subject, 0, 120)]);
    return ['sent' => false, 'reason' => 'mail_failed'];
  }
  return ['sent' => true];
}

/**
 * Trigger helper alert spike 5xx (>=10 5xx dalam window metrik berjalan).
 * Dipanggil integrasi index.php (mis. setelah sre_metrics_incr per request).
 */
function sre_alert_on_5xx_spike(int $count): void {
  if ($count >= 10) {
    sre_alert('5xx_spike', '5xx spike terdeteksi', $count . ' request gagal (5xx) dalam window metrik berjalan.', ['count' => $count]);
  }
}

/**
 * Trigger helper alert circuit breaker terbuka (dari circuit.php).
 */
function sre_alert_on_circuit_open($service): void {
  sre_alert('circuit_open', 'Circuit breaker terbuka', "Circuit untuk service '" . $service . "' berpindah ke state OPEN.", ['service' => $service]);
}

/**
 * Trigger helper alert kegagalan backup (dari backup.php).
 */
function sre_alert_on_backup_fail($reason): void {
  sre_alert('backup_fail', 'Backup gagal', 'Backup database/upload gagal: ' . $reason, ['reason' => $reason]);
}