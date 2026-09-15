<?php
/**
 * api/sre/integrity.php — SRE session integrity (anti session-fixation).
 *
 * Setiap sesi memegang token integritas acak yg diterbitkan server
 * (/csrf, /auth/login, /auth/me). Frontend WAJIB mengembalikan token tsb
 * sebagai header X-Session-Integrity; /auth/me menolak (403 INTEGRITY)
 * bila token hilang/tidak cocok. Ini yg membedakan request dari tab
 * yg sah vs request hasil fixasi/pembajakan sesi.
 *
 * Siklus: fail-open SEKALI pada sesi baru (belum ada token) — token
 * diterbitkan di respons — lalu fail-closed untuk request berikutnya.
 *
 * JANGAN DINONAKTIFKAN / JANGAN LEWATI verify-nya: tanpa ini, session
 * fixation protection lumpuh dan user sah akan mental ke halaman login
 * (lihat frontend/src/lib/sessionIntegrity.js — satu paket dgn file ini).
 */
function sre_integrity_issue(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) return '';
  if (empty($_SESSION['sre_integrity']) || !is_string($_SESSION['sre_integrity'])) {
    try { $_SESSION['sre_integrity'] = bin2hex(random_bytes(16)); }
    catch (Throwable $e) { $_SESSION['sre_integrity'] = bin2hex(openssl_random_pseudo_bytes(16)); }
  }
  return $_SESSION['sre_integrity'];
}

function sre_integrity_verify(): void {
  $expected = $_SESSION['sre_integrity'] ?? '';
  // Sesi lama / pre-login yg belum pegang token: fail-open sekali,
  // token diterbitkan lewat respons (lihat sre_integrity_issue).
  if ($expected === '' || !is_string($expected)) return;
  $got = $_SERVER['HTTP_X_SESSION_INTEGRITY'] ?? '';
  if ($got === '' || !hash_equals($expected, (string)$got)) {
    if (function_exists('jsonOut')) jsonOut(['success' => false, 'error' => ['code' => 'INTEGRITY', 'message' => 'Sesi tidak valid, muat ulang dan login kembali']], 403);
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => ['code' => 'INTEGRITY', 'message' => 'Sesi tidak valid']]);
    exit;
  }
}
