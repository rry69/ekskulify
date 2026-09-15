<?php
date_default_timezone_set('Asia/Jakarta');
defined('SCHEMA_VERSION') || define('SCHEMA_VERSION', 28);
// bump SCHEMA_VERSION saat menambah/mengubah fungsi ensureXxx supaya ensureAll() jalan ulang sekali
function pdoRaw(): PDO {
  if (!empty($GLOBALS['__pdo_raw']) && $GLOBALS['__pdo_raw'] instanceof PDO) return $GLOBALS['__pdo_raw'];
  static $shutdownRegistered=false;
  if (!$shutdownRegistered) {
    $shutdownRegistered=true;
    register_shutdown_function(function(){ $GLOBALS['__pdo_raw']=null; $pdo=null; });
  }
  $tmp = @require __DIR__ . '/config.php';
  $c = is_array($tmp) ? $tmp : ($GLOBALS['__app_config'] ?? []);
  $dsn="mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";
  $persistent=(getenv('DB_PERSISTENT')==='1'||(isset($_ENV['DB_PERSISTENT'])&&$_ENV['DB_PERSISTENT']==='1')||(isset($_SERVER['DB_PERSISTENT'])&&$_SERVER['DB_PERSISTENT']==='1'));
  $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_PERSISTENT=>($persistent===true),PDO::ATTR_TIMEOUT=>5,PDO::ATTR_STATEMENT_CLASS=>['SlowLogPDOStatement',[]]];
  // PHP 8.5: PDO::MYSQL_ATTR_INIT_COMMAND deprecated -> Pdo\Mysql::ATTR_INIT_COMMAND
  if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) $opts[constant('Pdo\\Mysql::ATTR_INIT_COMMAND')]="SET NAMES utf8mb4, SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'";
  else if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) $opts[@constant('PDO::MYSQL_ATTR_INIT_COMMAND')]="SET NAMES utf8mb4, SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'";
  $pdo=new SlowLogPDO($dsn,$c['db_user'],$c['db_pass'],$opts);
  $GLOBALS['__pdo_raw']=$pdo;
  try { $pdo->exec("SET time_zone = '+07:00'"); } catch(Exception $e) {}
  // DDL gate: ensureAll cuma jalan sekali per proses (static $done),
  // plus versi file biar antar-request skip semua (tanpa SHOW/COUNT/seed tiap request).
  // ponytail: versi file lokal per host; tambah APCu/invalidasi bila multi-server.
  static $doneGate=false; if($doneGate) return $pdo;
  try {
    $verFile=__DIR__.'/cache/.schema_v'.((int)SCHEMA_VERSION);
    if(!is_file($verFile)){ ensureAll($pdo); @mkdir(__DIR__.'/cache',0775,true); @file_put_contents($verFile,(string)time(),LOCK_EX); }
    $doneGate=true;
  } catch(Exception $e) { try { ensureAll($pdo); }catch(Exception $e2){} }
  return $pdo;
}
// Slow-log zero-infra: catat query >500ms ke api/logs/slow-YYYY-MM-DD.log format [time][ms][sql].
// Reuse rotasi sre_rotate_logs() bila modul SRE ter-load (sampled 1/20 agar murah).
function db_slow_log(string $sql, float $elapsedMs): void {
  if ($elapsedMs <= 500) return;
  try {
    $dir=__DIR__.'/logs'; if(!is_dir($dir)) @mkdir($dir,0755,true);
    $file=$dir.'/slow-'.date('Y-m-d').'.log';
    $oneLine=str_replace(["\r","\n"],' ',substr($sql,0,2000));
    $line='['.date('Y-m-d H:i:s').']['.(int)$elapsedMs.'ms]['.$oneLine.']'.PHP_EOL;
    @file_put_contents($file,$line,FILE_APPEND|LOCK_EX);
    if (function_exists('sre_rotate_logs') && mt_rand(1,20)===1) { try{ sre_rotate_logs(); }catch(Throwable $e){} }
    try{ if(is_file($file)&&(@filesize($file)?:0)>5242880) @rename($file,$dir.'/slow-'.date('Y-m-d').'.1.log'); }catch(Throwable $e){}
  }catch(Throwable $e){}
}
// Auto-wrap semua query via subclass: pdoRaw() return SlowLogPDO sehingga
// $pdo->query/exec di seluruh codebase otomatis diukur microtime tanpa edit 100+ callsite.
if (!class_exists('SlowLogPDO', false)) {
class SlowLogPDOStatement extends PDOStatement {
  public string $slowSql='';
  protected function __construct() {}
  public function execute(?array $params = null): bool {
    $t0=microtime(true);
    try { return parent::execute($params); }
    finally { try{ $ms=(microtime(true)-$t0)*1000; if($ms>500) db_slow_log($this->slowSql,$ms); }catch(Throwable $e){} }
  }
}
class SlowLogPDO extends PDO {
  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
    $t0=microtime(true);
    try { return parent::query($query, $fetchMode, ...$fetchModeArgs); }
    finally { try{ $ms=(microtime(true)-$t0)*1000; if($ms>500) db_slow_log($query,$ms); }catch(Throwable $e){} }
  }
  public function exec(string $statement): int|false {
    $t0=microtime(true);
    try { return parent::exec($statement); }
    finally { try{ $ms=(microtime(true)-$t0)*1000; if($ms>500) db_slow_log($statement,$ms); }catch(Throwable $e){} }
  }
  public function prepare(string $query, array $options = []): PDOStatement|false {
    $st = parent::prepare($query, $options);
    if ($st instanceof SlowLogPDOStatement) $st->slowSql = $query;
    return $st;
  }
}
}
// Integrasi SRE: wrapper circuit breaker + retry transien.
// Fallback aman ke pdoRaw() bila modul SRE tidak ter-load (script standalone).
// Retry HANYA untuk kegagalan transien (PDOException 2002/2006/2013/1205/1213
// atau gone-away/lost connection); kegagalan logika di-rethrow segera.
function pdo(): PDO {
  if (!function_exists('sre_circuit_run') || !function_exists('sre_config') || !function_exists('sre_with_retry')) {
    return pdoRaw();
  }
  try {
    // sre_with_retry sudah menangani allow/success/failure circuit sendiri —
    // JANGAN menyarangkan sre_circuit_run di sini (sukses/gagal dihitung 2x/op).
    return sre_with_retry('db', function () {
      return pdoRaw();
    }, 3);
  } catch (Throwable $e) {
    if (function_exists('sre_circuit_is_transient') && function_exists('sre_graceful_db_down') && sre_circuit_is_transient($e)) {
      sre_graceful_db_down($e); // log + JSON 503 bila headers belum terkirim; TIDAK exit
    }
    throw $e; // rethrow: kegagalan non-transien / circuit open — biar error handler global menangani
  }
}
function ensureAll(PDO $pdo){
  try { ensureAppSettings($pdo); } catch(Exception $e) {}
  if (schemaVersion($pdo) >= SCHEMA_VERSION) return;
  $ensures=[
    'ensureUsersNipColumn',
    'ensureKatalogColumns',
    'ensureEkskulKatalogIndexes',
    'ensureEventExtras',
    'ensureDashboardIndexes',
    'ensureSchedulesIndex',
    'ensureKalenderIndexes',
    'ensureUsersManagement',
    'ensureLaporanIndexes',
    'ensureEkskulPengumuman',
    'ensureEkskulSocial',
    'ensureNotifications',
    'ensurePresencePolls',
    'ensureLaporanProduction',
    'ensureApprovalColumns',
    'ensureCertificates',
    'ensureCoverColumns',
    'ensureEventImages',
    'ensureEventAttendance',
    'ensureRememberTokens',
    'ensureCertificateTemplates',
    'ensurePerfIndexes',
    'ensureJobs',
    'ensureForgotPassword',
  ];
  foreach($ensures as $fn){ try { $fn($pdo); } catch(Exception $e){} }
  saveSchemaVersion($pdo, SCHEMA_VERSION);
}
function schemaVersion(PDO $pdo): int {
  try {
    try { $v=$pdo->query("SELECT v FROM app_settings WHERE `k`='schema_version'")->fetch()['v'] ?? null; }
    catch(Exception $e){ $v=$pdo->query("SELECT v FROM app_settings WHERE k='schema_version'")->fetch()['v'] ?? null; }
    return ($v===null || $v==='') ? 0 : (int)$v;
  }catch(Exception $e){ return 0; }
}
function saveSchemaVersion(PDO $pdo, int $v){
  try {
    try { $exists=(bool)$pdo->query("SELECT 1 FROM app_settings WHERE `k`='schema_version'")->fetch(); }
    catch(Exception $e){ $exists=(bool)$pdo->query("SELECT 1 FROM app_settings WHERE k='schema_version'")->fetch(); }
    if($exists){
      try { $pdo->query("UPDATE app_settings SET v=".((int)$v)." WHERE `k`='schema_version'"); } catch(Exception $e){ $pdo->query("UPDATE app_settings SET v=".((int)$v)." WHERE k='schema_version'"); }
    } else {
      try { $pdo->exec("INSERT INTO app_settings(`k`,`v`) VALUES ('schema_version',".((int)$v).")"); } catch(Exception $e){ $pdo->exec("INSERT INTO app_settings(k,v) VALUES ('schema_version',".((int)$v).")"); }
    }
  }catch(Exception $e){}
}
function ensurePerfIndexes(PDO $pdo){
  $hasIdx = function(string $t, string $idx) use ($pdo): bool {
    try {
      if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        return (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name=".$pdo->quote($idx))->fetch();
      }
      return (bool)$pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=".$pdo->quote($t)." AND index_name=".$pdo->quote($idx))->fetch();
    }catch(Exception $e){ return false; }
  };
  $list=[
    ["events","idx_events_created_deleted","CREATE INDEX IF NOT EXISTS idx_events_created_deleted ON events(created_by, deleted_at)"],
    ["attendance_tokens","idx_attendance_tokens_schedule","CREATE INDEX IF NOT EXISTS idx_attendance_tokens_schedule ON attendance_tokens(schedule_id)"],
  ];
  foreach($list as $it){
    [$t,$idx,$sql]=$it;
    try { if($hasIdx($t,$idx)) continue; $pdo->exec($sql); }
    catch(Exception $e){ try { $pdo->exec(str_replace('IF NOT EXISTS ','',$sql)); }catch(Exception $e2){} }
  }
}
function ensureUsersNipColumn(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'nip'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN nip VARCHAR(30) NULL AFTER email, ADD INDEX idx_users_nip (nip)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'foto'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN foto VARCHAR(255) NULL AFTER role"); }catch(Exception $e){}
  try{ // SQLite: PRAGMA check, no AFTER support
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
      $cols=$pdo->query("PRAGMA table_info(users)")->fetchAll(); $names=array_column($cols,'name');
      if(!in_array('foto',$names)) $pdo->exec("ALTER TABLE users ADD COLUMN foto TEXT");
    }
  }catch(Exception $e){}
  try{ $pdo->exec("UPDATE users SET nip='19800101 001' WHERE role='pembina' AND (nip IS NULL OR nip='')"); }catch(Exception $e){}
}
function ensureEkskulKatalogIndexes(PDO $pdo){
  static $doneKs=false; if($doneKs) return; $doneKs=true;
  $stmts=[
    "CREATE INDEX idx_ekskul_status ON ekskul(status)",
    "CREATE INDEX idx_ekskul_nama ON ekskul(nama)",
    "CREATE INDEX idx_ekskul_deskripsi ON ekskul(deskripsi)",
    "CREATE INDEX idx_ekskul_pembina ON ekskul(pembina_id)",
    "CREATE INDEX idx_ekskul_is_dummy ON ekskul(is_dummy)",
    "CREATE INDEX idx_ekskul_deleted ON ekskul(deleted_at)",
  ];
  foreach($stmts as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }
}
function ensureKatalogColumns(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'is_dummy'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE ekskul ADD COLUMN is_dummy TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_ekskul_is_dummy (is_dummy)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'deleted_at'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE ekskul ADD COLUMN deleted_at DATETIME NULL, ADD INDEX idx_ekskul_deleted (deleted_at)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'is_dummy'")->fetch(); if($col && !pdo()->query("SHOW INDEX FROM ekskul WHERE Key_name='idx_ekskul_nama'")->fetch()) $pdo->exec("CREATE INDEX idx_ekskul_nama ON ekskul(nama)"); }catch(Exception $e){}
}
function ensureEventExtras(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'deleted_at'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE events ADD COLUMN deleted_at DATETIME NULL, ADD INDEX idx_events_deleted (deleted_at)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'waktu_selesai'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE events ADD COLUMN waktu_selesai TIME NULL"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'ekskul_id'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE events ADD COLUMN ekskul_id INT NULL, ADD INDEX idx_events_ekskul (ekskul_id), ADD FOREIGN KEY (ekskul_id) REFERENCES ekskul(id) ON DELETE SET NULL"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'ekskul_id'")->fetch(); if($col) { try{ $pdo->exec("CREATE INDEX idx_events_ekskul ON events(ekskul_id)"); }catch(Exception $e2){} } }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS event_ekskul (event_id INT NOT NULL, ekskul_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (event_id, ekskul_id), FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE, FOREIGN KEY (ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE, INDEX idx_ee_ekskul (ekskul_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("INSERT IGNORE INTO event_ekskul(event_id, ekskul_id) SELECT id, ekskul_id FROM events WHERE ekskul_id IS NOT NULL"); }catch(Exception $e){}
  // audit_log
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, action VARCHAR(50) NOT NULL, target_type VARCHAR(50) NOT NULL, target_id INT NULL, detail TEXT NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_target (target_type, target_id), INDEX idx_audit_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_events_nama ON events(nama)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_events_created_by ON events(created_by)"); }catch(Exception $e){}
}
function ensureKalenderIndexes(PDO $pdo){
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
function ensureSchedulesIndex(PDO $pdo){ ensureKalenderIndexes($pdo); try{ $pdo->exec("CREATE INDEX idx_reg_ekskul_deleted_status ON registrations(ekskul_id, deleted_at, status)"); }catch(Exception $e){} }
function ensureDashboardIndexes(PDO $pdo){
  static $done=false; if($done) return; $done=true;
  $stmts=[
    "CREATE INDEX idx_reg_status ON registrations(status)",
    "CREATE INDEX idx_reg_status_ekskul ON registrations(status, ekskul_id)",
    "CREATE INDEX idx_sched_tanggal ON schedules(tanggal)",
    "CREATE INDEX idx_event_tanggal_status ON events(tanggal,status)",
    "CREATE INDEX idx_reg_created ON registrations(created_at)",
  ];
  foreach($stmts as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }
}
function ensureUsersManagement(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'deleted_at'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER updated_at, ADD INDEX idx_users_deleted (deleted_at)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'kelas'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN kelas VARCHAR(20) NULL AFTER nip"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'aktif' AFTER role"); }catch(Exception $e){}
  try{ ensureUsersProduction($pdo); }catch(Exception $e){}
  try{ ensureAuditProduction($pdo); }catch(Exception $e){}
  try{ $pdo->exec("CREATE UNIQUE INDEX idx_users_email ON users(email)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_role ON users(role)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_created ON users(created_at)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_nama ON users(nama)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_kelas ON users(kelas)"); }catch(Exception $e){}
  try{ $pdo->exec("UPDATE users SET status='aktif' WHERE status IS NULL OR status=''"); }catch(Exception $e){}
}
function ensureUsersProduction(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER status"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN last_seen DATETIME NULL"); }catch(Exception $e){}
  try{
    $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch();
    if($col && stripos($col['Type'] ?? '', 'enum')===false){
      $pdo->exec("ALTER TABLE users MODIFY status ENUM('aktif','suspended','nonaktif') NOT NULL DEFAULT 'aktif'");
    }
  }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_status ON users(status)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_users_last_login ON users(last_login_at)"); }catch(Exception $e){}
  try{ $pdo->exec("UPDATE users SET status='aktif' WHERE status IS NULL OR status=''"); }catch(Exception $e){}
}
function ensureAuditProduction(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM audit_log LIKE 'ip'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE audit_log ADD COLUMN ip VARCHAR(45) NULL AFTER detail"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_audit_action ON audit_log(action)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_audit_created ON audit_log(created_at)"); }catch(Exception $e){}
}
function ensureLaporanIndexes(PDO $pdo){
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
function ensureLaporanProduction(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM audit_log LIKE 'ip'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE audit_log ADD COLUMN ip VARCHAR(45) NULL AFTER detail"); }catch(Exception $e){}
  $stmts=["CREATE INDEX idx_audit_action ON audit_log(action)","CREATE INDEX idx_audit_created ON audit_log(created_at)","CREATE INDEX idx_audit_target_type ON audit_log(target_type)"];
  foreach($stmts as $sql){ try{ $pdo->exec($sql); }catch(Exception $e){} }
  try{ $pdo->exec("CREATE INDEX idx_attendance_user_status ON attendance(user_id, status)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_schedules_tanggal_ekskul ON schedules(tanggal, ekskul_id)"); }catch(Exception $e){}
}
function ensureApprovalColumns(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'rejected_reason'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE ekskul ADD COLUMN rejected_reason TEXT NULL"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'rejected_reason'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE events ADD COLUMN rejected_reason TEXT NULL"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_ekskul_status_deleted ON ekskul(status, deleted_at)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_events_status_deleted ON events(status, deleted_at)"); }catch(Exception $e){}
}
function ensureAppSettings(PDO $pdo){
  static $done=false; if($done) return; $done=true;
  $isSqlite=false;
  try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
  // gate file/APCu sebelum sentuh DB — MySQL saja (SQLite :memory: fresh tiap proses, gate wajib skip)
  if(!$isSqlite){
    $gk='appsettings_v'.((int)SCHEMA_VERSION);
    $vf=__DIR__.'/cache/.appsettings_v'.((int)SCHEMA_VERSION);
    try{ if(function_exists('apcu_enabled')&&apcu_enabled()&&function_exists('apcu_fetch')){ $ok=false; $v=@apcu_fetch($gk,$ok); if($ok&&$v) return; } }catch(Throwable $e){}
    try{ if(is_file($vf)){ try{ if(function_exists('apcu_enabled')&&apcu_enabled()&&function_exists('apcu_store')) @apcu_store($gk,1,86400); }catch(Throwable $e2){} return; } }catch(Throwable $e){}
  }
  try{
    if($isSqlite){
      $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (k TEXT PRIMARY KEY, v TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (`k` VARCHAR(100) PRIMARY KEY, `v` LONGTEXT NULL, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    try{
      $col=$pdo->query("SHOW COLUMNS FROM app_settings LIKE 'v'")->fetch();
      $type=strtolower($col['Type']??'');
      if($type && $type!=='longtext' && strpos($type,'longtext')===false){
        $pdo->exec("ALTER TABLE app_settings MODIFY `v` LONGTEXT NULL");
      }
    }catch(Exception $e){}
    $cnt=$pdo->query("SELECT COUNT(*) c FROM app_settings")->fetch()['c']??0;
    if($cnt==0){ $pdo->exec("INSERT INTO app_settings(`k`,`v`) VALUES ('sekolah_nama','SMA Negeri 1'),('sekolah_alamat','Jl. Pendidikan No. 1, Jakarta'),('sekolah_telp','021-12345678'),('kepsek_nama','Drs. H. Ahmad Sulaiman, M.Pd'),('kepsek_nip','19650101 199003 1 001'),('kop_logo','')"); }
    // Opsi B: seed cert_bg_path + cert_layout (idempotent)
    try{
      $certLayout = json_encode([
        'page'=>['w'=>297,'h'=>210,'orientation'=>'landscape','dpi'=>300,'bg'=>'api/uploads/cert_bg/cert_bg.png'],
        'nama'=>['x'=>148.5,'y'=>88,'w'=>180,'align'=>'center','font_pt'=>28,'font_weight'=>'bold','color'=>'#1a1a1a'],
        'label'=>['x'=>148.5,'y'=>135,'w'=>180,'align'=>'center','font_pt'=>18,'font_weight'=>'bold','color'=>'#b8860b','uppercase'=>true],
        'deskripsi'=>['x'=>148.5,'y'=>150,'w'=>200,'align'=>'center','font_pt'=>11,'color'=>'#444444'],
        'ttd'=>['x'=>55,'y'=>175,'w'=>60,'align'=>'center','font_pt'=>10],
        'qr'=>['x'=>242,'y'=>175,'w'=>30,'h'=>30,'align'=>'center'],
        'nomor'=>['x'=>148.5,'y'=>195,'w'=>180,'align'=>'center','font_pt'=>7,'color'=>'#666666'],
      ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      $seed = [
        'cert_bg_path' => 'api/uploads/cert_bg/cert_bg.png',
        'cert_layout'  => $certLayout,
      ];
      foreach($seed as $k=>$v){
        try{
          $chk=$pdo->prepare("SELECT v FROM app_settings WHERE k=?");
          // MySQL uses backtick k, SQLite plain; try both
          try{ $chk=$pdo->prepare("SELECT v FROM app_settings WHERE `k`=?"); $chk->execute([$k]); }catch(Exception $e2){ $chk=$pdo->prepare("SELECT v FROM app_settings WHERE k=?"); $chk->execute([$k]); }
          if(!$chk->fetch()){
            try{ $pdo->prepare("INSERT INTO app_settings(`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([$k,$v]); }catch(Exception $e3){
              try{ $pdo->prepare("INSERT OR IGNORE INTO app_settings(k,v) VALUES (?,?)")->execute([$k,$v]); }catch(Exception $e4){}
            }
          }
        }catch(Exception $e){}
      }
    }catch(Exception $e){}
    // Fix nama y=100 -> y=88 (gold divider at ~102mm, gap 4mm)
    try{
      $raw=$pdo->query("SELECT v FROM app_settings WHERE `k`='cert_layout'")->fetch()['v']??'';
      if($raw===''){ $raw=$pdo->query("SELECT v FROM app_settings WHERE k='cert_layout'")->fetch()['v']??''; }
      $j=json_decode($raw,true);
      if(is_array($j) && isset($j['nama']['y']) && abs((float)$j['nama']['y'] - 100) < 0.01){
        $j['nama']['y']=88;
        $new=json_encode($j, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        try{ $pdo->prepare("UPDATE app_settings SET v=? WHERE `k`='cert_layout'")->execute([$new]); }catch(Exception $e2){ $pdo->prepare("UPDATE app_settings SET v=? WHERE k='cert_layout'")->execute([$new]); }
      }
    }catch(Exception $e){}
    if(!$isSqlite && isset($vf,$gk)){ try{ @mkdir(__DIR__.'/cache',0775,true); @file_put_contents($vf,(string)time(),LOCK_EX); }catch(Throwable $e){} }
    if(isset($gk)){ try{ if(function_exists('apcu_enabled')&&apcu_enabled()&&function_exists('apcu_store')) @apcu_store($gk,1,86400); }catch(Throwable $e){} }
  }catch(Exception $e){}
}
function ensureEkskulPengumuman(PDO $pdo){
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_pengumuman (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, isi TEXT NOT NULL, is_pinned TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ep_ekskul (ekskul_id), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul_pengumuman LIKE 'gambar_path'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE ekskul_pengumuman ADD COLUMN gambar_path VARCHAR(500) NULL AFTER isi"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul_pengumuman LIKE 'gambar_mime'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE ekskul_pengumuman ADD COLUMN gambar_mime VARCHAR(100) NULL AFTER gambar_path"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul_pengumuman LIKE 'gambar_size'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE ekskul_pengumuman ADD COLUMN gambar_size INT NULL AFTER gambar_mime"); }catch(Exception $e){}
}
function ensureEkskulSocial(PDO $pdo){
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_posts (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, user_id INT NOT NULL, tipe ENUM('diskusi','tanya','postingan') NOT NULL, judul VARCHAR(200) NULL, isi TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_posts_ekskul_tipe (ekskul_id, tipe, deleted_at), INDEX idx_posts_user (user_id), INDEX idx_posts_created (created_at), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_comments (id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NOT NULL, parent_id INT NULL, isi TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_comments_post_parent (post_id, parent_id), INDEX idx_comments_user (user_id), FOREIGN KEY(post_id) REFERENCES ekskul_posts(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_likes (id INT AUTO_INCREMENT PRIMARY KEY, target_type ENUM('post','comment') NOT NULL, target_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_like (target_type,target_id,user_id), INDEX idx_likes_target (target_type,target_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_uploads (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, post_id INT NULL, comment_id INT NULL, user_id INT NOT NULL, original_name VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, mime VARCHAR(100) NULL, size INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_uploads_ekskul (ekskul_id), INDEX idx_uploads_post (post_id), INDEX idx_uploads_size (size), INDEX idx_uploads_created (created_at), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_tab_reads (user_id INT NOT NULL, ekskul_id INT NOT NULL, tipe ENUM('diskusi','tanya','postingan') NOT NULL, last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, ekskul_id, tipe), INDEX idx_tab_reads_ekskul (ekskul_id, tipe)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_notifications (id INT AUTO_INCREMENT PRIMARY KEY, recipient_id INT NOT NULL, actor_id INT NOT NULL, ekskul_id INT NOT NULL, post_id INT NULL, comment_id INT NULL, tipe ENUM('reply','like_post','like_comment','mention','pengumuman') NOT NULL, group_key VARCHAR(150) NOT NULL, actor_count INT NOT NULL DEFAULT 1, actor_sample TEXT NULL, message TEXT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_notif_recipient_read (recipient_id,is_read,updated_at), INDEX idx_notif_group (recipient_id,group_key,is_read), INDEX idx_notif_ekskul (ekskul_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
}
function ensureNotifications(PDO $pdo){
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(200) NOT NULL, message TEXT NOT NULL, type VARCHAR(20) NOT NULL DEFAULT 'info', is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_notif_user_read (user_id,is_read,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM notifications LIKE 'group_key'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE notifications ADD COLUMN group_key VARCHAR(150) NULL AFTER type, ADD INDEX idx_notif_group (user_id, group_key, is_read)"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM notifications LIKE 'related_id'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE notifications ADD COLUMN related_id INT NULL AFTER group_key"); }catch(Exception $e){}
}
function ensurePresencePolls(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE users ADD COLUMN last_seen DATETIME NULL, ADD INDEX idx_users_last_seen (last_seen)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS ekskul_polls (id INT AUTO_INCREMENT PRIMARY KEY, ekskul_id INT NOT NULL, post_id INT NULL, user_id INT NOT NULL, question VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL, INDEX idx_polls_ekskul (ekskul_id), INDEX idx_polls_post (post_id), FOREIGN KEY(ekskul_id) REFERENCES ekskul(id) ON DELETE CASCADE, INDEX idx_polls_post2 (post_id), FOREIGN KEY(post_id) REFERENCES ekskul_posts(id) ON DELETE SET NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS poll_options (id INT AUTO_INCREMENT PRIMARY KEY, poll_id INT NOT NULL, label VARCHAR(200) NOT NULL, sort_order INT NOT NULL DEFAULT 0, INDEX idx_options_poll (poll_id), FOREIGN KEY(poll_id) REFERENCES ekskul_polls(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS poll_votes (id INT AUTO_INCREMENT PRIMARY KEY, poll_id INT NOT NULL, option_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_poll_user (poll_id,user_id), INDEX idx_votes_poll (poll_id), INDEX idx_votes_option (option_id), FOREIGN KEY(poll_id) REFERENCES ekskul_polls(id) ON DELETE CASCADE, FOREIGN KEY(option_id) REFERENCES poll_options(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
}
function ensureCertificates(PDO $pdo){
  try{
    $isSqlite=false;
    try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
    if($isSqlite){
      $pdo->exec("CREATE TABLE IF NOT EXISTS certificates (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, tipe TEXT NOT NULL, target_id INTEGER NOT NULL, hash TEXT NOT NULL UNIQUE, nomor TEXT NOT NULL, issued_by INTEGER REFERENCES users(id) ON DELETE SET NULL, issued_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS certificates (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, tipe ENUM('ekskul','event') NOT NULL, target_id INT NOT NULL, hash VARCHAR(64) NOT NULL, nomor VARCHAR(50) NOT NULL, issued_by INT NULL, issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_hash (hash), INDEX idx_cert_user (user_id), INDEX idx_cert_tipe_target (tipe,target_id), INDEX idx_cert_issued (issued_by), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(issued_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
  }catch(Exception $e){}
  try{ $pdo->exec("CREATE UNIQUE INDEX uniq_hash ON certificates(hash)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_cert_user ON certificates(user_id)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_cert_hash ON certificates(hash)"); }catch(Exception $e){}
  // Opsi B snapshot columns: kelas_snapshot + label (idempotent, MySQL + SQLite)
  try{
    $isSqlite=false;
    try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
    if($isSqlite){
      // SQLite: PRAGMA table_info + ALTER without AFTER
      try{
        $cols=$pdo->query("PRAGMA table_info(certificates)")->fetchAll();
        $names=array_column($cols,'name');
        if(!in_array('kelas_snapshot',$names)) $pdo->exec("ALTER TABLE certificates ADD COLUMN kelas_snapshot VARCHAR(20)");
        if(!in_array('label',$names)) $pdo->exec("ALTER TABLE certificates ADD COLUMN label VARCHAR(50)");
      }catch(Exception $e){}
    } else {
      try{ $col=$pdo->query("SHOW COLUMNS FROM certificates LIKE 'kelas_snapshot'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE certificates ADD COLUMN kelas_snapshot VARCHAR(20) NULL AFTER target_id"); }catch(Exception $e){ try{ $pdo->exec("ALTER TABLE certificates ADD COLUMN kelas_snapshot VARCHAR(20) NULL AFTER target_id"); }catch(Exception $e2){} }
      try{ $col=$pdo->query("SHOW COLUMNS FROM certificates LIKE 'label'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE certificates ADD COLUMN label VARCHAR(50) NULL AFTER kelas_snapshot"); }catch(Exception $e){ try{ $pdo->exec("ALTER TABLE certificates ADD COLUMN label VARCHAR(50) NULL AFTER kelas_snapshot"); }catch(Exception $e2){} }
      // backfill existing rows from users.kelas (MySQL only)
      try{ $pdo->exec("UPDATE certificates c JOIN users u ON u.id=c.user_id SET c.kelas_snapshot=u.kelas WHERE c.kelas_snapshot IS NULL"); }catch(Exception $e){}
    }
  }catch(Exception $e){}
  try{
    $chk=$pdo->prepare("SELECT v FROM app_settings WHERE `k`=?");
    $chk->execute(['sertifikat_secret']);
    if(!$chk->fetch()){
      $sec=bin2hex(random_bytes(32));
      try{ $pdo->prepare("INSERT INTO app_settings(`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute(['sertifikat_secret',$sec]); }catch(Exception $e2){ try{ $pdo->prepare("INSERT IGNORE INTO app_settings(k,v) VALUES (?,?)")->execute(['sertifikat_secret',$sec]); }catch(Exception $e3){} }
    }
  }catch(Exception $e){}
}
function ensureCoverColumns(PDO $pdo){
  try{ $col=$pdo->query("SHOW COLUMNS FROM ekskul LIKE 'cover_path'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE ekskul ADD COLUMN cover_path VARCHAR(500) NULL AFTER lokasi"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM events LIKE 'cover_path'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE events ADD COLUMN cover_path VARCHAR(500) NULL AFTER lokasi"); }catch(Exception $e){}
}
function ensureRememberTokens(PDO $pdo){
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (id INT AUTO_INCREMENT PRIMARY KEY, selector VARCHAR(32) NOT NULL UNIQUE, validator_hash VARCHAR(64) NOT NULL, user_id INT NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_remember_user (user_id), INDEX idx_remember_exp (expires_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
}
function ensureEventImages(PDO $pdo){
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS event_images (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, user_id INT NOT NULL, stored_path VARCHAR(500) NOT NULL, mime VARCHAR(100) NULL, size INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_event_images_event (event_id), INDEX idx_event_images_created (created_at), FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $col=$pdo->query("SHOW COLUMNS FROM event_images LIKE 'deleted_at'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE event_images ADD COLUMN deleted_at DATETIME NULL"); }catch(Exception $e){}
}
function ensureEventAttendance(PDO $pdo){
  // absensi QR multi-sesi per event: sesi independen + 1 scan per siswa per sesi
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS event_attendance_sessions (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, nama VARCHAR(150) NOT NULL DEFAULT 'Sesi 1', token VARCHAR(64) NOT NULL UNIQUE, durasi_jam INT NOT NULL DEFAULT 10, starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, status ENUM('aktif','stopped','expired') NOT NULL DEFAULT 'aktif', created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_eas_event (event_id), INDEX idx_eas_token (token), INDEX idx_eas_status (status), FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE TABLE IF NOT EXISTS event_attendance (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, user_id INT NOT NULL, scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_session_user (session_id, user_id), INDEX idx_ea_session (session_id), FOREIGN KEY(session_id) REFERENCES event_attendance_sessions(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
}
function ensureCertificateTemplates(PDO $pdo){
  try{
    $isSqlite=false;
    try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
    if($isSqlite){
      $pdo->exec("CREATE TABLE IF NOT EXISTS certificate_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, tipe TEXT NOT NULL, target_id INTEGER NOT NULL, label_default VARCHAR(50), deskripsi_override TEXT, layout_json TEXT, cert_bg_url VARCHAR(500), fonts_json TEXT, use_custom_layout INTEGER NOT NULL DEFAULT 0, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS certificate_templates (id INT AUTO_INCREMENT PRIMARY KEY, tipe VARCHAR(20) NOT NULL, target_id INT NOT NULL, label_default VARCHAR(50) NULL, deskripsi_override TEXT NULL, layout_json TEXT NULL, cert_bg_url VARCHAR(500) NULL, fonts_json TEXT NULL, use_custom_layout TINYINT NOT NULL DEFAULT 0, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_cert_tmpl_tipe_target (tipe, target_id), INDEX idx_cert_tmpl_target (target_id), FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
  }catch(Exception $e){}
  // MAJOR fonts persist server: fonts_json TEXT (migrasi aman IF NOT EXISTS, MySQL + SQLite)
  try{
    $isSqlite=false;
    try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
    if($isSqlite){
      try{
        $cols=$pdo->query("PRAGMA table_info(certificate_templates)")->fetchAll();
        $names=array_column($cols,'name');
        if(!in_array('fonts_json',$names)) $pdo->exec("ALTER TABLE certificate_templates ADD COLUMN fonts_json TEXT");
      }catch(Exception $e){}
    } else {
      try{ $col=$pdo->query("SHOW COLUMNS FROM certificate_templates LIKE 'fonts_json'")->fetch(); if(!$col) $pdo->exec("ALTER TABLE certificate_templates ADD COLUMN fonts_json TEXT NULL AFTER cert_bg_url"); }catch(Exception $e){}
    }
  }catch(Exception $e){}
  try{ $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_cert_tmpl_tipe_target ON certificate_templates(tipe, target_id)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cert_tmpl_target ON certificate_templates(target_id)"); }catch(Exception $e){}
  // Fix nama y=100 -> y=88 for per-event layouts affected by same bug
  try{ $rows=$pdo->query("SELECT id, layout_json FROM certificate_templates WHERE layout_json LIKE '%\"y\":100%' OR layout_json LIKE '%\"y\": 100%'")->fetchAll(); foreach($rows as $r){ $j=json_decode($r['layout_json'],true); if(!is_array($j)||!isset($j['nama']['y'])||abs((float)$j['nama']['y']-100)>=0.01) continue; $j['nama']['y']=88; $pdo->prepare("UPDATE certificate_templates SET layout_json=? WHERE id=?")->execute([json_encode($j, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $r['id']]); } }catch(Exception $e){}
}
function ensureJobs(PDO $pdo){
  // pseudo-queue zero-infra: jobs(id,type,status,attempts,run_at,payload,result,error,created_at,updated_at)
  // tiru ensureCertificates: dual-driver idempoten, tanpa FK (hindari gagal di shared hosting).
  try{
    $isSqlite=false;
    try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
    if($isSqlite){
      $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, payload TEXT NOT NULL DEFAULT '{}', result TEXT NULL, error TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(32) NOT NULL, status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, payload JSON NOT NULL, result TEXT NULL, error TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_jobs_claim (status, run_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
  }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_jobs_claim ON jobs(status, run_at)"); }catch(Exception $e){}
}
function ensureForgotPassword(PDO $pdo){
  // Lupa Password 2 metode: password sementara (server-generate) + link reset sekali pakai (token hash, expiry 30 mnt).
  // password_resets: token_hash unik (tanpa FK agar aman di shared hosting), used_at null = belum dipakai.
  // app_settings.forgot_password_method: 'temp' | 'link' (default 'temp').
  try{
    $isSqlite=false;
    try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
    if($isSqlite){
      $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, token_hash TEXT NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_pwreset_user (user_id), INDEX idx_pwreset_exp (expires_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
  }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_pwreset_user ON password_resets(user_id)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE INDEX idx_pwreset_exp ON password_resets(expires_at)"); }catch(Exception $e){}
  try{
    $chk=$pdo->prepare("SELECT v FROM app_settings WHERE k=?");
    try{ $chk=$pdo->prepare("SELECT v FROM app_settings WHERE `k`=?"); $chk->execute(['forgot_password_method']); }catch(Exception $e2){ $chk=$pdo->prepare("SELECT v FROM app_settings WHERE k=?"); $chk->execute(['forgot_password_method']); }
    if(!$chk->fetch()){
      try{ $pdo->prepare("INSERT INTO app_settings(`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute(['forgot_password_method','temp']); }catch(Exception $e3){
        try{ $pdo->prepare("INSERT OR IGNORE INTO app_settings(k,v) VALUES (?,?)")->execute(['forgot_password_method','temp']); }catch(Exception $e4){}
      }
    }
  }catch(Exception $e){}
}
