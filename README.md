# Manajemen Ekskul & Event Sekolah — MVP

> CI badge belum aktif — isi slug OWNER/REPO setelah push remote.
<!-- TODO Harry: setelah `git remote add origin <url>`, ganti baris di atas dengan: ![CI](https://github.com/OWNER/REPO/actions/workflows/ci.yml/badge.svg) -->

Stack: Vue 3 + Vite + PHP Native (PDO, SQLite local / MySQL prod) + File Manager deploy.

## Docs
- [Contributing](CONTRIBUTING.md) — branching (GitHub Flow), commit convention, review checklist
- [Branching](docs/BRANCHING.md) — diagram + commands
- [Architecture](docs/ARCHITECTURE.md) — routeMatch flow + top modul
- [API](docs/API.md) — endpoint + auth/CSRF
- [Runbook](docs/RUNBOOK.md) — troubleshoot lokal
- [Breakdown /saya](docs/saya-breakdown.md) (arsip)

> Catatan: klaim SQLite/`DB_TYPE` di bawah ini stale — MySQL satu-satunya driver aktif (`api/db.php:9`). Lihat RUNBOOK §3.

## Run local
```
# API (php built-in)
php -S 127.0.0.1:8000 -t api api/index.php
# Frontend
cd frontend && npm install && npm run dev
```
`api/db.php` auto-init SQLite `api/ekskul.db` from `api/schema.sqlite.sql` + seed 4 users.

## Accounts (password: password123)
- admin@sekolah.test (admin)
- budi@sekolah.test (pembina)
- andi@sekolah.test (siswa)
- kepsek@sekolah.test (kepsek)

## Build deploy (shared hosting)
```
cd frontend && npm run build
# upload frontend/dist/* ke public_html/
# upload api/ ke public_html/api/
# for MySQL prod: import sql/schema.sql + sql/seed.sql, set DB_TYPE=mysql env
```

## CI & Rilis Manual
CI (`ci.yml`): push/PR ke `main`/`master` → `php-lint` + `php-test` + `frontend` (paralel) → `package` (zip). Tanpa Docker/SSH/FTP.
3 langkah upload:
1. GitHub → Actions → run CI hijau → download `release-manual` (`backend.zip` + `frontend-dist.zip`).
2. Upload isi `frontend-dist.zip` → `public_html/`; `backend.zip` (`api/`,`sql/`) → `public_html/api/` (+ import `sql/schema.sql` + `sql/seed.sql` utk MySQL).
3. Set env prod (`DB_TYPE=mysql` dll) dari `.env.example`, tes login.

## Features MVP
- Auth role-based + CSRF + session httponly
- CRUD ekskul (scoped pembina, approval kepsek)
- Pendaftaran hybrid (requires_approval, kuota transaksi, max 2)
- Jadwal rutin/tambahan + kalender
- Absensi klik + QR 5m one-time + % kehadiran + fallback klik
- Event + rundown + pendaftaran + peserta + absensi reuse QR
- Kalender terpusat + pengumuman
- Laporan rekap + export CSV
