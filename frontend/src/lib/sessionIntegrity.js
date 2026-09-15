// SRE session integrity handshake — anti session-fixation.
// Server (api/sre/integrity.php) menerbitkan token per sesi via
// /csrf, /auth/login, /auth/me. Token WAJIB dikembalikan sebagai
// header X-Session-Integrity pada setiap /auth/me berikutnya; tanpa
// itu /auth/me balas 403 INTEGRITY dan auth store me-reset user
// (efek: mental ke /login — lihat stores/auth.js onAuthFail).
//
// Urutan boot: main.js memanggil sessionIntegrityBoot() + router guard
// menunggu boot sebelum auth.me() pertama, agar header sudah siap.
// JANGAN DIHAPUS / JANGAN LEWATI: menghapus ini sama dengan
// menonaktifkan session fixation protection — app terlihat jalan,
// tapi /auth/me gagal untuk SEMUA user login.
let integrity = null
let booted = false

export function getIntegrity() { return integrity }
export function setIntegrity(v) { if (typeof v === 'string' && v) integrity = v }

// Pick token dari respons mana pun yg memuatnya (csrf/login/me).
export function captureIntegrity(j) {
  const t = j?.data?.integrity || j?.integrity || null
  if (typeof t === 'string' && t) integrity = t
  return integrity
}

// Dipanggil sebelum pemuatan auth pertama: ambil token sesi dari
// endpoint publik agar request /auth/me berikutnya lolos verify.
export async function sessionIntegrityBoot() {
  if (booted && integrity) return integrity
  booted = true
  try {
    const r = await fetch('/api/csrf', { credentials: 'include' })
    const j = await r.json().catch(() => null)
    captureIntegrity(j)
  } catch { /* fail-open sekali: server terbitkan token di respons berikut */ }
  return integrity
}
