<?php
/**
 * api/sre/backup.php — Backup & DR TANPA mysqldump (PDO-based) + restore.
 * Zero-setup shared hosting: backup via PHP murni (SHOW TABLES / SHOW CREATE /
 * stream fetch + PDO::quote), output SQL ke api/backups/ (auto-mkdir 0755).
 * Restore WAJIB $force=true + konfirmasi: CLI (PHP_SAPI cli) atau web admin
 * (CSRF divalidasi di lapisan index.php) — jangan pernah restore tanpa itu.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/alert.php';

/**
 * Koneksi PDO reuse pdo()/pdoRaw() utama (db.php) bila tersedia — hapus
 * koneksi ke-2. Fallback mandiri hanya untuk script standalone tanpa db.php.
 * Persistent default OFF (DB_PERSISTENT=1 untuk ON). Gagal -> null.
 */
function sre_pdo_connect(int $timeoutSec = 5, ?bool $persistent = null, bool $reuse = true): ?PDO {
  try {
    if ($reuse) {
      if (function_exists('pdo')) { try { $p = pdo(); if ($p instanceof PDO) return $p; } catch (Throwable $e) {} }
      if (function_exists('pdoRaw')) { try { $p = pdoRaw(); if ($p instanceof PDO) return $p; } catch (Throwable $e) {} }
    }
    $tmpCfg = @require __DIR__ . '/../config.php';
    $cfg = is_array($tmpCfg) ? $tmpCfg : ($GLOBALS['__app_config'] ?? []);
    if(!is_array($cfg) || empty($cfg)) $cfg = $GLOBALS['__app_config'] ?? [];
    $dsn = 'mysql:host=' . ($cfg['db_host'] ?? '127.0.0.1') . ';dbname=' . ($cfg['db_name'] ?? '') . ';charset=utf8mb4';
    if ($persistent === null) $persistent = (getenv('DB_PERSISTENT') === '1');
    $opts = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_PERSISTENT         => $persistent,
      PDO::ATTR_TIMEOUT            => $timeoutSec,
    ];
    if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) $opts[constant('Pdo\\Mysql::ATTR_INIT_COMMAND')] = "SET NAMES utf8mb4, SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'";
    else if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) $opts[@constant('PDO::MYSQL_ATTR_INIT_COMMAND')] = "SET NAMES utf8mb4, SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'";
    return new PDO($dsn, $cfg['db_user'] ?? '', $cfg['db_pass'] ?? '', $opts);
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * Direktori backup (default api/backups) — relatif terhadap __DIR__.
 */
function sre_backup_dir(): string {
  return (string)sre_sec('backup_dir', __DIR__ . '/../backups');
}

/**
 * Direktori uploads (api/uploads).
 */
function sre_backup_uploads_dir(): string {
  return __DIR__ . '/../uploads';
}

/**
 * Auto-mkdir folder backup 0755 + pastikan writable.
 */
function sre_backup_ensure_dirs(): bool {
  $dir = sre_backup_dir();
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return is_dir($dir) && is_writable($dir);
}

/**
 * Kutip identifier MySQL dengan backtick (aman untuk nama tabel aneh).
 */
function sre_backup_ident(string $t): string {
  return '`' . str_replace('`', '``', $t) . '`';
}

/**
 * Tulis INSERT per chunk (maks 100 baris/statement) untuk satu tabel.
 */
function sre_backup_write_inserts($fh, PDO $pdo, string $tbl, array $rows): void {
  foreach (array_chunk($rows, 100) as $chunk) {
    $vals = [];
    foreach ($chunk as $row) {
      $esc = [];
      foreach ($row as $v) {
        $esc[] = ($v === null) ? 'NULL' : $pdo->quote((string)$v);
      }
      $vals[] = '(' . implode(',', $esc) . ')';
    }
    @fwrite($fh, 'INSERT INTO ' . $tbl . " VALUES\n" . implode(",\n", $vals) . ";\n");
  }
}

/**
 * Backup seluruh database user via PDO (tanpa mysqldump). Stream fetch:
 * buffer max 500 baris per tabel, INSERT batch 100 baris/statement sehingga
 * RAM tetap kecil. Tabel `cache` (sementara) di-skip. Gagal di tengah ->
 * file partial di-unlink lalu throw. Return path file SQL.
 */
function sre_backup_db(): string {
  if (!sre_backup_ensure_dirs()) {
    throw new RuntimeException('backup dir tidak writable: ' . sre_backup_dir());
  }
  $pdo = sre_pdo_connect(10, false);
  if (!$pdo) throw new RuntimeException('koneksi DB gagal untuk backup');

  $time = date('Y-m-d-His'); // db-YYYY-MM-DD-HHmmss.sql (spec) — regex retensi mengandalkan His
  $path = sre_backup_dir() . '/db-' . $time . '.sql';
  $fh   = @fopen($path, 'w');
  if (!$fh) throw new RuntimeException('tidak bisa buka file backup SQL');
  @flock($fh, LOCK_EX);
  try {
    $engine = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    @fwrite($fh, "-- SRE DB backup generated at " . date('c') . "\n");
    @fwrite($fh, "-- Driver: {$engine} — format MySQL/MariaDB compatible (api/sre/backup.php)\n");
    @fwrite($fh, "SET NAMES utf8mb4;\n");
    @fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $tbl) {
      if ($tbl === 'cache') { // tabel jelas sementara -> skip
        @fwrite($fh, "-- SKIP tabel sementara: {$tbl}\n");
        continue;
      }
      $t      = sre_backup_ident($tbl);
      $create = $pdo->query('SHOW CREATE TABLE ' . $t)->fetch();
      $isView = false;
      $createSql = $create['Create Table'] ?? '';
      if ($createSql === '') {
        $createSql = $create['Create View'] ?? '';
        $isView = $createSql !== '';
      }
      if ($createSql === '') continue;
      @fwrite($fh, "-- ===== Table: {$tbl} =====\n");
      @fwrite($fh, ($isView ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $t . ";\n");
      @fwrite($fh, $createSql . ";\n");

      $stmt = null;
      // unbuffered WAJIB sebelum query — sesudah query = no-op
      try { $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'), false); } catch (Throwable $e) {}
      $stmt = $pdo->query('SELECT * FROM ' . $t);
      // sweep §6: unbuffered + unset($row) per iterasi (RAM tetap kecil utk tabel besar)
      $buf  = [];
      while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        $buf[] = $row;
        unset($row);
        if (count($buf) >= 500) { // flush buffer per 500 baris -> 100/INSERT
          sre_backup_write_inserts($fh, $pdo, $t, $buf);
          $buf = [];
        }
      }
      if ($buf) sre_backup_write_inserts($fh, $pdo, $t, $buf);
      $stmt->closeCursor();
    }
    @fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    @fwrite($fh, "-- EOF backup\n");
    @fflush($fh);
  } catch (Throwable $e) {
    @flock($fh, LOCK_UN);
    @fclose($fh);
    if (is_file($path)) @unlink($path); // jangan tinggalkan file partial
    throw $e;
  }
  @flock($fh, LOCK_UN);
  @fclose($fh);
  return $path;
}

/**
 * Backup uploads ke api/backups. ZipArchive bila extension zip ada (skip
 * file >50MB, nama internal relatip); fallback copy tree bila zip tidak ada.
 * Return path arsip (zip/dir) atau null bila uploads kosong/absent.
 */
function sre_backup_uploads(): ?string {
  $src = sre_backup_uploads_dir();
  if (!is_dir($src)) return null;
  $files = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $size = $f->getSize();
    if ($size > 52428800) continue; // skip file > 50MB
    $files[] = [$f->getPathname(), $size];
  }
  if (!$files) return null;
  if (!sre_backup_ensure_dirs()) return null;

  $time = date('Y-m-d-His'); // sama format timestamp dgn db dump
  if (class_exists('ZipArchive')) {
    $path = sre_backup_dir() . '/uploads-' . $time . '.zip';
    $zip  = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
    $prefixLen = strlen($src) + 1;
    foreach ($files as [$abs]) {
      $local = str_replace('\\', '/', substr($abs, $prefixLen)); // nama zip relatip
      $zip->addFile($abs, $local);
    }
    $zip->close();
    return is_file($path) ? $path : null;
  }
  // Fallback: copy tree (ekstensi zip tidak tersedia di hosting)
  $dest = sre_backup_dir() . '/uploads-' . $time;
  if (!@mkdir($dest, 0755, true)) return null;
  $prefixLen = strlen($src) + 1;
  foreach ($files as [$abs]) {
    $rel    = substr($abs, $prefixLen);
    $target = $dest . DIRECTORY_SEPARATOR . $rel;
    if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
    @copy($abs, $target);
  }
  return is_dir($dest) ? $dest : null;
}

/**
 * Ukuran total byte sebuah direktori (rekursif).
 */
function sre_backup_dir_size(string $dir): int {
  $n = 0;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) { if ($f->isFile()) $n += $f->getSize(); }
  return $n;
}

/**
 * Baca api/backups/last_backup.json (dengan LOCK_SH) atau null bila absent.
 */
function sre_backup_last(): ?array {
  $f = sre_backup_dir() . '/last_backup.json';
  if (!is_file($f)) return null;
  $fh = @fopen($f, 'r');
  if (!$fh) return null;
  @flock($fh, LOCK_SH);
  $raw = @stream_get_contents($fh);
  @flock($fh, LOCK_UN);
  @fclose($fh);
  $j = $raw ? @json_decode($raw, true) : null;
  return is_array($j) ? $j : null;
}

/**
 * Jalankan backup DB (+ uploads bila enabled) lalu tulis last_backup.json
 * {ts, db_file, uploads_file, size_bytes, status}. DB gagal -> alert
 * backup_fail + status 'failed'. Tidak melempar ke pemanggil.
 */
function sre_backup_run(): array {
  $status = 'ok';
  $dbFile = null;
  $uploadsFile = null;
  $error  = null;
  $sizeBytes = 0;
  try {
    $dbFile = sre_backup_db();
    $sizeBytes += (int)@filesize($dbFile);
  } catch (Throwable $e) {
    sre_alert_on_backup_fail($e->getMessage());
    sre_log(3, 'sre_backup_run db gagal', ['error' => $e->getMessage()]);
    $status = 'failed';
    $error  = $e->getMessage();
  }
  if ($status !== 'failed' && (bool)sre_sec('backup_uploads_enabled', true)) {
    try {
      $uploadsFile = sre_backup_uploads();
      if ($uploadsFile !== null) {
        $sizeBytes += is_dir($uploadsFile) ? sre_backup_dir_size($uploadsFile) : (int)@filesize($uploadsFile);
      }
    } catch (Throwable $e) {
      sre_log(2, 'sre_backup_run uploads gagal (partial)', ['error' => $e->getMessage()]);
      $status = 'partial';
      $error  = $e->getMessage();
    }
  }
  // Normalisasi path ke bentuk kanonik (realpath) sebelum ditulis — jangan
  // simpan "api/sre/../backups/..." di last_backup.json. Reader (sre_backup_last,
  // status page) hanya menampilkan/membaca, tidak terpengaruh absolut vs relatif.
  $dbFile = $dbFile !== null ? (realpath($dbFile) !== false ? realpath($dbFile) : $dbFile) : null;
  $uploadsFile = $uploadsFile !== null ? (realpath($uploadsFile) !== false ? realpath($uploadsFile) : $uploadsFile) : null;
  $rec = ['ts' => time(), 'db_file' => $dbFile, 'uploads_file' => $uploadsFile, 'size_bytes' => $sizeBytes, 'status' => $status, 'error' => $error];
  if (sre_backup_ensure_dirs()) {
    $last = sre_backup_dir() . '/last_backup.json';
    $fh = @fopen($last, 'c+');
    if ($fh) {
      @flock($fh, LOCK_EX);
      @ftruncate($fh, 0);
      @rewind($fh);
      @fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      @fflush($fh);
      @flock($fh, LOCK_UN);
      @fclose($fh);
    }
  }
  sre_log(1, 'sre_backup_run selesai', ['status' => $status, 'db' => $dbFile, 'uploads' => $uploadsFile, 'bytes' => $sizeBytes]);
  return $rec;
}

/**
 * Verifikasi file backup SQL: header `--`, ada CREATE TABLE, size > 512 byte.
 * Return {ok, tables, size} (+header_ok untuk debug).
 */
function sre_backup_verify(string $sqlPath): array {
  $res = ['ok' => false, 'tables' => 0, 'size' => 0, 'header_ok' => false];
  if (!is_file($sqlPath)) return $res;
  $res['size'] = (int)@filesize($sqlPath);
  if ($res['size'] <= 512) return $res;
  $fh = @fopen($sqlPath, 'r');
  if (!$fh) return $res;
  // sweep §6: hitung CREATE TABLE per-chunk 1MB + overlap 64B, tanpa concat seluruh file
  $head  = (string)@fread($fh, 2048);
  $tables = 0;
  $carry = '';
  while (!feof($fh)) {
    $b = fread($fh, 1048576);
    if ($b === false || $b === '') break;
    $tables += preg_match_all('~CREATE TABLE~i', $carry . $b, $m);
    $carry = strlen($b) >= 64 ? substr($b, -64) : ($carry . $b);
    if (strlen($carry) > 64) $carry = substr($carry, -64);
    unset($b);
  }
  @fclose($fh);
  $res['header_ok'] = (strpos($head, '--') !== false);
  $res['tables']    = $tables;
  $res['ok'] = $res['header_ok'] && $res['tables'] > 0 && $res['size'] > 512;
  return $res;
}

/**
 * Retensi backup: pertahankan 7 harian TERAKHIR + 4 mingguan (Minggu),
 * hapus sisanya; arsip uploads retensi sama (minimal 3). Semua dihapus
 * di-log. Return {kept, weekly, deleted} untuk audit.
 */
function sre_backup_retention(): array {
  $dir = sre_backup_dir();
  $kept = [];
  $weekly = [];
  $deleted = [];
  if (!is_dir($dir)) return ['kept' => $kept, 'weekly' => $weekly, 'deleted' => $deleted];
  $daily  = max(1, (int)sre_sec('backup_retention_daily', 7));
  $weeklyN = max(1, (int)sre_sec('backup_retention_weekly', 4));

  $parseTs = function (string $date, string $time): int {
    $t = strtotime($date . ' ' . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2));
    return $t !== false ? $t : 0;
  };
  // ---- db backups ----
  $dbs = [];
  foreach (glob($dir . '/db-*.sql') ?: [] as $f) {
    if (!preg_match('~db-(\d{4}-\d{2}-\d{2})-(\d{6})\.sql$~', $f, $m)) continue;
    $dbs[] = ['path' => $f, 'ts' => $parseTs($m[1], $m[2]), 'date' => $m[1], 'weekly' => date('w', $parseTs($m[1], $m[2])) === '0'];
  }
  usort($dbs, fn($a, $b) => $b['ts'] <=> $a['ts']);
  $keep = [];
  foreach (array_slice($dbs, 0, $daily) as $d) $keep['D' . $d['path']] = 1;
  $wk = 0;
  foreach ($dbs as $d) {
    if ($d['weekly'] && $wk < $weeklyN) { $keep['W' . $d['path']] = 1; $wk++; $weekly[] = basename($d['path']); }
  }
  foreach ($dbs as $d) {
    if (isset($keep['D' . $d['path']]) || isset($keep['W' . $d['path']])) { $kept[] = basename($d['path']); }
    else { @unlink($d['path']); $deleted[] = basename($d['path']); }
  }
  // ---- arsip uploads (zip ATAU folder fallback) retensi sama, minimal 3 ----
  $ups = [];
  foreach (glob($dir . '/uploads-*') ?: [] as $f) {
    if (is_dir($f)) {
      if (preg_match('~uploads-(\d{4}-\d{2}-\d{2})-(\d{6})$~', $f, $m)) $ups[] = ['path' => $f, 'ts' => $parseTs($m[1], $m[2])];
      continue;
    }
    if (preg_match('~uploads-(\d{4}-\d{2}-\d{2})-(\d{6})\.zip$~', $f, $m)) $ups[] = ['path' => $f, 'ts' => $parseTs($m[1], $m[2])];
  }
  usort($ups, fn($a, $b) => $b['ts'] <=> $a['ts']);
  $upsKeep = max($daily, 3);
  foreach ($ups as $i => $u) {
    if ($i >= $upsKeep) { @unlink($u['path']); $deleted[] = is_dir($u['path']) ? basename($u['path']) : basename($u['path']); }
  }
  if ($deleted) sre_log(1, 'sre_backup_retention hapus', ['deleted' => $deleted]);
  return ['kept' => $kept, 'weekly' => $weekly, 'deleted' => $deleted];
}

/**
 * Pemecah statement SQL yang aman multi-byte: tidak memecah titik-koma di
 * dalam string literal '...' (data user bisa mengandung ';'), menangani ''
 * escape dan backslash, serta komentar `--` per baris. Menggantikan regex
 * dasar `~;\s*$~m` (yang rawan pecah) dengan hasil yang setara dan aman.
 */
function sre_sql_split_statements(string $sql): array {
  $out = [];
  $n   = strlen($sql);
  $cur = '';
  $inQ = false;
  $i   = 0;
  while ($i < $n) {
    $c = $sql[$i];
    if ($inQ) {
      if ($c === "\\" && $i + 1 < $n) { $cur .= $c . $sql[$i + 1]; $i += 2; continue; }
      if ($c === "'") {
        if ($i + 1 < $n && $sql[$i + 1] === "'") { $cur .= "''"; $i += 2; continue; }
        $cur .= $c; $inQ = false; $i++; continue;
      }
      $cur .= $c; $i++; continue;
    }
    if ($c === "'") { $cur .= $c; $inQ = true; $i++; continue; }
    if ($c === ';') { $out[] = $cur; $cur = ''; $i++; continue; }
    if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-') { // komentar `--`
      while ($i < $n && $sql[$i] !== "\n") { $cur .= $sql[$i]; $i++; }
      continue;
    }
    $cur .= $c; $i++;
  }
  if (trim($cur) !== '') $out[] = $cur;
  return $out;
}

/**
 * Restore SQL backup ke DB. GUARD ketat: WAJIB $force===true DAN konfirmasi
 * pemanggil — CLI (PHP_SAPI cli) atau web admin (integrasi wajib csrfCheck
 * sebelum memanggil fungsi ini). Eksekusi dalam transaksi; gagal -> rollback.
 * Backup TIDAK dihapus setelah restore. Return {ok, statements, error?}.
 */
function sre_backup_restore(string $sqlPath, bool $force = false): array {
  if ($force !== true) return ['ok' => false, 'statements' => 0, 'error' => 'FORCE_REQUIRED'];
  if (!is_file($sqlPath)) return ['ok' => false, 'statements' => 0, 'error' => 'FILE_NOT_FOUND'];
  $viaCli     = (PHP_SAPI === 'cli');
  $viaAdminWeb = !empty($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
  if (!$viaCli && !$viaAdminWeb) {
    return ['ok' => false, 'statements' => 0, 'error' => 'CONFIRMATION_REQUIRED'];
  }
  $pdo = sre_pdo_connect(20, false);
  if (!$pdo) return ['ok' => false, 'statements' => 0, 'error' => 'DB_UNAVAILABLE'];
  $sql = (string)@file_get_contents($sqlPath);
  if ($sql === '') return ['ok' => false, 'statements' => 0, 'error' => 'EMPTY_FILE'];

  $stmts   = sre_sql_split_statements($sql);
  $executed = 0;
  try {
    $pdo->beginTransaction();
  } catch (Throwable $e) {
    return ['ok' => false, 'statements' => 0, 'error' => 'beginTransaction: ' . $e->getMessage()];
  }
  foreach ($stmts as $st) {
    $st = trim($st);
    if ($st === '') continue;
    try {
      if (@$pdo->exec($st) === false) {
        $info = $pdo->errorInfo();
        throw new RuntimeException('exec gagal: ' . implode(' | ', (array)$info));
      }
      $executed++;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $x) {}
      }
      return ['ok' => false, 'statements' => $executed, 'error' => $e->getMessage() . ' [stmt#' . $executed . ']'];
    }
  }
  // Catatan: MySQL auto-commit statement DDL (DROP/CREATE) di dalam transaksi,
  // sehingga transaksi bisa sudah berakhir lebih dulu — commit hanya bila aktif.
  if ($pdo->inTransaction()) {
    try {
      $pdo->commit();
    } catch (Throwable $e) {
      try { $pdo->rollBack(); } catch (Throwable $x) {}
      return ['ok' => false, 'statements' => $executed, 'error' => 'commit gagal: ' . $e->getMessage()];
    }
  }
  sre_log(1, 'sre_backup_restore sukses', ['file' => basename($sqlPath), 'statements' => $executed]);
  return ['ok' => true, 'statements' => $executed];
}

/**
 * Apakah backup sudah "jatuh tempo"? Due bila last_backup.json belum ada
 * atau age >= backup_every_sec. Dipakai sre_tick().
 */
function sre_backup_due(): bool {
  $last  = sre_backup_last();
  $every = max(1, (int)sre_sec('backup_every_sec', 86400));
  return $last === null || !isset($last['ts']) || (time() - (int)$last['ts']) >= $every;
}