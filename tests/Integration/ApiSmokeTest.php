<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Smoke test integrasi: tanpa MySQL/Redis/web server.
// - routeMatch: smoke pola route prod.
// - sre_handle_route: health endpoint via subprocess (fungsi prod exit setelah echo).
// - SQLite :memory:: schema minimal + ensureAppSettings + validasi + uploadPath + session handler.
final class ApiSmokeTest extends TestCase {
  private static function runProbe(string $script, array $args = []): array {
    $cmd = PHP_BINARY . ' ' . escapeshellarg($script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    exec($cmd, $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
  }

  public function testRouteMatchProdPatterns(): void {
    $p = [];
    $this->assertTrue(routeMatch('/ekskul/:id', '/ekskul/42', $p));
    $this->assertSame('42', $p['id']);
    $p = [];
    $this->assertTrue(routeMatch('/events/:id', '/events/7', $p));
    $this->assertSame('7', $p['id']);
    $p = [];
    $this->assertTrue(routeMatch('/covers/:tipe/:id', '/covers/ekskul/9', $p));
    $this->assertSame(['tipe' => 'ekskul', 'id' => '9'], $p);
    $this->assertFalse(routeMatch('/ekskul/:id', '/events/7'));
  }

  public function testHealthLiveEndpoint(): void {
    $r = self::runProbe(__DIR__ . '/../Support/health_live_probe.php');
    $this->assertSame(0, $r['code'], 'probe health/live gagal: ' . $r['out']);
    $j = json_decode($r['out'], true);
    $this->assertSame('ok', $j['status'] ?? null);
    $this->assertArrayHasKey('ts', $j);
  }

  public function testHealthReadyDegradedWithoutDb(): void {
    // Tanpa MySQL di CI/lokal: sre_pdo_connect() -> null => 'down'.
    // Pastikan shape-nya stabil, bukan crash.
    $ready = sre_health_ready();
    $this->assertContains($ready['status'] ?? null, ['ok', 'degraded', 'down']);
    $this->assertArrayHasKey('db', $ready['checks'] ?? []);
    $this->assertArrayHasKey('storage', $ready['checks'] ?? []);
    $this->assertArrayHasKey('sessions', $ready['checks'] ?? []);
  }

  public function testSqliteSchemaAndValidateFlow(): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(file_get_contents(__DIR__ . '/../../api/schema.sqlite.sql'));
    $pdo->exec("INSERT INTO users(nama,email,password_hash,role) VALUES ('Pembina Satu','pembina1@sekolah.test','x','pembina')");
    $pdo->exec("INSERT INTO ekskul(nama,deskripsi,pembina_id,kuota,status) VALUES ('Futsal Hebat Club','Latihan rutin mingguan futsal',1,30,'approved')");
    $err = '';
    $this->assertTrue(validateEkskulPayload('Basket Hebat Club', 'Latihan rutin mingguan basket anak sekolah', 25, $err, 'Rabu', 'GOR Sekolah', '15:00', '17:00'));
    $row = $pdo->query("SELECT COUNT(*) c FROM ekskul WHERE status='approved'")->fetch();
    $this->assertSame(1, (int)$row['c']);
  }

  public function testSqliteEnsureAppSettingsIdempotent(): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureAppSettings($pdo);
    ensureAppSettings($pdo);
    $v = $pdo->query("SELECT v FROM app_settings WHERE k='sekolah_nama'")->fetch()['v'] ?? null;
    $this->assertSame('SMA Negeri 1', $v);
  }

  public function testSqliteSessionHandlerRoundtrip(): void {
    require_once __DIR__ . '/../../api/session_store.php';
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureSessionTable($pdo);
    $h = new DbSessionHandler($pdo, 60);
    $this->assertTrue($h->write('sess-abc', 'payload-data'));
    $this->assertSame('payload-data', $h->read('sess-abc'));
    $this->assertTrue($h->destroy('sess-abc'));
    $this->assertSame('', $h->read('sess-abc'));
  }

  public function testSqliteEndToEndValidateThenInsert(): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(file_get_contents(__DIR__ . '/../../api/schema.sqlite.sql'));
    $nama = 'Voli Hebat Club';
    $desk = 'Latihan rutin mingguan voli anak sekolah';
    $err = '';
    $this->assertTrue(validateEkskulPayload($nama, $desk, 20, $err, 'Jumat', 'Lapangan B', '14:00', '16:00'));
    $pdo->exec("INSERT INTO users(nama,email,password_hash,role) VALUES ('Pembina Dua','pembina2@sekolah.test','x','pembina')");
    $st = $pdo->prepare('INSERT INTO ekskul(nama,deskripsi,pembina_id,kuota,hari,jam_mulai,jam_selesai,lokasi,status) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([$nama, $desk, 1, 20, 'Jumat', '14:00', '16:00', 'Lapangan B', 'pending']);
    $row = $pdo->query('SELECT nama, kuota, status FROM ekskul')->fetch();
    $this->assertSame($nama, $row['nama']);
    $this->assertSame(20, (int)$row['kuota']);
  }

  public function testUploadPathTraversalBlocked(): void {
    $this->expectException(InvalidArgumentException::class);
    uploadPath('../../etc/passwd');
  }
}
