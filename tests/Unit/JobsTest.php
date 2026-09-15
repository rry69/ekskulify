<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Unit pseudo-queue Opsi A (sqlite :memory:): enqueue->claim->done,
// retry 1-2-3->failed, reap stale, claim kosong->null.
final class JobsTest extends TestCase {
  private PDO $pdo;

  protected function setUp(): void {
    require_once dirname(__DIR__, 2) . '/api/jobs.php';
    $this->pdo = new PDO('sqlite::memory:');
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->pdo->exec("CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, payload TEXT NOT NULL DEFAULT '{}', result TEXT NULL, error TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $this->pdo->exec("CREATE INDEX idx_jobs_claim ON jobs(status, run_at)");
  }

  public function testEnqueueClaimDone(): void {
    $id = jobs_enqueue($this->pdo, 'notify', ['audience' => 'admins', 'title' => 't']);
    $this->assertIsInt($id);
    $job = jobs_claim($this->pdo);
    $this->assertIsArray($job);
    $this->assertSame('running', $job['status']);
    $this->assertTrue(jobs_done($this->pdo, (int)$job['id'], '{"ok":true}'));
    $row = jobs_get($this->pdo, (int)$job['id']);
    $this->assertSame('done', $row['status']);
  }

  public function testRejectUnknownType(): void {
    $this->assertNull(jobs_enqueue($this->pdo, 'hack', []));
  }

  public function testRetryBackoffThenFailed(): void {
    $id = jobs_enqueue($this->pdo, 'backup', []);
    $this->assertIsInt($id);
    $job = jobs_claim($this->pdo);
    // fail 1 -> pending (backoff 5 mnt)
    $this->assertSame('pending', jobs_fail($this->pdo, (int)$job['id'], 'e1'));
    $row = jobs_get($this->pdo, (int)$job['id']);
    $this->assertSame('pending', $row['status']);
    // paksa run_at lampau agar bisa claim lagi, fail 2 -> pending
    $this->pdo->prepare('UPDATE jobs SET run_at=?, attempts=1 WHERE id=?')->execute(['2000-01-01 00:00:00', $id]);
    $job2 = jobs_claim($this->pdo);
    $this->assertIsArray($job2);
    $this->assertSame('pending', jobs_fail($this->pdo, (int)$job2['id'], 'e2'));
    // attempts=3 -> failed
    $this->pdo->prepare('UPDATE jobs SET run_at=?, attempts=3, status=? WHERE id=?')->execute(['2000-01-01 00:00:00', 'running', $id]);
    $this->assertSame('failed', jobs_fail($this->pdo, $id, 'e3'));
    $row = jobs_get($this->pdo, $id);
    $this->assertSame('failed', $row['status']);
  }

  public function testReapStale(): void {
    $id = jobs_enqueue($this->pdo, 'export_laporan', ['tipe' => 'ekskul']);
    $job = jobs_claim($this->pdo);
    $this->assertIsArray($job);
    // lease habis: run_at lampau + status running
    $this->pdo->prepare('UPDATE jobs SET run_at=? WHERE id=?')->execute(['2000-01-01 00:00:00', $id]);
    $this->assertSame(1, jobs_reap_stale($this->pdo));
    $row = jobs_get($this->pdo, $id);
    $this->assertSame('pending', $row['status']);
  }

  public function testClaimEmptyReturnsNull(): void {
    $this->assertNull(jobs_claim($this->pdo));
    // future run_at tidak ke-claim
    jobs_enqueue($this->pdo, 'notify', [], date('Y-m-d H:i:s', time() + 3600));
    $this->assertNull(jobs_claim($this->pdo));
  }
}
