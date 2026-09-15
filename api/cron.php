<?php
// api/cron.php — worker pseudo-queue zero-infra. CLI ONLY, 1 job per run.
// Cron cPanel: */5 * * * * /usr/bin/php /home/USER/public_html/api/cron.php >> /dev/null 2>&1
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "cron.php CLI only\n"); exit(2); }
if (function_exists('set_time_limit')) @set_time_limit(0);
date_default_timezone_set('Asia/Jakarta');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$lockFile = $logDir . '/.cron.lock';
// Pola tick.php:71 — fopen c+ + LOCK_EX|LOCK_NB (non-blocking, cegah overlap cron).
$lk = @fopen($lockFile, 'c+');
if ($lk && !@flock($lk, LOCK_EX | LOCK_NB)) {
  @fclose($lk);
  fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => 'locked']) . "\n");
  exit(0);
}

$code = 0;
try {
  require_once __DIR__ . '/config.php';
  require_once __DIR__ . '/db.php';
  require_once __DIR__ . '/jobs.php';
  require_once __DIR__ . '/job_handlers.php';
  if (is_file(__DIR__ . '/../vendor/autoload.php')) require_once __DIR__ . '/../vendor/autoload.php';
  elseif (is_file(__DIR__ . '/vendor/autoload.php')) require_once __DIR__ . '/vendor/autoload.php';
  $pdo = pdo();
  $reaped = jobs_reap_stale($pdo);
  $job = jobs_claim($pdo);
  if (!$job) {
    fwrite(STDOUT, json_encode(['ok' => true, 'idle' => true, 'reaped' => $reaped]) . "\n");
    exit(0);
  }
  $t0 = microtime(true);
  try {
    $res = jobs_dispatch($pdo, $job);
  } catch (Throwable $e) {
    $res = ['ok' => false, 'error' => $e->getMessage()];
  }
  $ms = (int)((microtime(true) - $t0) * 1000);
  if (!empty($res['ok'])) {
    jobs_done($pdo, (int)$job['id'], json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fwrite(STDOUT, json_encode(['ok' => true, 'job' => (int)$job['id'], 'type' => $job['type'], 'ms' => $ms, 'reaped' => $reaped]) . "\n");
    exit(0);
  }
  $st = jobs_fail($pdo, (int)$job['id'], (string)($res['error'] ?? 'handler gagal'));
  fwrite(STDOUT, json_encode(['ok' => false, 'job' => (int)$job['id'], 'type' => $job['type'], 'retry' => $st, 'error' => substr((string)($res['error'] ?? ''), 0, 300), 'ms' => $ms]) . "\n");
  $code = 1;
} catch (Throwable $e) {
  fwrite(STDERR, 'cron fatal: ' . $e->getMessage() . "\n");
  $code = 1;
} finally {
  if (!empty($lk)) { @flock($lk, LOCK_UN); @fclose($lk); }
}
exit($code);
