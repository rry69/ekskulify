# Ekskulify — Manajemen Ekskul & Event Sekolah

[![CI](https://github.com/rry69/ekskulify/actions/workflows/ci.yml/badge.svg)](https://github.com/rry69/ekskulify/actions/workflows/ci.yml)

Pendaftaran ekskul, absensi QR, dan laporan sekolah dalam satu aplikasi.
Katalog ekskul, pendaftaran online dengan kuota realtime, absensi QR 5 menit,
persetujuan Kepala Sekolah satu klik, kalender terpusat, pengumuman,
laporan kehadiran, export CSV, dan sertifikat.

## 📔 Table of Contents

- [About the Project](#-about-the-project)
  - [Tech Stack](#-tech-stack)
  - [Features](#-features)
  - [Color Reference](#-color-reference)
  - [Environment Variables](#-environment-variables)
- [Getting Started](#-getting-started)
  - [Prerequisites](#-prerequisites)
  - [Installation](#-installation)
  - [Running Tests](#-running-tests)
  - [Run Locally](#-run-locally)
  - [Deployment](#-deployment)
- [Usage](#-usage)
- [Roadmap](#-roadmap)
- [FAQ](#-faq)
- [License](#-license)
- [Contact](#-contact)
- [Acknowledgements](#-acknowledgements)

## 🌟 About the Project

Ekskulify mengelola ekskul dan event sekolah dalam satu sistem untuk 4 role:
**admin**, **pembina**, **siswa**, dan **kepala sekolah** — masing-masing dengan
hak akses berbeda yang ditegakkan server-side (bukan cuma di Vue).

Alur inti: siswa daftar ekskul/event online (kuota realtime, maks 2 ekskul
diterima) → pembina kelola anggota & jadwal → absensi via QR 5 menit sekali
pakai (atau klik manual) → kepsek approve 1 klik → laporan + export CSV +
sertifikat.

### 👾 Tech Stack

**Client**

- [Vue 3](https://vuejs.org/) + [Vite 6](https://vite.dev/) + [Pinia](https://pinia.vuejs.org/) + [Vue Router 4](https://router.vuejs.org/)
- [html5-qrcode](https://github.com/mebjas/html5-qrcode) / [qrcode](https://github.com/soldair/node-qrcode) — scan & generate QR
- [ApexCharts](https://apexcharts.com/) — grafik dashboard
- [Lucide](https://lucide.dev/) / [Iconify](https://iconify.design/) — ikon

**Server**

- PHP Native 8.x — router `api/index.php` + PDO + session + CSRF
- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) — export Excel
- [Dompdf](https://github.com/dompdf/dompdf) — cetak sertifikat PDF
- [PHPUnit 11](https://phpunit.de/) — testing

**Database**

- [MySQL 8](https://www.mysql.com/) — produksi (`sql/schema.sql`)
- SQLite — skema referensi lokal (`api/schema.sqlite.sql`)

**DevOps**

- GitHub Actions — `php -l` + PHPUnit + `vite build` + paket zip rilis
- Shared hosting (DirectAdmin) — upload manual, tanpa Docker/SSH

### 🎯 Features

- Auth role-based + CSRF + session httpOnly (admin/pembina/siswa/kepsek)
- Katalog ekskul + pendaftaran hybrid (kuota transaksi, approval per ekskul, max 2)
- Jadwal rutin/tambahan + kalender terpusat + deteksi bentrok
- Absensi QR 5 menit one-time + fallback klik manual + rekap % kehadiran
- Event + rundown + pendaftaran + peserta + absensi (reuse QR)
- Approval Kepsek 1 klik (ekskul, event, pendaftaran)
- Laporan rekap + export CSV/Excel + sertifikat PDF + verifikasi publik
- Pengumuman + diskusi per ekskul + notifikasi in-app
- Kelola users (CRUD, reset password, 2 metode lupa password tanpa SMTP)
- Modul SRE: health check, backup, integrity, rate limit, slow-query log

### 🎨 Color Reference

Design system "Mindora" — CSS variables `--m-*`, scoped per komponen.

| Token         | Hex     | Pakai untuk            |
|---------------|---------|------------------------|
| `--m-cta`     | #4A7875 | Tombol utama / aksen   |
| `--m-cta-h`   | #5A908C | Hover tombol utama     |
| `--m-bg`      | #F1F5F4 | Latar halaman          |
| `--m-ink`     | #2F3E46 | Teks utama             |
| `--m-muted`   | #6B7C85 | Teks sekunder          |
| `--m-line`    | #E0E5E3 | Border / divider       |
| `--m-green`   | #5EB87E | Status sukses / hadir  |
| `--m-blue`    | #A7C7E7 | Info / jadwal rutin    |
| `--m-pink`    | #E8AEB3 | Peringatan lembut      |
| unread notif  | #EFF6F3 | Latar notifikasi baru  |

### 🔑 Environment Variables

Salin `.env.example` menjadi `.env`, lalu isi sesuai lingkungan.
**Jangan commit `.env`.**

| Key                 | Contoh          | Keterangan                              |
|---------------------|-----------------|-----------------------------------------|
| `DB_HOST`           | `127.0.0.1`     | Host MySQL                              |
| `DB_NAME`           | `ekskul`        | Nama database                           |
| `DB_USER`           | `root`          | User database                           |
| `DB_PASS`           | *(kosong)*      | Password database                       |
| `APP_KEY`           | *(64 hex)*      | Generate: `php -r "echo bin2hex(random_bytes(32));"` |
| `APP_FORCE_HTTPS`   | `0` / `1`       | Aktifkan (`1`) di produksi              |
| `APP_ORIGIN`        | `https://a.com` | Daftar origin CORS, pisah koma          |
| `SESSION_DRIVER`    | `file` / `db`   | `db` wajib untuk multi-instance         |
| `CACHE_DRIVER`      | `auto`          | `auto` / `file` / `redis`               |
| `RATE_LIMIT_DRIVER` | `file`          | `file` / `redis` (butuh phpredis)       |
| `APP_ENV`           | `production`    | `production` / `dev` / `local`          |
| `APP_BASE_URL`      | *(kosong)*      | Kosong = auto dari request              |

Frontend (`frontend/.env`): `VITE_API_BASE=/api`

## 🧰 Getting Started

### ‼️ Prerequisites

- PHP 8.3+ dengan ekstensi `pdo_mysql`
- MySQL 8.x
- Node.js 20+ + npm
- Composer (untuk PHPUnit & dependency PHP)

### ⚙️ Installation

Clone project:

```bash
git clone https://github.com/rry69/ekskulify.git
cd ekskulify
```

Setup backend:

```bash
cp .env.example .env
# isi DB_* dan APP_KEY di .env
composer install
mysql -u root -p -e "CREATE DATABASE ekskul"
mysql -u root -p ekskul < sql/schema.sql
mysql -u root -p ekskul < sql/seed.sql
```

Setup frontend:

```bash
cd frontend && npm install && cd ..
```

### 🧪 Running Tests

```bash
composer test                # full suite (unit + integration)
composer test-unit           # unit saja
composer test-integration    # integration saja
php tests/run.php            # fallback tanpa PHPUnit (shared hosting)
```

### 🏃 Run Locally

Cara cepat (Windows, buka 2 terminal sekaligus):

```
start.bat
```

Manual:

```bash
# Terminal 1 — API :8000
php -S 127.0.0.1:8000 -t api api/index.php

# Terminal 2 — Frontend :5173 (proxy /api -> :8000)
cd frontend && npm run dev
```

Buka `http://127.0.0.1:5173`. Akun seed (password: `password123`):

| Email               | Role    |
|---------------------|---------|
| admin@sekolah.test  | admin   |
| budi@sekolah.test   | pembina |
| andi@sekolah.test   | siswa   |
| kepsek@sekolah.test | kepsek  |

> Akun di atas hanya seed lokal untuk testing. Jangan pakai password
> tersebut di produksi.

### 🚩 Deployment

Target: shared hosting (DirectAdmin) tanpa SSH.

```bash
cd frontend && npm run build
```

1. GitHub → Actions → tunggu CI hijau → download artifact `release-manual`
   (`backend.zip` + `frontend-dist.zip`).
2. Upload isi `frontend-dist.zip` → `public_html/`; isi `backend.zip`
   (`api/`, `sql/`) → `public_html/api/`. Untuk MySQL: import
   `sql/schema.sql` + `sql/seed.sql` via phpMyAdmin.
3. Set env prod dari `.env.example`, tes login.

Alternatif manual: copy `frontend/dist/*` → `public_html/`,
copy `api/` → `public_html/api/`. File `sql/` hanya via phpMyAdmin,
jangan upload ke hosting.

## 👀 Usage

**Siswa** — buka `/katalog`, pilih ekskul, klik Daftar. Cek status di `/saya`.
Hadir via `/scan` (scan QR dari pembina) atau tombol hadir manual.
Maksimal 2 ekskul dengan status diterima.

**Pembina** — kelola ekskul di `/admin/ekskul`, tambah jadwal di
`/ekskul/:id`, generate QR absensi (berlaku 5 menit, sekali pakai),
ACC/tolak pendaftaran, lihat rekap di `/laporan`.

**Kepsek** — approve ekskul/event/pendaftaran 1 klik di `/kepsek/approval`,
pantau `/kepsek/laporan`.

**Admin** — kelola users `/admin/users`, sertifikat `/admin/sertifikat`,
pengaturan `/pengaturan`, file `/admin/files`.

Verifikasi sertifikat publik: `/verify/:hash`.

## 🧭 Roadmap

- [ ] Conflict guard saat tambah jadwal (deteksi bentrok otomatis)
- [ ] Notifikasi real-time (saat ini polling + in-app)
- [ ] Galeri kegiatan per ekskul
- [ ] Multi-sekolah (saat ini 1 lisensi = 1 sekolah)
- [ ] PWA / mode offline absensi

## ❔ FAQ

**Q: SQLite bisa untuk produksi?**
A: Tidak. MySQL satu-satunya driver aktif (`api/db.php`). SQLite hanya skema
referensi lokal (`api/schema.sqlite.sql`).

**Q: Bagaimana deploy tanpa SSH?**
A: Via artifact zip CI + upload File Manager, atau copy manual `deploy/`.
Lihat [Deployment](#-deployment).

**Q: Session hilang saat multi-instance?**
A: Set `SESSION_DRIVER=db` dan arahkan `STORAGE_DIR` ke shared storage.
Lihat `.env.example` bagian Scaling.

**Q: Lupa password tanpa SMTP?**
A: Ada 2 metode bawaan (token temp 10 karakter / link reset 30 menit),
diatur admin di `/pengaturan`. Token tersimpan sebagai SHA-256 hash,
sekali pakai.

**Q: Rate limit berapa?**
A: Default file-based 60 request/menit per endpoint sensitif → `429` +
header `Retry-After`. Set `RATE_LIMIT_DRIVER=redis` bila sudah ada phpredis.

## ⚠️ License

Lisensi per sekolah, termasuk instalasi awal. Lihat `composer.json` /
`frontend/package.json` untuk lisensi dependency masing-masing.

## 🤝 Contact

Harry Prasetyo — Project Link:
[https://github.com/rry69/ekskulify](https://github.com/rry69/ekskulify)

## 💎 Acknowledgements

- [Vue.js](https://vuejs.org/) — framework frontend
- [Vite](https://vite.dev/) — build tool frontend
- [html5-qrcode](https://github.com/mebjas/html5-qrcode) — scan QR di browser
- [ApexCharts](https://apexcharts.com/) — grafik dashboard
- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) — export Excel
- [Dompdf](https://github.com/dompdf/dompdf) — cetak sertifikat PDF
- [Lucide](https://lucide.dev/) — ikon
