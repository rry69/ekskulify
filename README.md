<div align="center">

# Ekskulify

### Manajemen Ekskul dan Event Sekolah — dari pendaftaran, absensi QR, approval Kepsek, hingga laporan dan sertifikat dalam satu aplikasi.

[![Vue](https://img.shields.io/badge/Vue-3.4-4FC08D?logo=vue.js&logoColor=white)](https://vuejs.org)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](https://github.com/rry69/ekskulify/pulls)

**Repo:** [github.com/rry69/ekskulify](https://github.com/rry69/ekskulify)

</div>

---

## Daftar Isi

- [Fitur](#fitur)
- [Alur Pendaftaran](#alur-pendaftaran)
- [Arsitektur](#arsitektur)
- [Struktur Database](#struktur-database)
- [Teknologi](#teknologi)
- [Design System Mindora](#design-system-mindora)
- [Instalasi](#instalasi)
- [Konfigurasi Opsional](#konfigurasi-opsional)
- [Akun Demo](#akun-demo)
- [Testing](#testing)
- [Scheduled Tasks](#scheduled-tasks)
- [Deploy](#deploy)
- [Kontribusi](#kontribusi)
- [Lisensi](#lisensi)

---

## Fitur

### Untuk Siswa

- **Katalog ekskul** dengan pencarian, filter status/kuota, dan sorting.
- **Pendaftaran online** dengan kuota realtime — maksimal 2 ekskul berstatus diterima.
- **Pendaftaran event** dengan guard periode, kuota, dan status approval.
- **Absensi QR** — token 5 menit sekali pakai, plus fallback klik manual.
- **Halaman Saya** — status pendaftaran, rekap kehadiran persen per ekskul.
- **Kalender terpusat** — jadwal rutin, tambahan, dan event dalam satu papan.
- **Pengumuman dan diskusi** per ekskul (posting, komentar, like).
- **Notifikasi in-app** untuk setiap perubahan status.
- **Sertifikat** — unduh PDF dan verifikasi publik via hash.

### Untuk Pembina

- **Dashboard scoped** — hanya ekskul binaan yang terlihat.
- **Kelola ekskul**: CRUD, jadwal rutin/tambahan, kuota, periode pendaftaran.
- **Kelola anggota**: ACC/tolak pendaftaran, kick anggota (kuota kembali otomatis).
- **Generate QR absensi** per sesi + absensi manual per anggota.
- **Rekap kehadiran** per ekskul dan per sesi.
- **Kelola event** yang dibuat sendiri (scoped `created_by`).

### Untuk Kepala Sekolah

- **Approval 1 klik** — ekskul, event, dan pendaftaran (eksklusif, admin diblokir 403).
- **Laporan rekap** — agregat counts + pendaftaran terbaru + jadwal hari ini.
- **Export CSV/Excel** dan pantau kalender seluruh sekolah.

### Untuk Admin

- **Dashboard** dengan statistik, grafik ApexCharts, dan export CSV 1 klik.
- **Kelola users**: CRUD, pencarian, reset password, soft-delete + audit log.
- **Kelola sertifikat**: template editor, sesi cetak, batch via background job.
- **Kelola file**: upload cover/hero ekskul dan event.
- **Pengaturan**: metode lupa password, teks login hero, konfigurasi aplikasi.
- **Log aktivitas (`audit_log`)** — semua aksi penting tercatat.
- **Modul SRE**: health check, backup, integrity, metrik, circuit breaker.

### Keamanan

- Session PHP httpOnly + SameSite Lax, `session_regenerate_id` saat login.
- CSRF token wajib di semua endpoint tulis (`X-CSRF-Token`), kecuali login dan reset publik.
- Password `password_hash` bcrypt; role ditegakkan server-side via `requireRole()`.
- Approval ekskul/event eksklusif Kepsek — admin dan pembina mendapat 403.
- Scoped pembina via `isPembinaOf()` — bukan pembina ekskul tersebut mendapat 403.
- Upload tervalidasi (mime whitelist + nama file acak), rate limit 60/menit per endpoint sensitif.
- Token reset password disimpan sebagai SHA-256 hash, expiry 30 menit, sekali pakai.
- Semua query via PDO prepared statement — tidak ada interpolasi string ke SQL.

---

## Alur Pendaftaran

```
Siswa
│
▼
Katalog ekskul (/katalog) — cari, filter, lihat kuota realtime
│
▼
Daftar (POST /ekskul/:id/daftar)
│
├─ ekskul requires_approval=0 → langsung diterima
│
└─ ekskul requires_approval=1 → menunggu → Pembina ACC/Tolak
│
▼
Terdaftar (maks 2 ekskul diterima, kelebihan → 409)
│
▼
Hadir — scan QR sesi (5 menit, sekali pakai) atau klik manual
│
▼
Rekap % kehadiran (/saya) → Sertifikat (PDF + verifikasi publik)
```

Pendaftaran event mengikuti alur sama dengan guard tambahan:
event harus `approved`, dalam periode pendaftaran, dan kuota tersedia.

---

## Arsitektur

```
api/
├── index.php          # router utama (~monolit, routeMatch + handler per resource)
├── social_routes.php  # rute pengumuman, diskusi, komentar, like
├── db.php             # PDO + auto-migrate ensureXxx (SCHEMA_VERSION 28)
├── config.php         # load .env + APP_KEY + driver session/cache/rate-limit
├── helpers.php        # validator payload, CSRF, auth, response
├── jobs.php           # pseudo-queue (enqueue/claim/done/fail, 4 tipe)
├── job_handlers.php   # handler notify, backup, sertifikat_batch, export_laporan
├── cron.php           # worker CLI, 1 job per run, lock non-blocking
├── ratelimit.php      # file/redis rate limit 60 per menit
├── session_store.php  # session file/db driver
├── storage.php        # upload tervalidasi + nama acak
├── cert_template.php  # render sertifikat ke PDF (Dompdf)
├── lib/phpqrcode.php  # generate QR server-side
└── sre/               # health, metrics, backup, integrity, alert, tick, circuit

frontend/src/
├── App.vue            # layout + token Mindora + notifikasi
├── router/index.js    # 30+ rute (publik, siswa, pembina, admin, kepsek)
├── stores/auth.js     # Pinia auth + session
├── lib/               # api.js, sanitizeHtml.js, sessionIntegrity.js
├── components/        # ApexChart, BrandLogo, CertEditor, UserAvatar
└── views/             # 25 view (Katalog, EkskulDetail, Saya, ScanQR,
                       #   Kalender, Events, Dashboard, Laporan, Admin*, ...)

sql/
├── schema.sql         # skema MySQL produksi (15 tabel)
└── seed.sql           # seed minimal (5 users + 3 ekskul + 2 event)
```

**Alur data inti:** `Vue view` → `lib/api.js` (fetch + CSRF header) →
`api/index.php` (routeMatch → guard role → validator → PDO transaksi) →
`MySQL`. Job berat (notifikasi, backup, sertifikat batch, export) masuk
tabel `jobs` lalu dikerjakan `cron.php` per 5 menit.

**Skema rute utama:**

| Area    | Prefix                                              | Guard                  |
|---------|-----------------------------------------------------|------------------------|
| Publik  | `/auth/login`, `/auth/forgot-password`, `/verify/*` | Tanpa login            |
| Siswa   | `/katalog`, `/saya`, `/scan`, `/events`             | Session + role siswa   |
| Pembina | `/admin/ekskul`, `/ekskul/:id` (kelola)             | Session + `isPembinaOf`|
| Kepsek  | `/kepsek/approval`, `/kepsek/laporan`               | Session + role kepsek  |
| Admin   | `/admin/users`, `/admin/*`, `/pengaturan`           | Session + role admin   |

---

## Struktur Database

Entitas inti dan relasi:

```
users ──< ekskul (pembina_id)
  │         ├──< registrations (user_id, ekskul_id, status, deleted_at)
  │         ├──< schedules (rutin/tambahan)
  │         │     └──< attendance_tokens (token 5 mnt, used)
  │         │     └──< attendance (hadir/izin/alpa)
  │         └──< announcements, ekskul_posts ──< ekskul_comments ──< ekskul_likes
  │
  ├──< events (created_by)
  │     ├──< event_participants
  │     └──< event_attendance_sessions ──< event_attendance
  │
  ├──< audit_log (user_id, action, target, detail)
  ├──< jobs (notify/backup/sertifikat_batch/export_laporan)
  ├──< password_resets (token_hash sha256, expiry 30 mnt, used_at)
  └──< remember_tokens, notifications
```

`registrations.status` (`diterima`/`menunggu`/`ditolak`) + soft-delete
`deleted_at` menjaga hitungan kuota akurat via subquery `COUNT` —
tanpa N+1 di endpoint list. Index terpasang untuk kolom yang sering
difilter (`status`, `tanggal`, `pembina_id`, `deleted_at`).

---

## Teknologi

| Komponen   | Teknologi |
|------------|-----------|
| Frontend   | Vue 3 + Vite 6 + Pinia + Vue Router 4 |
| QR         | html5-qrcode (scan) + qrcode (render) + phpqrcode (server) |
| Grafik     | ApexCharts |
| Ikon       | Lucide + Iconify |
| Backend    | PHP Native 8.x (router + PDO + session + CSRF, tanpa framework) |
| Database   | **MySQL 8** (produksi) / SQLite (skema referensi lokal) |
| PDF        | [dompdf/dompdf](https://github.com/dompdf/dompdf) |
| Spreadsheet| [phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) |
| Queue      | Pseudo-queue MySQL + cron (tanpa Redis/daemon) |
| Testing    | PHPUnit 11 (25 test: 17 unit + 8 integration) + fallback `tests/run.php` |
| CI/CD      | GitHub Actions (php-lint + php-test + frontend + paket zip) |

---

## Design System Mindora

Seluruh antarmuka dibangun di atas token CSS `--m-*` yang didefinisikan
di `App.vue` dan dipakai scoped per komponen.

Prinsip inti:

- **Token, bukan nilai mentah** — warna selalu via `var(--m-*)`, tidak ada hex tersebar di komponen.
- **Scoped per file** — setiap view punya prefix class unik, tidak ada style global bocor.
- **Aksesibilitas** — `aria-label` di aksi ikon, `scope=col` di tabel, `role=progressbar` di bar kuota, keyboard navigable, `prefers-reduced-motion` dihormati.

| Token       | Hex     | Pakai untuk           |
|-------------|---------|-----------------------|
| `--m-cta`   | #4A7875 | Tombol utama / aksen  |
| `--m-cta-h` | #5A908C | Hover tombol utama    |
| `--m-bg`    | #F1F5F4 | Latar halaman         |
| `--m-ink`   | #2F3E46 | Teks utama            |
| `--m-muted` | #6B7C85 | Teks sekunder         |
| `--m-line`  | #E0E5E3 | Border / divider      |
| `--m-green` | #5EB87E | Sukses / hadir        |
| `--m-blue`  | #A7C7E7 | Info / jadwal rutin   |
| `--m-pink`  | #E8AEB3 | Peringatan lembut     |

---

## Instalasi

| Kebutuhan | Versi |
|-----------|-------|
| PHP (ekstensi `pdo_mysql`, `mbstring`) | 8.3+ |
| MySQL | 8.x |
| Node.js + npm | 20+ |
| Composer | terbaru |

### Langkah Instalasi

```bash
# 1. Clone repositori
git clone https://github.com/rry69/ekskulify.git
cd ekskulify

# 2. Konfigurasi environment
cp .env.example .env
# isi DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_KEY di .env
# generate APP_KEY: php -r "echo bin2hex(random_bytes(32));"

# 3. Install dependency PHP & JS
composer install
cd frontend && npm install && cd ..

# 4. Siapkan database
mysql -u root -p -e "CREATE DATABASE ekskul"
mysql -u root -p ekskul < sql/schema.sql
mysql -u root -p ekskul < sql/seed.sql

# 5. Jalankan lokal (Windows: cukup klik start.bat)
php -S 127.0.0.1:8000 -t api api/index.php   # terminal 1 — API :8000
cd frontend && npm run dev                     # terminal 2 — Frontend :5173
```

Buka `http://127.0.0.1:5173`. Vite mem-proxy `/api` ke `:8000` otomatis.

---

## Konfigurasi Opsional

| Key | Default | Keterangan |
|-----|---------|------------|
| `APP_FORCE_HTTPS` | `0` | Set `1` di produksi (redirect HTTP ke HTTPS) |
| `APP_ORIGIN` | kosong | Daftar origin CORS, pisah koma |
| `SESSION_DRIVER` | `file` | `db` wajib untuk multi-instance tanpa Redis |
| `SESSION_TTL` | `2592000` | Masa berlaku session (detik, 30 hari) |
| `CACHE_DRIVER` | `auto` | `auto` / `file` / `redis` |
| `RATE_LIMIT_DRIVER` | `file` | `file` / `redis` (butuh ekstensi phpredis) |
| `STORAGE_DIR` | `api/uploads` | Arahkan ke shared storage untuk multi-instance |
| `APP_ENV` | `production` | `dev` membuka flag dummy di `api/index.php` |
| `APP_BASE_URL` | kosong | URL absolut untuk QR/sertifikat; kosong = auto |
| `ALLOW_DEBUG_IP` | kosong | CSV IP untuk guard debug; **jangan isi di prod** |

Frontend (`frontend/.env`): `VITE_API_BASE=/api`

---

## Akun Demo

Seed `sql/seed.sql` — password semua akun: `password123`

| Email               | Role    |
|---------------------|---------|
| admin@sekolah.test  | admin   |
| budi@sekolah.test   | pembina |
| andi@sekolah.test   | siswa   |
| siti@sekolah.test   | siswa   |
| kepsek@sekolah.test | kepsek  |

Akun di atas hanya seed lokal untuk testing. Jangan pakai kredensial
tersebut di produksi.

---

## Testing

```bash
composer test                # full suite via PHPUnit (unit + integration)
composer test-unit           # unit saja (17 test)
composer test-integration    # integration saja (8 test)
php tests/run.php            # fallback 15 checks tanpa PHPUnit (shared hosting)
php tests/run.php --unit     # fallback unit saja
```

CI (`ci.yml`) berjalan di setiap push/PR ke `main`/`master`:
`php-lint` + `php-test` + `frontend` (paralel) lalu `package` (zip rilis).
Tanpa Docker/SSH/FTP.

---

## Scheduled Tasks

Worker pseudo-queue zero-infra — CLI only, 1 job per run, lock non-blocking
agar cron yang overlap tidak dobel eksekusi.

```cron
*/5 * * * * /usr/bin/php /home/USER/public_html/api/cron.php >> /dev/null 2>&1
```

Tipe job yang didukung (`jobs_enqueue` whitelist): `notify`, `backup`,
`sertifikat_batch`, `export_laporan`. Job gagal diretry otomatis lalu
ditandai `failed` bila melewati batas — pantau via modul SRE (`/api/sre/`).

---

## Deploy

Target: shared hosting (DirectAdmin) tanpa SSH. Tiga langkah:

1. GitHub → Actions → tunggu CI hijau → download artifact `release-manual`
   (`backend.zip` + `frontend-dist.zip`).
2. Upload isi `frontend-dist.zip` → `public_html/`; isi `backend.zip`
   (`api/`, `sql/`) → `public_html/api/`. Import `sql/schema.sql` +
   `sql/seed.sql` via phpMyAdmin untuk database MySQL.
3. Set env produksi dari `.env.example`, buka `/api/` sekali untuk
   auto-migrate, lalu tes login.

Alternatif manual: `cd frontend && npm run build`, copy `dist/*` →
`public_html/`, copy `api/` → `public_html/api/`. Folder `sql/` hanya
dieksekusi via phpMyAdmin — jangan upload ke hosting.

---

## Kontribusi

Kontribusi selalu diterima. Alur: fork → branch fitur (`fitur/nama-fitur`)
→ commit mengikuti konvensi (`fix:`, `feat:`, `chore:`, `docs:`) → pull
request ke `master`. Pastikan `composer test` dan `npm run build` hijau
sebelum membuka PR.

---

## Lisensi

Didistribusikan di bawah Lisensi MIT. Lihat [LICENSE](LICENSE) untuk
informasi selengkapnya.

---

## Acknowledgements

- [Vue.js](https://vuejs.org/) — framework frontend
- [Vite](https://vite.dev/) — build tool frontend
- [html5-qrcode](https://github.com/mebjas/html5-qrcode) — scan QR di browser
- [ApexCharts](https://apexcharts.com/) — grafik dashboard
- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) — export Excel
- [Dompdf](https://github.com/dompdf/dompdf) — cetak sertifikat PDF
- [Lucide](https://lucide.dev/) — ikon
