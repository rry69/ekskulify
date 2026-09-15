<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase {
  public function testRouteMatchParam(): void {
    $p = [];
    $this->assertTrue(routeMatch('/ekskul/:id', '/ekskul/123', $p));
    $this->assertSame('123', $p['id']);
    $p = [];
    $this->assertFalse(routeMatch('/ekskul/:id', '/ekskul'));
  }

  public function testValidateEkskulPayload(): void {
    $err = '';
    $this->assertTrue(validateEkskulPayload('Futsal Hebat Club', 'Latihan rutin mingguan futsal anak sekolah', 30, $err, 'Senin', 'Lapangan A', '15:00', '17:00'));
    $err = '';
    $this->assertFalse(validateEkskulPayload('Te', 'Latihan rutin mingguan futsal anak sekolah', 30, $err, 'Senin', 'Lapangan A', '15:00', '17:00'));
    $this->assertNotSame('', $err);
    $err = '';
    $this->assertFalse(validateEkskulPayload('Futsal Hebat Club', 'pendek', 30, $err, 'Senin', 'Lapangan A', '15:00', '17:00'));
  }

  public function testValidateEkskulJamOrder(): void {
    $err = '';
    $this->assertFalse(validateEkskulPayload('Futsal Hebat Club', 'Latihan rutin mingguan futsal anak sekolah', 30, $err, 'Senin', 'Lapangan A', '17:00', '15:00'));
    $this->assertStringContainsString('Jam mulai', $err);
  }

  public function testValidateEventPayload(): void {
    $err = '';
    $this->assertTrue(validateEventPayload('Lomba Cerdas Cermat Sekolah', date('Y-m-d', strtotime('+7 days')), '08:00', 'Aula Utama', 100, $err));
    $err = '';
    $this->assertFalse(validateEventPayload('Lomba Cerdas Cermat Sekolah', '2020-01-01', '08:00', 'Aula Utama', 100, $err));
    $this->assertNotSame('', $err);
    $err = '';
    $this->assertFalse(validateEventPayload('Lomba', date('Y-m-d', strtotime('+7 days')), '08:00', 'Aula Utama', 0, $err));
  }

  public function testValidateUserPayload(): void {
    $err = '';
    $this->assertTrue(validateUserPayload('Budi Santoso', 'budi@sekolah.test', 'password123', 'siswa', $err, 'XII-1'));
    $err = '';
    $this->assertFalse(validateUserPayload('Budi', 'bukan-email', 'password123', 'siswa', $err));
    $err = '';
    $this->assertFalse(validateUserPayload('Budi', 'budi@sekolah.test', 'pendek', 'siswa', $err));
    $err = '';
    $this->assertFalse(validateUserPayload('Budi', 'budi@sekolah.test', 'password123', 'rektor', $err));
  }

  public function testNormalizeEkskulIds(): void {
    $this->assertNull(normalizeEkskulIds(null));
    $this->assertNull(normalizeEkskulIds('umum'));
    $this->assertSame([1, 2], normalizeEkskulIds('2,1,2'));
    $this->assertSame([3], normalizeEkskulIds([3, 3]));
  }

  public function testHelpersPure(): void {
    $this->assertSame('&lt;b&gt;', e('<b>'));
    $this->assertNotSame('', ensureCsrfToken());
    $this->assertSame('a_' . md5(json_encode([[1, 2]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), cacheKey('a', [1, 2]));
    $this->assertSame('/api/uploads/covers/x.png', uploadUrl('covers/x.png'));
  }

  public function testStorageHelpers(): void {
    $base = storageBaseDir();
    $this->assertStringNotContainsString('..', uploadPath('covers/x.png'));
    $this->assertStringStartsWith($base, uploadPath('covers/x.png'));
    $this->assertStringEndsWith('covers' . DIRECTORY_SEPARATOR . 'x.png', uploadPath('api/uploads/covers/x.png'));
    $this->assertStringEndsWith('covers' . DIRECTORY_SEPARATOR . 'x.png', uploadPath('uploads/covers/x.png'));
    $this->expectException(InvalidArgumentException::class);
    uploadPath('../secret.txt');
  }

  public function testGetRealIp(): void {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    $this->assertSame('203.0.113.7', getRealIp()); // public IP langsung
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.2';
    $this->assertSame('198.51.100.9', getRealIp()); // private REMOTE_ADDR -> forward pertama
  }

  public function testRateLimitFileAllowsThenBlocks(): void {
    $key = 'phpunit-' . bin2hex(random_bytes(4));
    $r1 = rlHitFile($key, 2, 60);
    $r2 = rlHitFile($key, 2, 60);
    $r3 = rlHitFile($key, 2, 60);
    $this->assertTrue($r1['allowed']);
    $this->assertTrue($r2['allowed']);
    $this->assertFalse($r3['allowed']);
    $this->assertGreaterThanOrEqual(1, $r3['retryAfter']);
  }

  public function testSreNonRouteReturnsNull(): void {
    $this->assertNull(sre_handle_route('GET', '/ekskul'));
    $this->assertNull(sre_handle_route('POST', '/auth/login'));
  }

  public function testSreStatusRequiresLogin(): void {
    unset($_SESSION['user']);
    $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../Support/sre_status_probe.php') . ' GET /sre/status';
    exec($cmd, $out, $code);
    $this->assertSame(0, $code);
    $text = implode("\n", $out);
    $this->assertMatchesRegularExpression('/__STATUS__:401/', $text);
    $this->assertStringContainsString('UNAUTHORIZED', $text);
  }
}
