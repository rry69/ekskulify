<?php
// api/job_handlers.php — worker handlers utk pseudo-queue zero-infra.
// Require-safe: hanya definisi fungsi. JANGAN require api/index.php di sini.
// Dipakai oleh api/cron.php (CLI). Semua fungsi terima PDO eksplisit.
if (!function_exists('jh_settings_map')) {
function jh_settings_map(PDO $pdo): array {
  try {
    $st = $pdo->query("SELECT k, v FROM app_settings WHERE k IN ('sekolah_nama','sekolah_alamat','sekolah_telp','kepsek_nama','kepsek_nip')");
    $m = [];
    foreach (($st ? $st->fetchAll() : []) as $r) $m[$r['k']] = $r['v'];
    return $m;
  } catch (Throwable $e) { return []; }
}
}
if (!function_exists('jh_sertifikat_secret')) {
function jh_sertifikat_secret(PDO $pdo): string {
  try {
    $st = $pdo->prepare('SELECT v FROM app_settings WHERE k=?');
    $st->execute(['sertifikat_secret']);
    $s = $st->fetch()['v'] ?? '';
    if ($s === '') {
      $s = bin2hex(random_bytes(32));
      try { $pdo->prepare('INSERT OR REPLACE INTO app_settings(k,v) VALUES (?,?)')->execute(['sertifikat_secret', $s]); } catch (Throwable $e) {}
    }
    return (string)$s;
  } catch (Throwable $e) { return bin2hex(random_bytes(16)); }
}
}
if (!function_exists('jh_sertifikat_nomor')) {
function jh_sertifikat_nomor(int $id): string {
  return sprintf('%04d/SERT-EVT/%s/%05d', (int)date('Y'), date('m'), $id);
}
}
if (!function_exists('jh_eligible')) {
function jh_eligible(PDO $pdo, int $uid, int $tid): array {
  try {
    $row = $pdo->prepare('SELECT ep.hadir, e.id AS ev_ok FROM events e LEFT JOIN event_participants ep ON ep.event_id=e.id AND ep.user_id=? WHERE e.id=? AND e.deleted_at IS NULL');
    $row->execute([$uid, $tid]);
    $r = $row->fetch();
    if (!$r || $r['ev_ok'] === null) return [false, 'Event tidak ditemukan'];
    if ($r['hadir'] === null) return [false, 'Belum terdaftar di event ini'];
    if ((int)($r['hadir'] ?? 0) !== 1) return [false, 'Belum ditandai hadir di event ini (hadir=0)'];
    return [true, 'OK'];
  } catch (Throwable $e) { return [false, 'db: ' . $e->getMessage()]; }
}
}
if (!function_exists('handleNotify')) {
// Payload: ['audience'=>'admins|kepsek', 'title'=>..,'message'=>..,'type'=>..,'group_key'=>..,'related_id'=>..]
// Loop INSERT dipindah dari helpers.php:77-120 (dedupe group_key 1 hari + fallback kolom).
function handleNotify(PDO $pdo, array $p): array {
  $aud = ($p['audience'] ?? 'admins') === 'kepsek' ? 'kepsek' : 'admins';
  $title = (string)($p['title'] ?? '');
  $message = (string)($p['message'] ?? '');
  $type = (string)($p['type'] ?? 'admin_alert');
  $groupKey = isset($p['group_key']) ? (string)$p['group_key'] : null;
  $relatedId = isset($p['related_id']) ? (int)$p['related_id'] : null;
  $role = $aud === 'kepsek' ? 'kepsek' : 'admin';
  $isSqlite = false;
  try { $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'); } catch (Throwable $e) {}
  $users = $pdo->query("SELECT id FROM users WHERE role=" . $pdo->quote($role) . ' AND deleted_at IS NULL')->fetchAll();
  $n = 0;
  foreach ($users as $u) {
    $uid = (int)$u['id'];
    if ($groupKey) {
      try {
        $since = $isSqlite ? "datetime('now','-1 day')" : 'DATE_SUB(NOW(), INTERVAL 1 DAY)';
        $chk = $pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND group_key=? AND is_read=0 AND created_at >= $since LIMIT 1");
        $chk->execute([$uid, $groupKey]);
        $row = $chk->fetch();
        if ($row) {
          $nowF = $isSqlite ? "datetime('now')" : 'NOW()';
          $pdo->prepare("UPDATE notifications SET title=?, message=?, type=?, related_id=?, created_at=$nowF WHERE id=?")->execute([$title, $message, $type, $relatedId, $row['id']]);
          $n++;
          continue;
        }
      } catch (Throwable $e) {}
    }
    try {
      $pdo->prepare('INSERT INTO notifications(user_id,title,message,type,group_key,related_id) VALUES (?,?,?,?,?,?)')->execute([$uid, $title, $message, $type, $groupKey, $relatedId]);
    } catch (Throwable $e) {
      try { $pdo->prepare('INSERT INTO notifications(user_id,title,message,type) VALUES (?,?,?,?)')->execute([$uid, $title, $message, $type]); } catch (Throwable $e2) { continue; }
    }
    $n++;
  }
  return ['ok' => true, 'sent' => $n, 'audience' => $aud];
}
}
if (!function_exists('handleBackup')) {
// Wrapper sre_backup_run (modul SRE). Return rec array.
function handleBackup(PDO $pdo, array $p): array {
  if (!function_exists('sre_backup_run')) {
    $f = __DIR__ . '/sre/backup.php';
    if (is_file($f)) require_once $f;
  }
  if (!function_exists('sre_backup_run')) return ['ok' => false, 'error' => 'sre_backup_run unavailable'];
  $rec = sre_backup_run();
  if (function_exists('sre_backup_retention')) { try { $rec['retention'] = sre_backup_retention(); } catch (Throwable $e) {} }
  $rec['ok'] = ($rec['status'] ?? 'failed') !== 'failed';
  return $rec;
}
}
if (!function_exists('handleSertifikatBatch')) {
// Payload: ['tipe'=>'event','target_id'=>int,'user_ids'=>[int],'label'=>str,'issued_by'=>int]
// Loop per-cert dipindah dari index.php batch generate (idempotent, per-row transaction).
function handleSertifikatBatch(PDO $pdo, array $p): array {
  $tipe = 'event';
  $tid = (int)($p['target_id'] ?? 0);
  $userIds = $p['user_ids'] ?? [];
  $labelRaw = trim((string)($p['label'] ?? 'PESERTA'));
  if ($labelRaw === '') $labelRaw = 'PESERTA';
  $issuedBy = (int)($p['issued_by'] ?? 0);
  if (!$tid) return ['ok' => false, 'error' => 'target_id wajib'];
  if (!is_array($userIds) || !count($userIds)) return ['ok' => false, 'error' => 'user_ids kosong'];
  $userIds = array_unique(array_map('intval', $userIds));
  if (count($userIds) > 500) return ['ok' => false, 'error' => 'max 500 user per batch'];
  $secret = jh_sertifikat_secret($pdo);
  $isSqlite = false;
  try { $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'); } catch (Throwable $e) {}
  $results = []; $created = 0; $skipped = 0; $failed = 0;
  foreach ($userIds as $uid) {
    $uid = (int)$uid;
    if (!$uid) { $results[] = ['user_id' => 0, 'ok' => false, 'error' => 'invalid user_id']; $failed++; continue; }
    $u = $pdo->prepare('SELECT id,nama,email,kelas FROM users WHERE id=? AND deleted_at IS NULL');
    $u->execute([$uid]);
    $urow = $u->fetch();
    if (!$urow) { $results[] = ['user_id' => $uid, 'ok' => false, 'error' => 'user not found']; $failed++; continue; }
    $dup = $pdo->prepare('SELECT id,hash,nomor FROM certificates WHERE user_id=? AND tipe=? AND target_id=?');
    $dup->execute([$uid, $tipe, $tid]);
    $ex = $dup->fetch();
    if ($ex) { $results[] = ['user_id' => $uid, 'ok' => true, 'exists' => true, 'id' => (int)$ex['id'], 'hash' => $ex['hash'], 'nomor' => $ex['nomor'], 'nama' => $urow['nama']]; $skipped++; continue; }
    [$ok, $msg] = jh_eligible($pdo, $uid, $tid);
    if (!$ok) { $results[] = ['user_id' => $uid, 'ok' => false, 'error' => 'not eligible: ' . $msg, 'nama' => $urow['nama']]; $failed++; continue; }
    try {
      $pdo->beginTransaction();
      $chk = $pdo->prepare('SELECT id FROM certificates WHERE user_id=? AND tipe=? AND target_id=?');
      $chk->execute([$uid, $tipe, $tid]);
      if ($chk->fetch()) { $pdo->rollBack(); $results[] = ['user_id' => $uid, 'ok' => true, 'exists' => true, 'nama' => $urow['nama'], 'error' => 'race duplicate']; $skipped++; continue; }
      $hash = substr(hash_hmac('sha256', bin2hex(random_bytes(32)) . $uid . $tipe . $tid . microtime(true), $secret), 0, 64);
      $tries = 0;
      while ($tries < 3) {
        $hchk = $pdo->prepare('SELECT 1 FROM certificates WHERE hash=?');
        $hchk->execute([$hash]);
        if (!$hchk->fetch()) break;
        $hash = substr(hash_hmac('sha256', bin2hex(random_bytes(32)) . $uid . $tipe . $tid . microtime(true), $secret), 0, 64);
        $tries++;
      }
      $kelasSnapshot = trim((string)($urow['kelas'] ?? ''));
      if ($kelasSnapshot === '') $kelasSnapshot = null;
      $nomor = 'TMP';
      try {
        if ($isSqlite) {
          $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,kelas_snapshot,label,hash,nomor,issued_by,issued_at) VALUES (?,?,?,?,?,?,?,?,"now")')->execute([$uid, $tipe, $tid, $kelasSnapshot, $labelRaw, $hash, $nomor, $issuedBy ?: null]);
        } else {
          $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,kelas_snapshot,label,hash,nomor,issued_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$uid, $tipe, $tid, $kelasSnapshot, $labelRaw, $hash, $nomor, $issuedBy ?: null]);
        }
      } catch (Throwable $eIns) {
        if (stripos($eIns->getMessage(), 'kelas_snapshot') !== false || stripos($eIns->getMessage(), 'label') !== false) {
          if ($isSqlite) { $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,hash,nomor,issued_by,issued_at) VALUES (?,?,?,?,?,?,"now")')->execute([$uid, $tipe, $tid, $hash, $nomor, $issuedBy ?: null]); }
          else { $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,hash,nomor,issued_by) VALUES (?,?,?,?,?,?)')->execute([$uid, $tipe, $tid, $hash, $nomor, $issuedBy ?: null]); }
        } else { throw $eIns; }
      }
      $newId = (int)$pdo->lastInsertId();
      $nomorReal = jh_sertifikat_nomor($newId);
      $pdo->prepare('UPDATE certificates SET nomor=? WHERE id=?')->execute([$nomorReal, $newId]);
      $pdo->commit();
      try { $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$issuedBy ?: null, 'generate_batch', 'certificate', $newId, json_encode(['user_id' => $uid, 'tipe' => $tipe, 'target_id' => $tid, 'nomor' => $nomorReal])]); } catch (Throwable $e) {}
      $results[] = ['user_id' => $uid, 'ok' => true, 'exists' => false, 'id' => $newId, 'hash' => $hash, 'nomor' => $nomorReal, 'nama' => $urow['nama']];
      $created++;
    } catch (Throwable $e) {
      try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $x) {}
      $results[] = ['user_id' => $uid, 'ok' => false, 'error' => 'db: ' . $e->getMessage(), 'nama' => $urow['nama'] ?? ''];
      $failed++;
    }
  }
  return ['ok' => true, 'results' => $results, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'total' => count($userIds)];
}
}
if (!function_exists('jh_export_dir')) {
function jh_export_dir(): string {
  if (function_exists('uploadPath')) { try { return uploadPath('exports'); } catch (Throwable $e) {} }
  $d = __DIR__ . '/uploads/exports';
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}
}
if (!function_exists('handleExportLaporan')) {
// Payload: ['tipe'=>'ekskul|event','id'=>int,'from'=>Y-m-d,'to'=>Y-m-d,'format'=>'csv|xlsx|pdf','session_id'=>?,'by_name'=>?]
// OPSI A: query export di-COPY dari route /laporan/export (bukan di-extract/refactor).
// Render ke FILE api/uploads/exports/job-{id}.{ext} + job-{id}.json (bukan stream).
function handleExportLaporan(PDO $pdo, array $p, int $jobId): array {
  $tipe = ($p['tipe'] ?? '') === 'event' ? 'event' : 'ekskul';
  $id = (int)($p['id'] ?? 0);
  $from = (string)($p['from'] ?? date('Y-m-01'));
  $to = (string)($p['to'] ?? date('Y-m-t'));
  $format = strtolower((string)($p['format'] ?? 'csv'));
  if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) $format = 'csv';
  $sessionId = $p['session_id'] ?? null;
  $kelasExp = trim((string)($p['kelas'] ?? ''));
  if ($kelasExp === 'all') $kelasExp = '';
  $hasKelas = $kelasExp !== '';
  $kelasSuffix = $hasKelas ? ' | Kelas: ' . $kelasExp : '';
  if (!$id) return ['ok' => false, 'error' => 'id wajib'];
  $settings = jh_settings_map($pdo);
  $sekolahNama = $settings['sekolah_nama'] ?? 'SMA Negeri 1';
  $sekolahAlamat = $settings['sekolah_alamat'] ?? 'Jl. Pendidikan No. 1, Jakarta';
  $sekolahTelp = $settings['sekolah_telp'] ?? '021-12345678';
  $kepsekNama = $settings['kepsek_nama'] ?? 'Drs. H. Ahmad Sulaiman, M.Pd';
  $kepsekNip = $settings['kepsek_nip'] ?? '19650101 199003 1 001';
  $byName = (string)($p['by_name'] ?? '');
  // nama target (copy validasi route)
  if ($tipe === 'ekskul') {
    $e = $pdo->prepare('SELECT id, nama FROM ekskul WHERE id=? AND deleted_at IS NULL');
    $e->execute([$id]);
    $row = $e->fetch();
    if (!$row) return ['ok' => false, 'error' => 'ekskul id tidak ditemukan'];
    $namaTarget = $row['nama'];
  } else {
    $e = $pdo->prepare('SELECT id, nama FROM events WHERE id=? AND deleted_at IS NULL');
    $e->execute([$id]);
    $row = $e->fetch();
    if (!$row) return ['ok' => false, 'error' => 'event id tidak ditemukan'];
    $namaTarget = $row['nama'];
  }
  $dir = jh_export_dir();
  $ext = $format === 'xlsx' ? 'xlsx' : ($format === 'pdf' ? 'pdf' : 'csv');
  $file = $dir . '/job-' . $jobId . '.' . $ext;
  $meta = ['job_id' => $jobId, 'tipe' => $tipe, 'id' => $id, 'nama' => $namaTarget, 'from' => $from, 'to' => $to, 'format' => $format, 'kelas' => $kelasExp, 'file' => basename($file), 'rows' => 0];
  // unbuffered utk MySQL (sweep §6)
  $wasBuffered = null;
  try { $wasBuffered = $pdo->getAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')); $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'), false); } catch (Throwable $e) {}
  try {
    if ($format === 'csv') {
      $out = @fopen($file, 'w');
      if (!$out) return ['ok' => false, 'error' => 'gagal buka file export'];
      fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
      fputcsv($out, [$sekolahNama]);
      fputcsv($out, [$sekolahAlamat . ' | Telp: ' . $sekolahTelp]);
      fputcsv($out, ['REKAP ' . strtoupper($tipe) . ' — ' . $namaTarget . ' | Periode: ' . $from . ' s/d ' . $to . $kelasSuffix]);
      fputcsv($out, []);
      $n = 0;
      if ($tipe === 'ekskul') {
        // COPY dari route csv ekskul
        fputcsv($out, ['No', 'Nama', 'Email', 'Kelas', 'Total Sesi', 'Hadir', 'Alpa', 'Izin', 'Persen']);
        $q = 'SELECT u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COALESCE(SUM(CASE WHEN a.status="hadir" THEN 1 ELSE 0 END),0) AS hadir, COALESCE(SUM(CASE WHEN a.status="alpa" THEN 1 ELSE 0 END),0) AS alpa, COALESCE(SUM(CASE WHEN a.status="izin" THEN 1 ELSE 0 END),0) AS izin FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC';
        $st = $pdo->prepare($hasKelas ? str_replace(' GROUP BY ', ' WHERE u.kelas=? GROUP BY ', $q) : $q);
        $st->execute($hasKelas ? [$id, $from, $to, $id, $id, $from, $to, $kelasExp] : [$id, $from, $to, $id, $id, $from, $to]);
        $no = 1;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $total = (int)$row['total_sesi']; $hadir = (int)$row['hadir']; $alpa = (int)$row['alpa']; $izin = (int)$row['izin'];
          $persen = $total ? round($hadir / $total * 100, 1) : 0;
          fputcsv($out, [$no++, $row['nama'], $row['email'], $row['kelas'] ?? '', $total, $hadir, $alpa, $izin, $persen . '%']);
          $n++;
          unset($row);
        }
        $st->closeCursor();
      } else {
        if ($sessionId && $sessionId !== 'all') {
          fputcsv($out, ['No', 'Peserta', 'Email', 'Kelas', 'Sesi', 'Waktu Scan']);
          $q = 'SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE a.session_id=? ORDER BY a.scanned_at DESC';
          $st = $pdo->prepare($hasKelas ? str_replace(' ORDER BY ', ' AND u.kelas=? ORDER BY ', $q) : $q);
          $st->execute($hasKelas ? [(int)$sessionId, $kelasExp] : [(int)$sessionId]);
          $no = 1;
          while ($row = $st->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, [$no++, $row['nama'], $row['email'], $row['kelas'] ?? '', $row['nama_sesi'], $row['scanned_at']]); $n++; unset($row); }
          $st->closeCursor();
        } elseif ($sessionId === 'all') {
          fputcsv($out, ['No', 'Peserta', 'Email', 'Kelas', 'Sesi', 'Waktu Scan']);
          $q = 'SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE s.event_id=? ORDER BY s.id ASC, a.scanned_at ASC';
          $st = $pdo->prepare($hasKelas ? str_replace(' ORDER BY ', ' AND u.kelas=? ORDER BY ', $q) : $q);
          $st->execute($hasKelas ? [$id, $kelasExp] : [$id]);
          $no = 1;
          while ($row = $st->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, [$no++, $row['nama'], $row['email'], $row['kelas'] ?? '', $row['nama_sesi'], $row['scanned_at']]); $n++; unset($row); }
          $st->closeCursor();
        } else {
          fputcsv($out, ['No', 'Peserta', 'Email', 'Kelas', 'Status', 'Tgl Daftar']);
          $q = 'SELECT u.nama, u.email, u.kelas, p.status, p.created_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC';
          $st = $pdo->prepare($hasKelas ? str_replace(' ORDER BY ', ' AND u.kelas=? ORDER BY ', $q) : $q);
          $st->execute($hasKelas ? [$id, $from, $to, $kelasExp] : [$id, $from, $to]);
          $no = 1;
          while ($row = $st->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, [$no++, $row['nama'], $row['email'], $row['kelas'] ?? '', $row['status'], $row['created_at']]); $n++; unset($row); }
          $st->closeCursor();
        }
      }
      fputcsv($out, []);
      fputcsv($out, ['Jakarta, ' . date('d F Y')]);
      fputcsv($out, ['Kepala Sekolah,']);
      fputcsv($out, []);
      fputcsv($out, [$kepsekNama]);
      fputcsv($out, ['NIP. ' . $kepsekNip]);
      fclose($out);
      $meta['rows'] = $n;
    } elseif ($format === 'xlsx') {
      if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) return ['ok' => false, 'error' => 'PhpSpreadsheet tidak tersedia'];
      $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
      $sheet = $ss->getActiveSheet();
      $sheet->setTitle(substr('Rekap ' . ucfirst($tipe), 0, 31));
      $sheet->mergeCells('A1:I1'); $sheet->setCellValue('A1', $sekolahNama);
      $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
      $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $sheet->mergeCells('A2:I2'); $sheet->setCellValue('A2', $sekolahAlamat . ' | Telp: ' . $sekolahTelp);
      $sheet->mergeCells('A3:I3'); $sheet->setCellValue('A3', 'REKAP ' . strtoupper($tipe) . ' — ' . $namaTarget);
      $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(11);
      $sheet->mergeCells('A4:I4'); $sheet->setCellValue('A4', 'Periode: ' . $from . ' s/d ' . $to . $kelasSuffix . ' | Dicetak: ' . date('d/m/Y H:i') . ' | Oleh: ' . $byName);
      $hdrRow = 6;
      $n = 0;
      if ($tipe === 'ekskul') {
        $headers = ['No', 'Nama', 'Email', 'Kelas', 'Total Sesi', 'Hadir', 'Alpa', 'Izin', '% Hadir'];
        $col = 'A';
        foreach ($headers as $h) { $sheet->setCellValue($col . $hdrRow, $h); $col++; }
        $sheet->getStyle('A' . $hdrRow . ':I' . $hdrRow)->getFont()->setBold(true);
        // COPY query ekskul
        $q = 'SELECT u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COALESCE(SUM(CASE WHEN a.status="hadir" THEN 1 ELSE 0 END),0) AS hadir, COALESCE(SUM(CASE WHEN a.status="alpa" THEN 1 ELSE 0 END),0) AS alpa, COALESCE(SUM(CASE WHEN a.status="izin" THEN 1 ELSE 0 END),0) AS izin FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC';
        $st = $pdo->prepare($hasKelas ? str_replace(' GROUP BY ', ' WHERE u.kelas=? GROUP BY ', $q) : $q);
        $st->execute($hasKelas ? [$id, $from, $to, $id, $id, $from, $to, $kelasExp] : [$id, $from, $to, $id, $id, $from, $to]);
        $r = $hdrRow + 1; $no = 1;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $total = (int)$row['total_sesi']; $hadir = (int)$row['hadir']; $alpa = (int)$row['alpa']; $izin = (int)$row['izin'];
          $persen = $total ? round($hadir / $total * 100, 1) : 0;
          $sheet->setCellValue('A' . $r, $no++);
          $sheet->setCellValue('B' . $r, $row['nama']);
          $sheet->setCellValue('C' . $r, $row['email']);
          $sheet->setCellValue('D' . $r, $row['kelas'] ?? '-');
          $sheet->setCellValue('E' . $r, $total);
          $sheet->setCellValue('F' . $r, $hadir);
          $sheet->setCellValue('G' . $r, $alpa);
          $sheet->setCellValue('H' . $r, $izin);
          $sheet->setCellValue('I' . $r, $persen . '%');
          $r++; $n++;
          unset($row);
        }
        $st->closeCursor();
        $tt = $r + 2;
        $sheet->mergeCells('F' . $tt . ':I' . $tt); $sheet->setCellValue('F' . $tt, 'Jakarta, ' . date('d F Y'));
        $sheet->mergeCells('F' . ($tt + 5) . ':I' . ($tt + 5)); $sheet->setCellValue('F' . ($tt + 5), $kepsekNama);
        $sheet->mergeCells('F' . ($tt + 6) . ':I' . ($tt + 6)); $sheet->setCellValue('F' . ($tt + 6), 'NIP. ' . $kepsekNip);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
      } else {
        $headers = ['No', 'Peserta', 'Email', 'Kelas', 'Status', 'Tgl Daftar'];
        $col = 'A';
        foreach ($headers as $h) { $sheet->setCellValue($col . $hdrRow, $h); $col++; }
        $sheet->getStyle('A' . $hdrRow . ':F' . $hdrRow)->getFont()->setBold(true);
        if ($sessionId && $sessionId !== 'all') {
          $q = 'SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE a.session_id=? ORDER BY a.scanned_at DESC';
          $st = $pdo->prepare($hasKelas ? str_replace(' ORDER BY ', ' AND u.kelas=? ORDER BY ', $q) : $q);
          $st->execute($hasKelas ? [(int)$sessionId, $kelasExp] : [(int)$sessionId]);
        } elseif ($sessionId === 'all') {
          $q = 'SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE s.event_id=? ORDER BY s.id ASC, a.scanned_at ASC';
          $st = $pdo->prepare($hasKelas ? str_replace(' ORDER BY ', ' AND u.kelas=? ORDER BY ', $q) : $q);
          $st->execute($hasKelas ? [$id, $kelasExp] : [$id]);
        } else {
          $q = 'SELECT u.nama, u.email, u.kelas, p.status, p.created_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC';
          $st = $pdo->prepare($hasKelas ? str_replace(' ORDER BY ', ' AND u.kelas=? ORDER BY ', $q) : $q);
          $st->execute($hasKelas ? [$id, $from, $to, $kelasExp] : [$id, $from, $to]);
        }
        $r = $hdrRow + 1; $no = 1;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $sheet->setCellValue('A' . $r, $no++);
          $sheet->setCellValue('B' . $r, $row['nama']);
          $sheet->setCellValue('C' . $r, $row['email']);
          $sheet->setCellValue('D' . $r, $row['kelas'] ?? '-');
          $sheet->setCellValue('E' . $r, $row['status'] ?? $row['nama_sesi'] ?? '');
          $sheet->setCellValue('F' . $r, $row['created_at'] ?? $row['scanned_at'] ?? '');
          $r++; $n++;
          unset($row);
        }
        $st->closeCursor();
      }
      $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
      $writer->save($file);
      $meta['rows'] = $n;
    } else {
      // PDF: render per-row langsung ke HTML string (unbuffered while, tanpa fetchAll).
      if (!class_exists('Dompdf\\Dompdf')) return ['ok' => false, 'error' => 'Dompdf tidak tersedia'];
      $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
      $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . '@page{ margin:28px 24px 24px 24px; size:A4 ' . ($tipe === 'ekskul' ? 'landscape' : 'portrait') . '; }'
        . 'body{ font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size:9px; color:#18181b; }'
        . '.kop{ text-align:center; border-bottom:2px solid #18181b; padding-bottom:10px; margin-bottom:10px; }'
        . '.kop h1{ font-size:16px; margin:0; } .kop p{ margin:2px 0; font-size:9px; color:#52525b; }'
        . '.judul{ text-align:center; font-weight:700; font-size:12px; margin:10px 0 2px; }'
        . '.periode{ text-align:center; font-size:9px; color:#71717a; margin-bottom:10px; }'
        . 'table{ width:100%; border-collapse:collapse; font-size:9px; }'
        . 'th{ background:#18181b; color:#fff; padding:7px 6px; text-align:left; }'
        . 'td{ padding:6px; border-bottom:1px solid #e4e4e7; }'
        . '.ttd{ margin-top:22px; text-align:right; font-size:10px; }'
        . '</style></head><body>';
      $html .= '<div class="kop"><h1>' . $esc($sekolahNama) . '</h1><p>' . $esc($sekolahAlamat) . ' | Telp: ' . $esc($sekolahTelp) . '</p></div>';
      $html .= '<div class="judul">REKAP ' . strtoupper($esc($tipe)) . ' — ' . $esc($namaTarget) . '</div>';
      $html .= '<div class="periode">Periode: ' . $esc($from) . ' s/d ' . $esc($to) . $esc($kelasSuffix) . ' | Dicetak: ' . date('d/m/Y H:i') . ' | Oleh: ' . $esc($byName) . '</div>';
      $html .= '<table><thead><tr>';
      $n = 0;
      if ($tipe === 'ekskul') {
        $html .= '<th>No</th><th>Nama</th><th>Email</th><th>Kelas</th><th>Sesi</th><th>Hadir</th><th>Alpa</th><th>Izin</th><th>%</th></tr></thead><tbody>';
        // COPY query ekskul
        $q = 'SELECT u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COALESCE(SUM(CASE WHEN a.status="hadir" THEN 1 ELSE 0 END),0) AS hadir, COALESCE(SUM(CASE WHEN a.status="alpa" THEN 1 ELSE 0 END),0) AS alpa, COALESCE(SUM(CASE WHEN a.status="izin" THEN 1 ELSE 0 END),0) AS izin FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC';
        $st = $pdo->prepare($hasKelas ? str_replace(' GROUP BY ', ' WHERE u.kelas=? GROUP BY ', $q) : $q);
        $st->execute($hasKelas ? [$id, $from, $to, $id, $id, $from, $to, $kelasExp] : [$id, $from, $to, $id, $id, $from, $to]);
        $no = 1;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $total = (int)$row['total_sesi']; $hadir = (int)$row['hadir'];
          $persen = $total ? round($hadir / $total * 100, 1) : 0;
          $html .= '<tr><td>' . ($no++) . '</td><td>' . $esc($row['nama']) . '</td><td>' . $esc($row['email']) . '</td><td>' . $esc($row['kelas'] ?? '-') . '</td><td>' . $total . '</td><td>' . $hadir . '</td><td>' . (int)$row['alpa'] . '</td><td>' . (int)$row['izin'] . '</td><td>' . $persen . '%</td></tr>';
          $n++;
          unset($row);
        }
        $st->closeCursor();
      } else {
        $html .= '<th>No</th><th>Peserta</th><th>Email</th><th>Kelas</th><th>Status</th><th>Tgl Daftar</th></tr></thead><tbody>';
        $q = 'SELECT u.nama, u.email, u.kelas, p.status, p.created_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC';
        $st = $pdo->prepare($hasKelas ? str_replace(' ORDER BY ', ' AND u.kelas=? ORDER BY ', $q) : $q);
        $st->execute($hasKelas ? [$id, $from, $to, $kelasExp] : [$id, $from, $to]);
        $no = 1;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $html .= '<tr><td>' . ($no++) . '</td><td>' . $esc($row['nama']) . '</td><td>' . $esc($row['email']) . '</td><td>' . $esc($row['kelas'] ?? '-') . '</td><td>' . $esc($row['status']) . '</td><td>' . $esc($row['created_at']) . '</td></tr>';
          $n++;
          unset($row);
        }
        $st->closeCursor();
      }
      $html .= '</tbody></table>';
      $html .= '<div class="ttd"><div>Jakarta, ' . date('d F Y') . '</div><div>Kepala Sekolah,</div><div style="font-weight:700;margin-top:54px">' . $esc($kepsekNama) . '</div><div>NIP. ' . $esc($kepsekNip) . '</div></div>';
      $html .= '</body></html>';
      $opts = new \Dompdf\Options();
      $opts->set('isRemoteEnabled', false);
      $opts->set('defaultFont', 'DejaVu Sans');
      $dom = new \Dompdf\Dompdf($opts);
      $dom->loadHtml($html);
      $dom->setPaper('A4', $tipe === 'ekskul' ? 'landscape' : 'portrait');
      $dom->render();
      @file_put_contents($file, $dom->output());
      unset($html);
      $meta['rows'] = $n;
    }
  } finally {
    if ($wasBuffered !== null) { try { $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'), $wasBuffered); } catch (Throwable $e) {} }
  }
  $meta['size'] = is_file($file) ? (int)@filesize($file) : 0;
  @file_put_contents($dir . '/job-' . $jobId . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  if (!is_file($file) || $meta['size'] <= 0) return ['ok' => false, 'error' => 'file export kosong/gagal'];
  return array_merge(['ok' => true], $meta);
}
}
if (!function_exists('jobs_dispatch')) {
// Dispatch 1 claimed job ke handler. Return array hasil.
function jobs_dispatch(PDO $pdo, array $job): array {
  $type = (string)($job['type'] ?? '');
  $payload = json_decode((string)($job['payload'] ?? '{}'), true);
  if (!is_array($payload)) $payload = [];
  $id = (int)$job['id'];
  switch ($type) {
    case 'notify': return handleNotify($pdo, $payload);
    case 'backup': return handleBackup($pdo, $payload);
    case 'sertifikat_batch': return handleSertifikatBatch($pdo, $payload);
    case 'export_laporan': return handleExportLaporan($pdo, $payload, $id);
    default: return ['ok' => false, 'error' => 'unknown type ' . $type];
  }
}
}
