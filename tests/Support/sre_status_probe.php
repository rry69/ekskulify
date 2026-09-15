<?php
// Probe subprocess: GET /sre/status tanpa session.
// sre_http_json() prod echo + exit di dalam, jadi HTTP status ditangkap via
// shutdown handler dan di-append sebagai baris terakhir __STATUS__:<code>.
// Argumen: METHOD PATH. Stdout: body JSON + "\n__STATUS__:<code>".
$_SESSION = [];
require __DIR__ . '/../../api/sre/index.php';
$method = $argv[1] ?? 'GET';
$path = $argv[2] ?? '/sre/status';
register_shutdown_function(static function (): void {
  $c = http_response_code();
  if ($c === false) $c = 0;
  echo "\n__STATUS__:" . $c;
});
$r = sre_handle_route($method, $path);
$c = http_response_code();
if ($c === false) $c = 0;
echo "\n__STATUS__:" . $c . "\n__HANDLED__:" . var_export($r, true);
