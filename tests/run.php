<?php
// Vanilla fallback runner (zero-dependency): php tests/run.php [--unit|--integration].
// Dipakai bila vendor/bin/phpunit belum terinstal (mis. shared hosting tanpa composer install).
// Tidak menggantikan PHPUnit — hanya subset fallback tanpa PHPUnit (15 checks, bukan mirror penuh 20 tests PHPUnit).
declare(strict_types=1);
error_reporting(E_ALL);

$suite = $argv[1] ?? 'all';
$filterOk = static fn(string $s) => $suite === 'all'
  || ($suite === '--unit' && str_starts_with($s, 'unit:'))
  || ($suite === '--integration' && str_starts_with($s, 'integration:'));

require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0; $failed = [];
function check(string $name, callable $fn): void {
  global $pass, $fail, $failed, $filterOk;
  if (!$filterOk($name)) return;
  try { $fn(); $pass++; echo "PASS $name\n"; }
  catch (Throwable $e) { $fail++; $failed[] = $name; echo "FAIL $name: {$e->getMessage()}\n"; }
}
function eq($a, $b, string $msg = ''): void {
  if ($a !== $b) throw new RuntimeException(($msg ? $msg . ' ' : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
}
function ok(bool $v, string $msg = ''): void { if (!$v) throw new RuntimeException($msg ?: 'expected true'); }
function no(bool $v, string $msg = ''): void { if ($v) throw new RuntimeException($msg ?: 'expected false'); }

// --- unit: routeMatch ---
check('unit:routeMatchParam', function () {
  $p = []; ok(routeMatch('/ekskul/:id', '/ekskul/123', $p)); eq($p['id'], '123');
  $p = []; no(routeMatch('/ekskul/:id', '/ekskul'));
});
// --- unit: validators ---
check('unit:validateEkskulPayload', function () {
  $err = ''; ok(validateEkskulPayload('Futsal Hebat Club', 'Latihan rutin mingguan futsal anak sekolah', 30, $err, 'Senin', 'Lapangan A', '15:00', '17:00'));
  $err = ''; no(validateEkskulPayload('Te', 'Latihan rutin mingguan futsal anak sekolah', 30, $err, 'Senin', 'Lapangan A', '15:00', '17:00')); ok($err !== '');
  $err = ''; no(validateEkskulPayload('Futsal Hebat Club', 'pendek', 30, $err, 'Senin', 'Lapangan A', '15:00', '17:00'));
});
check('unit:validateEkskulJamOrder', function () {
  $err = ''; no(validateEkskulPayload('Futsal Hebat Club', 'Latihan rutin mingguan futsal anak sekolah', 30, $err, 'Senin', 'Lapangan A', '17:00', '15:00'));
  ok(str_contains($err, 'Jam mulai'));
});
check('unit:validateEventPayload', function () {
  $err = ''; ok(validateEventPayload('Lomba Cerdas Cermat Sekolah', date('Y-m-d', strtotime('+7 days')), '08:00', 'Aula Utama', 100, $err));
  $err = ''; no(validateEventPayload('Lomba Cerdas Cermat Sekolah', '2020-01-01', '08:00', 'Aula Utama', 100, $err)); ok($err !== '');
  $err = ''; no(validateEventPayload('Lomba', date('Y-m-d', strtotime('+7 days')), '08:00', 'Aula Utama', 0, $err));
});
check('unit:validateUserPayload', function () {
  $err = ''; ok(validateUserPayload('Budi Santoso', 'budi@sekolah.test', 'password123', 'siswa', $err, 'XII-1'));
  $err = ''; no(validateUserPayload('Budi', 'bukan-email', 'password123', 'siswa', $err));
  $err = ''; no(validateUserPayload('Budi', 'budi@sekolah.test', 'pendek', 'siswa', $err));
  $err = ''; no(validateUserPayload('Budi', 'budi@sekolah.test', 'password123', 'rektor', $err));
});
check('unit:normalizeEkskulIds', function () {
  eq(normalizeEkskulIds(null), null); eq(normalizeEkskulIds('umum'), null);
  eq(normalizeEkskulIds('2,1,2'), [1, 2]); eq(normalizeEkskulIds([3, 3]), [3]);
});
check('unit:helpersPure', function () {
  eq(e('<b>'), '&lt;b&gt;'); ok(ensureCsrfToken() !== '');
  eq(cacheKey('a', [1, 2]), 'a_' . md5(json_encode([[1, 2]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
  eq(uploadUrl('covers/x.png'), '/api/uploads/covers/x.png');
});
check('unit:storageHelpers', function () {
  $base = storageBaseDir();
  ok(str_starts_with(uploadPath('covers/x.png'), $base));
  ok(str_ends_with(uploadPath('api/uploads/covers/x.png'), 'covers' . DIRECTORY_SEPARATOR . 'x.png'));
  try { uploadPath('../secret.txt'); throw new RuntimeException('traversal lolos'); }
  catch (InvalidArgumentException $e) { /* expected */ }
});
check('unit:getRealIp', function () {
  $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
  unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
  eq(getRealIp(), '203.0.113.7');
  $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
  $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.2';
  eq(getRealIp(), '198.51.100.9');
});
check('unit:rateLimitFile', function () {
  $key = 'vanilla-' . bin2hex(random_bytes(4));
  $r1 = rlHitFile($key, 2, 60); $r2 = rlHitFile($key, 2, 60); $r3 = rlHitFile($key, 2, 60);
  ok($r1['allowed']); ok($r2['allowed']); no($r3['allowed']); ok($r3['retryAfter'] >= 1);
});
check('unit:sreNonRoute', function () {
  eq(sre_handle_route('GET', '/ekskul'), null);
  eq(sre_handle_route('POST', '/auth/login'), null);
});
// --- integration ---
check('integration:routeMatchProd', function () {
  $p = []; ok(routeMatch('/ekskul/:id', '/ekskul/42', $p)); eq($p['id'], '42');
  $p = []; ok(routeMatch('/covers/:tipe/:id', '/covers/ekskul/9', $p)); eq($p, ['tipe' => 'ekskul', 'id' => '9']);
  no(routeMatch('/ekskul/:id', '/events/7'));
});
check('integration:healthLive', function () {
  $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/Support/health_live_probe.php');
  exec($cmd, $out, $code);
  eq($code, 0, 'probe exit: ' . implode("\n", $out));
  $j = json_decode(implode("\n", $out), true);
  eq($j['status'] ?? null, 'ok');
});
check('integration:healthReadyShape', function () {
  $r = sre_health_ready();
  ok(in_array($r['status'] ?? null, ['ok', 'degraded', 'down'], true));
  ok(isset($r['checks']['db'], $r['checks']['storage'], $r['checks']['sessions']));
});
check('integration:sqliteFlow', function () {
  $pdo = new PDO('sqlite::memory:');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec(file_get_contents(__DIR__ . '/../api/schema.sqlite.sql'));
  ensureAppSettings($pdo);
  $v = $pdo->query("SELECT v FROM app_settings WHERE k='sekolah_nama'")->fetch()['v'] ?? null;
  eq($v, 'SMA Negeri 1');
  require_once __DIR__ . '/../api/session_store.php';
  ensureSessionTable($pdo);
  $h = new DbSessionHandler($pdo, 60);
  ok($h->write('sess-abc', 'payload-data')); eq($h->read('sess-abc'), 'payload-data');
});

echo "---\npass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
