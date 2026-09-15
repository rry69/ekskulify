-- Manajemen Ekskul & Event — schema.sql
-- MySQL InnoDB, PDO prepared, shared hosting ready
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','pembina','siswa','kepsek') NOT NULL DEFAULT 'siswa',
  foto VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ekskul (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,
  deskripsi TEXT,
  pembina_id INT NULL,
  kuota INT NOT NULL DEFAULT 30,
  requires_approval TINYINT(1) NOT NULL DEFAULT 0,
  hari VARCHAR(20) NULL,
  jam_mulai TIME NULL,
  jam_selesai TIME NULL,
  lokasi VARCHAR(150) NULL,
  cover_path VARCHAR(500) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  registration_start DATETIME NULL,
  registration_end DATETIME NULL,
  is_dummy TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pembina_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ekskul_pembina (pembina_id),
  INDEX idx_ekskul_status (status),
  INDEX idx_ekskul_nama (nama),
  INDEX idx_ekskul_is_dummy (is_dummy),
  INDEX idx_ekskul_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  ekskul_id INT NOT NULL,
  status ENUM('menunggu','diterima','ditolak') NOT NULL DEFAULT 'menunggu',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  UNIQUE KEY uniq_user_ekskul (user_id, ekskul_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE,
  INDEX idx_reg_ekskul_status (ekskul_id, status),
  INDEX idx_reg_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ekskul_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jam_mulai TIME NOT NULL,
  jam_selesai TIME NOT NULL,
  lokasi VARCHAR(150) NULL,
  tipe ENUM('rutin','tambahan') NOT NULL DEFAULT 'rutin',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE,
  INDEX idx_sched_ekskul_tgl (ekskul_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('hadir','izin','alpa') NOT NULL DEFAULT 'hadir',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sched_user (schedule_id, user_id),
  FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_att_sched (schedule_id),
  INDEX idx_att_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_tokens (
  token VARCHAR(64) PRIMARY KEY,
  ekskul_id INT NOT NULL,
  schedule_id INT NOT NULL,
  expiry DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE,
  FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
  INDEX idx_token_expiry (expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,
  deskripsi TEXT,
  tanggal DATE NOT NULL,
  waktu TIME NOT NULL,
  waktu_selesai TIME NULL,
  lokasi VARCHAR(150) NULL,
  cover_path VARCHAR(500) NULL,
  kuota INT NOT NULL DEFAULT 50,
  rundown TEXT NULL,
  ekskul_id INT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  registration_start DATETIME NULL,
  registration_end DATETIME NULL,
  created_by INT NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (ekskul_id) REFERENCES ekskul(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_events_status (status),
  INDEX idx_events_tanggal (tanggal),
  INDEX idx_events_nama (nama),
  INDEX idx_events_deleted (deleted_at),
  INDEX idx_events_created_by (created_by),
  INDEX idx_events_ekskul (ekskul_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_ekskul (
  event_id INT NOT NULL,
  ekskul_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, ekskul_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE,
  INDEX idx_ee_ekskul (ekskul_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(50) NOT NULL,
  target_type VARCHAR(50) NOT NULL,
  target_id INT NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_target (target_type, target_id),
  INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('menunggu','diterima','ditolak') NOT NULL DEFAULT 'diterima',
  hadir TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_event_user (event_id, user_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ep_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_attendance_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  nama VARCHAR(150) NOT NULL DEFAULT 'Sesi 1',
  token VARCHAR(64) NOT NULL UNIQUE,
  durasi_jam INT NOT NULL DEFAULT 10,
  starts_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status ENUM('aktif','stopped','expired') NOT NULL DEFAULT 'aktif',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_eas_event (event_id),
  INDEX idx_eas_token (token),
  INDEX idx_eas_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  user_id INT NOT NULL,
  scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_session_user (session_id, user_id),
  FOREIGN KEY (session_id) REFERENCES event_attendance_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ea_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  judul VARCHAR(200) NOT NULL,
  isi TEXT NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(32) NOT NULL,
  status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payload JSON NOT NULL,
  result TEXT NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jobs_claim (status, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  selector VARCHAR(32) NOT NULL UNIQUE,
  validator_hash VARCHAR(64) NOT NULL,
  user_id INT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_remember_user (user_id),
  INDEX idx_remember_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
