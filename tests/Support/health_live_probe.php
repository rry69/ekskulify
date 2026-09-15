<?php
// Probe subprocess: hit endpoint GET /health/live tanpa web server.
// sre_http_json() echo + exit di dalam proses ini — aman karena subprocess.
require __DIR__ . '/../../api/sre/index.php';
$r = sre_handle_route('GET', '/health/live');
fwrite(STDERR, "NOT-HANDLED\n");
exit(3);
