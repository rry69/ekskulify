-- Seed minimal: 4 users + 2 ekskul + 1 event + 1 schedule
-- Password semua: password123 (hash digenerate PHP, di sini placeholder akan diganti api/seed.php)
-- Jika import langsung via SQL, password_hash di bawah sudah bcrypt untuk "password123"

INSERT INTO users (nama, email, password_hash, role) VALUES
('Ibu Sari', 'admin@sekolah.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Pak Budi', 'budi@sekolah.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pembina'),
('Andi', 'andi@sekolah.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'siswa'),
('Pak Kepsek', 'kepsek@sekolah.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'kepsek'),
('Siti', 'siti@sekolah.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'siswa');

INSERT INTO ekskul (nama, deskripsi, pembina_id, kuota, requires_approval, hari, jam_mulai, jam_selesai, lokasi, status) VALUES
('Pramuka', 'Ekskul wajib Pramuka', 2, 40, 0, 'Jumat', '15:00:00', '17:00:00', 'Lapangan Utama', 'approved'),
('Futsal', 'Ekskul Futsal selektif', 2, 20, 1, 'Rabu', '15:30:00', '17:30:00', 'G lapangan', 'approved'),
('PMR', 'Palang Merah Remaja', 2, 25, 1, 'Selasa', '14:00:00', '16:00:00', 'Aula', 'pending');

INSERT INTO events (nama, deskripsi, tanggal, waktu, lokasi, kuota, status, created_by) VALUES
('Lomba Porseni', 'Pekan Olahraga & Seni', DATE_ADD(CURDATE(), INTERVAL 7 DAY), '08:00:00', 'Lapangan Utama', 100, 'approved', 1),
('LDKS', 'Latihan Dasar Kepemimpinan', DATE_ADD(CURDATE(), INTERVAL 14 DAY), '07:00:00', 'Aula', 50, 'pending', 1);

INSERT INTO schedules (ekskul_id, tanggal, jam_mulai, jam_selesai, lokasi, tipe) VALUES
(1, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '15:00:00', '17:00:00', 'Lapangan Utama', 'rutin'),
(2, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '15:30:00', '17:30:00', 'G lapangan', 'rutin');

INSERT INTO announcements (judul, isi, created_by) VALUES
('Selamat Datang', 'Sistem Manajemen Ekskul & Event resmi dibuka. Daftar maksimal 2 ekskul/siswa.', 1);
