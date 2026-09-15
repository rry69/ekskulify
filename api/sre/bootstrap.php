<?php
/**
 * api/sre/bootstrap.php — Error handling & graceful degradation.
 * Panggil sre_boot() SEKALI dari index.php SETELAH config/vendor, SEBELUM
 * routing. Semua error dipetakan ke sre_log (JSONL) + (optional) alert;
 * output error internal selalu JSON 500 dengan request_id, bukan HTML rawan
 * bocor. Zero-setup shared hosting: semuanya PHP murni, tanpa framework.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/alert.php';
require_once __DIR__ . '/metrics.php'; // untuk sre_request_log (shutdown metrics wiring)
require_once __DIR__ . '/tick.php'; // untuk register_shutdown_function('sre_tick') di sre_boot()

/**
 * Request id: dari header X-Request-Id (sanitasi alnum-dash, maks 64) atau
 * generate bin2hex(random_bytes(8)). Di-memoize (satu id per request),
 * dipakai sebagai req_id di semua baris log.
 */
function sre_req_id(): string {
  static $rid = null;
  if ($rid !== null) return $rid;
  $h = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['X_REQUEST_ID'] ?? '');
  $h = preg_replace('/[^A-Za-z0-9\-]/', '', $h);
  if (strlen($h) > 64) $h = substr($h, 0, 64);
  if ($h === '') $h = bin2hex(random_bytes(8));
  $rid = $h;
  return $rid;
}

/**
 * Boot SRE: guard-`idempotent`. Mengeset error_reporting E_ALL, display_errors
 * 0 (tanpa menimpa yang sudah 0), handler error/exception/shutdown, dan
 * timezone Asia/Jakarta bila belum diset.
 */
function sre_boot(): void {
  static $booted = false;
  if ($booted) return;
  $booted = true;
  // Reset flag per-request: guard double-JSON (graceful 503 lalu handler
  // exception global tidak boleh emit JSON 500 kedua walau headers belum sent).
  $GLOBALS['sre_response_sent'] = false;
  // Titik awal durasi request untuk metrik permintaan (shutdown).
  if (!defined('SRE_REQ_START')) define('SRE_REQ_START', microtime(true));
  if (date_default_timezone_get() === 'UTC') date_default_timezone_set('Asia/Jakarta');
  if (ini_get('display_errors') === '1') @ini_set('display_errors', '0'); // jangan override bila sudah '0'
  error_reporting(E_ALL);
  set_error_handler('sre_php_error');
  set_exception_handler('sre_php_exception');
  register_shutdown_function('sre_php_shutdown');
  // Housekeeping traffic-triggered di akhir request. sre_tick() sendiri yang
  // meng-guard: skip saat CLI, semua exception di-swallow + log, tidak pernah
  // mematikan request.
  register_shutdown_function('sre_tick');
  // Metrik request: didaftarkan PALING AKHIR => LIFO shutdown = request_log
  // jalan PALING AWAL (urutan benar: request_log dulu, baru tick).
  register_shutdown_function('sre_request_log');
}

/**
 * Error handler: konversi ke ErrorException. Khusus error yang TIDAK termasuk
 * mask error_reporting() aktif — termasuk operator @ (di PHP 8+, error_reporting()
 * saat @ = E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR|E_USER_ERROR|E_RECOVERABLE_ERROR|
 * E_PARSE (4437), BUKAN 0) — return false (biarkan perilaku default, tanpa
 * melempar). Guard identik dengan handler anonim lama di index.php (paritas).
 */
function sre_php_error($severity, $message, $file, $line) {
  if (!(error_reporting() & $severity)) return false; // @-suppressed / di luar mask aktif
  // PHP 8.5 deprecations (PDO::MYSQL_* -> Pdo\Mysql::*) tidak boleh jadi exception fatal
  if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
}

/**
 * Uncaught exception handler: log CRITICAL + alert + JSON 500 (bila header
 * belum terkirim). Jangan pernah menampilkan detail error ke klien.
 */
function sre_php_exception(Throwable $e): void {
  // Guard double-JSON: bila respons graceful/503 sudah dikirim (mis. DB down
  // lalu pdo() rethrow), jangan emit JSON 500 kedua — cukup log.
  if (!empty($GLOBALS['sre_response_sent'])) {
    sre_log(3, 'exception after response sent', [
      'class'   => get_class($e),
      'message' => $e->getMessage(),
      'at'      => $e->getFile() . ':' . $e->getLine(),
    ]);
    return;
  }
  $frames = array_slice($e->getTrace(), 0, 5);
  $trace  = [];
  foreach ($frames as $fr) {
    $trace[] = ($fr['file'] ?? '?') . ':' . ($fr['line'] ?? '?');
  }
  sre_log(4, 'uncaught exception', [
    'class'   => get_class($e),
    'message' => $e->getMessage(),
    'at'      => $e->getFile() . ':' . $e->getLine(),
    'trace'   => $trace,
  ]);
  sre_alert('uncaught_exception', 'Uncaught exception: ' . get_class($e), $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
  if (!headers_sent()) {
    sre_http_json(500, ['error' => 'Terjadi kesalahan internal', 'code' => 'INTERNAL_ERROR', 'request_id' => sre_req_id()]);
  }
}

/**
 * Shutdown handler: deteksi fatal (E_ERROR/PARSE/CORE dst.) dari error_get_last.
 * Log + alert; JSON 500 hanya bila header belum terkirim (jangan menimpa
 * status yang sudah dikirim).
 */
function sre_php_shutdown(): void {
  $err = error_get_last();
  if (!$err) return;
  $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
  if (!in_array($err['type'], $fatal, true)) return;
  // Guard double-JSON: fatal yang muncul SETELAH respons graceful terkirim
  // hanya di-log, tidak boleh menimpa status/body yang sudah keluar.
  if (!empty($GLOBALS['sre_response_sent'])) {
    sre_log(4, 'fatal after response sent', ['type' => $err['type'], 'message' => $err['message'], 'at' => $err['file'] . ':' . $err['line']]);
    return;
  }
  sre_log(4, 'fatal error', ['type' => $err['type'], 'message' => $err['message'], 'at' => $err['file'] . ':' . $err['line']]);
  sre_alert('fatal_error', 'Fatal error: ' . $err['message'], $err['message'], ['at' => $err['file'] . ':' . $err['line']]);
  if (!headers_sent()) {
    sre_http_json(500, ['error' => 'Terjadi kesalahan internal', 'code' => 'INTERNAL_ERROR', 'request_id' => sre_req_id()], true);
  }
}

/**
 * Kirim JSON dengan status HTTP. Bersihkan output buffer bila header belum
 * terkirim; Content-Type application/json; charset=utf-8; exit default true.
 */
function sre_http_json(int $status, array $payload, bool $exit = true): void {
  if (!headers_sent()) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($exit) exit;
}

/**
 * Helper integrasi saat DB down (panggil dari wrapper pdo/* user code):
 * log + JSON 503 + Retry-After: 5, tanpa exit agar pemanggil bisa lanjut.
 */
function sre_graceful_db_down(?Throwable $e = null): void {
  // Flag PER-REQUEST sebelum echo: setelah ini handler exception global TIDAK
  // boleh emit JSON 500 kedua walau headers belum terkirim (OB ON di hosting).
  $GLOBALS['sre_response_sent'] = true;
  // Retry-After WAJIB sebelum echo (setelah echo hilang saat OB off).
  // Guard headers_sent: hindari warning "headers already sent" yang justru
  // dikonversi sre_php_error menjadi ErrorException di tengah graceful path.
  if (!headers_sent()) header('Retry-After: 5');
  sre_log(3, 'db down / unavailable', ['message' => $e ? $e->getMessage() : null]);
  sre_alert('db_down', 'Database tidak tersedia', $e ? $e->getMessage() : '', ['at' => $e ? $e->getFile() . ':' . $e->getLine() : null]);
  sre_http_json(503, ['error' => 'Layanan sedang sibuk, coba lagi', 'code' => 'DB_UNAVAILABLE', 'request_id' => sre_req_id()], false);
}