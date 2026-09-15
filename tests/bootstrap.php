<?php
declare(strict_types=1);
// Bootstrap PHPUnit + vanilla runner (tests/run.php).
// Zero-dependency ke server: tanpa MySQL/Redis/web server.
// Side-effect file diisolasi ke sys temp (STORAGE_DIR diarahkan ke temp
// SEBELUM config.php pertama kali di-load via configValue()).
error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');

$testStorage = sys_get_temp_dir() . '/proj1_test_storage';
putenv('STORAGE_DIR=' . $testStorage);
$_ENV['STORAGE_DIR'] = $testStorage;

$root = dirname(__DIR__);
require_once __DIR__ . '/Support/extract_functions.php';
require_once $root . '/api/sre/index.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/storage.php';
require_once $root . '/api/ratelimit.php';
require_once $root . '/api/db.php';

// Fungsi pure yang terkunci di monolit api/index.php (file tsb tidak bisa
// di-require: top-level code jalanin session/router/exit). Ekstrak deklarasi
// fungsinya dari source aktual supaya test selalu menguji kode prod asli.
$wanted = ['routeMatch', 'getRealIp', 'validateEkskulPayload', 'validateEventPayload', 'validateUserPayload', 'normalizeEkskulIds'];
if (!function_exists('routeMatch')) {
  eval(tests_extract_functions($root . '/api/index.php', $wanted));
}
foreach ($wanted as $fn) {
  if (!function_exists($fn)) throw new RuntimeException("bootstrap: $fn tidak ketemu di api/index.php");
}
