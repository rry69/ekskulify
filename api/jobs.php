<?php
// api/jobs.php — pseudo-queue zero-infra (PHP+MySQL+cron+file only, no Redis/daemon).
// Require-safe: hanya definisi fungsi, tanpa top-level router/session/exit.
// JANGAN require api/index.php dari sini (monolit 4781 baris, top-level router jalan).
if (!function_exists('jobs_is_sqlite')) {
function jobs_is_sqlite(PDO $pdo): bool {
  try { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; } catch (Throwable $e) { return false; }
}
}
if (!function_exists('jobs_now_expr')) {
function jobs_now_expr(PDO $pdo): string {
  return jobs_is_sqlite($pdo) ? "datetime('now')" : 'NOW()';
}
}
if (!function_exists('jobs_table_exists')) {
function jobs_table_exists(PDO $pdo): bool {
  try {
    if (jobs_is_sqlite($pdo)) {
      $r = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='jobs'")->fetch();
      return (bool)$r;
    }
    $r = $pdo->query('SELECT 1 FROM jobs LIMIT 0');
    if ($r) { $r->closeCursor(); return true; }
    return true;
  } catch (Throwable $e) { return false; }
}
}
if (!function_exists('jobs_enqueue')) {
// Whitelist 4 type. Return int job id, atau null bila tabel belum ada / gagal (caller fallback sync).
function jobs_enqueue(PDO $pdo, string $type, array $payload = [], ?string $runAt = null): ?int {
  static $allowed = ['notify' => 1, 'backup' => 1, 'sertifikat_batch' => 1, 'export_laporan' => 1];
  if (!isset($allowed[$type])) return null;
  try {
    if (!jobs_table_exists($pdo)) return null;
    $runAt = $runAt ?: date('Y-m-d H:i:s');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = '{}';
    $st = $pdo->prepare('INSERT INTO jobs(type,status,attempts,run_at,payload) VALUES (?,\'pending\',0,?,?)');
    $st->execute([$type, $runAt, $json]);
    $id = (int)$pdo->lastInsertId();
    return $id > 0 ? $id : null;
  } catch (Throwable $e) { return null; }
}
}
if (!function_exists('jobs_claim')) {
// Claim 1 job pending yang sudah jatuh tempo. Lease 10 menit via run_at = now+lease.
// Coba SELECT ... FOR UPDATE SKIP LOCKED dalam transaksi; fallback UPDATE race-safe via id.
function jobs_claim(PDO $pdo, int $leaseSec = 600): ?array {
  try {
    if (!jobs_table_exists($pdo)) return null;
    $sqlite = jobs_is_sqlite($pdo);
    // Banding jatuh tempo pakai jam PHP (Asia/Jakarta) — bukan NOW() DB:
    // sqlite datetime('now')=UTC selisih 7 jam dari run_at PHP, bikin claim selalu null.
    $nowLit = $pdo->quote(date('Y-m-d H:i:s'));
    // Path 1: row-level lock (MySQL). SQLite lempar -> rollback -> fallback.
    try {
      $pdo->beginTransaction();
      $sql = "SELECT * FROM jobs WHERE status='pending' AND run_at<=$nowLit ORDER BY run_at ASC, id ASC LIMIT 1 FOR UPDATE SKIP LOCKED";
      $st = $pdo->query($sql);
      $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
      if ($st) $st->closeCursor();
      if (!$row) { $pdo->rollBack(); return null; }
      $lease = date('Y-m-d H:i:s', time() + $leaseSec);
      $up = $pdo->prepare('UPDATE jobs SET status=\'running\', attempts=attempts+1, run_at=? WHERE id=?');
      $up->execute([$lease, $row['id']]);
      $pdo->commit();
      $row['status'] = 'running';
      $row['attempts'] = (int)($row['attempts'] ?? 0) + 1;
      $row['run_at'] = $lease;
      return $row;
    } catch (Throwable $e) {
      try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $x) {}
      // SQLite / MySQL tanpa SKIP LOCKED -> fallback di bawah
      if (!$sqlite && stripos($e->getMessage(), 'jobs') !== false && stripos($e->getMessage(), 'exist') !== false) return null;
    }
    // Path 2 (fallback universal): SELECT id dulu, lalu UPDATE ... WHERE id AND status pending.
    $sel = $pdo->query("SELECT id FROM jobs WHERE status='pending' AND run_at<=$nowLit ORDER BY run_at ASC, id ASC LIMIT 1");
    $cand = $sel ? $sel->fetch(PDO::FETCH_ASSOC) : false;
    if ($sel) $sel->closeCursor();
    if (!$cand) return null;
    $lease = date('Y-m-d H:i:s', time() + $leaseSec);
    $up = $pdo->prepare('UPDATE jobs SET status=\'running\', attempts=attempts+1, run_at=? WHERE id=? AND status=\'pending\'');
    $up->execute([$lease, $cand['id']]);
    if ($up->rowCount() < 1) return null; // kalah race
    $g = $pdo->prepare('SELECT * FROM jobs WHERE id=?');
    $g->execute([$cand['id']]);
    $row = $g->fetch(PDO::FETCH_ASSOC);
    if ($g) $g->closeCursor();
    return $row ?: null;
  } catch (Throwable $e) { try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $x) {} return null; }
}
}
if (!function_exists('jobs_done')) {
function jobs_done(PDO $pdo, int $id, ?string $result = null): bool {
  try {
    if ($result !== null) {
      $st = $pdo->prepare('UPDATE jobs SET status=\'done\', result=? WHERE id=?');
      $st->execute([$result, $id]);
    } else {
      $st = $pdo->prepare('UPDATE jobs SET status=\'done\' WHERE id=?');
      $st->execute([$id]);
    }
    return $st->rowCount() >= 0;
  } catch (Throwable $e) { return false; }
}
}
if (!function_exists('jobs_fail')) {
// attempts>=3 -> failed, else pending + backoff 5 menit.
function jobs_fail(PDO $pdo, int $id, string $error = '', int $backoffSec = 300): string {
  try {
    $g = $pdo->prepare('SELECT attempts FROM jobs WHERE id=?');
    $g->execute([$id]);
    $row = $g->fetch(PDO::FETCH_ASSOC);
    if ($g) $g->closeCursor();
    $att = (int)($row['attempts'] ?? 0);
    if ($att >= 3) {
      $st = $pdo->prepare('UPDATE jobs SET status=\'failed\', error=? WHERE id=?');
      $st->execute([substr($error, 0, 2000), $id]);
      return 'failed';
    }
    $runAt = date('Y-m-d H:i:s', time() + $backoffSec);
    $st = $pdo->prepare('UPDATE jobs SET status=\'pending\', run_at=?, error=? WHERE id=?');
    $st->execute([$runAt, substr($error, 0, 2000), $id]);
    return 'pending';
  } catch (Throwable $e) { return 'pending'; }
}
}
if (!function_exists('jobs_reap_stale')) {
// running + run_at<=NOW (lease habis) -> requeue pending / failed bila attempts>=3.
function jobs_reap_stale(PDO $pdo, int $backoffSec = 300): int {
  try {
    if (!jobs_table_exists($pdo)) return 0;
    $nowLit = $pdo->quote(date('Y-m-d H:i:s')); // jam PHP, bukan NOW() DB (alasan sama spt jobs_claim)
    $st = $pdo->query("SELECT id, attempts FROM jobs WHERE status='running' AND run_at<=$nowLit LIMIT 20");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($st) $st->closeCursor();
    $n = 0;
    foreach ($rows as $r) {
      $id = (int)$r['id'];
      if ((int)($r['attempts'] ?? 0) >= 3) {
        try { $pdo->prepare('UPDATE jobs SET status=\'failed\', error=? WHERE id=? AND status=\'running\'')->execute(['lease expired (reaped)', $id]); $n++; } catch (Throwable $e) {}
      } else {
        $runAt = date('Y-m-d H:i:s', time() + $backoffSec);
        try { $pdo->prepare('UPDATE jobs SET status=\'pending\', run_at=?, error=? WHERE id=? AND status=\'running\'')->execute([$runAt, 'lease expired (reaped)', $id]); $n++; } catch (Throwable $e) {}
      }
    }
    return $n;
  } catch (Throwable $e) { return 0; }
}
}
if (!function_exists('jobs_get')) {
function jobs_get(PDO $pdo, int $id): ?array {
  try {
    $st = $pdo->prepare('SELECT * FROM jobs WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($st) $st->closeCursor();
    return $row ?: null;
  } catch (Throwable $e) { return null; }
}
}
