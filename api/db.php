<?php
date_default_timezone_set('Asia/Jakarta');
$config = require __DIR__ . '/config.php';
function pdo(): PDO {
  static $pdo=null;
  if ($pdo) return $pdo;
  $c = require __DIR__ . '/config.php';
  if ($c['db_type']==='sqlite') {
    $file = __DIR__ . '/ekskul.db';
    $needInit = !file_exists($file);
    $pdo = new PDO('sqlite:'.$file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($needInit) initSqlite($pdo);
    return $pdo;
  }
  $dsn="mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";
  $pdo=new PDO($dsn,$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  // sinkron timezone MySQL ke Asia/Jakarta agar NOW() konsisten dengan PHP date()
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch(Exception $e) {}
  // ensure katalog columns & indexes + dashboard indexes (shared hosting)
  try { ensureUsersNipColumn($pdo, $c); } catch(Exception $e) {}
  try { ensureKatalogColumns($pdo, $c); } catch(Exception $e) {}
  try { ensureEkskulKatalogIndexes($pdo, $c); } catch(Exception $e) {}
  try { ensureEventExtras($pdo, $c); } catch(Exception $e) {}
  try { ensureDashboardIndexes($pdo, $c); } catch(Exception $e) {}
  try { ensureSchedulesIndex($pdo, $c); } catch(Exception $e) {}
   try { ensureKalenderIndexes($pdo, $c); } catch(Exception $e) {}
    try { ensureUsersManagement($pdo, $c); } catch(Exception $e) {}
     try { ensureLaporanIndexes($pdo, $c); } catch(Exception $e) {}
      try { ensureEkskulPengumuman($pdo, $c); } catch(Exception $e) {}
      try { ensureEkskulSocial($pdo, $c); } catch(Exception $e) {}
      try { ensurePresencePolls($pdo, $c); } catch(Exception $e) {}
      return $pdo;
}
function initSqlite(PDO $pdo){
  $sql=@file_get_contents(__DIR__.'/schema.sqlite.sql');
  if(!$sql) $sql=@file_get_contents(__DIR__.'/../sql/schema.sqlite.sql');
  if($sql) $pdo->exec($sql);
  // seed if empty
  $cnt=$pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c']??0;
  if($cnt==0) seedSqlite($pdo);
}
function seedSqlite(PDO $pdo){
  $hash=password_hash('password123',PASSWORD_BCRYPT);
  $pdo->exec("INSERT INTO users(nama,email,password_hash,role) VALUES ('Ibu Sari','admin@sekolah.test','$hash','admin'),('Pak Budi','budi@sekolah.test','$hash','pembina'),('Andi','andi@sekolah.test','$hash','siswa'),('Pak Kepsek','kepsek@sekolah.test','$hash','kepsek'),('Siti','siti@sekolah.test','$hash','siswa')");
  $pdo->exec("INSERT INTO ekskul(nama,deskripsi,pembina_id,kuota,requires_approval,hari,jam_mulai,jam_selesai,lokasi,status) VALUES ('Pramuka','Ekskul wajib Pramuka',2,40,0,'Jumat','15:00','17:00','Lapangan Utama','approved'),('Futsal','Ekskul Futsal selektif',2,20,1,'Rabu','15:30','17:30','G lapangan','approved'),('PMR','Palang Merah Remaja',2,25,1,'Selasa','14:00','16:00','Aula','pending')");
  $d7=date('Y-m-d',time()+604800); $d14=date('Y-m-d',time()+1209600); $d2=date('Y-m-d',time()+172800); $d3=date('Y-m-d',time()+259200);
  $pdo->exec("INSERT INTO events(nama,deskripsi,tanggal,waktu,lokasi,kuota,status,created_by) VALUES ('Lomba Porseni','Pekan Olahraga & Seni','$d7','08:00','Lapangan Utama',100,'approved',1),('LDKS','Latihan Dasar Kepemimpinan','$d14','07:00','Aula',50,'pending',1)");
  $pdo->exec("INSERT INTO schedules(ekskul_id,tanggal,jam_mulai,jam_selesai,lokasi,tipe) VALUES (1,'$d2','15:00','17:00','Lapangan Utama','rutin'),(2,'$d3','15:30','17:30','G lapangan','rutin')");
  $pdo->exec("INSERT INTO announcements(judul,isi,created_by) VALUES('Selamat Datang','Sistem Manajemen Ekskul & Event resmi dibuka. Daftar maksimal 2 ekskul/siswa.',1)");
}
function ensureUsersNipColumn(PDO $pdo, array $c){
  try{
    if(($c['db_type'] ?? 'mysql') !== 'mysql'){
      $cols=$pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
      $names=array_column($cols,'name');
      if(!in_array('nip',$names,true)) $pdo->exec("ALTER TABLE users ADD COLUMN nip VARCHAR(30)");
      if(!in_array('nip',$names,true)) return;
      // seed nip for existing pembina if empty
      $pdo->exec("UPDATE users SET nip='19800101 001' WHERE role='pembina' AND (nip IS NULL OR nip='')");
      $pdo->exec("UPDATE users SET nip='19800102 002' WHERE email='budi@sekolah.test' AND (nip IS NULL OR nip='')");
      return;
    }
    $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'nip'")->fetch();
    if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN nip VARCHAR(30) NULL AFTER email, ADD INDEX idx_users_nip (nip)");
    // seed nip if null for pembina
    try{ $pdo->exec("UPDATE users SET nip='19800101 001' WHERE role='pembina' AND (nip IS NULL OR nip='')"); }catch(Exception $e){}
  }catch(Exception $e){}
}
function ensureEkskulKatalogIndexes(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql') return;
  static $doneKs=false; if($doneKs) return; $doneKs=true;
  $stmts=[
    "CREATE INDEX idx_ekskul_status ON ekskul(status)",
    "CREATE INDEX idx_ekskul_nama ON ekskul(nama)",
    "CREATE INDEX idx_ekskul_deskripsi ON ekskul(deskripsi)",
    "CREATE INDEX idx_ekskul_pembina ON ekskul(pembina_id)",
    "CREATE INDEX idx_ekskul_is_dummy ON ekskul(is_dummy)",
    "CREATE INDEX idx_ekskul_deleted ON ekskul(deleted_at)",
  ];
  foreach($stmts as $sql){
    try{ $pdo->exec($sql); }catch(Exception $e){
      if(strpos($e->getMessage(),'1061')===false && strpos($e->getMessage(),'Duplicate')===false && strpos($e->getMessage(),'exists')===false){}
    }
  }
}
function ensureKatalogColumns(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql'){
    // SQLite: ADD COLUMN if missing
    try{
      $cols=$pdo->query("PRAGMA table_info(ekskul)")->fetchAll(PDO::FETCH_ASSOC);
      $names=array_column($cols,'name');
      if(!in_array('is_dummy',$names,true)) $pdo->exec("ALTER TABLE ekskul ADD COLUMN is_dummy INTEGER NOT NULL DEFAULT 0");
      if(!in_array('deleted_at',$names,true)) $pdo->exec("ALTER TABLE ekskul ADD COLUMN deleted_at DATETIME");
    }catch(Exception $e){}
    return;
  }
  try{
    $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'is_dummy'")->fetch();
    if(!$col) $pdo->exec("ALTER TABLE ekskul ADD COLUMN is_dummy TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_ekskul_is_dummy (is_dummy)");
  }catch(Exception $e){}
  try{
    $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'deleted_at'")->fetch();
    if(!$col) $pdo->exec("ALTER TABLE ekskul ADD COLUMN deleted_at DATETIME NULL, ADD INDEX idx_ekskul_deleted (deleted_at)");
  }catch(Exception $e){}
  try{
    $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'is_dummy'")->fetch();
    if($col && !pdo()->query("SHOW INDEX FROM ekskul WHERE Key_name='idx_ekskul_nama'")->fetch()) $pdo->exec("CREATE INDEX idx_ekskul_nama ON ekskul(nama)");
  }catch(Exception $e){}
}
function ensureEventExtras(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql'){
    try{
      $cols=$pdo->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC);
      $names=array_column($cols,'name');
      if(!in_array('deleted_at',$names,true)) $pdo->exec("ALTER TABLE events ADD COLUMN deleted_at DATETIME");
      if(!in_array('waktu_selesai',$names,true)) $pdo->exec("ALTER TABLE events ADD COLUMN waktu_selesai TIME");
      // audit_log sqlite
      $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER, detail TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    }catch(Exception $e){}
    return;
  }
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'deleted_at'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE events ADD COLUMN deleted_at DATETIME NULL, ADD INDEX idx_events_deleted (deleted_at)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'waktu_selesai'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE events ADD COLUMN waktu_selesai TIME NULL"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'waktu'")->fetch(); }catch(Exception $e){}
  // audit_log
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, action VARCHAR(50) NOT NULL, target_type VARCHAR(50) NOT NULL, target_id INT NULL, detail TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_target (target_type, target_id), INDEX idx_audit_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  // indexes if missing
  $idxs=[
    "CREATE INDEX idx_events_nama ON events(nama)",
    "CREATE INDEX idx_events_created_by ON events(created_by)",
  ];
  foreach($idxs as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }
}
function ensureKalenderIndexes(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql') return;
  static $doneKal=false; if($doneKal) return; $doneKal=true;
  $stmts=[
    "CREATE INDEX idx_schedules_tanggal ON schedules(tanggal)",
    "CREATE INDEX idx_schedules_ekskul_tanggal ON schedules(ekskul_id, tanggal)",
    "CREATE INDEX idx_events_tanggal ON events(tanggal)",
    "CREATE INDEX idx_events_tanggal_status ON events(tanggal, status)",
    "CREATE INDEX idx_schedules_tanggal_jam ON schedules(tanggal, jam_mulai)",
  ];
  foreach($stmts as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }
}
function ensureSchedulesIndex(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql') return;
  ensureKalenderIndexes($pdo,$c);
  try{ $pdo->exec("CREATE INDEX idx_reg_ekskul_deleted_status ON registrations(ekskul_id, deleted_at, status)"); }catch(Exception $e){}
}
function ensureDashboardIndexes(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql') return;
  static $done=false; if($done) return; $done=true;
  $stmts=[
    "CREATE INDEX idx_reg_status ON registrations(status)",
    "CREATE INDEX idx_reg_status_ekskul ON registrations(status, ekskul_id)",
    "CREATE INDEX idx_sched_tanggal ON schedules(tanggal)",
    "CREATE INDEX idx_event_tanggal_status ON events(tanggal,status)",
    "CREATE INDEX idx_reg_created ON registrations(created_at)",
  ];
  foreach($stmts as $sql){
    try{ $pdo->exec($sql); }catch(Exception $e){
      // index exists — ignore (MySQL 1061)
      if(strpos($e->getMessage(),'1061')===false && strpos($e->getMessage(),'Duplicate')===false && strpos($e->getMessage(),'exists')===false){
        // ignore also if no permission on shared hosting
      }
    }
  }
}
function ensureUsersManagement(PDO $pdo, array $c){
  // spec PRD:207-212 + task: idx_users_email UNIQUE, idx_users_role, idx_users_created + deleted_at + kelas
  if(($c['db_type'] ?? 'mysql') !== 'mysql'){
    try{
      $cols=$pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
      $names=array_column($cols,'name');
      if(!in_array('deleted_at',$names,true)) $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME");
      if(!in_array('kelas',$names,true)) $pdo->exec("ALTER TABLE users ADD COLUMN kelas VARCHAR(20)");
      if(!in_array('status',$names,true)) $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'aktif'");
    }catch(Exception $e){}
    return;
  }
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'deleted_at'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER updated_at, ADD INDEX idx_users_deleted (deleted_at)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'kelas'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN kelas VARCHAR(20) NULL AFTER nip"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'aktif' AFTER role"); }catch(Exception $e){}
  // indices wajib task
  try{ $pdo->exec("CREATE UNIQUE INDEX idx_users_email ON users(email)"); }catch(Exception $e){}
  // idx_users_email already exists via UNIQUE, but ensure name idx_users_email exists (MySQL uses email unique key name 'email')
  // ensure idx_users_role
  try{ $pdo->exec("CREATE INDEX idx_users_role ON users(role)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_created ON users(created_at)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_nama ON users(nama)"); }catch(Exception $e){}
  // backfill status aktif where null
  try{ $pdo->exec("UPDATE users SET status='aktif' WHERE status IS NULL OR status=''"); }catch(Exception $e){}
   // ensure deleted users excluded from UNIQUE check by keeping email unique only for non-deleted (ponytail: full unique still enforced, deleted emails are appended suffix on soft-delete)
 }
function ensureLaporanIndexes(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql') return;
  static $doneLap=false; if($doneLap) return; $doneLap=true;
  $stmts=[
    "CREATE INDEX idx_registrations_ekskul_status ON registrations(ekskul_id, status)",
    "CREATE INDEX idx_attendance_sesi ON attendance(schedule_id, status)",
    "CREATE INDEX idx_events_tanggal ON events(tanggal)",
    "CREATE INDEX idx_registrations_user ON registrations(user_id)",
    "CREATE INDEX idx_registrations_deleted ON registrations(deleted_at)",
    "CREATE INDEX idx_schedules_ekskul ON schedules(ekskul_id)",
  ];
  foreach($stmts as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }
}
function ensureEkskulPengumuman(PDO $pdo, array $c){
  if(($c['db_type'] ?? 'mysql') !== 'mysql'){
    try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_pengumuman (id INTEGER PRIMARY KEY AUTOINCREMENT, ekskul_id INTEGER NOT NULL, isi TEXT NOT NULL, is_pinned INTEGER NOT NULL DEFAULT 1, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE)"); }catch(Exception $e){}
    return;
  }
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_pengumuman (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, isi TEXT NOT NULL, is_pinned TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ep_ekskul (ekskul_id), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
}
function ensureEkskulSocial(PDO $pdo, array $c){
  $isMysql = ($c['db_type'] ?? 'mysql') === 'mysql';
  if(!$isMysql){
    try{
      $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, ekskul_id INTEGER NOT NULL, user_id INTEGER NOT NULL, tipe TEXT NOT NULL CHECK(tipe IN ('diskusi','tanya','postingan')), judul TEXT NULL, isi TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL)");
      $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, user_id INTEGER NOT NULL, parent_id INTEGER NULL, isi TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, FOREIGN KEY(post_id) REFERENCES ekskul_posts(id) ON DELETE CASCADE)");
      $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_likes (id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL CHECK(target_type IN ('post','comment')), target_id INTEGER NOT NULL, user_id INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(target_type,target_id,user_id))");
      $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, ekskul_id INTEGER NOT NULL, post_id INTEGER NULL, comment_id INTEGER NULL, user_id INTEGER NOT NULL, original_name TEXT NOT NULL, stored_path TEXT NOT NULL, mime TEXT, size INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL)");
      $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, ekskul_id INTEGER NOT NULL, post_id INTEGER NULL, comment_id INTEGER NULL, tipe TEXT NOT NULL CHECK(tipe IN ('reply','like_post','like_comment','mention','pengumuman')), group_key TEXT NOT NULL, actor_count INTEGER DEFAULT 1, actor_sample TEXT, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_ekskul ON ekskul_posts(ekskul_id, tipe)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notif_recipient ON ekskul_notifications(recipient_id, is_read)");
    }catch(Exception $e){}
    return;
  }
  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_posts (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, user_id INT NOT NULL, tipe ENUM('diskusi','tanya','postingan') NOT NULL, judul VARCHAR(200) NULL, isi TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_posts_ekskul_tipe (ekskul_id, tipe, deleted_at), INDEX idx_posts_user (user_id), INDEX idx_posts_created (created_at), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Exception $e){}
  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_comments (id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NOT NULL, parent_id INT NULL, isi TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_comments_post_parent (post_id, parent_id), INDEX idx_comments_user (user_id), FOREIGN KEY(post_id) REFERENCES ekskul_posts(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Exception $e){}
  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_likes (id INT AUTO_INCREMENT PRIMARY KEY, target_type ENUM('post','comment') NOT NULL, target_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_like (target_type,target_id,user_id), INDEX idx_likes_target (target_type,target_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Exception $e){}
  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_uploads (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, post_id INT NULL, comment_id INT NULL, user_id INT NOT NULL, original_name VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, mime VARCHAR(100) NULL, size INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_uploads_ekskul (ekskul_id), INDEX idx_uploads_post (post_id), INDEX idx_uploads_size (size), INDEX idx_uploads_created (created_at), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Exception $e){}
  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_notifications (id INT AUTO_INCREMENT PRIMARY KEY, recipient_id INT NOT NULL, actor_id INT NOT NULL, ekskul_id INT NOT NULL, post_id INT NULL, comment_id INT NULL, tipe ENUM('reply','like_post','like_comment','mention','pengumuman') NOT NULL, group_key VARCHAR(150) NOT NULL, actor_count INT NOT NULL DEFAULT 1, actor_sample TEXT NULL, message TEXT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_notif_recipient_read (recipient_id,is_read,updated_at), INDEX idx_notif_group (recipient_id,group_key,is_read), INDEX idx_notif_ekskul (ekskul_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Exception $e){}
}
function ensurePresencePolls(PDO $pdo, array $c){
  $isMysql = ($c['db_type'] ?? 'mysql') === 'mysql';
  if(!$isMysql){
    try{
      $cols=$pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
      $names=array_column($cols,'name');
      if(!in_array('last_seen',$names,true)) $pdo->exec("ALTER TABLE users ADD COLUMN last_seen DATETIME");
      $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_polls (id INTEGER PRIMARY KEY AUTOINCREMENT, ekskul_id INTEGER NOT NULL, post_id INTEGER NULL, user_id INTEGER NOT NULL, question TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL)");
      $pdo->exec("CREATE TABLE IF NOT EXISTS poll_options (id INTEGER PRIMARY KEY AUTOINCREMENT, poll_id INTEGER NOT NULL, label TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)");
      $pdo->exec("CREATE TABLE IF NOT EXISTS poll_votes (id INTEGER PRIMARY KEY AUTOINCREMENT, poll_id INTEGER NOT NULL, option_id INTEGER NOT NULL, user_id INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(poll_id,user_id))");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_polls_ekskul ON ekskul_polls(ekskul_id)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_options_poll ON poll_options(poll_id)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_votes_poll ON poll_votes(poll_id)");
    }catch(Exception $e){}
    return;
  }
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN last_seen DATETIME NULL, ADD INDEX idx_users_last_seen (last_seen)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_polls (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, post_id INT NULL, user_id INT NOT NULL, question VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL, INDEX idx_polls_ekskul (ekskul_id), INDEX idx_polls_post (post_id), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE, FOREIGN KEY(post_id) REFERENCES ekskul_posts(id) ON DELETE SET NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS poll_options (id INT AUTO_INCREMENT PRIMARY KEY, poll_id INT NOT NULL, label VARCHAR(200) NOT NULL, sort_order INT NOT NULL DEFAULT 0, INDEX idx_options_poll (poll_id), FOREIGN KEY(poll_id) REFERENCES ekskul_polls(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS poll_votes (id INT AUTO_INCREMENT PRIMARY KEY, poll_id INT NOT NULL, option_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_poll_user (poll_id,user_id), INDEX idx_votes_poll (poll_id), INDEX idx_votes_option (option_id), FOREIGN KEY(poll_id) REFERENCES ekskul_polls(id) ON DELETE CASCADE, FOREIGN KEY(option_id) REFERENCES poll_options(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
}
