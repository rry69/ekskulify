<?php
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if(!is_dir(__DIR__.'/logs')){ @mkdir(__DIR__.'/logs',0777,true); }
ini_set('error_log', __DIR__.'/logs/php_errors.log');
// === Integrasi SRE: error handling/graceful degradation via modul api/sre ===
// (pengganti set_exception_handler/set_error_handler anonim lama — paritas perilaku:
//  JSON 500 {"error":"Terjadi kesalahan internal"} + request_id, tanpa stack trace,
//  @-suppression dihormati oleh sre_php_error)
require_once __DIR__.'/sre/config.php';
require_once __DIR__.'/sre/bootstrap.php';
require_once __DIR__.'/sre/log.php';
require_once __DIR__.'/sre/metrics.php';
require_once __DIR__.'/sre/alert.php';
require_once __DIR__.'/sre/circuit.php';
require_once __DIR__.'/sre/health.php';
require_once __DIR__.'/sre/backup.php';
require_once __DIR__.'/sre/tick.php';
require_once __DIR__.'/sre/index.php';
require_once __DIR__.'/sre/integrity.php';
sre_boot();
date_default_timezone_set('Asia/Jakarta');
$config = require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';
require __DIR__.'/session_store.php';
require __DIR__.'/storage.php';
require __DIR__.'/ratelimit.php';
// session_driver 'db' → initSessionFromConfig pasang handler PDO; 'file' → save_path file (perilaku existing)
// save_path stabil: api/sessions (bukan sys temp yg kehapus restart), create if missing
initSessionFromConfig($config);
if(($config['session_driver'] ?? 'file') === 'file'){
  $stableSessDir = __DIR__.'/sessions';
  if(!is_dir($stableSessDir)){ @mkdir($stableSessDir, 0700, true); }
  if(is_dir($stableSessDir) && is_writable($stableSessDir)){ ini_set('session.save_path', $stableSessDir); }
}
// session TTL (SESSION_TTL, default 30 hari) dipakai utk gc_maxlifetime + expiry handler db — cookie tetap session cookie (baseline: httponly + samesite=Lax saja)
$sessionTtl = (int)($config['session_ttl'] ?? 30*86400);
if($sessionTtl <= 0) $sessionTtl = 30*86400;
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax']);
ini_set('session.gc_maxlifetime', (string)$sessionTtl);
session_start();
header('Content-Type: application/json');
// CORS whitelist (Vite dev + APP_ORIGIN env) + same-origin; selain itu tanpa ACAO agar browser blokir
$corsAllowedOrigins=['http://localhost:5173','http://127.0.0.1:5173'];
$appOrigins=getenv('APP_ORIGIN');
if(is_string($appOrigins) && trim($appOrigins)!==''){ foreach(array_map('trim',explode(',',$appOrigins)) as $ao){ if($ao!=='') $corsAllowedOrigins[]=$ao; } }
$corsOrigin='';
if(isset($_SERVER['HTTP_ORIGIN'])){
  $reqOrigin=trim($_SERVER['HTTP_ORIGIN']);
  $corsAllowed=in_array($reqOrigin,$corsAllowedOrigins,true);
  if(!$corsAllowed){
    $corsSame=($isHttps?'https':'http').'://'.($_SERVER['HTTP_HOST']??'');
    if($reqOrigin===$corsSame) $corsAllowed=true;
  }
  if($corsAllowed) $corsOrigin=$reqOrigin;
}
if($corsOrigin!==''){ header('Access-Control-Allow-Origin: '.$corsOrigin); header('Access-Control-Allow-Credentials: true'); }
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){ http_response_code(204); exit; }

require __DIR__.'/helpers.php';
require_once __DIR__ . '/cert_template.php';
// auto-restore session dari remember_me cookie untuk semua route proteksi
if(empty($_SESSION['user'])) tryRememberLogin();
if(file_exists(__DIR__.'/../vendor/autoload.php')) require __DIR__.'/../vendor/autoload.php';
else if(file_exists(__DIR__.'/vendor/autoload.php')) require __DIR__.'/vendor/autoload.php';

if(strlen($_SERVER['QUERY_STRING']??'')>4096) jsonOut(['success'=>false,'error'=>['code'=>'URI_TOO_LONG','message'=>'Query string terlalu panjang']],414);
$uri=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$uri=preg_replace('#^/api#','',$uri);
$uri=rtrim($uri,'/'); if($uri==='') $uri='/';
$method=$_SERVER['REQUEST_METHOD']??'GET';

function routeMatch($pattern,$uri,&$params=[]){
  $rx='#^'.preg_replace('#:([\w]+)#','(?P<$1>[^/]+)',$pattern).'$#';
  if(preg_match($rx,$uri,$m)){ foreach($m as $k=>$v) if(!is_int($k)) $params[$k]=$v; return true; }
  return false;
}
function getBody(){ if(isset($GLOBALS['_json_body'])) return $GLOBALS['_json_body']; $b=file_get_contents('php://input'); if(strlen($b)>1048576) jsonOut(['success'=>false,'error'=>['code'=>'PAYLOAD_TOO_LARGE','message'=>'Payload terlalu besar']],413); if(strpos($b,"\0")!==false) jsonOut(['success'=>false,'error'=>['code'=>'PAYLOAD_INVALID','message'=>'Payload tidak valid']],400); $j=json_decode($b,true); return is_array($j)?$j:[]; }
function getRealIp(){ $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1'; if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false) return $ip; $fwd=trim((string)($_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['HTTP_X_REAL_IP']??'')); if($fwd!==''){ $p=explode(',',$fwd); $cand=trim((string)array_shift($p)); if($cand!=='' && filter_var($cand,FILTER_VALIDATE_IP)) return $cand; } return $ip; }
function certLogAppend($line){ try{ $f=__DIR__.'/logs/cert_font.log'; if(!is_dir(__DIR__.'/logs')) @mkdir(__DIR__.'/logs',0777,true); if(is_file($f)){ @clearstatcache(true,$f); if(@filesize($f)>5242880) @file_put_contents($f,'',LOCK_EX); } @file_put_contents($f,date('c').' '.$line.PHP_EOL,FILE_APPEND|LOCK_EX); }catch(Exception $e){} }

// === Integrasi SRE: hook route (health/status/backup) — DI SINI, sebelum route publik & CSRF guard ===
// sre_handle_route() mengembalikan true bila sudah ter-handle (respons terkirim + exit internal).
$sreHandled = sre_handle_route($method, $uri);
if ($sreHandled === true) { exit; }

// public
if($uri==='/' && $method==='GET') jsonOut(['success'=>true,'data'=>['name'=>'Ekskul API','version'=>'1.0'],'message'=>'OK']);
if(routeMatch('/csrf',$uri) && $method==='GET') jsonOut(['success'=>true,'data'=>['csrf'=>ensureCsrfToken(),'integrity'=>sre_integrity_issue()]]);
if(routeMatch('/auth/login',$uri) && $method==='POST'){
  $b=getBody(); $email=trim($b['email']??''); $pass=$b['password']??'';
  if(!$email||!$pass) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Email & password wajib']],422);
  loginRateLimit($email);
  $u=pdo()->prepare('SELECT * FROM users WHERE email=? AND deleted_at IS NULL'); $u->execute([$email]); $user=$u->fetch();
  if(!$user||!password_verify($pass,$user['password_hash'])){ loginRateLimit($email,'fail'); jsonOut(['success'=>false,'error'=>['code'=>'AUTH','message'=>'Email/password salah']],401); }
  // suspend check: only aktif can login
  $st = $user['status'] ?? 'aktif';
  if($st!=='aktif'){ loginRateLimit($email,'fail'); jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akun dinonaktifkan ('.$st.')']],403); }
  loginRateLimit($email,'reset');
  try{ $up=pdo()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?'); $up->execute([$user['id']]); }catch(Exception $e){}
  session_regenerate_id(true); $_SESSION['user']=['id'=>$user['id'],'nama'=>$user['nama'],'email'=>$user['email'],'role'=>$user['role']];
  ensureCsrfToken();
  if(!empty($b['remember'])) issueRememberToken((int)$user['id'], 30);
  jsonOut(['success'=>true,'data'=>['user'=>$_SESSION['user'],'csrf'=>$_SESSION['csrf']]]);
}
if(routeMatch('/auth/logout',$uri) && $method==='POST'){ revokeRememberToken(); $_SESSION=[]; if(ini_get('session.use_cookies')){ $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',!empty($p['secure']),!empty($p['httponly'])); } session_destroy(); jsonOut(['success'=>true,'data'=>null,'message'=>'Logged out']); }
if(routeMatch('/auth/me',$uri) && $method==='GET'){
  if(empty($_SESSION['user'])) tryRememberLogin();
  if(empty($_SESSION['user'])) jsonOut(['success'=>false,'error'=>['code'=>'UNAUTHORIZED','message'=>'Belum login']],401);
  sre_integrity_verify();
  $me=$_SESSION['user'];
  try{ $fs=pdo()->prepare('SELECT foto FROM users WHERE id=?'); $fs->execute([(int)$me['id']]); $fr=$fs->fetch(); $me['foto']=$fr['foto']??null; $me['foto_url']=$me['foto']?'/api/avatar/'.(int)$me['id']:null; $_SESSION['user']['foto']=$me['foto']; $_SESSION['user']['foto_url']=$me['foto_url']; }catch(Exception $e){ $me['foto']=$me['foto']??null; $me['foto_url']=$me['foto_url']??null; }
  jsonOut(['success'=>true,'data'=>['user'=>$me,'csrf'=>ensureCsrfToken(),'integrity'=>sre_integrity_issue()]]);
}

// CSRF guard — exempt public auth routes (login/csrf) which are handled above
$csrfExempt = ['/auth/login','/auth/forgot-password','/auth/reset-confirm','/csrf','/'];
$isCsrfExempt=false; foreach($csrfExempt as $p){ if($uri===$p) $isCsrfExempt=true; }
$publicGets = ['/ekskul','/events','/announcements','/kalender','/login-settings','/login-hero'];
$isPublicGet=false;
if($method==='GET'){
  foreach($publicGets as $p){ if($uri===$p || str_starts_with($uri,$p.'/') || $uri==='/kalender') { $isPublicGet=true; break; } }
  if(routeMatch('/ekskul/:id',$uri) && $method==='GET') $isPublicGet=true;
  if(routeMatch('/events/:id',$uri) && $method==='GET') $isPublicGet=true;
  if(routeMatch('/covers/:tipe/:id',$uri) && $method==='GET') $isPublicGet=true;
  if(routeMatch('/avatar/:id',$uri) && $method==='GET') $isPublicGet=true;
}
if(!$isPublicGet && !$isCsrfExempt){
  if(in_array($method,['POST','PUT','PATCH','DELETE'],true)){
    $h=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!$h){ $tmp=getBody(); $h=$tmp['_csrf']??''; $GLOBALS['_json_body']=$tmp; }

    if(empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$h)) jsonOut(['success'=>false,'error'=>['code'=>'CSRF_INVALID','message'=>'CSRF invalid, refresh token']],403);
  }
}

// helpers
function scopedEkskulIds($pembinaId){
  $rows=pdo()->prepare('SELECT id FROM ekskul WHERE pembina_id=?'); $rows->execute([$pembinaId]);
  return array_column($rows->fetchAll(),'id');
}
function isPembinaOf($ekskulId){
  $u=currentUser(); if(!$u) return false;
  if($u['role']==='admin') return true;
  if($u['role']!=='pembina') return false;
  $r=pdo()->prepare('SELECT 1 FROM ekskul WHERE id=? AND pembina_id=?'); $r->execute([$ekskulId,$u['id']]);
  return (bool)$r->fetch();
}
function isKepsekOrAdmin(){ $u=currentUser(); return $u && in_array($u['role'],['admin','kepsek'],true); }

// === EKSKUL — Katalog Ekskul Admin View (Opsi C Hybrid + Opsi B Performant) ===
if($uri==='/ekskul' && $method==='GET'){
  // query: ?page=&limit=&search=&status=&kuota=&sort=&include_dummy=1
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $search=trim($_GET['search']??'');
  $status=trim($_GET['status']??''); // All/Approved/Pending/Rejected
  $kuota=trim($_GET['kuota']??''); // all/tersedia/penuh
  $sort=trim($_GET['sort']??''); // nama_asc | terisi_desc | updated_desc (default)
  $includeDummy = isset($_GET['include_dummy']) && $_GET['include_dummy']=='1';
  // APP_ENV: is_dummy always hidden in prod unless include_dummy & dev role
  $cu=currentUser();
  $isDev = getenv('APP_ENV')==='local' || getenv('APP_ENV')==='dev' || ($_SERVER['APP_ENV']??'')==='local';
  // Server cache 60s (file/APCu) — hit final payload, skip DB. Key = semua params + scope user.
  $cacheKey = cacheKey('ekskul', [$page,$limit,$search,$status,$kuota,$sort,$includeDummy,$_GET['mode']??'', $cu['role']??'guest', $cu['id']??0]);
  $cachedJson = cacheGet($cacheKey, 60);
  if($cachedJson !== null){
    $cacheMeta = json_decode($cachedJson, true);
    $etag = '"'.md5($cachedJson).'"';
    $cc = $cu ? 'private, max-age=60, stale-while-revalidate=300' : 'public, max-age=60, stale-while-revalidate=300';
    header('ETag: '.$etag); header('Cache-Control: '.$cc); header('Vary: Cookie');
    header('X-Total-Count: '.($cacheMeta['meta']['total']??0)); header('X-Page: '.($cacheMeta['meta']['page']??'')); header('X-Limit: '.($cacheMeta['meta']['limit']??''));
    if(!empty($cacheMeta['meta']['limited'])) header('X-Scope: siswa-limited');
    if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
    header('Content-Type: application/json');
    echo $cachedJson; exit;
  }
  // base
  $where=[]; $par=[];
  // soft delete
  $where[]='e.deleted_at IS NULL';
  // dummy guard: hide dummy unless dev+include_dummy
  if(!$includeDummy || !$isDev){
    $where[]='e.is_dummy=0';
  }
  // scoped pembina: only own ekskul
  $scopedPembina = ($cu && $cu['role']==='pembina');
  if($scopedPembina){ $where[]='e.pembina_id=?'; $par[]=$cu['id']; }
  // siswa max-2: siswa dengan 2 diterima hanya lihat miliknya (registrations diterima/menunggu, exclude ditolak)
  // FE mode: ?mode=mine hanya miliknya (untuk tab Saya); default discovery bila <2
  $siswaLimited=false; $siswaModeMine=(($_GET['mode']??'')==='mine');
  if($cu && $cu['role']==='siswa'){
    try{
      $mc=pdo()->prepare("SELECT COUNT(*) c FROM registrations WHERE user_id=? AND status='diterima' AND deleted_at IS NULL");
      $mc->execute([$cu['id']]); $mineCount=(int)($mc->fetch()['c']??0);
    }catch(Exception $e){ $mineCount=0; }
    if($mineCount>=2 || $siswaModeMine){
      $siswaLimited=true;
      $where[]="EXISTS (SELECT 1 FROM registrations r0 WHERE r0.ekskul_id=e.id AND r0.user_id=? AND r0.status IN ('diterima','menunggu') AND r0.deleted_at IS NULL)";
      $par[]=$cu['id'];
    }
  }
  // siswa/public only approved — admin/kepsek/pembina can filter all
  // siswa limited (2 diterima): mode=mine diabaikan — list sudah miliknya
  if(!$cu || $cu['role']==='siswa'){
    $where[]="e.status='approved'";
  } else if($status && in_array($status,['pending','approved','rejected'],true)){
    $where[]='e.status=?'; $par[]=$status;
  } else if($status==='mine' && $scopedPembina){
    // already scoped above, ignore status
  }
  // search BE sync — nama OR deskripsi OR pembina (3 placeholder, prepared) — sinkron dengan FE includes
  if($search!==''){
    $where[]='(e.nama LIKE ? OR e.deskripsi LIKE ? OR u.nama LIKE ?)';
    $kw='%'.$search.'%';
    $par[]=$kw; $par[]=$kw; $par[]=$kw;
  }
  // kuota filter sinkron FE: tersedia sisa>3, hampir 1-3, penuh <=0 (sisa = kuota - terisi)
  // hemat: pakai derived rc (1x GROUP BY, sama spt SELECT) — tanpa subquery korelasi per baris
  $rcJoin=' LEFT JOIN (SELECT r.ekskul_id, COUNT(*) AS terisi FROM registrations r WHERE r.status IN ("diterima","menunggu") AND r.deleted_at IS NULL GROUP BY r.ekskul_id) rc ON rc.ekskul_id=e.id';
  $kuotaWhere='';
  if($kuota==='tersedia'){
    $kuotaWhere=' AND (e.kuota - COALESCE(rc.terisi,0)) > 3';
  } else if($kuota==='hampir'){
    $kuotaWhere=' AND (e.kuota - COALESCE(rc.terisi,0)) BETWEEN 1 AND 3';
  } else if($kuota==='penuh'){
    $kuotaWhere=' AND (e.kuota - COALESCE(rc.terisi,0)) <= 0';
  }
  // sort
  $order=' ORDER BY e.updated_at DESC';
  if($sort==='nama_asc') $order=' ORDER BY e.nama ASC';
  else if($sort==='terisi_desc') $order=' ORDER BY terisi DESC, e.nama ASC';
  // total — need LEFT JOIN users u when search uses u.nama
  $joinU = ($search!=='') ? ' LEFT JOIN users u ON u.id=e.pembina_id' : '';
  $cq='SELECT COUNT(*) c FROM ekskul e'.$joinU.$rcJoin.' WHERE '.implode(' AND ',$where).$kuotaWhere;
  $ct=pdo()->prepare($cq); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // data: 1x JOIN + LEFT JOIN (GROUP BY) terisi sekali — no N+1, kolom eksplisit (tanpa SELECT * / correl subquery), PDO 100% prepared
  $ekskulCols='e.id,e.nama,e.deskripsi,e.pembina_id,e.kuota,e.requires_approval,e.hari,e.jam_mulai,e.jam_selesai,e.lokasi,e.status,e.registration_start,e.registration_end,e.is_dummy,e.deleted_at,e.created_at,e.updated_at,e.cover_path,e.rejected_reason';
  $q='SELECT '.$ekskulCols.', u.nama pembina_nama, COALESCE(rc.terisi,0) AS terisi FROM ekskul e LEFT JOIN users u ON u.id=e.pembina_id'.$rcJoin.' WHERE '.implode(' AND ',$where).$kuotaWhere.$order.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
  $st=pdo()->prepare($q); $st->execute($par);
  $rows=$st->fetchAll();
  // my_status for siswa: 1 extra query no N+1 — is_registered pattern like Events
  if($cu && $cu['role']==='siswa' && $rows){
    $ids=array_column($rows,'id');
    if($ids){
      $ph=implode(',',array_fill(0,count($ids),'?'));
      $par2=array_merge([$cu['id']], $ids);
      $st2=pdo()->prepare("SELECT ekskul_id, status FROM registrations WHERE user_id=? AND ekskul_id IN ($ph) AND deleted_at IS NULL");
      $st2->execute($par2);
      $map=[];
      foreach($st2->fetchAll() as $r){ $map[(int)$r['ekskul_id']]=$r['status']; }
      foreach($rows as &$r){ $r['my_status']=$map[(int)$r['id']] ?? null; }
      unset($r);
    } else {
      foreach($rows as &$r){ $r['my_status']=null; } unset($r);
    }
  } else {
    foreach($rows as &$r){ $r['my_status']=null; } unset($r);
  }
  // cover_url: stable public URL (null when no cover) — strip cover_path (internal fs layout, anti-leak)
  foreach($rows as &$r){ $r['cover_url']=!empty($r['cover_path'])?('/api/covers/ekskul/'.$r['id']):null; unset($r['cover_path']); } unset($r);
  // Server cache 60s: simpan PAYLOAD final (sudah escapd+transform). Browser ETag/Cache-Control tetap jalan di atasnya.
  $outJson = json_encode(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=> (int)ceil($total/$limit),'limited'=>$siswaLimited]], JSON_UNESCAPED_UNICODE);
  cacheSet($cacheKey, $outJson, 60);
  $etag='"'.md5($outJson).'"';
  $cc = $cu ? 'private, max-age=60, stale-while-revalidate=300' : 'public, max-age=60, stale-while-revalidate=300';
  header('ETag: '.$etag); header('Cache-Control: '.$cc); header('Vary: Cookie');
  header('X-Total-Count: '.$total); header('X-Page: '.$page); header('X-Limit: '.$limit);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  if($siswaLimited) header('X-Scope: siswa-limited');
  header('Content-Type: application/json');
  echo $outJson; exit;
}
if(routeMatch('/ekskul/:id',$uri,$pm) && $method==='GET'){
  // JOIN users pembina, 1 query — PRD :93 / task Backend GET /api/ekskul/:id + anti-leak pembina/admin scoping
  $st=pdo()->prepare('SELECT e.id,e.nama,e.deskripsi,e.pembina_id,e.kuota,e.requires_approval,e.hari,e.jam_mulai,e.jam_selesai,e.lokasi,e.status,e.registration_start,e.registration_end,e.is_dummy,e.deleted_at,e.created_at,e.updated_at,e.cover_path,e.rejected_reason, u.nama pembina_nama, COALESCE(rc.terisi,0) AS terisi FROM ekskul e LEFT JOIN users u ON u.id=e.pembina_id LEFT JOIN (SELECT r.ekskul_id, COUNT(*) AS terisi FROM registrations r WHERE r.status IN ("diterima","menunggu") AND r.deleted_at IS NULL GROUP BY r.ekskul_id) rc ON rc.ekskul_id=e.id WHERE e.id=? AND e.deleted_at IS NULL'); $st->execute([$pm['id']]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $isDev = getenv('APP_ENV')==='local' || getenv('APP_ENV')==='dev' || ($_SERVER['APP_ENV']??'')==='local';
  $cu=currentUser();
  if(!empty($row['is_dummy'])){
    $allowDummy = $isDev || ($cu && in_array($cu['role'],['admin','pembina'],true) && !empty($_GET['include_dummy']));
    if(!$allowDummy) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  }
  // leak guard: public/siswa hanya approved; pembina HANYA own (semua status) — blind 404 untuk non-binaan
  if(!$cu || $cu['role']==='siswa'){
    if($row['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  } else if($cu['role']==='pembina'){
    if((int)$row['pembina_id'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  }
  $row['my_status']=null;
  if($cu && $cu['role']==='siswa'){
    $chk=pdo()->prepare('SELECT status FROM registrations WHERE user_id=? AND ekskul_id=? AND deleted_at IS NULL'); $chk->execute([$cu['id'],$row['id']]);
    $fr=$chk->fetch(); if($fr) $row['my_status']=$fr['status'];
  }
  $row['cover_url']=!empty($row['cover_path'])?('/api/covers/ekskul/'.$row['id']):null;
  unset($row['cover_path']);
  $etag='"'.md5(json_encode($row).($cu['id']??'guest')).'"';
  $cc = $cu ? 'private, max-age=60, stale-while-revalidate=300' : 'public, max-age=60, stale-while-revalidate=300';
  header('ETag: '.$etag); header('Cache-Control: '.$cc); header('Vary: Cookie');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$row]);
}
function validateEkskulPayload($nama,$deskripsi,$kuota,&$err, $hari=null, $lokasi=null, $jamMulai=null, $jamSelesai=null){
  $n=trim($nama??''); $d=trim($deskripsi??'');
  if(!$n) { $err='Nama wajib'; return false; }
  if(strlen($n)<3) { $err='Nama minimal 3 karakter'; return false; }
  if(preg_match('/^Kuota\d+$/i',$n)) { $err='Nama tidak boleh generik Kuota1/Kuota2'; return false; }
  if(preg_match('/^\s*(test|dummy|asdf|coba)\s*$/i',$n)) { $err='Nama tidak boleh test/dummy'; return false; }
  if(strlen($n)<15 && preg_match('/test/i',$n)) { $err='Nama mengandung test tidak diperbolehkan (kecuali nama panjang resmi)'; return false; }
  if((int)$kuota<1) { $err='Kuota harus >0'; return false; }
  // task: deskripsi required min 10
  if($d==='') { $err='Deskripsi wajib (minimal 10 karakter)'; return false; }
  if(strlen($d)<10) { $err='Deskripsi minimal 10 karakter'; return false; }
  if(preg_match('/^\s*(test|dummy|asdf|coba)\s*$/i',$d)) { $err='Deskripsi tidak boleh hanya test/dummy'; return false; }
  // task: hari, lokasi, jam required
  if($hari!==null){
    $h=trim((string)$hari);
    if($h==='') { $err='Hari wajib diisi (Senin-Minggu)'; return false; }
    if(!in_array($h,['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'],true)) { $err='Hari tidak valid'; return false; }
  }
  if($lokasi!==null){
    $l=trim((string)$lokasi);
    if($l==='') { $err='Lokasi wajib diisi'; return false; }
    if(strlen($l)<3) { $err='Lokasi minimal 3 karakter'; return false; }
  }
  if($jamMulai!==null || $jamSelesai!==null){
    $jm=trim((string)($jamMulai??'')); $js=trim((string)($jamSelesai??''));
    if($jm==='') { $err='Jam mulai wajib diisi'; return false; }
    if($js==='') { $err='Jam selesai wajib diisi'; return false; }
    if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$jm) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$js)) { $err='Format jam harus HH:MM'; return false; }
    // normalize to HH:MM for compare
    $a=strtotime('2000-01-01 '.$jm); $b=strtotime('2000-01-01 '.$js);
    if($a!==false && $b!==false && $a >= $b){ $err='Jam mulai harus lebih kecil dari jam selesai'; return false; }
    // also reject dummy test
    if(preg_match('/^(00:00)/',$jm) && preg_match('/^(00:00)/',$js)){ /* allow but not error */ }
  }
  return true;
}
function validatePembinaId($pembinaId, &$err){
  if($pembinaId==='' || $pembinaId===null) { $err='Pembina wajib dipilih'; return false; }
  $pid=(int)$pembinaId;
  if($pid<1) { $err='Pembina tidak valid'; return false; }
  $st=pdo()->prepare('SELECT id FROM users WHERE id=? AND role=?'); $st->execute([$pid,'pembina']);
  if(!$st->fetch()){ $err='Pembina tidak ditemukan atau bukan role pembina'; return false; }
  return true;
}
if($uri==='/ekskul' && $method==='POST'){
  requireRole('admin','pembina');
  $b=getBody();
  $nama=trim($b['nama']??''); $kuota=(int)($b['kuota']??0); $deskripsi=$b['deskripsi']??'';
  // task: hari/lokasi/jam required on CREATE — null handling: if missing treat as '' => wajib
  $hari=array_key_exists('hari',$b)?$b['hari']:''; 
  $lokasi=array_key_exists('lokasi',$b)?$b['lokasi']:'';
  $jm=array_key_exists('jam_mulai',$b)?$b['jam_mulai']:'';
  $js=array_key_exists('jam_selesai',$b)?$b['jam_selesai']:'';
  $err=''; if(!validateEkskulPayload($nama,$deskripsi,$kuota,$err,$hari,$lokasi,$jm,$js)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>$err]],422);
  // pembina_id WAJIB exists AND role='pembina' — PDO prepared 100%
  $rawPid = $b['pembina_id']??null;
  if(currentUser()['role']==='pembina') $rawPid=currentUser()['id'];
  // if admin didn't supply, default to current admin id only if admin is also pembina? no — require explicit
  if($rawPid===null || $rawPid==='') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pembina wajib dipilih']],422);
  if(!validatePembinaId($rawPid,$err)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>$err]],422);
  $pembina_id=(int)$rawPid;
  $requires=(int)!empty($b['requires_approval']); // default 0 auto terima PRD:19
  $role=currentUser()['role'];
  $status = ($role==='kepsek'||$role==='admin')? 'approved':'pending';
  if($role==='pembina') $status='pending';
  // is_dummy auto-flag for dev data
  $isDummy = (preg_match('/^Kuota\d+$/i',$nama) || preg_match('/^\s*(test|dummy)/i',$nama) || preg_match('/^\s*(test|dummy)/i',$deskripsi)) ? 1 : 0;
  $st=pdo()->prepare('INSERT INTO ekskul(nama,deskripsi,pembina_id,kuota,requires_approval,hari,jam_mulai,jam_selesai,lokasi,status,registration_start,registration_end,is_dummy) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $st->execute([$nama,$deskripsi?:null,$pembina_id,$kuota,$requires,$b['hari']??null,$b['jam_mulai']??null,$b['jam_selesai']??null,$b['lokasi']??null,$status,$b['registration_start']??null,$b['registration_end']??null,$isDummy]);
  $newId=(int)pdo()->lastInsertId();
  // Opsi B admin notif: ekskul pending need approval
  try{
    if($status==='pending'){
      $cntP=pdo()->prepare('SELECT COUNT(*) c FROM ekskul WHERE status="pending" AND deleted_at IS NULL'); $cntP->execute(); $pendingTotal=(int)($cntP->fetch()['c']??0);
      notifyAdmins($pendingTotal.' ekskul menunggu persetujuan','Ekskul '.$nama.' pending — perlu approval ('.$pendingTotal.' total pending)','admin_alert','ekskul_pending',$newId);
      notifyKepsek('Ekskul menunggu: '.$nama, $nama.' oleh '.currentUser()['nama'].' — '.$pendingTotal.' pending','admin_alert','ekskul_pending',$newId);
    }
  }catch(Exception $e2){}
  cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['id'=>$newId,'status'=>$status,'is_dummy'=>$isDummy]],201);
}
if(routeMatch('/ekskul/:id',$uri,$pm) && in_array($method,['PUT','PATCH'],true)){
  requireLogin(); $b=getBody();
  $id=$pm['id']; if(!isPembinaOf($id) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  // validate if nama/deskripsi/kuota/hari/lokasi/jam present — enforce required for task
  if(array_key_exists('nama',$b) || array_key_exists('deskripsi',$b) || array_key_exists('kuota',$b) || array_key_exists('hari',$b) || array_key_exists('lokasi',$b) || array_key_exists('jam_mulai',$b) || array_key_exists('jam_selesai',$b)){
    $cur=pdo()->prepare('SELECT nama, deskripsi, kuota, hari, lokasi, jam_mulai, jam_selesai FROM ekskul WHERE id=?'); $cur->execute([$id]); $curRow=$cur->fetch();
    $nama=array_key_exists('nama',$b)?$b['nama']:$curRow['nama'];
    $desk=array_key_exists('deskripsi',$b)?$b['deskripsi']:$curRow['deskripsi'];
    $kuota=array_key_exists('kuota',$b)?$b['kuota']:$curRow['kuota'];
    $hari=array_key_exists('hari',$b)?$b['hari']:$curRow['hari'];
    $lok=array_key_exists('lokasi',$b)?$b['lokasi']:$curRow['lokasi'];
    $jm=array_key_exists('jam_mulai',$b)?$b['jam_mulai']:$curRow['jam_mulai'];
    $js=array_key_exists('jam_selesai',$b)?$b['jam_selesai']:$curRow['jam_selesai'];
    $err=''; if(!validateEkskulPayload($nama,$desk,$kuota,$err,$hari,$lok,$jm,$js)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>$err]],422);
  }
  if(array_key_exists('kuota',$b) && (int)$b['kuota']<1) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Kuota harus >0']],422);
  // normalize empty string -> NULL for nullable fields; skip invalid pembina_id
  foreach(['hari','jam_mulai','jam_selesai','lokasi','registration_start','registration_end'] as $nf){
    if(array_key_exists($nf,$b) && trim((string)$b[$nf])==='') $b[$nf]=null;
  }
  // pembina_id: only admin can change; '' or null -> skip update (keep old) — but if provided must be valid pembina
  if(array_key_exists('pembina_id',$b)){
    if(currentUser()['role']!=='admin') unset($b['pembina_id']);
    else {
      $pid=trim((string)$b['pembina_id']);
      if($pid==='' || $pid==='0') unset($b['pembina_id']);
      else {
        $err=''; if(!validatePembinaId($pid,$err)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>$err]],422);
        $b['pembina_id']=(int)$pid;
      }
    }
  }
  // normalize requires_approval to 0/1
  if(array_key_exists('requires_approval',$b)) $b['requires_approval']=(int)!empty($b['requires_approval']);
  $fields=['nama','deskripsi','kuota','requires_approval','hari','jam_mulai','jam_selesai','lokasi','pembina_id','registration_start','registration_end'];
  $sets=[]; $par=[];
  foreach($fields as $f){ if(array_key_exists($f,$b)){ $sets[]="$f=?"; $par[]=$b[$f]; } }
  // auto recompute is_dummy if nama/deskripsi changed
  if(array_key_exists('nama',$b) || array_key_exists('deskripsi',$b)){
    $nm=array_key_exists('nama',$b)?$b['nama']:null;
    $ds=array_key_exists('deskripsi',$b)?$b['deskripsi']:null;
    if($nm===null){ $tmp=pdo()->prepare('SELECT nama FROM ekskul WHERE id=?'); $tmp->execute([$id]); $nm=$tmp->fetch()['nama']??''; }
    if($ds===null){ $tmp=pdo()->prepare('SELECT deskripsi FROM ekskul WHERE id=?'); $tmp->execute([$id]); $ds=$tmp->fetch()['deskripsi']??''; }
    $isDummy = (preg_match('/^Kuota\d+$/i',trim($nm)) || preg_match('/^\s*(test|dummy)/i',trim($nm)) || preg_match('/^\s*(test|dummy)/i',trim((string)$ds))) ? 1 : 0;
    $sets[]='is_dummy=?'; $par[]=$isDummy;
  }
  if(!$sets) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tidak ada field']],422);
  $sets[]='updated_at=NOW()';
  $par[]=$id; pdo()->prepare('UPDATE ekskul SET '.implode(',',$sets).' WHERE id=?')->execute($par);
  cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['id'=>$id]]);
}
if(routeMatch('/ekskul/:id',$uri,$pm) && $method==='DELETE'){
  requireRole('admin'); pdo()->prepare('UPDATE ekskul SET deleted_at=NOW(), updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$pm['id']]); cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap'); jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/ekskul/:id/toggle-approval',$uri,$pm) && $method==='POST'){
  requireLogin(); $id=$pm['id'];
  if(!isPembinaOf($id) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina']],403);
  // toggle requires_approval — only admin & pembina owner, kepsek not needed here but allowed
  $cur=pdo()->prepare('SELECT requires_approval FROM ekskul WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $next = $row['requires_approval'] ? 0 : 1;
  pdo()->prepare('UPDATE ekskul SET requires_approval=?, updated_at=NOW() WHERE id=?')->execute([$next,$id]);
  cacheDelPrefix('ekskul'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['requires_approval'=>$next]]);
}
// approvals — HANYA kepsek (admin tidak boleh approve ekskul/event, sesuai PRD) — Opsi C: rejected_reason + audit + idempotent + notif
if(routeMatch('/ekskul/:id/approve',$uri,$pm) && $method==='POST'){
  requireRole('kepsek'); $b=getBody(); $act=trim(strtolower($b['action']??'approve')); $st=$act==='reject'?'rejected':'approved';
  if(!in_array($act,['approve','reject'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'action harus approve/reject']],422);
  $cur=pdo()->prepare('SELECT status, nama FROM ekskul WHERE id=? AND deleted_at IS NULL'); $cur->execute([$pm['id']]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  if($row['status']!== 'pending') jsonOut(['success'=>false,'error'=>['code'=>'CONFLICT','message'=>'Hanya pending bisa di-approve/reject (status sekarang: '.$row['status'].')']],409);
  if($st==='rejected'){
    $reason=trim($b['rejected_reason']??$b['reason']??'');
    if(mb_strlen($reason)<10) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Alasan reject minimal 10 karakter']],422);
    // ensure column exists (auto-migrated via db.php but keep fallback)
    try{ $col=pdo()->query("SHOW COLUMNS FROM ekskul LIKE 'rejected_reason'")->fetch(); if(!$col) pdo()->exec("ALTER TABLE ekskul ADD COLUMN rejected_reason TEXT NULL"); }catch(Exception $e){}
    pdo()->prepare('UPDATE ekskul SET status=?, rejected_reason=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$st,$reason,$pm['id']]);
  } else {
    pdo()->prepare('UPDATE ekskul SET status=?, rejected_reason=NULL, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$st,$pm['id']]);
  }
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'approve','ekskul',$pm['id'], json_encode(['status'=>$st,'reason'=>$b['rejected_reason']??$b['reason']??null],JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'approve','ekskul',$pm['id'], json_encode(['status'=>$st,'reason'=>$b['rejected_reason']??null],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  // notif to pembina + admin
  try{
    $ekName=$row['nama']??('Ekskul #'.$pm['id']);
    if($st==='approved'){
      // best-effort: notify pembina via polling reveal, no direct inbox yet — keep audit canonical
    }
  }catch(Exception $e){}
  cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['status'=>$st]]);
}
if(routeMatch('/approvals/batch',$uri,$pm) && $method==='POST'){
  requireRole('kepsek'); $b=getBody();
  $type=trim($b['type']??''); // ekskul|event|all
  $action=trim(strtolower($b['action']??''));
  $ids=$b['ids']??[];
  $reason=trim($b['rejected_reason']??$b['reason']??'');
  if(!in_array($type,['ekskul','event','mixed'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'type harus ekskul/event/mixed']],422);
  if(!in_array($action,['approve','reject'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'action harus approve/reject']],422);
  if(!is_array($ids) || !count($ids)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids wajib array non-empty']],422);
  if(count($ids)>50) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Batch maksimal 50']],422);
  if($action==='reject' && mb_strlen($reason)<10) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Alasan reject minimal 10 karakter']],422);
  $ids=array_values(array_unique(array_map('intval',$ids)));
  foreach($ids as $v) if($v<=0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids tidak valid']],422);
  $st=$action==='reject'?'rejected':'approved';
  $ok=0; $fail=[];
  $pdo=pdo();
  foreach($ids as $id){
    try{
      if($type==='ekskul' || $type==='mixed'){
        $cur=$pdo->prepare('SELECT status FROM ekskul WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $row=$cur->fetch();
        if($row && $row['status']==='pending'){
          if($st==='rejected') $pdo->prepare('UPDATE ekskul SET status=?, rejected_reason=?, updated_at=NOW() WHERE id=?')->execute([$st,$reason,$id]);
          else $pdo->prepare('UPDATE ekskul SET status=?, rejected_reason=NULL, updated_at=NOW() WHERE id=?')->execute([$st,$id]);
          try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'batch_approve','ekskul',$id, json_encode(['status'=>$st,'reason'=>$reason?:null],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
          $ok++;
          continue;
        }
      }
      if($type==='event' || $type==='mixed'){
        $cur=$pdo->prepare('SELECT status FROM events WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $row=$cur->fetch();
        if($row && $row['status']==='pending'){
          if($st==='rejected') $pdo->prepare('UPDATE events SET status=?, rejected_reason=?, updated_at=NOW() WHERE id=?')->execute([$st,$reason,$id]);
          else $pdo->prepare('UPDATE events SET status=?, updated_at=NOW() WHERE id=?')->execute([$st,$id]);
          try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'batch_approve','event',$id, json_encode(['status'=>$st,'reason'=>$reason?:null],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
          $ok++;
          continue;
        }
      }
      $fail[]=['id'=>$id,'reason'=>'not pending or not found'];
    }catch(Exception $e){ $fail[]=['id'=>$id,'reason'=>$e->getMessage()]; }
  }
  cacheDelPrefix('ekskul'); cacheDelPrefix('events'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['ok'=>$ok,'fail'=>$fail,'status'=>$st]]);
}
if($uri==='/approvals/export' && $method==='GET'){
  requireRole('kepsek');
  $status=trim($_GET['status']??'pending');
  if(!in_array($status,['pending','approved','rejected','all'],true)) $status='pending';
  if(ob_get_level()) @ob_end_clean(); header_remove('Content-Type');
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="approvals-'.$status.'-'.date('Ymd').'.csv"'); header('Cache-Control: no-store');
  echo chr(0xEF).chr(0xBB).chr(0xBF);
  $out=fopen('php://output','w'); fputcsv($out,['tipe','id','nama','status','pembina/creator','tanggal','kuota','created_at']);
  $pdo=pdo(); try{ $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),false); }catch(Exception $e){}
  if($status==='all' || $status==='pending' || $status==='approved' || $status==='rejected'){
    $whereEk='e.deleted_at IS NULL'; $parEk=[];
    if($status!=='all'){ $whereEk.=' AND e.status=?'; $parEk[]=$status; }
    $st=$pdo->prepare("SELECT e.id,e.nama,e.status,e.pembina_id,u.nama pembina_nama,e.tanggal,e.kuota,e.created_at FROM (SELECT id,nama,status,pembina_id, NULL tanggal, kuota, created_at, deleted_at FROM ekskul UNION ALL SELECT id,nama,status,created_by pembina_id, tanggal, kuota, created_at, deleted_at FROM events) e LEFT JOIN users u ON u.id=e.pembina_id WHERE $whereEk ORDER BY e.created_at DESC LIMIT 500");
    // fallback simple: 2 queries
    try{
      $ekQ=$pdo->prepare("SELECT e.id,e.nama,e.status,e.pembina_id,u.nama pembina_nama, NULL tanggal, e.kuota,e.created_at,'ekskul' tipe FROM ekskul e LEFT JOIN users u ON u.id=e.pembina_id WHERE e.deleted_at IS NULL ".($status!=='all'?" AND e.status=?":"")." ORDER BY e.created_at DESC LIMIT 300");
      $ekQ->execute($status!=='all'?[$status]:[]);
      while($r=$ekQ->fetch(PDO::FETCH_ASSOC)){ fputcsv($out, ['ekskul',$r['id'],$r['nama'],$r['status'],$r['pembina_nama']??'',$r['tanggal']??'',$r['kuota'],$r['created_at']]); }
      $evQ=$pdo->prepare("SELECT e.id,e.nama,e.status,u.nama creator, e.tanggal,e.kuota,e.created_at,'event' tipe FROM events e LEFT JOIN users u ON u.id=e.created_by WHERE e.deleted_at IS NULL ".($status!=='all'?" AND e.status=?":"")." ORDER BY e.created_at DESC LIMIT 300");
      $evQ->execute($status!=='all'?[$status]:[]);
      while($r=$evQ->fetch(PDO::FETCH_ASSOC)){ fputcsv($out, ['event',$r['id'],$r['nama'],$r['status'],$r['creator']??'',$r['tanggal'],$r['kuota'],$r['created_at']]); }
    }catch(Exception $e){}
  }
  fclose($out); exit;
}

// pendaftaran hybrid
if(routeMatch('/ekskul/:id/daftar',$uri,$pm) && $method==='POST'){
  requireRole('siswa'); $ekskulId=$pm['id']; $uid=currentUser()['id'];
  $pdo=pdo();
  $ek=$pdo->prepare('SELECT * FROM ekskul WHERE id=?'); $ek->execute([$ekskulId]); $e=$ek->fetch();
  if(!$e) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  if($e['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_OPEN','message'=>'Ekskul belum disetujui']],422);
  if(!empty($e['registration_start']) && strtotime($e['registration_start'])>time()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_OPEN','message'=>'Pendaftaran belum dibuka']],422);
  if(!empty($e['registration_end']) && strtotime($e['registration_end'])<time()) jsonOut(['success'=>false,'error'=>['code'=>'CLOSED','message'=>'Pendaftaran sudah ditutup']],422);
  // max 2 check
  $c=$pdo->prepare('SELECT COUNT(*) c FROM registrations WHERE user_id=? AND status="diterima" AND deleted_at IS NULL'); $c->execute([$uid]); $have=$c->fetch()['c'];
  if($have>=2) jsonOut(['success'=>false,'error'=>['code'=>'LIMIT','message'=>'Maks 2 ekskul']],422);
  // already registered?
  $ex=$pdo->prepare('SELECT status FROM registrations WHERE user_id=? AND ekskul_id=?'); $ex->execute([$uid,$ekskulId]); $already=$ex->fetch();
  if($already) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sudah terdaftar: '.$already['status']]],409);
  // kuota check transaction
  try{
    $pdo->beginTransaction();
    $cnt=$pdo->prepare('SELECT COUNT(*) c FROM registrations WHERE ekskul_id=? AND status IN ("diterima","menunggu") AND deleted_at IS NULL'); $cnt->execute([$ekskulId]); $terisi=$cnt->fetch()['c'];
    if($terisi >= (int)$e['kuota']){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'FULL','message'=>'Kuota penuh']],409); }
    $status = $e['requires_approval'] ? 'menunggu' : 'diterima';
    $pdo->prepare('INSERT INTO registrations(user_id,ekskul_id,status) VALUES (?,?,?)')->execute([$uid,$ekskulId,$status]);
    $pdo->commit();
    // Opsi B admin notif: pendaftaran menunggu (grouped digest) + kuota hampir penuh
    try{
      if($status==='menunggu'){
        $cntM=$pdo->prepare('SELECT COUNT(*) c FROM registrations WHERE status="menunggu" AND deleted_at IS NULL'); $cntM->execute(); $mTotal=(int)($cntM->fetch()['c']??0);
        notifyAdmins($mTotal.' pendaftaran menunggu','Ekskul '.$e['nama'].' — '.currentUser()['nama'].' menunggu ACC ('.$mTotal.' total menunggu)','admin_alert','pendaftaran_menunggu', (int)$ekskulId);
        notifyKepsek($mTotal.' pendaftaran menunggu','Ekskul '.$e['nama'].' menunggu ACC','admin_alert','pendaftaran_menunggu', (int)$ekskulId);
      }
      // kuota hampir penuh >=90% or sisa <=3
      $cnt2=$pdo->prepare('SELECT COUNT(*) c FROM registrations WHERE ekskul_id=? AND status IN ("diterima","menunggu") AND deleted_at IS NULL'); $cnt2->execute([$ekskulId]); $terisi2=(int)($cnt2->fetch()['c']??0);
      $sisa=(int)$e['kuota'] - $terisi2;
      if($sisa<=3 || ($terisi2 >= (int)$e['kuota']*0.9 && $terisi2>0)){
        $pct= $e['kuota']>0? round($terisi2/(int)$e['kuota']*100):0;
        notifyAdmins('Kuota hampir penuh: '.$e['nama'],'Terisi '.$terisi2.'/'.$e['kuota'].' ('.$pct.'%, sisa '.$sisa.')','admin_alert','kuota_'.$ekskulId,(int)$ekskulId);
      }
    }catch(Exception $e2){}
    cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
    jsonOut(['success'=>true,'data'=>['status'=>$status]],201);
  }catch(Exception $exx){ if($pdo->inTransaction()) $pdo->rollBack(); throw $exx; }
}
if(routeMatch('/ekskul/:id/anggota',$uri,$pm) && $method==='GET'){
  requireLogin();
  $eid=(int)$pm['id'];
  $u=currentUser();
  $isMember=false;
  try{ $chk=pdo()->prepare("SELECT 1 FROM registrations WHERE ekskul_id=? AND user_id=? AND deleted_at IS NULL AND status IN ('diterima','menunggu')"); $chk->execute([$eid,$u['id']]); $isMember=(bool)$chk->fetch(); }catch(Exception $e){}
  if(!isPembinaOf($eid) && $u['role']!=='admin' && !$isMember && $u['role']!=='kepsek') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota/pembina ekskul ini']],403);
  $limit = isset($_GET['limit']) && $_GET['limit']!=='' ? min(100,max(1,(int)$_GET['limit'])) : 100;
  $off = 0; // compact list, no pagination needed for now
  // is_online via last_seen > 3 menit
  $qMysql="SELECT r.*, u.nama, u.email, u.last_seen, CASE WHEN u.last_seen IS NOT NULL AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 1 ELSE 0 END AS is_online FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.ekskul_id=? AND r.deleted_at IS NULL ORDER BY is_online DESC, r.created_at DESC LIMIT $limit";
  $st=pdo()->prepare($qMysql);
  $st->execute([$eid]);
  $rows=$st->fetchAll();
  $etag='"'.md5(json_encode($rows).$eid).'"'; header('ETag: '.$etag); header('Cache-Control: private, max-age=30, stale-while-revalidate=60');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}
if(routeMatch('/ekskul/:id/kick/:uid',$uri,$pm) && $method==='POST'){
  requireLogin();
  // requireRole admin/pembina scoped WHERE pembina_id — task Backend
  if(!in_array(currentUser()['role'], ['admin','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya admin/pembina']],403);
  if(!isPembinaOf($pm['id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  $pdo=pdo();
  try {
    $pdo->beginTransaction();
    $now=date('Y-m-d H:i:s');
    // soft-delete registrations + kuota kembali — COMMIT
    $st=$pdo->prepare('UPDATE registrations SET deleted_at=?, status="ditolak" WHERE ekskul_id=? AND user_id=? AND deleted_at IS NULL');
    $st->execute([$now,$pm['id'],$pm['uid']]);
    if($st->rowCount()===0){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Anggota tidak ditemukan atau sudah di-kick']],404); }
    $pdo->commit();
    // return fresh kuota for toast
    $cnt=$pdo->prepare('SELECT COUNT(*) c FROM registrations WHERE ekskul_id=? AND status IN ("diterima","menunggu") AND deleted_at IS NULL'); $cnt->execute([$pm['id']]);
    $terisi=(int)($cnt->fetch()['c'] ?? 0);
    $kuotaRow=$pdo->prepare('SELECT kuota FROM ekskul WHERE id=?'); $kuotaRow->execute([$pm['id']]);
    $kuota=(int)($kuotaRow->fetch()['kuota'] ?? 0);
    cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
    jsonOut(['success'=>true,'data'=>['terisi'=>$terisi,'kuota'=>$kuota,'message'=>'Kick berhasil, kuota kembali']]);
  } catch(Exception $exx){ if($pdo->inTransaction()) $pdo->rollBack(); throw $exx; }
}
if(routeMatch('/registrations/:id/status',$uri,$pm) && $method==='POST'){
  requireLogin(); $b=getBody(); $act=$b['action']??'';
  $r=pdo()->prepare('SELECT * FROM registrations WHERE id=?'); $r->execute([$pm['id']]); $row=$r->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Registrasi tidak ada']],404);
  if(!isPembinaOf($row['ekskul_id']) && !in_array(currentUser()['role'],['admin'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina']],403);
  $new=$act==='approve'?'diterima':($act==='reject'?'ditolak':null);
  if(!$new) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'action approve/reject']],422);
  // kuota guard when approve
  if($new==='diterima'){
    $e=pdo()->prepare('SELECT kuota FROM ekskul WHERE id=?'); $e->execute([$row['ekskul_id']]); $kuota=$e->fetch()['kuota'];
    $c=pdo()->prepare('SELECT COUNT(*) c FROM registrations WHERE ekskul_id=? AND status="diterima" AND deleted_at IS NULL'); $c->execute([$row['ekskul_id']]); $terisi=$c->fetch()['c'];
    if($terisi >= $kuota) jsonOut(['success'=>false,'error'=>['code'=>'FULL','message'=>'Kuota penuh']],409);
  }
  pdo()->prepare('UPDATE registrations SET status=? WHERE id=?')->execute([$new,$pm['id']]);
  cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['status'=>$new]]);
}
if($uri==='/me/registrations' && $method==='GET'){
  requireRole('siswa');
  $uid=currentUser()['id'];
  // enrich join — 1 query, no N+1, escape pembina_nama/lokasi
  $st=pdo()->prepare("SELECT r.id, r.ekskul_id, r.status, r.created_at, e.nama ekskul_nama, e.hari, e.jam_mulai, e.jam_selesai, e.lokasi, e.pembina_id, u.nama pembina_nama, e.status ekskul_status FROM registrations r JOIN ekskul e ON e.id=r.ekskul_id LEFT JOIN users u ON u.id=e.pembina_id WHERE r.user_id=? AND r.deleted_at IS NULL AND e.deleted_at IS NULL ORDER BY r.created_at DESC");
  $st->execute([$uid]);
  $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['ekskul_nama']=e($r['ekskul_nama']); $r['pembina_nama']=$r['pembina_nama']?e($r['pembina_nama']):null; $r['lokasi']=$r['lokasi']?e($r['lokasi']):null; $r['hari']=$r['hari']?e($r['hari']):null; } unset($r);
  $etag='"'.md5(json_encode($rows).$uid).'"';
  header('ETag: '.$etag); header('Cache-Control: private, max-age=60, stale-while-revalidate=300'); header('Vary: Cookie');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}
// siswa batal daftar — allow menunggu + diterima H-3, 409 NEED_ADMIN jika <H-3
if(routeMatch('/ekskul/:id/batal',$uri,$pm) && $method==='POST'){
  requireRole('siswa'); $eid=(int)$pm['id']; $uid=currentUser()['id'];
  $st=pdo()->prepare('SELECT id, status, ekskul_id FROM registrations WHERE ekskul_id=? AND user_id=? AND deleted_at IS NULL'); $st->execute([$eid,$uid]);
  $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Belum terdaftar di ekskul ini']],404);
  if(!in_array($row['status'],['menunggu','diterima'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pendaftaran menunggu/diterima yang bisa dibatalkan']],409);
  $prevStatus=$row['status'];
  if($prevStatus==='diterima'){
    // H-3 guard: jadwal pertama ekskul > now+3 hari
    $sch=pdo()->prepare('SELECT tanggal FROM schedules WHERE ekskul_id=? ORDER BY tanggal ASC, jam_mulai ASC LIMIT 1'); $sch->execute([$eid]);
    $first=$sch->fetch();
    if($first && !empty($first['tanggal'])){
      $firstTs=strtotime($first['tanggal'].' 00:00:00');
      $limitTs=strtotime('+3 days 00:00:00');
      if($firstTs!==false && $limitTs!==false && $firstTs <= $limitTs){
        jsonOut(['success'=>false,'error'=>['code'=>'NEED_ADMIN','message'=>'Sudah berjalan, hubungi pembina']],409);
      }
    }
  }
  // ensure enum includes batal (ponytail: one-time alter, upgrade when audit demands)
  try{ $chk=pdo()->query("SHOW COLUMNS FROM registrations LIKE 'status'")->fetch(); if($chk && strpos($chk['Type']??'','batal')===false){ pdo()->exec("ALTER TABLE registrations MODIFY status ENUM('menunggu','diterima','ditolak','batal') NOT NULL DEFAULT 'menunggu'"); } }catch(Exception $e){}
  $now=date('Y-m-d H:i:s');
  pdo()->prepare('UPDATE registrations SET deleted_at=?, status=? WHERE id=?')->execute([$now,'batal',$row['id']]);
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$uid,'batal','registration',$row['id'], json_encode(['actor_uid'=>$uid,'ekskul_id'=>$eid,'prev_status'=>$prevStatus,'forced'=>false,'created_at'=>$now],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['message'=>'Pendaftaran dibatalkan','prev_status'=>$prevStatus]]);
}

// === SCHEDULES === GET /api/ekskul/:id/schedules + Cache 60s+ETag + anti-leak pembina
if(routeMatch('/ekskul/:id/schedules',$uri,$pm) && $method==='GET'){
  // anti-leak: same visibility as GET /ekskul/:id — pembina HANYA own (blind 404)
  $ek=pdo()->prepare('SELECT status, pembina_id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $ek->execute([$pm['id']]); $erow=$ek->fetch();
  if(!$erow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $cu=currentUser();
  if(!$cu || $cu['role']==='siswa'){
    if($erow['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  } else if($cu['role']==='pembina'){
    if((int)$erow['pembina_id'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  }
  $pg=max(1,(int)($_GET['page']??1)); $lim=min(100,max(1,(int)($_GET['limit']??50))); $off2=($pg-1)*$lim;
  $ct2=pdo()->prepare('SELECT COUNT(*) c FROM schedules WHERE ekskul_id=?'); $ct2->execute([$pm['id']]); $totSched=(int)($ct2->fetch()['c']??0);
  $st=pdo()->prepare('SELECT id,ekskul_id,tanggal,jam_mulai,jam_selesai,lokasi,tipe,created_at FROM schedules WHERE ekskul_id=? ORDER BY tanggal ASC, jam_mulai ASC LIMIT '.$lim.' OFFSET '.$off2); $st->execute([$pm['id']]); $rows=$st->fetchAll();
  $etag='"'.md5(json_encode($rows).$pm['id'].$pg.$lim).'"';
  header('ETag: '.$etag); header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
  header('X-Total-Count: '.$totSched); header('X-Page: '.$pg); header('X-Limit: '.$lim);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$totSched,'page'=>$pg,'limit'=>$lim,'pages'=>max(1,(int)ceil($totSched/$lim))]]);
}
if(routeMatch('/ekskul/:id/schedules',$uri,$pm) && $method==='POST'){
  requireLogin(); if(!isPembinaOf($pm['id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  $b=getBody();
  if(empty($b['tanggal'])||empty($b['jam_mulai'])||empty($b['jam_selesai'])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tanggal & jam wajib']],422);
  $tgl=trim($b['tanggal']); $jm=trim($b['jam_mulai']); $js=trim($b['jam_selesai']); $lok=trim($b['lokasi']??''); $tipe=in_array($b['tipe']??'rutin',['rutin','tambahan'],true)?$b['tipe']:'rutin';
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tgl)||!strtotime($tgl)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tanggal harus YYYY-MM-DD']],422);
  if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$jm) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$js)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Format jam harus HH:MM']],422);
  $a=strtotime('2000-01-01 '.$jm); $bb=strtotime('2000-01-01 '.$js);
  if($a!==false && $bb!==false && $a >= $bb) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Jam selesai harus lebih besar dari jam mulai']],422);
  if($lok==='') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'lokasi wajib diisi']],422);
  // --- conflict check: overlap same tanggal (any ekskul/event) WHERE NOT (jam_selesai <= :mulai OR jam_mulai >= :selesai) ---
  $force = !empty($b['force']) && ($b['force']==='1' || $b['force']===true || $b['force']==1);
  if($force && !in_array(currentUser()['role'],['admin','kepsek'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Force hanya untuk admin/kepsek']],403);
  $pdo=pdo();
  $confQ="(SELECT s.jam_mulai, s.jam_selesai, e.nama AS nama, s.tipe, s.lokasi FROM schedules s JOIN ekskul e ON e.id=s.ekskul_id WHERE s.tanggal=:d AND NOT (s.jam_selesai <= :mulai OR s.jam_mulai >= :selesai)) UNION ALL (SELECT e2.waktu AS jam_mulai, COALESCE(e2.waktu_selesai, ADDTIME(e2.waktu,'01:00:00')) AS jam_selesai, e2.nama AS nama, 'event' AS tipe, e2.lokasi FROM events e2 WHERE e2.tanggal=:d2 AND e2.status='approved' AND NOT (COALESCE(e2.waktu_selesai, ADDTIME(e2.waktu,'01:00:00')) <= :mulai2 OR e2.waktu >= :selesai2))";
  $cq=$pdo->prepare($confQ);
  $cq->execute([':d'=>$tgl,':mulai'=>$jm,':selesai'=>$js,':d2'=>$tgl,':mulai2'=>$jm,':selesai2'=>$js]);
  $conf=$cq->fetchAll();
  if($conf && !$force){
    jsonOut(['success'=>false,'error'=>['code'=>'CONFLICT','message'=>'Jadwal bentrok pada tanggal '.e($tgl)],'conflicts'=>array_map(function($c){ $c['nama']=e($c['nama']); $c['lokasi']=$c['lokasi']?e($c['lokasi']):null; return $c; },$conf)],409);
  }
  // insertsafe PDO 100% prepared (no concat)
  pdo()->prepare('INSERT INTO schedules(ekskul_id,tanggal,jam_mulai,jam_selesai,lokasi,tipe) VALUES (?,?,?,?,?,?)')->execute([$pm['id'],$tgl,$jm,$js,$lok,$tipe]);
  $newId=pdo()->lastInsertId();
  if($force && $conf){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'force_create','schedule',$newId, json_encode(['forced_by'=>currentUser()['id'],'forced_at'=>date('Y-m-d H:i:s'),'payload'=>$b,'conflicts_snapshot'=>$conf],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){} }
  cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['id'=>$newId]],201);
}
if(routeMatch('/schedules/:id',$uri,$pm) && $method==='DELETE'){
  requireLogin(); $s=pdo()->prepare('SELECT * FROM schedules WHERE id=?'); $s->execute([$pm['id']]); $row=$s->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Jadwal tidak ada']],404);
  if(!isPembinaOf($row['ekskul_id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina']],403);
  pdo()->prepare('DELETE FROM schedules WHERE id=?')->execute([$pm['id']]); cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap'); jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/schedules/:id',$uri,$pm) && ($method==='PATCH' || $method==='PUT')){
  requireLogin(); $s=pdo()->prepare('SELECT * FROM schedules WHERE id=?'); $s->execute([$pm['id']]); $row=$s->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Jadwal tidak ada']],404);
  if(!isPembinaOf($row['ekskul_id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina']],403);
  $b=getBody();
  $tgl=trim($b['tanggal']??$row['tanggal']); $jm=trim($b['jam_mulai']??$row['jam_mulai']); $js=trim($b['jam_selesai']??$row['jam_selesai']); $lok=trim($b['lokasi']??$row['lokasi']??''); $tipe=in_array($b['tipe']??$row['tipe'],['rutin','tambahan'],true)?($b['tipe']??$row['tipe']):'rutin';
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tgl)||!strtotime($tgl)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tanggal harus YYYY-MM-DD']],422);
  if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$jm) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$js)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Format jam harus HH:MM']],422);
  $a=strtotime('2000-01-01 '.$jm); $bb=strtotime('2000-01-01 '.$js);
  if($a!==false && $bb!==false && $a >= $bb) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Jam selesai harus lebih besar dari jam mulai']],422);
  if($lok==='') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'lokasi wajib diisi']],422);
  $force = !empty($b['force']) && ($b['force']==='1' || $b['force']===true || $b['force']==1);
  if($force && !in_array(currentUser()['role'],['admin','kepsek'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Force hanya untuk admin/kepsek']],403);
  $pdo=pdo();
  $confQ2="(SELECT s.jam_mulai, s.jam_selesai, e.nama AS nama, s.tipe, s.lokasi FROM schedules s JOIN ekskul e ON e.id=s.ekskul_id WHERE s.tanggal=:d AND s.id != :curId AND NOT (s.jam_selesai <= :mulai OR s.jam_mulai >= :selesai)) UNION ALL (SELECT e2.waktu AS jam_mulai, COALESCE(e2.waktu_selesai, ADDTIME(e2.waktu,'01:00:00')) AS jam_selesai, e2.nama AS nama, 'event' AS tipe, e2.lokasi FROM events e2 WHERE e2.tanggal=:d2 AND e2.status='approved' AND NOT (COALESCE(e2.waktu_selesai, ADDTIME(e2.waktu,'01:00:00')) <= :mulai2 OR e2.waktu >= :selesai2))";
  $cq2=$pdo->prepare($confQ2);
  $cq2->execute([':d'=>$tgl,':curId'=>$pm['id'],':mulai'=>$jm,':selesai'=>$js,':d2'=>$tgl,':mulai2'=>$jm,':selesai2'=>$js]);
  $conf2=$cq2->fetchAll();
  if($conf2 && !$force){
    jsonOut(['success'=>false,'error'=>['code'=>'CONFLICT','message'=>'Jadwal bentrok pada tanggal '.e($tgl)],'conflicts'=>array_map(function($c){ $c['nama']=e($c['nama']); $c['lokasi']=$c['lokasi']?e($c['lokasi']):null; return $c; },$conf2)],409);
  }
  pdo()->prepare('UPDATE schedules SET tanggal=?, jam_mulai=?, jam_selesai=?, lokasi=?, tipe=? WHERE id=?')->execute([$tgl,$jm,$js,$lok,$tipe,$pm['id']]);
  if($force && $conf2){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'force_update','schedule',$pm['id'], json_encode(['forced_by'=>currentUser()['id'],'forced_at'=>date('Y-m-d H:i:s'),'payload'=>$b,'conflicts_snapshot'=>$conf2],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){} }
  $st=pdo()->prepare('SELECT * FROM schedules WHERE id=?'); $st->execute([$pm['id']]); $upd=$st->fetch();
  cacheDelPrefix('ekskul'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>$upd]);
}

// === ATTENDANCE CLICK + QR ===
if(routeMatch('/attendance/mark',$uri) && $method==='POST'){
  requireLogin(); if(!in_array(currentUser()['role'],['pembina','admin'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pembina']],403);
  $b=getBody(); $sid=$b['schedule_id']??0; $uid=$b['user_id']??0; $status=$b['status']??'hadir';
  if(!$sid||!$uid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'schedule_id & user_id wajib']],422);
  $s=pdo()->prepare('SELECT ekskul_id FROM schedules WHERE id=?'); $s->execute([$sid]); $sc=$s->fetch();
  if(!$sc) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Jadwal tidak ada']],404);
  if(!isPembinaOf($sc['ekskul_id'])) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan ekskul binaan']],403);
  pdo()->prepare('INSERT INTO attendance(schedule_id,user_id,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)')->execute([$sid,$uid,$status]);
  jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/attendance/list/:sid',$uri,$pm) && $method==='GET'){
  requireLogin();
  // leak guard: only pembina pemilik jadwal / admin
  $sid=(int)$pm['sid']; $sc=pdo()->prepare('SELECT ekskul_id FROM schedules WHERE id=?'); $sc->execute([$sid]); $srow=$sc->fetch();
  if(!$srow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Jadwal tidak ada']],404);
  if(!isPembinaOf($srow['ekskul_id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  $pg=max(1,(int)($_GET['page']??1)); $lim=min(100,max(1,(int)($_GET['limit']??50))); $off2=($pg-1)*$lim;
  $ctA=pdo()->prepare('SELECT COUNT(*) c FROM attendance a WHERE a.schedule_id=?'); $ctA->execute([$sid]); $totAtt=(int)($ctA->fetch()['c']??0);
  $st=pdo()->prepare('SELECT a.id,a.schedule_id,a.user_id,a.status,a.created_at, u.nama FROM attendance a JOIN users u ON u.id=a.user_id WHERE a.schedule_id=? ORDER BY a.created_at DESC LIMIT '.$lim.' OFFSET '.$off2); $st->execute([$sid]);
  jsonOut(['success'=>true,'data'=>$st->fetchAll(),'meta'=>['total'=>$totAtt,'page'=>$pg,'limit'=>$lim,'pages'=>max(1,(int)ceil($totAtt/$lim))]]);
}
if(routeMatch('/attendance/summary/:ekskul_id',$uri,$pm) && $method==='GET'){
  requireLogin();
  if(!isPembinaOf($pm['ekskul_id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  $st=pdo()->prepare('SELECT u.id, u.nama, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=?) AS total_sesi, COUNT(a.id) AS hadir_count FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.status="diterima" AND r.deleted_at IS NULL LEFT JOIN attendance a ON a.user_id=u.id AND a.status="hadir" AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=?) GROUP BY u.id, u.nama');
  $st->execute([$pm['ekskul_id'],$pm['ekskul_id'],$pm['ekskul_id']]); $rows=$st->fetchAll();
  foreach($rows as &$row){ $row['hadir_count']=(int)$row['hadir_count']; $row['total_sesi']=(int)$row['total_sesi']; $row['persen']= $row['total_sesi']? round($row['hadir_count']/$row['total_sesi']*100,1):0; }
  unset($row);
  $etag='"'.md5(json_encode($rows).$pm['ekskul_id']).'"';
  header('ETag: '.$etag); header('Cache-Control: private, max-age=60');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}
// Alias: GET /api/ekskul/:id/rekap — same as summary (task req) — scoped pembina
if(routeMatch('/ekskul/:id/rekap',$uri,$pm) && $method==='GET'){
  requireLogin();
  if(!isPembinaOf($pm['id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  $st=pdo()->prepare('SELECT u.id, u.nama, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=?) AS total_sesi, COUNT(a.id) AS hadir_count FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.status="diterima" AND r.deleted_at IS NULL LEFT JOIN attendance a ON a.user_id=u.id AND a.status="hadir" AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=?) GROUP BY u.id, u.nama');
  $st->execute([$pm['id'],$pm['id'],$pm['id']]); $rows=$st->fetchAll();
  foreach($rows as &$row){ $row['hadir_count']=(int)$row['hadir_count']; $row['total_sesi']=(int)$row['total_sesi']; $row['persen']= $row['total_sesi']? round($row['hadir_count']/$row['total_sesi']*100,1):0; }
  unset($row);
  $etag='"'.md5(json_encode($rows).$pm['id']).'"';
  header('ETag: '.$etag); header('Cache-Control: private, max-age=60');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}
if(routeMatch('/attendance/qr-generate',$uri) && $method==='POST'){
  requireLogin(); if(!in_array(currentUser()['role'],['pembina','admin'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pembina']],403);
  $uid=currentUser()['id'];
  // rate limit 10 generate / menit per pembina (file flock, anti race)
  $rlf=sys_get_temp_dir().'/qr_gen_'.$uid.'.json'; $now=time(); $cnt=0; $win=$now;
  if(file_exists($rlf)){ $j=@json_decode(@file_get_contents($rlf),true); if($j && ($now - (int)($j['start']??0) < 60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
  if($cnt>=10){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering generate QR, tunggu 1 menit']],429); }
  @file_put_contents($rlf, json_encode(['count'=>$cnt+1,'start'=>$win]));
  $b=getBody(); $sid=(int)($b['schedule_id']??0); if(!$sid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'schedule_id wajib']],422);
  $s=pdo()->prepare('SELECT * FROM schedules WHERE id=?'); $s->execute([$sid]); $sc=$s->fetch();
  if(!$sc) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Jadwal tidak ada']],404);
  if(!isPembinaOf($sc['ekskul_id'])) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan ekskul binaan']],403);
  // opportunistic cleanup expired >30 menit (ringan, no lock)
  try{ pdo()->exec("DELETE FROM attendance_tokens WHERE expiry < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"); }catch(Exception $e){}
  $token=bin2hex(random_bytes(16)); $exp=date('Y-m-d H:i:s',time()+300);
  pdo()->prepare('INSERT INTO attendance_tokens(token,ekskul_id,schedule_id,expiry,used) VALUES (?,?,?,?,0)')->execute([$token,$sc['ekskul_id'],$sid,$exp]);
  jsonOut(['success'=>true,'data'=>['token'=>$token,'expiry'=>$exp]]);
}
if(routeMatch('/attendance/scan',$uri) && $method==='POST'){
  requireRole('siswa');
  $uid=currentUser()['id'];
  // rate limit 20 scan / menit per siswa
  $rlf=sys_get_temp_dir().'/qr_scan_'.$uid.'.json'; $now=time(); $cnt=0; $win=$now;
  if(file_exists($rlf)){ $j=@json_decode(@file_get_contents($rlf),true); if($j && ($now - (int)($j['start']??0) < 60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
  if($cnt>=20){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering scan, tunggu 1 menit']],429); }
  @file_put_contents($rlf, json_encode(['count'=>$cnt+1,'start'=>$win]));
  $b=getBody(); $token=trim($b['token']??'');
  if(!$token) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'token wajib']],422);
  if(!preg_match('/^[a-f0-9]{32}$/',$token)) jsonOut(['success'=>false,'error'=>['code'=>'INVALID','message'=>'Token tidak valid']],404);
  $pdo=pdo();
  try{
    $pdo->beginTransaction();
    $sql='SELECT * FROM attendance_tokens WHERE token=? FOR UPDATE';
    $t=$pdo->prepare($sql); $t->execute([$token]); $row=$t->fetch();
    if(!$row){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'INVALID','message'=>'Token tidak valid']],404); }
    if((int)$row['used']===1){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'USED','message'=>'Token sudah dipakai']],409); }
    if(strtotime($row['expiry'])<time()){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'EXPIRED','message'=>'QR expired 5 menit']],410); }
    // cek anggota — must be diterima
    $reg=$pdo->prepare('SELECT 1 FROM registrations WHERE user_id=? AND ekskul_id=? AND status="diterima" AND deleted_at IS NULL'); $reg->execute([$uid,$row['ekskul_id']]);
    if(!$reg->fetch()){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'NOT_MEMBER','message'=>'Bukan anggota ekskul ini']],403); }
    // atomic used=1 — WHERE used=0 guard double race if 2 tx race
    $upd=$pdo->prepare('UPDATE attendance_tokens SET used=1 WHERE token=? AND used=0'); $upd->execute([$token]);
    if($upd->rowCount()===0){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'USED','message'=>'Token sudah dipakai']],409); }
    $pdo->prepare('INSERT INTO attendance(schedule_id,user_id,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status="hadir"')->execute([$row['schedule_id'],$uid,'hadir']);
    $pdo->commit();
    jsonOut(['success'=>true,'data'=>['schedule_id'=>$row['schedule_id'],'ekskul_id'=>$row['ekskul_id']]]);
  }catch(Exception $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    // rethrow if not our jsonOut exit (jsonOut exits, so only real exceptions)
    throw $ex;
  }
}

// === EVENTS — Opsi B Hybrid Ideal (security+perf) ===
function validateEventPayload($nama,$tanggal,$waktu,$lokasi,$kuota,&$err,$waktuSelesai=null,$deskripsi=null,$rundown=null,$force=false){
  $n=trim($nama??''); $t=trim($tanggal??''); $w=trim($waktu??''); $l=trim($lokasi??''); $k=(int)($kuota??0);
  if($n==='') { $err='Nama wajib'; return false; }
  if(mb_strlen($n)<3) { $err='Nama minimal 3 karakter'; return false; }
  if(preg_match('/^Kuota\d+$/i',$n)) { $err='Nama tidak boleh generik Kuota1/Kuota2'; return false; }
  if(preg_match('/^\s*(test|dummy|asdf|coba|dfgdfg|qwerty)\s*$/i',$n)) { $err='Nama tidak boleh test/dummy/gibberish'; return false; }
  if(preg_match('/dfgdfg|qwerty|asdfg/i',$n)) { $err='Nama mengandung gibberish tidak diperbolehkan'; return false; }
  if(mb_strlen($n)<15 && preg_match('/test/i',$n)) { $err='Nama mengandung test tidak diperbolehkan'; return false; }
  if($t==='') { $err='Tanggal wajib'; return false; }
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$t) || !strtotime($t)) { $err='Tanggal tidak valid (YYYY-MM-DD)'; return false; }
  // PAST_DATE guard — kecuali force=1 (admin/kepsek bypass audit)
  if(!$force && $t < date('Y-m-d')) { $err='Tanggal event tidak boleh di masa lalu'; return false; }
  if($w==='') { $err='Jam mulai wajib'; return false; }
  if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$w)) { $err='Format jam harus HH:MM'; return false; }
  if($l==='') { $err='Lokasi wajib diisi'; return false; }
  if(mb_strlen($l)<3) { $err='Lokasi minimal 3 karakter'; return false; }
  if($k<1) { $err='Kuota harus >0'; return false; }
  if($waktuSelesai!==null && trim((string)$waktuSelesai)!==''){
    $js=trim((string)$waktuSelesai);
    if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$js)) { $err='Format jam selesai harus HH:MM'; return false; }
    $a=strtotime('2000-01-01 '.$w); $b=strtotime('2000-01-01 '.$js);
    if($a!==false && $b!==false && $a >= $b){ $err='Jam mulai harus lebih kecil dari jam selesai'; return false; }
  }
  // deskripsi if provided: if empty not required? but task says optional? we allow empty but if present min 3 not gibberish for event
  if($deskripsi!==null){
    $d=trim((string)$deskripsi);
    if($d!=='' && mb_strlen($d)<3) { $err='Deskripsi minimal 3 karakter jika diisi'; return false; }
    if($d!=='' && preg_match('/^\s*(test|dummy|asdf|coba)\s*$/i',$d)) { $err='Deskripsi tidak boleh hanya test/dummy'; return false; }
  }
  // rundown: bisa lebih dari 1 baris, format jam + kegiatan per baris "HH:MM - kegiatan"
  if($rundown!==null){
    $r=trim((string)$rundown);
    if($r!==''){
      $lines=preg_split('/\r?\n/',$r);
      foreach($lines as $idx=>$line){
        $ln=trim($line);
        if($ln==='') continue;
        if(!preg_match('/^\d{2}:\d{2}(:\d{2})?\s*[-–—·]?\s*.+/',$ln)){
          $err='Rundown baris '.($idx+1).' harus format jam + kegiatan (contoh: 08:00 - Registrasi)';
          return false;
        }
        // split jam dan kegiatan
        if(preg_match('/^(\d{2}:\d{2}(?::\d{2})?)\s*[-–—·]?\s*(.*)$/',$ln,$m)){
          $jam=$m[1]; $keg=trim($m[2]??'');
          if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$jam)){ $err='Jam rundown baris '.($idx+1).' harus HH:MM'; return false; }
          if(mb_strlen($keg)<2){ $err='Kegiatan rundown baris '.($idx+1).' minimal 2 karakter'; return false; }
        }
      }
    }
  }
  return true;
}

function normalizeEkskulIds($raw){
  // accepts: null, 'umum', int, string comma, array. Returns null (Umum) or int[] dedup sorted.
  if($raw===null) return null;
  if(is_string($raw)){ $raw=trim($raw); if($raw==='' || strtolower($raw)==='umum') return null; }
  $arr = is_array($raw) ? $raw : (is_string($raw) ? preg_split('/[,\s]+/',$raw) : [$raw]);
  $out=[]; foreach($arr as $v){
    if($v===null || $v==='') continue;
    $s=trim((string)$v); if($s==='' || strtolower($s)==='umum') continue;
    $n=(int)$s; if($n>0) $out[]=$n;
  }
  $out=array_values(array_unique($out)); sort($out);
  return $out ?: null;
}
function syncEventEkskul(PDO $pdo, int $eventId, ?array $ekskulIds){
  // sync pivot + legacy ekskul_id (legacy keeps first id or null for compat)
  try{
    $pdo->prepare('DELETE FROM event_ekskul WHERE event_id=?')->execute([$eventId]);
  }catch(Exception $e){}
  $legacy=null;
  if($ekskulIds && count($ekskulIds)){
    // validate ekskul exists
    foreach($ekskulIds as $eid){
      $cek=$pdo->prepare('SELECT id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $cek->execute([$eid]);
      if(!$cek->fetch()) { http_response_code(422); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Ekskul tidak ditemukan (#'.$eid.')']], JSON_UNESCAPED_UNICODE); exit; }
    }
    foreach($ekskulIds as $eid){ try{ $pdo->prepare('INSERT INTO event_ekskul(event_id, ekskul_id) VALUES (?,?)')->execute([$eventId,$eid]); }catch(Exception $e){} }
    $legacy=$ekskulIds[0];
  }
  // keep legacy column in sync (compat for old clients / Kalender)
  try{ $pdo->prepare('UPDATE events SET ekskul_id=? WHERE id=?')->execute([$legacy,$eventId]); }catch(Exception $e){}
  return $legacy;
}
function enrichEventsWithEkskul(PDO $pdo, array &$rows){
  if(!$rows) return;
  $ids=array_column($rows,'id');
  $ph=implode(',', array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT ee.event_id, ee.ekskul_id, ek.nama FROM event_ekskul ee JOIN ekskul ek ON ek.id=ee.ekskul_id WHERE ee.event_id IN ($ph) ORDER BY ee.ekskul_id");
  $st->execute($ids); $map=[];
  foreach($st->fetchAll() as $r){ $eid=(int)$r['event_id']; if(!isset($map[$eid])) $map[$eid]=[]; $map[$eid][]=['id'=>(int)$r['ekskul_id'],'nama'=>$r['nama']]; }
  foreach($rows as &$r){
    $eid=(int)$r['id'];
    $r['ekskul_ids']= isset($map[$eid]) ? array_column($map[$eid],'id') : [];
    $r['ekskul_list']= $map[$eid] ?? [];
    // compat: if pivot empty but legacy ekskul_id exists, expose it (for rows created before pivot)
    if(empty($r['ekskul_list']) && !empty($r['ekskul_id'])){ $r['ekskul_ids']=[(int)$r['ekskul_id']]; if(!empty($r['ekskul_nama'])) $r['ekskul_list']=[['id'=>(int)$r['ekskul_id'],'nama'=>$r['ekskul_nama']]]; }
    if(!isset($r['ekskul_nama']) || !$r['ekskul_nama']){ $r['ekskul_nama']= $r['ekskul_list'][0]['nama'] ?? null; }
  }
  unset($r);
}

function canSiswaDaftarEkskulEvent(int $uid, array $ekskulIds, PDO $pdo): array {
  // returns [bool can, string|null reason, array ekskulList names]
  if(!$ekskulIds) return [true, null, []]; // Umum
  // check if siswa is anggota diterima/menunggu of any of these ekskul
  $ph=implode(',', array_fill(0, count($ekskulIds), '?'));
  // gate: status diterima only? spec: use diterima per membership. allow diterima.
  $st=$pdo->prepare("SELECT ekskul_id FROM registrations WHERE user_id=? AND ekskul_id IN ($ph) AND status='diterima' AND deleted_at IS NULL");
  $st->execute(array_merge([$uid], $ekskulIds));
  $mine=$st->fetchAll(PDO::FETCH_COLUMN);
  if($mine) return [true, null, $mine];
  // fetch names for message
  $st2=$pdo->prepare("SELECT GROUP_CONCAT(nama SEPARATOR ', ') FROM ekskul WHERE id IN ($ph)");
  $st2->execute($ekskulIds);
  $names=$st2->fetchColumn()?:'Ekskul terkait';
  return [false, 'Hanya anggota '.$names.' yang bisa mendaftar', []];
}

if($uri==='/events' && $method==='GET'){
  // query: ?page&limit&search&status&sort (sort: tanggal_asc|tanggal_desc|updated_desc)
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $search=trim($_GET['search']??''); $status=trim($_GET['status']??''); $sort=trim($_GET['sort']??'tanggal_asc');
  $cu=currentUser();
  $where=[]; $par=[];
  $where[]='e.deleted_at IS NULL';
  // event hanya admin: pembina diperlakukan seperti publik (hanya approved)
  // public/siswa/pembina only approved — admin/kepsek bisa filter all
  if(!$cu || in_array($cu['role'],['siswa','pembina'],true)){
    $where[]="e.status='approved'";
  } else if($status && in_array($status,['pending','approved','rejected'],true)){
    $where[]='e.status=?'; $par[]=$status;
  }
if($search!==''){
     $where[]='(e.nama LIKE ? OR e.lokasi LIKE ? OR e.deskripsi LIKE ?)';
     $kw='%'.$search.'%';
     $par[]=$kw; $par[]=$kw; $par[]=$kw;
   }
   // Task1: siswa filter sudah_daftar/tersedia server-side
   $filterSiswa = '';
   if($cu && $cu['role']==='siswa' && in_array($status,['sudah_daftar','tersedia'],true)){
     if($status==='sudah_daftar'){
       $where[]='EXISTS (SELECT 1 FROM event_participants p2 WHERE p2.event_id=e.id AND p2.user_id=?)';
       $par[]=$cu['id'];
      } else if($status==='tersedia'){
        $where[]='COALESCE(pcf.terisi,0) < e.kuota';
      }
     // reset status for approved handling below
     $status='';
}
    // Server cache 60s (file/APCu) — hit final payload, skip DB. Key = params efektif + scope user.
    // NOTE: pakai $_GET['status'] (sebelum reset filter siswa), karena sudah_daftar & tersedia sama-sama di-null-kan oleh $status.
    $cacheKey = cacheKey('events', [$page,$limit,$search,$_GET['status']??'',$sort, $cu['role']??'guest', $cu['id']??0]);
    $cachedJson = cacheGet($cacheKey, 60);
    if($cachedJson !== null){
      $cacheMeta = json_decode($cachedJson, true);
      $etag='"'.md5($cachedJson).'"';
      if(!$cu){ header('ETag: '.$etag); header('Cache-Control: public, max-age=60, stale-while-revalidate=300'); header('Vary: Accept-Encoding'); }
      else { header('ETag: '.$etag); header('Cache-Control: private, max-age=60, stale-while-revalidate=300'); header('Vary: Cookie, Authorization'); }
      header('X-Total-Count: '.($cacheMeta['meta']['total']??0)); header('X-Page: '.($cacheMeta['meta']['page']??'')); header('X-Limit: '.($cacheMeta['meta']['limit']??''));
      if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
      header('Content-Type: application/json');
      echo $cachedJson; exit;
    }
    // total — filter tersedia pakai derived yang sama (tanpa subquery korelasi)
    $pcJoin=' LEFT JOIN (SELECT p.event_id, COUNT(*) AS terisi FROM event_participants p GROUP BY p.event_id) pcf ON pcf.event_id=e.id';
    $cq='SELECT COUNT(*) c FROM events e'.$pcJoin.' WHERE '.implode(' AND ',$where);
  $ct=pdo()->prepare($cq); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // sort: Terdekat=tanggal_asc, Terlengkap=terisi_desc (kuota terisi terbanyak)
  $order=' ORDER BY e.tanggal ASC, e.waktu ASC'; // terdekat default
  if($sort==='tanggal_desc') $order=' ORDER BY e.tanggal DESC, e.waktu DESC';
  else if($sort==='updated_desc') $order=' ORDER BY e.updated_at DESC';
  else if($sort==='nama_asc') $order=' ORDER BY e.nama ASC';
  else if($sort==='terisi_desc') $order=' ORDER BY terisi DESC, e.tanggal ASC';
  // data: 1x JOIN users + LEFT JOIN ekskul + LEFT JOIN (GROUP BY) terisi sekali — kolom eksplisit (tanpa SELECT * / correl subquery)
  $eventCols='e.id,e.nama,e.deskripsi,e.tanggal,e.waktu,e.waktu_selesai,e.lokasi,e.cover_path,e.kuota,e.ekskul_id,e.status,e.registration_start,e.registration_end,e.created_by,e.deleted_at,e.created_at,e.updated_at,e.rejected_reason';
  $q='SELECT '.$eventCols.', u.nama creator, ek.nama ekskul_nama, COALESCE(pcf.terisi,0) AS terisi FROM events e LEFT JOIN users u ON u.id=e.created_by LEFT JOIN ekskul ek ON ek.id=e.ekskul_id'.$pcJoin.' WHERE '.implode(' AND ',$where).$order.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
  $st=pdo()->prepare($q); $st->execute($par);
  $rows=$st->fetchAll();
  // multi-ekskul enrich (adds ekskul_ids / ekskul_list)
  enrichEventsWithEkskul(pdo(), $rows);
  // Opsi B soft: can_register + badge hint — list tetap global, daftar di-gate
  if($rows){
    if($cu && $cu['role']==='siswa'){
      try{
        $mSt=pdo()->prepare("SELECT ekskul_id FROM registrations WHERE user_id=? AND status='diterima' AND deleted_at IS NULL");
        $mSt->execute([$cu['id']]); $mineSet=array_flip(array_map('intval', $mSt->fetchAll(PDO::FETCH_COLUMN)));
      }catch(Exception $e){ $mineSet=[]; }
      foreach($rows as &$r){
        if(empty($r['ekskul_ids'])){ $r['can_register']=true; $r['register_block_reason']=null; }
        else {
          $hit=false; foreach((array)$r['ekskul_ids'] as $eid){ if(isset($mineSet[(int)$eid])){ $hit=true; break; } }
          if($hit){ $r['can_register']=true; $r['register_block_reason']=null; }
          else { $r['can_register']=false; $names=implode(', ', array_map(fn($x)=> $x['nama'], $r['ekskul_list']??[])); if(!$names) $names='Ekskul terkait'; $r['register_block_reason']='Hanya anggota '.$names.' yang bisa mendaftar'; }
        }
      } unset($r);
    } else {
      foreach($rows as &$r){ $r['can_register']=true; $r['register_block_reason']=null; } unset($r);
    }
  }
  // is_registered for siswa — 1 extra query, no N+1 (pertahankan)
  if($cu && $cu['role']==='siswa' && $rows){
    $ids=array_column($rows,'id');
    if($ids){
      $ph=implode(',',array_fill(0,count($ids),'?'));
      $par2=array_merge([$cu['id']], $ids);
      $st2=pdo()->prepare("SELECT event_id FROM event_participants WHERE user_id=? AND event_id IN ($ph)");
      $st2->execute($par2);
      $regMap=array_flip(array_column($st2->fetchAll(),'event_id'));
      foreach($rows as &$r){ $r['is_registered']=isset($regMap[$r['id']]) ? 1 : 0; }
      unset($r);
    }
  } else {
    foreach($rows as &$r){ $r['is_registered']=0; } unset($r);
  }
  // cover_url: stable public URL (null when no cover)
  foreach($rows as &$r){ $r['cover_url']=$r['cover_path']?('/api/covers/event/'.$r['id']):null; } unset($r);
  // Server cache 60s: simpan PAYLOAD final (tercakup is_registered/can_register per user). Browser ETag/Cache-Control tetap jalan.
  $outJson = json_encode(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit)]], JSON_UNESCAPED_UNICODE);
  cacheSet($cacheKey, $outJson, 60);
  $etag='"'.md5($outJson).'"';
  if(!$cu){
    header('ETag: '.$etag); header('Cache-Control: public, max-age=60, stale-while-revalidate=300'); header('Vary: Accept-Encoding');
  } else {
    // login: private + Vary Cookie/Authorization agar is_registered tetap benar per user
    header('ETag: '.$etag); header('Cache-Control: private, max-age=60, stale-while-revalidate=300'); header('Vary: Cookie, Authorization');
  }
  header('X-Total-Count: '.$total); header('X-Page: '.$page); header('X-Limit: '.$limit);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  header('Content-Type: application/json');
  echo $outJson; exit;
}
if(routeMatch('/events/:id',$uri,$pm) && $method==='GET'){
  $st=pdo()->prepare('SELECT e.id,e.nama,e.deskripsi,e.tanggal,e.waktu,e.waktu_selesai,e.lokasi,e.cover_path,e.kuota,e.ekskul_id,e.status,e.registration_start,e.registration_end,e.created_by,e.deleted_at,e.created_at,e.updated_at,e.rejected_reason, u.nama creator, ek.nama ekskul_nama, COALESCE(pcf.terisi,0) AS terisi FROM events e LEFT JOIN users u ON u.id=e.created_by LEFT JOIN ekskul ek ON ek.id=e.ekskul_id LEFT JOIN (SELECT p.event_id, COUNT(*) AS terisi FROM event_participants p GROUP BY p.event_id) pcf ON pcf.event_id=e.id WHERE e.id=? AND e.deleted_at IS NULL'); $st->execute([$pm['id']]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  // anti-leak: public/siswa/pembina hanya approved (event hanya admin)
  $cu=currentUser();
  if(!$cu || in_array($cu['role'],['siswa','pembina'],true)){
    if($row['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  }
  $row['is_registered']=0;
  if($cu && $cu['role']==='siswa'){
    $chk=pdo()->prepare('SELECT 1 FROM event_participants WHERE event_id=? AND user_id=?'); $chk->execute([$row['id'],$cu['id']]); if($chk->fetch()) $row['is_registered']=1;
  }
  $row['cover_url']=$row['cover_path']?('/api/covers/event/'.$row['id']):null;
  // multi-ekskul: fill ekskul_ids/list for detail
  try{ $stP=pdo()->prepare('SELECT ee.ekskul_id, ek.nama FROM event_ekskul ee JOIN ekskul ek ON ek.id=ee.ekskul_id WHERE ee.event_id=? ORDER BY ee.ekskul_id'); $stP->execute([$row['id']]); $elist=$stP->fetchAll(); $row['ekskul_list']=array_map(fn($r)=>['id'=>(int)$r['ekskul_id'],'nama'=>$r['nama']], $elist); $row['ekskul_ids']=array_column($row['ekskul_list'],'id'); if(empty($row['ekskul_list']) && !empty($row['ekskul_id'])){ $row['ekskul_ids']=[(int)$row['ekskul_id']]; if(!empty($row['ekskul_nama'])) $row['ekskul_list']=[['id'=>(int)$row['ekskul_id'],'nama'=>$row['ekskul_nama']]]; } }catch(Exception $e){ $row['ekskul_list']=$row['ekskul_list']??[]; $row['ekskul_ids']=$row['ekskul_ids']??[]; }
  // Opsi B soft: can_register for detail
  if($cu && $cu['role']==='siswa'){
    if(empty($row['ekskul_ids'])){ $row['can_register']=true; $row['register_block_reason']=null; }
    else { $chk=pdo()->prepare('SELECT 1 FROM registrations WHERE user_id=? AND status=\'diterima\' AND deleted_at IS NULL AND ekskul_id IN ('.implode(',', array_fill(0,count($row['ekskul_ids']),'?')).')'); $chk->execute(array_merge([$cu['id']], $row['ekskul_ids'])); if($chk->fetch()){ $row['can_register']=true; $row['register_block_reason']=null; } else { $row['can_register']=false; $names=implode(', ', array_map(fn($x)=> $x['nama'], $row['ekskul_list']??[])); if(!$names) $names='Ekskul terkait'; $row['register_block_reason']='Hanya anggota '.$names.' yang bisa mendaftar'; } }
  } else { $row['can_register']=true; $row['register_block_reason']=null; }
  $rowForEtag = $row; unset($rowForEtag['is_registered'],$rowForEtag['can_register'],$rowForEtag['register_block_reason']);
  $etag='"'.md5(json_encode($rowForEtag)).'"';
  if(!$cu){
    header('ETag: '.$etag); header('Cache-Control: public, max-age=60, stale-while-revalidate=300'); header('Vary: Accept-Encoding');
  } else {
    header('ETag: '.$etag); header('Cache-Control: private, max-age=60, stale-while-revalidate=300'); header('Vary: Cookie, Authorization');
  }
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$row]);
}
if($uri==='/events' && $method==='POST'){
  requireRole('admin'); $b=getBody();
  $nama=trim($b['nama']??''); $tanggal=trim($b['tanggal']??''); $waktu=trim($b['waktu']??$b['jam_mulai']??'');
  $waktuSelesai=trim($b['waktu_selesai']??$b['jam_selesai']??''); $lokasi=trim($b['lokasi']??'');
  $kuota=(int)($b['kuota']??0); if($kuota===0 && isset($b['kuota']) && $b['kuota']!=='') $kuota=(int)$b['kuota']; if(empty($b['kuota']) && $b['kuota']!=='0') $kuota=(int)($b['kuota']??50);
  // normalize empty kuota default 50 but still validate >0
  if(!isset($b['kuota']) || $b['kuota']==='' || $b['kuota']===null) $kuota=50;
  else $kuota=(int)$b['kuota'];
  // ekskul scope: null/Umum = Umum, array/int = multi-ekskul (validasi ekskul exists)
  $ekskulIdsRaw = $b['ekskul_ids'] ?? $b['ekskulIds'] ?? $b['ekskul_id'] ?? $b['ekskulId'] ?? null;
  $ekskulIds = normalizeEkskulIds($ekskulIdsRaw);
  if($ekskulIds && count($ekskulIds)){
    // validate each exists
    foreach($ekskulIds as $eid){ $cekEk = pdo()->prepare('SELECT id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $cekEk->execute([$eid]); if(!$cekEk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Ekskul tidak ditemukan (#'.$eid.')']],422); }
  }
  $ekskulId = $ekskulIds ? $ekskulIds[0] : null; // legacy compat column
  // force flag perlu diketahui sebelum validasi PAST_DATE
  $force = !empty($b['force']) && ($b['force']==='1' || $b['force']===true || $b['force']==1);
  if($force && !in_array(currentUser()['role'],['admin','kepsek'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Force hanya untuk admin/kepsek']],403);
  $err=''; if(!validateEventPayload($nama,$tanggal,$waktu,$lokasi,$kuota,$err,$waktuSelesai?:null,$b['deskripsi']??null,$b['rundown']??null,$force?true:false)) {
    $code = (strpos($err,'masa lalu')!==false) ? 'PAST_DATE' : 'VALIDATION';
    $msg = $code==='PAST_DATE' ? 'Tanggal event tidak boleh di masa lalu' : $err;
    jsonOut(['success'=>false,'error'=>['code'=>$code,'message'=>$msg]],422);
  }
  // periode optional: normalize empty to null, validate start<end if both
  $regStart = !empty($b['registration_start']) ? $b['registration_start'] : null;
  $regEnd = !empty($b['registration_end']) ? $b['registration_end'] : null;
  if($regStart && $regEnd && strtotime($regStart) && strtotime($regEnd) && strtotime($regStart) >= strtotime($regEnd)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Periode mulai harus < periode akhir']],422);
  // Task4 silang: registration_end harus < tanggal 00:00
  if($regEnd){
    $regEndTs=strtotime($regEnd);
    $evTs=strtotime($tanggal.' 00:00:00');
    if($regEndTs!==false && $evTs!==false && $regEndTs >= $evTs) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'registration_end harus sebelum tanggal event 00:00']],422);
  }
  if(!$force){
    $wsEf = $waktuSelesai ?: date('H:i:s', strtotime($waktu.' +1 hour'));
    $cq=pdo()->prepare("(SELECT jam_mulai, jam_selesai FROM schedules WHERE tanggal=:d AND NOT (jam_selesai <= :mulai OR jam_mulai >= :selesai)) UNION ALL (SELECT waktu AS jam_mulai, COALESCE(waktu_selesai, ADDTIME(waktu,'01:00:00')) AS jam_selesai FROM events WHERE tanggal=:d2 AND status='approved' AND NOT (COALESCE(waktu_selesai, ADDTIME(waktu,'01:00:00')) <= :mulai2 OR waktu >= :selesai2))");
    $cq->execute([':d'=>$tanggal,':mulai'=>$waktu,':selesai'=>$wsEf,':d2'=>$tanggal,':mulai2'=>$waktu,':selesai2'=>$wsEf]);
    $conf=$cq->fetchAll();
    if($conf) jsonOut(['success'=>false,'error'=>['code'=>'CONFLICT','message'=>'Event bentrok pada tanggal '.e($tanggal)],'conflicts'=>$conf],409);
  }
  // status default pending untuk admin/pembina — hanya kepsek bisa approve (security fix)
  $status='pending';
  // capture conflicts snapshot even when force=1 for audit (need same query)
  $forceConfSnapshot=null;
  if($force){
    $wsEf2 = $waktuSelesai ?: date('H:i:s', strtotime($waktu.' +1 hour'));
    $cq2=pdo()->prepare("(SELECT jam_mulai, jam_selesai FROM schedules WHERE tanggal=:d AND NOT (jam_selesai <= :mulai OR jam_mulai >= :selesai)) UNION ALL (SELECT waktu AS jam_mulai, COALESCE(waktu_selesai, ADDTIME(waktu,'01:00:00')) AS jam_selesai FROM events WHERE tanggal=:d2 AND status='approved' AND NOT (COALESCE(waktu_selesai, ADDTIME(waktu,'01:00:00')) <= :mulai2 OR waktu >= :selesai2))");
    $cq2->execute([':d'=>$tanggal,':mulai'=>$waktu,':selesai'=>$wsEf2,':d2'=>$tanggal,':mulai2'=>$waktu,':selesai2'=>$wsEf2]);
    $forceConfSnapshot=$cq2->fetchAll();
  }
  // PDO prepared 100% — 1 query
  pdo()->prepare('INSERT INTO events(nama,deskripsi,tanggal,waktu,waktu_selesai,lokasi,kuota,rundown,ekskul_id,status,created_by,registration_start,registration_end) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$nama,$b['deskripsi']??null,$tanggal,$waktu, $waktuSelesai?:null, $lokasi?:null, $kuota, $b['rundown']??null, $ekskulId, $status, currentUser()['id'], $regStart, $regEnd]);
  $id=pdo()->lastInsertId();
  // sync pivot multi-ekskul (keeps legacy ekskul_id via helper)
  syncEventEkskul(pdo(), (int)$id, $ekskulIds);
  // audit log
  try{
    $detail=['nama'=>$nama,'ekskul_ids'=>$ekskulIds];
    if($force) $detail=array_merge($detail,['forced_by'=>currentUser()['id'],'forced_at'=>date('Y-m-d H:i:s'),'payload'=>$b,'conflicts_snapshot'=>$forceConfSnapshot]);
    pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],$force?'force_create':'create','event',$id, json_encode($detail,JSON_UNESCAPED_UNICODE)]);
  }catch(Exception $e){}
  // Opsi B admin notif: event pending
  try{
    if($status==='pending'){
      $cntPe=pdo()->prepare('SELECT COUNT(*) c FROM events WHERE status="pending" AND deleted_at IS NULL'); $cntPe->execute(); $pendingEv=(int)($cntPe->fetch()['c']??0);
      notifyAdmins($pendingEv.' event menunggu persetujuan','Event '.$nama.' pending — '.$pendingEv.' total pending','admin_alert','event_pending',(int)$id);
      notifyKepsek('Event menunggu: '.$nama, $nama.' oleh '.currentUser()['nama'],'admin_alert','event_pending',(int)$id);
    }
  }catch(Exception $e2){}
  cacheDelPrefix('events'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['id'=>$id,'status'=>$status]],201);
}
if(routeMatch('/events/:id',$uri,$pm) && in_array($method,['PUT','PATCH'],true)){
  requireRole('admin'); $b=getBody(); $id=$pm['id'];
  // event hanya admin — tidak ada scoped pembina
  $cur=pdo()->prepare('SELECT * FROM events WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $curRow=$cur->fetch();
  if(!$curRow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  // build merged values for validation if any of validated fields present
  $nama=array_key_exists('nama',$b)?$b['nama']:$curRow['nama'];
  $tanggal=array_key_exists('tanggal',$b)?$b['tanggal']:$curRow['tanggal'];
  $waktu=array_key_exists('waktu',$b)?$b['waktu']:(array_key_exists('jam_mulai',$b)?$b['jam_mulai']:$curRow['waktu']);
  $waktuSelesai=array_key_exists('waktu_selesai',$b)?$b['waktu_selesai']:(array_key_exists('jam_selesai',$b)?$b['jam_selesai']:$curRow['waktu_selesai']);
  $lokasi=array_key_exists('lokasi',$b)?$b['lokasi']:$curRow['lokasi'];
  $kuota=array_key_exists('kuota',$b)?$b['kuota']:$curRow['kuota'];
  $desk=array_key_exists('deskripsi',$b)?$b['deskripsi']:$curRow['deskripsi'];
  // PAST_DATE guard for PATCH: cek merged values (existing tanggal jika tidak di-patch) — kecuali force=1
  $forcePatch = !empty($b['force']) && ($b['force']==='1' || $b['force']===true || $b['force']==1);
  if($forcePatch && !in_array(currentUser()['role'],['admin','kepsek'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Force hanya untuk admin/kepsek']],403);
  // validate if any of those keys touched OR tanggal needs past check even if not touched but force logic requires? minimal validate tanggal past for any PATCH
  $needValidate = array_key_exists('nama',$b) || array_key_exists('tanggal',$b) || array_key_exists('waktu',$b) || array_key_exists('jam_mulai',$b) || array_key_exists('lokasi',$b) || array_key_exists('kuota',$b) || array_key_exists('waktu_selesai',$b) || array_key_exists('jam_selesai',$b);
  // jika tanggal tidak di-patch tapi tetap PAST_DATE? existing tanggal past is OK for PATCH non-tanggal; only validate when tanggal touched or any payload touched that re-validates merged tanggal
  if($needValidate){
    $err=''; if(!validateEventPayload($nama,$tanggal,$waktu,$lokasi,$kuota,$err,$waktuSelesai,$desk,null,$forcePatch?true:false)) {
      $code = (strpos($err,'masa lalu')!==false) ? 'PAST_DATE' : 'VALIDATION';
      $msg = $code==='PAST_DATE' ? 'Tanggal event tidak boleh di masa lalu' : $err;
      jsonOut(['success'=>false,'error'=>['code'=>$code,'message'=>$msg]],422);
    }
  } else if(isset($b['tanggal']) || $tanggal < date('Y-m-d')){
    // edge: jika client hanya patch tanggal ke past tanpa field lain — sudah covered di atas, tapi jaga
    if(!$forcePatch && $tanggal < date('Y-m-d')) jsonOut(['success'=>false,'error'=>['code'=>'PAST_DATE','message'=>'Tanggal event tidak boleh di masa lalu']],422);
  }
  // periode validate + silang Task4
  $regStart = array_key_exists('registration_start',$b)? ($b['registration_start']?:null) : $curRow['registration_start'];
  $regEnd = array_key_exists('registration_end',$b)? ($b['registration_end']?:null) : $curRow['registration_end'];
  if($regStart && $regEnd && strtotime($regStart) && strtotime($regEnd) && strtotime($regStart) >= strtotime($regEnd)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Periode mulai harus < periode akhir']],422);
  if($regEnd){
    $regEndTs=strtotime($regEnd); $evTs=strtotime($tanggal.' 00:00:00');
    if($regEndTs!==false && $evTs!==false && $regEndTs >= $evTs) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'registration_end harus sebelum tanggal event 00:00']],422);
  }
  // Task6 lock edit: kuota < terisi or tanggal < today -> lock
  $terisiChk=pdo()->prepare('SELECT COUNT(*) c FROM event_participants WHERE event_id=?'); $terisiChk->execute([$id]); $terisiN=(int)($terisiChk->fetch()['c']??0);
  if((int)$kuota < $terisiN) jsonOut(['success'=>false,'error'=>['code'=>'LOCKED','message'=>'Kuota tidak boleh < terisi ('.$terisiN.') — lock edit']],409);
  if($tanggal < date('Y-m-d') && !$forcePatch) jsonOut(['success'=>false,'error'=>['code'=>'LOCKED','message'=>'Event sudah lewat — lock edit']],409);
  // ekskul multi: ekskul_ids / ekskul_id / ekskulId — null/Umum = Umum, array = multi
  $hasEkskul = array_key_exists('ekskul_ids',$b) || array_key_exists('ekskulIds',$b) || array_key_exists('ekskul_id',$b) || array_key_exists('ekskulId',$b);
  $ekskulIdsPatch = null; $ekskulTouched=false;
  if($hasEkskul){
    $ekskulTouched=true;
    $rawEk = $b['ekskul_ids'] ?? $b['ekskulIds'] ?? $b['ekskul_id'] ?? $b['ekskulId'] ?? null;
    $ekskulIdsPatch = normalizeEkskulIds($rawEk);
    if($ekskulIdsPatch && count($ekskulIdsPatch)){
      foreach($ekskulIdsPatch as $eid2){ $cek=pdo()->prepare('SELECT id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $cek->execute([$eid2]); if(!$cek->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Ekskul tidak ditemukan (#'.$eid2.')']],422); }
    }
    $b['ekskul_id']= $ekskulIdsPatch ? $ekskulIdsPatch[0] : null;
    unset($b['ekskulId'],$b['ekskulIds'],$b['ekskul_ids']);
  }
  $fields=['nama','deskripsi','tanggal','waktu','waktu_selesai','lokasi','kuota','rundown','ekskul_id','registration_start','registration_end'];
  // alias jam_mulai/jam_selesai -> waktu/waktu_selesai
  if(array_key_exists('jam_mulai',$b) && !array_key_exists('waktu',$b)) { $b['waktu']=$b['jam_mulai']; }
  if(array_key_exists('jam_selesai',$b) && !array_key_exists('waktu_selesai',$b)) { $b['waktu_selesai']=$b['jam_selesai']; }
  $sets=[]; $par=[]; foreach($fields as $f) if(array_key_exists($f,$b)){ $sets[]="$f=?"; $par[]=$b[$f]===''?null:$b[$f]; }
  if(!$sets) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tidak ada field']],422);
  $sets[]='updated_at=NOW()';
  $par[]=$id; pdo()->prepare('UPDATE events SET '.implode(',',$sets).' WHERE id=?')->execute($par);
  if($ekskulTouched){ syncEventEkskul(pdo(), (int)$id, $ekskulIdsPatch); }
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'update','event',$id, json_encode($b,JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  cacheDelPrefix('events'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/events/:id',$uri,$pm) && $method==='DELETE'){ requireRole('admin'); $id=$pm['id']; $now=date('Y-m-d H:i:s'); pdo()->prepare('UPDATE events SET deleted_at=?, updated_at=? WHERE id=? AND deleted_at IS NULL')->execute([$now,$now,$id]);
  // cleanup storage: cover + inline images — same pattern as Ekskul delete best-effort
  try{
    $cov=pdo()->prepare('SELECT cover_path FROM events WHERE id=?'); $cov->execute([$id]); $cr=$cov->fetch();
    if($cr && !empty($cr['cover_path'])){ $abs=uploadPath($cr['cover_path']); if(is_file($abs)) @unlink($abs); foreach(['png','jpg','jpeg','webp'] as $e2){ $p=uploadPath('covers/event_'.$id.'.'.$e2); if(is_file($p)) @unlink($p); } }
  }catch(Exception $e){}
  try{
    $imgs=pdo()->prepare('SELECT stored_path FROM event_images WHERE event_id=? AND deleted_at IS NULL'); $imgs->execute([$id]);
    foreach($imgs->fetchAll() as $r){ $p=uploadPath($r['stored_path']); if(is_file($p)) @unlink($p); }
    pdo()->prepare('UPDATE event_images SET deleted_at=NOW() WHERE event_id=? AND deleted_at IS NULL')->execute([$id]);

  }catch(Exception $e){}
  // also delete event folder if exists
  try{ $dir=uploadPath('event_'.$id); if(is_dir($dir)){ $fs=glob($dir.'/*'); foreach($fs as $f) if(is_file($f)) @unlink($f); @rmdir($dir); } }catch(Exception $e){}
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'delete','event',$id, json_encode(['deleted_at'=>$now],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){} cacheDelPrefix('events'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap'); jsonOut(['success'=>true,'data'=>null]); }
// === Event inline images: POST /events/:id/images (multipart), GET /events/:id/images, GET /event-images/:id, DELETE /event-images/:id ===
if(routeMatch('/events/:id/images',$uri,$pm) && $method==='POST'){
  requireLogin();
  $eid=(int)$pm['id'];
  // guard: event hanya admin
  $cu=currentUser();
  if(!$cu || $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya admin']],403);
  $ev=pdo()->prepare('SELECT id FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$eid]); $er=$ev->fetch();
  if(!$er) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  sertifikatRateLimit('event_img_upload',20);
  try{ ensureEventImages(pdo()); }catch(Exception $e){}
  if(empty($_FILES['image']) && empty($_FILES['images']) && empty($_FILES['file'])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File image wajib (field: image)']],422);
  $files=[];
  if(!empty($_FILES['image']['name'])) $files[]=$_FILES['image'];
  if(!empty($_FILES['file']['name'])) $files[]=$_FILES['file'];
  if(!empty($_FILES['images']['name'])){
    $arr=$_FILES['images'];
    if(is_array($arr['name'])){ for($i=0;$i<count($arr['name']);$i++) $files[]=['name'=>$arr['name'][$i],'type'=>$arr['type'][$i],'tmp_name'=>$arr['tmp_name'][$i],'error'=>$arr['error'][$i],'size'=>$arr['size'][$i]]; }
    else $files[]=$arr;
  }
  $saved=[];
  $uid=(int)$cu['id'];
  $baseDir=uploadPath('event_'.$eid);
  if(!is_dir($baseDir)) @mkdir($baseDir,0775,true);
  foreach($files as $f){
    if($f['error']!==0) continue;
    if($f['size']>2*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gambar '.$f['name'].' maksimal 2MB']],422);
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)); if(!$ext) $ext='jpg';
    if(!in_array($ext,['jpg','jpeg','png','webp','gif'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Format harus jpg/png/webp/gif']],422);
    $mime=mime_content_type($f['tmp_name']) ?: $f['type']; if(strpos($mime,'image/')!==0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File bukan gambar valid']],422);
    $info=@getimagesize($f['tmp_name']); if(!$info) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gambar tidak valid']],422);
    $safe=preg_replace('/[^A-Za-z0-9_\-]/','_', pathinfo($f['name'],PATHINFO_FILENAME));
    $stored=$baseDir.'/'.uniqid().'_'.$safe.'.'.$ext;
    if(!@move_uploaded_file($f['tmp_name'],$stored)) @copy($f['tmp_name'],$stored);
    $rel='uploads/event_'.$eid.'/'.basename($stored);
    pdo()->prepare("INSERT INTO event_images(event_id,user_id,stored_path,mime,size) VALUES (?,?,?,?,?)")->execute([$eid,$uid,$rel,$mime,$f['size']]);
    $nid=(int)pdo()->lastInsertId();
    $saved[]=['id'=>$nid,'event_id'=>$eid,'mime'=>$mime,'size'=>$f['size'],'url'=>'/api/event-images/'.$nid];
  }
  if(!$saved) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tidak ada file tersimpan']],422);
  cacheDelPrefix('events'); cacheDelPrefix('kalender');
  jsonOut(['success'=>true,'data'=>$saved],201);
}
if(routeMatch('/events/:id/images',$uri,$pm) && $method==='GET'){
  $eid=(int)$pm['id'];
  $st=pdo()->prepare("SELECT id,event_id,mime,size,stored_path,created_at FROM event_images WHERE event_id=? AND deleted_at IS NULL ORDER BY created_at ASC, id ASC");
  $st->execute([$eid]); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['url']='/api/event-images/'.$r['id']; unset($r['stored_path']); } unset($r);
  jsonOut(['success'=>true,'data'=>$rows]);
}
if(routeMatch('/event-images/:id',$uri,$pm) && $method==='GET'){
  $iid=(int)$pm['id'];
  $st=pdo()->prepare("SELECT * FROM event_images WHERE id=? AND deleted_at IS NULL"); $st->execute([$iid]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Gambar tidak ada']],404);
  $path=uploadPath($row['stored_path']); if(!is_file($path)) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'File hilang']],404);
  $mime=$row['mime']?: mime_content_type($path) ?: 'image/jpeg';
  header('Content-Type: '.$mime); header('Content-Length: '.filesize($path)); header('Cache-Control: public, max-age=86400'); header('Content-Disposition: inline; filename="'.addslashes(basename($row['stored_path'])).'"');
  readfile($path); exit;
}
if(routeMatch('/event-images/:id',$uri,$pm) && $method==='DELETE'){
  requireLogin(); $iid=(int)$pm['id'];
  $st=pdo()->prepare("SELECT * FROM event_images WHERE id=? AND deleted_at IS NULL"); $st->execute([$iid]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Gambar tidak ada']],404);
  $cu=currentUser(); $eid=(int)$row['event_id'];
  $ev=pdo()->prepare('SELECT created_by FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$eid]); $er=$ev->fetch();
  $isOwner=(int)$row['user_id']===(int)$cu['id'];
  $isEventOwner=$er && (int)$er['created_by']===(int)$cu['id'];
  if(!$isOwner && !$isEventOwner && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pemilik/admin']],403);
  csrfCheck(); sertifikatRateLimit('event_img_delete',30);
  $p=uploadPath($row['stored_path']); if(is_file($p)) @unlink($p);
  try{ pdo()->prepare("UPDATE event_images SET deleted_at=NOW() WHERE id=?")->execute([$iid]); }catch(Exception $e){ pdo()->prepare("UPDATE event_images SET deleted_at=datetime('now') WHERE id=?")->execute([$iid]); }
  cacheDelPrefix('events'); cacheDelPrefix('kalender');
  jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/events/:id/approve',$uri,$pm) && $method==='POST'){
  requireRole('kepsek'); $b=getBody(); $act=trim(strtolower($b['action']??'approve')); $st=$act==='reject'?'rejected':'approved';
  if(!in_array($act,['approve','reject'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'action harus approve/reject']],422);
  $cur=pdo()->prepare('SELECT status, nama FROM events WHERE id=? AND deleted_at IS NULL'); $cur->execute([$pm['id']]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  if($row['status']!=='pending') jsonOut(['success'=>false,'error'=>['code'=>'CONFLICT','message'=>'Hanya pending bisa di-approve/reject (status: '.$row['status'].')']],409);
  if($st==='rejected'){
    $reason=trim($b['rejected_reason']??$b['reason']??'');
    if(mb_strlen($reason)<10) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Alasan reject minimal 10 karakter']],422);
    try{ $col=pdo()->query("SHOW COLUMNS FROM events LIKE 'rejected_reason'")->fetch(); if(!$col) pdo()->exec("ALTER TABLE events ADD COLUMN rejected_reason TEXT NULL"); }catch(Exception $e){}
    pdo()->prepare('UPDATE events SET status=?, rejected_reason=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$st,$reason,$pm['id']]);
  } else {
    pdo()->prepare('UPDATE events SET status=?, rejected_reason=NULL, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$st,$pm['id']]);
  }
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'approve','event',$pm['id'], json_encode(['status'=>$st,'reason'=>$b['rejected_reason']??$b['reason']??null],JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'approve','event',$pm['id'], json_encode(['status'=>$st,'reason'=>$b['rejected_reason']??null],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  cacheDelPrefix('events'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['status'=>$st]]);
}
if(routeMatch('/events/:id/daftar',$uri,$pm) && $method==='POST'){
  requireRole('siswa'); $eid=(int)$pm['id']; $uid=currentUser()['id'];
  // Task3 rate-limit 5/menit per user per event
  $rlFile=sys_get_temp_dir().'/event_daftar_'.$uid.'_'.$eid.'.json';
  $now=time(); $cnt=0; $win=$now;
  if(file_exists($rlFile)){ $j=@json_decode(@file_get_contents($rlFile),true); if($j && ($now - (int)($j['start']??0) < 60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
  if($cnt>=5){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering daftar, tunggu 1 menit']],429); }
  @file_put_contents($rlFile, json_encode(['count'=>$cnt+1,'start'=>$win]));
  $pdo=pdo();
  // ensure unique index for race guard
  try{ $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_event_user ON event_participants(event_id,user_id)"); }catch(Exception $e){}
  try{ $pdo->exec("CREATE UNIQUE INDEX uniq_event_user ON event_participants(event_id,user_id)"); }catch(Exception $e){}
  $e=pdo()->prepare('SELECT * FROM events WHERE id=? AND deleted_at IS NULL'); $e->execute([$eid]); $row=$e->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  if($row['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_OPEN','message'=>'Event belum approved — Menunggu approval']],422);
  if(!empty($row['registration_start']) && strtotime($row['registration_start'])>time()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_OPEN','message'=>'Pendaftaran belum dibuka']],422);
  if(!empty($row['registration_end']) && strtotime($row['registration_end'])<time()) jsonOut(['success'=>false,'error'=>['code'=>'CLOSED','message'=>'Pendaftaran sudah ditutup']],422);
  // Opsi B soft — gate daftar: Ekskul event hanya anggota ekskul terkait
  try{
    $ekIds=[]; $stEk=pdo()->prepare('SELECT ekskul_id FROM event_ekskul WHERE event_id=?'); $stEk->execute([$eid]); $ekIds=array_map('intval', array_column($stEk->fetchAll(),'ekskul_id'));
    if(empty($ekIds) && !empty($row['ekskul_id'])) $ekIds=[(int)$row['ekskul_id']];
    if($ekIds){
      $phEk=implode(',', array_fill(0,count($ekIds),'?'));
      $chkM=pdo()->prepare("SELECT 1 FROM registrations WHERE user_id=? AND status='diterima' AND deleted_at IS NULL AND ekskul_id IN ($phEk)"); $chkM->execute(array_merge([$uid], $ekIds));
      if(!$chkM->fetch()){
        $nmSt=pdo()->prepare("SELECT GROUP_CONCAT(nama SEPARATOR ', ') FROM ekskul WHERE id IN ($phEk)"); $nmSt->execute($ekIds); $nm=$nmSt->fetchColumn()?:'Ekskul terkait';
        jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota '.$nm.' yang bisa mendaftar']],403);
      }
    }
  }catch(PDOException $e2){ throw $e2; }catch(Exception $e2){}
  // FOR UPDATE transaction
  try{
    $pdo->beginTransaction();
    $lock=$pdo->prepare('SELECT kuota FROM events WHERE id=? FOR UPDATE'); $lock->execute([$eid]); $locked=$lock->fetch();
    if(!$locked){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404); }
    $ex=$pdo->prepare('SELECT 1 FROM event_participants WHERE event_id=? AND user_id=? FOR UPDATE'); $ex->execute([$eid,$uid]);
    if($ex->fetch()){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sudah daftar']],409); }
    $c=$pdo->prepare('SELECT COUNT(*) c FROM event_participants WHERE event_id=? FOR UPDATE'); $c->execute([$eid]); $terisi=(int)($c->fetch()['c']??0);
    if($terisi >= (int)$locked['kuota']){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'FULL','message'=>'Kuota penuh']],409); }
    $pdo->prepare('INSERT INTO event_participants(event_id,user_id,status,hadir) VALUES (?,?,?,0)')->execute([$eid,$uid,'diterima']);
    $pdo->commit();
  }catch(PDOException $exx){
    if($pdo->inTransaction()) $pdo->rollBack();
    // duplicate 1062 / 23000
    $code=$exx->getCode(); $msg=$exx->getMessage();
    if($code==23000 || strpos($msg,'1062')!==false || strpos($msg,'UNIQUE')!==false || strpos($msg,'uniq')!==false){
      jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sudah daftar']],409);
    }
    throw $exx;
  }catch(Exception $exx){ if($pdo->inTransaction()) $pdo->rollBack(); throw $exx; }
  // notify queue best-effort
  try{ @file_put_contents(sys_get_temp_dir().'/notif_'.$uid.'.json', json_encode(['type'=>'event_daftar','event_id'=>$eid,'at'=>date('c')])); }catch(Exception $e){}
  cacheDelPrefix('events'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  // keep legacy success below - return
  jsonOut(['success'=>true,'data'=>null],201);
}
if(routeMatch('/events/:id/peserta',$uri,$pm) && $method==='GET'){
  requireRole('admin');
  // peserta event hanya admin (tanpa leak ke pembina/kepsek)
  $ev=pdo()->prepare('SELECT id FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$pm['id']]);
  if(!$ev->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $pg=max(1,(int)($_GET['page']??1)); $lim=min(100,max(1,(int)($_GET['limit']??50))); $off2=($pg-1)*$lim;
  $ctP=pdo()->prepare('SELECT COUNT(*) c FROM event_participants WHERE event_id=?'); $ctP->execute([$pm['id']]); $totP=(int)($ctP->fetch()['c']??0);
  $st=pdo()->prepare('SELECT p.*, u.nama, u.email FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? ORDER BY p.created_at DESC LIMIT '.$lim.' OFFSET '.$off2); $st->execute([$pm['id']]);
  header('X-Total-Count: '.$totP); header('X-Page: '.$pg); header('X-Limit: '.$lim);
  jsonOut(['success'=>true,'data'=>$st->fetchAll(),'meta'=>['total'=>$totP,'page'=>$pg,'limit'=>$lim,'pages'=>max(1,(int)ceil($totP/$lim))]]);
}
if(routeMatch('/events/:id/hadir',$uri,$pm) && $method==='POST'){
  requireRole('admin');
  $ev=pdo()->prepare('SELECT id FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$pm['id']]);
  if(!$ev->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $b=getBody(); $uid=$b['user_id']??0; $hadir=(int)!empty($b['hadir']);
  pdo()->prepare('UPDATE event_participants SET hadir=? WHERE event_id=? AND user_id=?')->execute([$hadir,$pm['id'],$uid]);
  jsonOut(['success'=>true,'data'=>null]);
}
if($uri==='/me/event-registrations' && $method==='GET'){
  requireRole('siswa'); $meUid=currentUser()['id'];
  $pg=max(1,(int)($_GET['page']??1)); $lim=min(100,max(1,(int)($_GET['limit']??50))); $off2=($pg-1)*$lim;
  $ctE=pdo()->prepare('SELECT COUNT(*) c FROM event_participants p WHERE p.user_id=?'); $ctE->execute([$meUid]); $totE=(int)($ctE->fetch()['c']??0);
  $st=pdo()->prepare('SELECT p.id,p.event_id,p.user_id,p.status,p.hadir,p.created_at, e.nama event_nama, e.tanggal, e.waktu, e.waktu_selesai, e.lokasi FROM event_participants p JOIN events e ON e.id=p.event_id WHERE p.user_id=? ORDER BY e.tanggal DESC LIMIT '.$lim.' OFFSET '.$off2); $st->execute([$meUid]); $rows=$st->fetchAll(); foreach($rows as &$r){ $r['event_nama']=e($r['event_nama']); $r['lokasi']=$r['lokasi']?e($r['lokasi']):null; } unset($r); $etag='"'.md5(json_encode($rows).$meUid.$pg.$lim).'"'; header('ETag: '.$etag); header('Cache-Control: private, max-age=60'); header('X-Total-Count: '.$totE); header('X-Page: '.$pg); header('X-Limit: '.$lim); if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; } jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$totE,'page'=>$pg,'limit'=>$lim,'pages'=>max(1,(int)ceil($totE/$lim))]]);
}
if(routeMatch('/events/:id/batal',$uri,$pm) && $method==='POST'){
  requireRole('siswa'); $eid=(int)$pm['id']; $uid=currentUser()['id'];
  $chk=pdo()->prepare('SELECT 1 FROM event_participants WHERE event_id=? AND user_id=?'); $chk->execute([$eid,$uid]); if(!$chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Belum terdaftar di event ini']],404);
  $ev=pdo()->prepare('SELECT tanggal FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$eid]); $row=$ev->fetch(); if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $evTs=strtotime($row['tanggal'].' 00:00:00'); $now0=strtotime(date('Y-m-d').' 00:00:00');
  if($evTs!==false && $now0!==false && ($evTs - $now0) <= 86400) jsonOut(['success'=>false,'error'=>['code'=>'TOO_LATE','message'=>'H-1 tidak bisa batal online']],409);
  pdo()->prepare('DELETE FROM event_participants WHERE user_id=? AND event_id=?')->execute([$uid,$eid]);
  cacheDelPrefix('events'); cacheDelPrefix('kalender'); cacheDelPrefix('dashboard_rekap');
  jsonOut(['success'=>true,'data'=>['message'=>'Event dibatalkan']]);
}
// === EVENT ATTENDANCE — absensi QR multi-sesi (kelola admin-only, scan siswa) ===
// QR payload: EVENT:<session_id>:<token> — session_id unik per sesi, token 32hex per sesi.
// Tiap sesi independen (expiry sendiri), 1 siswa = 1 data per sesi (UNIQUE session_id+user_id).
function eventSessionQr(array $s): string { return 'EVENT:'.$s['id'].':'.$s['token']; }
function eventSessionRefresh(PDO $pdo, array $s): array {
  if(($s['status']??'')==='aktif' && strtotime($s['expires_at']??'') < time()){
    try{ $pdo->prepare("UPDATE event_attendance_sessions SET status='expired' WHERE id=? AND status='aktif'")->execute([$s['id']]); }catch(Exception $e){}
    $s['status']='expired';
  }
  return $s;
}
function eventSessionOut(array $s, int $hadir=0): array {
  $s['hadir_count']=$hadir; $s['qr_text']=eventSessionQr($s); return $s;
}
if(routeMatch('/events/:id/sessions',$uri,$pm) && $method==='POST'){
  requireRole('admin');
  try{ ensureEventAttendance(pdo()); }catch(Exception $e){}
  $eid=(int)$pm['id'];
  $ev=pdo()->prepare('SELECT id,nama FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$eid]); $erow=$ev->fetch();
  if(!$erow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $b=getBody();
  $nama=trim($b['nama']??'');
  if($nama===''){ $c=pdo()->prepare('SELECT COUNT(*) c FROM event_attendance_sessions WHERE event_id=?'); $c->execute([$eid]); $nama='Sesi '.(((int)($c->fetch()['c']??0))+1); }
  if(mb_strlen($nama)>150) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Nama sesi maksimal 150 karakter']],422);
  $dur=(int)($b['durasi_jam']??0);
  if($dur<1 || $dur>24) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'durasi_jam wajib 1-24 jam']],422);
  // rate limit 10 generate / menit per admin (file flock, anti race)
  $auid=currentUser()['id'];
  $rlf=sys_get_temp_dir().'/evt_sess_'.$auid.'.json'; $now=time(); $cnt=0; $win=$now;
  if(file_exists($rlf)){ $j=@json_decode(@file_get_contents($rlf),true); if($j && ($now - (int)($j['start']??0) < 60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
  if($cnt>=10){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering buat sesi, tunggu 1 menit']],429); }
  @file_put_contents($rlf, json_encode(['count'=>$cnt+1,'start'=>$win]));
  $starts=date('Y-m-d H:i:s'); $exp=date('Y-m-d H:i:s', $now+$dur*3600);
  $newId=0;
  for($i=0;$i<3;$i++){
    $token=bin2hex(random_bytes(16));
    try{ pdo()->prepare('INSERT INTO event_attendance_sessions(event_id,nama,token,durasi_jam,starts_at,expires_at,status,created_by) VALUES (?,?,?,?,?,?,\'aktif\',?)')->execute([$eid,$nama,$token,$dur,$starts,$exp,$auid]); $newId=(int)pdo()->lastInsertId(); break; }
    catch(Exception $e){ if(stripos($e->getMessage(),'uplicate')===false) throw $e; }
  }
  if(!$newId) jsonOut(['success'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal buat sesi, coba lagi']],500);
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$auid,'create','event_session',$newId, json_encode(['event_id'=>$eid,'nama'=>$nama,'durasi_jam'=>$dur],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  $g=pdo()->prepare('SELECT * FROM event_attendance_sessions WHERE id=?'); $g->execute([$newId]); $gs=$g->fetch();
  jsonOut(['success'=>true,'data'=>eventSessionOut($gs,0)],201);
}
if(routeMatch('/events/:id/sessions',$uri,$pm) && $method==='GET'){
  requireRole('admin');
  try{ ensureEventAttendance(pdo()); }catch(Exception $e){}
  $eid=(int)$pm['id'];
  $ev=pdo()->prepare('SELECT id FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$eid]);
  if(!$ev->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $st=pdo()->prepare('SELECT s.*, (SELECT COUNT(*) FROM event_attendance a WHERE a.session_id=s.id) AS hadir_count FROM event_attendance_sessions s WHERE s.event_id=? ORDER BY s.id ASC'); $st->execute([$eid]);
  $out=[]; foreach($st->fetchAll() as $s){ $s=eventSessionRefresh(pdo(),$s); $out[]=eventSessionOut($s,(int)($s['hadir_count']??0)); }
  jsonOut(['success'=>true,'data'=>$out]);
}
if(routeMatch('/event-sessions/:sid/stop',$uri,$pm) && $method==='POST'){
  requireRole('admin');
  try{ ensureEventAttendance(pdo()); }catch(Exception $e){}
  $sid=(int)$pm['sid'];
  $st=pdo()->prepare('SELECT * FROM event_attendance_sessions WHERE id=?'); $st->execute([$sid]); $s=$st->fetch();
  if(!$s) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sesi tidak ada']],404);
  $s=eventSessionRefresh(pdo(),$s);
  if($s['status']==='aktif'){ pdo()->prepare("UPDATE event_attendance_sessions SET status='stopped' WHERE id=? AND status='aktif'")->execute([$sid]); $s['status']='stopped'; }
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'stop','event_session',$sid, json_encode(['status'=>$s['status']],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  $c=pdo()->prepare('SELECT COUNT(*) c FROM event_attendance WHERE session_id=?'); $c->execute([$sid]);
  jsonOut(['success'=>true,'data'=>eventSessionOut($s,(int)($c->fetch()['c']??0))]);
}
if(routeMatch('/event-sessions/:sid/mark',$uri,$pm) && $method==='POST'){
  // Opsi A: manual absen terikat sesi — 1 insert ke event_attendance, idempoten (UNIQUE session+user)
  requireRole('admin');
  try{ ensureEventAttendance(pdo()); }catch(Exception $e){}
  $sid=(int)$pm['sid'];
  $b=getBody(); $uid=(int)($b['user_id']??0);
  if(!$sid||!$uid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'session & user wajib']],422);
  $pdo=pdo();
  $st=$pdo->prepare('SELECT * FROM event_attendance_sessions WHERE id=?'); $st->execute([$sid]); $s=$st->fetch();
  if(!$s) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sesi tidak ada']],404);
  $s=eventSessionRefresh($pdo,$s);
  if($s['status']==='stopped') jsonOut(['success'=>false,'error'=>['code'=>'STOPPED','message'=>'Sesi sudah dihentikan — pilih sesi aktif']],410);
  if($s['status']!=='aktif') jsonOut(['success'=>false,'error'=>['code'=>'EXPIRED','message'=>'Sesi sudah kedaluwarsa — pilih sesi aktif']],410);
  // wajib terdaftar di event
  $reg=$pdo->prepare("SELECT 1 FROM event_participants WHERE event_id=? AND user_id=? AND status='diterima'"); $reg->execute([$s['event_id'],$uid]);
  if(!$reg->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_REGISTERED','message'=>'Siswa belum terdaftar di event ini']],403);
  // guard duplikat: sudah tercatat di sesi ini (scan QR ataupun manual sebelumnya)
  $dup=$pdo->prepare('SELECT 1 FROM event_attendance WHERE session_id=? AND user_id=?'); $dup->execute([$sid,$uid]);
  if($dup->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'ALREADY','message'=>'Siswa sudah tercatat absensi di sesi "'.$s['nama'].'"']],409);
  try{ $pdo->prepare('INSERT INTO event_attendance(session_id,user_id) VALUES (?,?)')->execute([$sid,$uid]); }
  catch(Exception $e){ jsonOut(['success'=>false,'error'=>['code'=>'ALREADY','message'=>'Siswa sudah tercatat absensi di sesi "'.$s['nama'].'"']],409); }
  try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'mark','event_attendance',$sid, json_encode(['user_id'=>$uid,'session_nama'=>$s['nama']],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['session_id'=>$sid,'session_nama'=>$s['nama']]],201);
}
if(routeMatch('/event-sessions/:sid/unmark',$uri,$pm) && $method==='POST'){
  // Opsi A: batalkan absen manual/scan per sesi (admin) — hapus baris event_attendance
  requireRole('admin');
  try{ ensureEventAttendance(pdo()); }catch(Exception $e){}
  $sid=(int)$pm['sid'];
  $b=getBody(); $uid=(int)($b['user_id']??0);
  if(!$sid||!$uid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'session & user wajib']],422);
  $pdo=pdo();
  $del=$pdo->prepare('DELETE FROM event_attendance WHERE session_id=? AND user_id=?'); $del->execute([$sid,$uid]);
  if(!$del->rowCount()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Belum tercatat di sesi ini']],404);
  try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'unmark','event_attendance',$sid, json_encode(['user_id'=>$uid],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>null]);
}
if($uri==='/event-attendance/scan' && $method==='POST'){
  requireRole('siswa');
  try{ ensureEventAttendance(pdo()); }catch(Exception $e){}
  $uid=currentUser()['id'];
  // rate limit 20 scan / menit per siswa
  $rlf=sys_get_temp_dir().'/evt_scan_'.$uid.'.json'; $now=time(); $cnt=0; $win=$now;
  if(file_exists($rlf)){ $j=@json_decode(@file_get_contents($rlf),true); if($j && ($now - (int)($j['start']??0) < 60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
  if($cnt>=20){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering scan, tunggu 1 menit']],429); }
  @file_put_contents($rlf, json_encode(['count'=>$cnt+1,'start'=>$win]));
  $b=getBody(); $raw=trim($b['token']??'');
  if($raw==='') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'token wajib']],422);
  $sid=0; $token='';
  if(preg_match('/^EVENT:(\d+):([a-f0-9]{32})$/i',$raw,$m)){ $sid=(int)$m[1]; $token=strtolower($m[2]); }
  else if(preg_match('/^[a-f0-9]{32}$/i',$raw)){ $token=strtolower($raw); }
  else jsonOut(['success'=>false,'error'=>['code'=>'INVALID','message'=>'QR tidak valid — gunakan QR absensi event']],404);
  $pdo=pdo();
  try{
    $pdo->beginTransaction();
    if($sid>0){ $t=$pdo->prepare('SELECT * FROM event_attendance_sessions WHERE id=? AND token=? FOR UPDATE'); $t->execute([$sid,$token]); }
    else { $t=$pdo->prepare('SELECT * FROM event_attendance_sessions WHERE token=? FOR UPDATE'); $t->execute([$token]); }
    $row=$t->fetch();
    if(!$row){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'INVALID','message'=>'Sesi absensi tidak ditemukan']],404); }
    // lazy expire di dalam transaksi
    if($row['status']==='aktif' && strtotime($row['expires_at']) < time()){
      $pdo->prepare("UPDATE event_attendance_sessions SET status='expired' WHERE id=? AND status='aktif'")->execute([$row['id']]);
      $row['status']='expired';
    }
    if($row['status']==='stopped'){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'STOPPED','message'=>'Sesi sudah dihentikan panitia']],410); }
    if($row['status']!=='aktif'){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'EXPIRED','message'=>'QR sesi sudah kedaluwarsa']],410); }
    // wajib login + sudah daftar event ini
    $reg=$pdo->prepare("SELECT 1 FROM event_participants WHERE event_id=? AND user_id=? AND status='diterima'"); $reg->execute([$row['event_id'],$uid]);
    if(!$reg->fetch()){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'NOT_REGISTERED','message'=>'Belum terdaftar di event ini — daftar dulu ya']],403); }
    // guard double scan: tetap 1 data per sesi
    $dup=$pdo->prepare('SELECT 1 FROM event_attendance WHERE session_id=? AND user_id=?'); $dup->execute([$row['id'],$uid]);
    if($dup->fetch()){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'ALREADY','message'=>'Anda sudah melakukan absensi']],409); }
    try{ $pdo->prepare('INSERT INTO event_attendance(session_id,user_id) VALUES (?,?)')->execute([$row['id'],$uid]); }
    catch(Exception $e){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'ALREADY','message'=>'Anda sudah melakukan absensi']],409); }
    $pdo->commit();
    jsonOut(['success'=>true,'data'=>['session_id'=>(int)$row['id'],'event_id'=>(int)$row['event_id'],'session_nama'=>$row['nama']]]);
  }catch(Exception $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
  }
}
if(routeMatch('/events/:id/sessions/:sid/laporan',$uri,$pm) && $method==='GET'){
  requireRole('admin');
  try{ ensureEventAttendance(pdo()); }catch(Exception $e){}
  $eid=(int)$pm['id']; $sid=(int)$pm['sid'];
  $st=pdo()->prepare('SELECT s.*, e.nama AS event_nama FROM event_attendance_sessions s JOIN events e ON e.id=s.event_id WHERE s.id=? AND s.event_id=? AND e.deleted_at IS NULL'); $st->execute([$sid,$eid]); $s=$st->fetch();
  if(!$s) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sesi tidak ada']],404);
  $s=eventSessionRefresh(pdo(),$s);
  $pg=max(1,(int)($_GET['page']??1)); $lim=min(100,max(1,(int)($_GET['limit']??50))); $off2=($pg-1)*$lim;
  // counts terpisah (index-friendly, tanpa fetch full rows)
  $ch=pdo()->prepare('SELECT COUNT(*) c FROM event_attendance WHERE session_id=?'); $ch->execute([$sid]); $cntHadir=(int)($ch->fetch()['c']??0);
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM event_participants WHERE event_id=? AND status='diterima'"); $ct->execute([$eid]); $cntTerdaftar=(int)($ct->fetch()['c']??0);
  $cntTidak=max(0,$cntTerdaftar-$cntHadir);
  $h=pdo()->prepare('SELECT u.id, u.nama, u.email, u.kelas, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id WHERE a.session_id=? ORDER BY a.scanned_at ASC LIMIT '.$lim.' OFFSET '.$off2); $h->execute([$sid]); $hadir=$h->fetchAll();
  // Terdaftar-tapi-Tidak-Absen: peserta diterima yang belum scan sesi ini
  $t=pdo()->prepare("SELECT u.id, u.nama, u.email, u.kelas FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND p.status='diterima' AND NOT EXISTS (SELECT 1 FROM event_attendance a WHERE a.session_id=? AND a.user_id=p.user_id) ORDER BY u.nama ASC LIMIT ".$lim.' OFFSET '.$off2); $t->execute([$eid,$sid]); $tidak=$t->fetchAll();
  $etag='"'.md5($eid.'.'.$sid.'.'.$pg.'.'.$lim.'.'.$cntHadir.'.'.$cntTerdaftar).'"';
  header('ETag: '.$etag); header('Cache-Control: private, max-age=60');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>['session'=>eventSessionOut($s,$cntHadir),'hadir'=>$hadir,'tidak_absen'=>$tidak,'counts'=>['hadir'=>$cntHadir,'tidak_absen'=>$cntTidak,'terdaftar'=>$cntTerdaftar]],'meta'=>['page'=>$pg,'limit'=>$lim]]);
}
if($uri==='/me/attendance-summary' && $method==='GET'){
  requireRole('siswa');
  $uid=currentUser()['id'];
  // real per-ekskul attendance for this siswa: total_sesi = all schedules for ekskul, hadir = status hadir
  $st=pdo()->prepare("SELECT e.id ekskul_id, e.nama ekskul_nama, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=e.id) AS total_sesi, (SELECT COUNT(*) FROM attendance a JOIN schedules s ON s.id=a.schedule_id WHERE a.user_id=? AND a.status='hadir' AND s.ekskul_id=e.id) AS hadir FROM ekskul e JOIN registrations r ON r.ekskul_id=e.id WHERE r.user_id=? AND r.deleted_at IS NULL AND r.status IN ('diterima','menunggu') GROUP BY e.id");
  $st->execute([$uid,$uid]);
  $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['total_sesi']=(int)$r['total_sesi']; $r['hadir']=(int)$r['hadir']; $r['persen']=$r['total_sesi']? round($r['hadir']/$r['total_sesi']*100,1):0; $r['ekskul_nama']=e($r['ekskul_nama']); } unset($r);
  $etag='"'.md5(json_encode($rows).$uid).'"';
  header('ETag: '.$etag); header('Cache-Control: private, max-age=60');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}

// === NOTIFICATIONS Queue Bell 30s + cron H-1 ===
if($uri==='/notifications' && $method==='GET'){
  requireLogin(); $uid=currentUser()['id'];
  // auto-generate H-1 notifications for events user joined where event tanggal = tomorrow
  try{
    $tomorrow=date('Y-m-d', strtotime('+1 day'));
    $st=pdo()->prepare("SELECT e.id,e.nama,e.tanggal,e.waktu FROM events e JOIN event_participants p ON p.event_id=e.id WHERE p.user_id=? AND e.tanggal=? AND e.deleted_at IS NULL"); $st->execute([$uid,$tomorrow]);
    foreach($st->fetchAll() as $ev){
      $chk=pdo()->prepare("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"); $chk->execute([$uid,'%H-1%'.$ev['id'].'%']);
      if(!$chk->fetch()){
        try{ pdo()->prepare("INSERT INTO notifications(user_id,title,message,type) VALUES (?,?,?,?)")->execute([$uid, 'H-1 Event: '.$ev['nama'].' #'.$ev['id'], 'Event '.$ev['nama'].' besok '.$ev['tanggal'].' jam '.$ev['waktu'].' — jangan lupa hadir!', 'reminder']); }catch(Exception $e){}
        try{ pdo()->prepare("INSERT INTO notifications(user_id,title,message,type) VALUES (?,?,?,?)")->execute([$uid, 'H-1 Event: '.$ev['nama'], 'Event '.$ev['nama'].' besok '.$ev['tanggal'].' jam '.$ev['waktu']]); }catch(Exception $e){}
      }
    }
  }catch(Exception $e){}
  $st=pdo()->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY is_read ASC, created_at DESC LIMIT 20"); $st->execute([$uid]); $rows=$st->fetchAll();
  // deep-link per tipe: FE klik -> halaman reset khusus (bukan modal query).
  foreach($rows as &$r){
    $r['link']=null;
    if(($r['type']??'')==='forgot_password' && !empty($r['related_id'])) $r['link']='/admin/users/'.(int)$r['related_id'].'/reset';
  }
  unset($r);
  $unread=0; foreach($rows as $r) if(!$r['is_read']) $unread++;
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['unread'=>$unread]]);
}
if(routeMatch('/notifications/:id/read',$uri,$pm) && $method==='POST'){
  requireLogin(); pdo()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$pm['id'], currentUser()['id']]); jsonOut(['success'=>true]);
}
if($uri==='/notifications/read-all' && $method==='POST'){
  requireLogin(); pdo()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([currentUser()['id']]); jsonOut(['success'=>true]);
}
if($uri==='/events/check-conflict' && $method==='GET'){
  $tanggal=trim($_GET['tanggal']??''); $waktu=trim($_GET['waktu']??$_GET['jam_mulai']??''); $waktuSelesai=trim($_GET['waktu_selesai']??$_GET['jam_selesai']??'');
  if(!$tanggal || !$waktu) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tanggal & waktu wajib']],422);
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tanggal)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tanggal YYYY-MM-DD']],422);
  $wsEf=$waktuSelesai ?: date('H:i:s', strtotime($waktu.' +1 hour'));
  $q=pdo()->prepare("(SELECT e.nama, s.jam_mulai, s.jam_selesai, s.lokasi, 'jadwal' as src FROM schedules s JOIN ekskul e ON e.id=s.ekskul_id WHERE s.tanggal=? AND NOT (s.jam_selesai <= ? OR s.jam_mulai >= ?)) UNION ALL (SELECT nama, waktu as jam_mulai, COALESCE(waktu_selesai, ADDTIME(waktu,'01:00:00')) as jam_selesai, lokasi, 'event' as src FROM events WHERE tanggal=? AND status='approved' AND deleted_at IS NULL AND NOT (COALESCE(waktu_selesai, ADDTIME(waktu,'01:00:00')) <= ? OR waktu >= ?))");
  $q->execute([$tanggal,$waktu,$wsEf, $tanggal,$waktu,$wsEf]);
  $conf=$q->fetchAll();
  jsonOut(['success'=>true,'data'=>['conflicts'=>$conf,'has_conflict'=>count($conf)>0]]);
}
if($uri==='/events/export' && $method==='GET'){
  requireLogin();
  if(!in_array(currentUser()['role'],['admin','kepsek','pembina'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
  if(ob_get_level()) @ob_end_clean(); header_remove('Content-Type');
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="events-global-'.date('Ymd').'.csv"'); header('Cache-Control: no-store');
  echo chr(0xEF).chr(0xBB).chr(0xBF);
  $out=fopen('php://output','w'); fputcsv($out,['id','nama','tanggal','waktu','lokasi','kuota','terisi','status','creator']);
  $pdoEv=pdo(); try{ $pdoEv->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),false); }catch(Exception $e){}
  $st=$pdoEv->query("SELECT e.id,e.nama,e.tanggal,e.waktu,e.lokasi,e.kuota,e.status, u.nama creator, COALESCE(pcf.terisi,0) as terisi FROM events e LEFT JOIN users u ON u.id=e.created_by LEFT JOIN (SELECT p.event_id, COUNT(*) AS terisi FROM event_participants p GROUP BY p.event_id) pcf ON pcf.event_id=e.id WHERE e.deleted_at IS NULL ORDER BY e.tanggal ASC");
  while($r=$st->fetch(PDO::FETCH_ASSOC)){ fputcsv($out, [$r['id'],$r['nama'],$r['tanggal'],$r['waktu'],$r['lokasi']??'',$r['kuota'],$r['terisi'],$r['status'],$r['creator']??'']); }
  fclose($out); exit;
}
// === KALENDER TERPUSAT — Opsi A (single UNION ALL + PHP conflict + ETag 304 + rate 60/min + scoped) ===
if($uri==='/kalender' && $method==='GET'){
  // --- rate limit 60/min per IP (file cache + flock) - FIX5 flock anti race ---
  $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
  $rlFile = sys_get_temp_dir().'/kalender_rl_'.md5($ip).'.json';
  $now=time(); $cnt=0; $winStart=$now;
  $fh=@fopen($rlFile,'c+');
  if($fh){
    if(@flock($fh, LOCK_EX)){
      $rawStr=@stream_get_contents($fh);
      $raw=$rawStr?@json_decode($rawStr,true):null;
      if($raw && isset($raw['start']) && ($now - (int)$raw['start'] < 60)){
        $cnt=(int)($raw['count']??0); $winStart=(int)$raw['start'];
      } else { $cnt=0; $winStart=$now; }
      if($cnt>=60){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu banyak request, coba lagi 1 menit']],429); }
      @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt+1,'start'=>$winStart])); @fflush($fh); @flock($fh,LOCK_UN);
    }
    @fclose($fh);
  } else {
    // fallback tanpa flock (shared hosting lock unavailable)
    if(file_exists($rlFile)){
      $raw=@json_decode(@file_get_contents($rlFile),true);
      if($raw && isset($raw['start']) && ($now - (int)$raw['start'] < 60)){ $cnt=(int)($raw['count']??0); $winStart=(int)$raw['start']; }
    }
    if($cnt>=60){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu banyak request, coba lagi 1 menit']],429); }
    @file_put_contents($rlFile, json_encode(['count'=>$cnt+1,'start'=>$winStart]));
  }
  // --- validate bulan YYYY-MM strict ---
  $bulan=trim($_GET['bulan']??'');
  if($bulan==='') $bulan=date('Y-m');
  if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$bulan)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'bulan harus YYYY-MM (01-12)']],422);
  $startDate=$bulan.'-01';
  $endDate=date('Y-m-t', strtotime($startDate));
  if(!strtotime($startDate)||!strtotime($endDate)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'bulan tidak valid']],422);
  $cu=currentUser();
  $isPembina = $cu && $cu['role']==='pembina';
  $isSiswaCal = $cu && $cu['role']==='siswa';
  // Server cache 60s (file/APCu) — HANYA payload final. Rate-limit di atas TETAP jalan (security).
  $cacheKey = cacheKey('kalender', [$bulan, $cu['id']??'guest', $cu['role']??'guest']);
  $cachedJson = cacheGet($cacheKey, 60);
  if($cachedJson !== null){
    $etag='"'.md5($cachedJson).'"';
    header('ETag: '.$etag);
    header('Cache-Control: private, max-age=60, stale-while-revalidate=300');
    header('Vary: Cookie');
    header('Content-Type: application/json');
    if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
    echo $cachedJson; exit;
  }
  $pdo=pdo();
  // --- single UNION ALL: schedules(join ekspembina) + events ---
  // SELECT only needed cols, LIMIT 200, ORDER BY tanggal,jam_mulai
  // Personal: siswa → hanya ekskul yang diikuti (registrations status diterima/menunggu, deleted_at IS NULL) + events global
  //           pembina → hanya ekskul yang dibina (e.pembina_id) + events global
  //           admin/kepsek/guest → semua approved
  $schedWhere="e.status='approved' AND s.tanggal BETWEEN :start AND :end";
  $eventWhere="e2.status='approved' AND e2.tanggal BETWEEN :start2 AND :end2";
  $par=[];
  $schedSql="SELECT s.id, s.ekskul_id, s.tanggal, s.jam_mulai, s.jam_selesai, s.lokasi, s.tipe, e.nama AS ekskul_nama, u.nama AS pembina_nama, e.kuota, (SELECT COUNT(*) FROM registrations r WHERE r.ekskul_id=s.ekskul_id AND r.status IN ('diterima','menunggu') AND r.deleted_at IS NULL) AS terisi, s.created_at, e.updated_at AS ekskul_updated FROM schedules s JOIN ekskul e ON e.id=s.ekskul_id LEFT JOIN users u ON u.id=e.pembina_id WHERE $schedWhere";
  $eventSql="SELECT e2.id, NULL AS ekskul_id, e2.tanggal, e2.waktu AS jam_mulai, COALESCE(e2.waktu_selesai, ADDTIME(e2.waktu,'01:00:00')) AS jam_selesai, e2.lokasi, 'event' AS tipe, e2.nama AS ekskul_nama, u2.nama AS pembina_nama, e2.kuota, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id=e2.id) AS terisi, e2.created_at, e2.updated_at AS ekskul_updated FROM events e2 LEFT JOIN users u2 ON u2.id=e2.created_by WHERE $eventWhere";
  if($isSiswaCal){
    $schedSql.=" AND s.ekskul_id IN (SELECT ekskul_id FROM registrations WHERE user_id=:siswaUid AND deleted_at IS NULL AND status='diterima')";
  } else if($isPembina){
    $schedSql.=" AND e.pembina_id=:uid";
    // note: events tetap global untuk kalender; scoped hanya schedules
  }
  $unionSql="($schedSql) UNION ALL ($eventSql) ORDER BY tanggal ASC, jam_mulai ASC LIMIT 500";
  $st=$pdo->prepare($unionSql);
  $st->bindValue(':start',$startDate);
  $st->bindValue(':end',$endDate);
  $st->bindValue(':start2',$startDate);
  $st->bindValue(':end2',$endDate);
  if($isSiswaCal) $st->bindValue(':siswaUid',$cu['id'], PDO::PARAM_INT);
  if($isPembina) $st->bindValue(':uid',$cu['id'], PDO::PARAM_INT);
  $st->execute();
  $rows=$st->fetchAll();
  // --- split rutin/tambahan/events (keep raw for conflicts) ---
  $rutin=[]; $tambahan=[]; $eventsArr=[];
  foreach($rows as $r){
    // XSS escape text fields for JSON consumers that dangerouslySetInnerHTML (defense)
    $r['ekskul_nama']=e($r['ekskul_nama']);
    $r['pembina_nama']=$r['pembina_nama']?e($r['pembina_nama']):null;
    $r['lokasi']=$r['lokasi']?e($r['lokasi']):null;
    $r['terisi']=(int)($r['terisi']??0);
    $r['kuota']=(int)($r['kuota']??0);
    $r['sisa']=max(0, $r['kuota'] - $r['terisi']);
    if($r['tipe']==='rutin') $rutin[]=$r;
    else if($r['tipe']==='tambahan') $tambahan[]=$r;
    else $eventsArr[]=$r;
  }
  // --- conflict detection PHP O(n log n) per tanggal (correct overlap, not COUNT>1) ---
  $byDate=[];
  foreach($rows as $r){ $byDate[$r['tanggal']][]=$r; }
  $conflicts=[];
  foreach($byDate as $d=>$list){
    usort($list,function($a,$b){ return strcmp($a['jam_mulai'],$b['jam_mulai']); });
    for($i=1;$i<count($list);$i++){
      $prev=$list[$i-1]; $cur=$list[$i];
      // overlap if cur.jam_mulai < prev.jam_selesai (and not touching)
      if(strcmp($cur['jam_mulai'],$prev['jam_selesai'])<0){
        $conflicts[]=[
          'tanggal'=>$d,
          'a'=>['id'=>$prev['id'],'tipe'=>$prev['tipe'],'nama'=>$prev['ekskul_nama'],'jam'=>$prev['jam_mulai'].'-'.$prev['jam_selesai'],'lokasi'=>$prev['lokasi']],
          'b'=>['id'=>$cur['id'],'tipe'=>$cur['tipe'],'nama'=>$cur['ekskul_nama'],'jam'=>$cur['jam_mulai'].'-'.$cur['jam_selesai'],'lokasi'=>$cur['lokasi']],
        ];
      }
    }
  }
  $data=['bulan'=>$bulan,'start'=>$startDate,'end'=>$endDate,'rutin'=>$rutin,'tambahan'=>$tambahan,'events'=>$eventsArr,'conflicts'=>$conflicts, 'all'=>$rows];
  // Server cache 60s: simpan PAYLOAD final (setelah XSS-escape + conflict detection selesai) — bukan cache di tengah proses.
  // TODO V1.1: cross-midnight (jam_selesai < jam_mulai) & filter pembina/lokasi sama belum ditangani — tunda, current overlap cukup untuk shared hosting MVP
  $outJson = json_encode(['success'=>true,'data'=>$data,'meta'=>['total'=>count($rows),'bulan'=>$bulan,'conflicts'=>count($conflicts)]], JSON_UNESCAPED_UNICODE);
  cacheSet($cacheKey, $outJson, 60);
  $etag='"'.md5($outJson).'"';
  header('ETag: '.$etag);
  header('Cache-Control: private, max-age=60, stale-while-revalidate=300');
  header('Vary: Cookie');
  header('Content-Type: application/json');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  // payload <50KB guard: LIMIT 200 already
  echo $outJson; exit;
}

// === ANNOUNCEMENTS ===
if($uri==='/announcements' && $method==='GET'){
  $st=pdo()->query('SELECT a.*, u.nama creator FROM announcements a LEFT JOIN users u ON u.id=a.created_by ORDER BY a.created_at DESC');
  jsonOut(['success'=>true,'data'=>$st->fetchAll()]);
}
if($uri==='/announcements' && $method==='POST'){
  requireRole('admin'); $b=getBody();
  if(empty($b['judul'])||empty($b['isi'])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'judul & isi wajib']],422);
  pdo()->prepare('INSERT INTO announcements(judul,isi,created_by) VALUES (?,?,?)')->execute([$b['judul'],$b['isi'],currentUser()['id']]);
  $annId=(int)pdo()->lastInsertId();
  try{ notifyAdmins('Pengumuman baru: '.mb_substr($b['judul'],0,40), '\"'.mb_substr($b['isi'],0,80).'\" — oleh '.(currentUser()['nama']??'Admin'), 'pengumuman', 'pengumuman:daily:'.date('Y-m-d'), $annId); }catch(Exception $e){}
  try{ notifyKepsek('Pengumuman baru: '.mb_substr($b['judul'],0,40), '\"'.mb_substr($b['isi'],0,80).'\"', 'pengumuman', 'pengumuman:daily:'.date('Y-m-d'), $annId); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['id'=>$annId]],201);
}

// === EKSKUL PENGUMUMAN — per-ekskul, pembina-only POST, scoped, ETag+Cache ===
if(routeMatch('/ekskul/:id/pengumuman',$uri,$pm) && $method==='GET'){
  $eid=(int)$pm['id'];
  $ek=pdo()->prepare('SELECT status, pembina_id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $ek->execute([$eid]); $erow=$ek->fetch();
  if(!$erow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $cu=currentUser();
  if(!$cu || $cu['role']==='siswa'){
    if($erow['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  } else if($cu['role']==='pembina'){
    if((int)$erow['pembina_id'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  }
  $st=pdo()->prepare('SELECT p.*, u.nama creator_nama, u.role creator_role, p.created_by AS creator_id FROM ekskul_pengumuman p LEFT JOIN users u ON u.id=p.created_by WHERE p.ekskul_id=? ORDER BY p.is_pinned DESC, p.created_at DESC LIMIT 50'); $st->execute([$eid]); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['isi']=e($r['isi']); $r['creator_nama']=$r['creator_nama']?e($r['creator_nama']):null; $r['creator_role']=$r['creator_role']??null; $has=!empty($r['gambar_path']); $r['has_gambar']=$has; $r['gambar_url']=$has?'/api/ekskul/'.$eid.'/pengumuman/'.$r['id'].'/gambar':null; if(isset($r['gambar_path'])) unset($r['gambar_path']); if(isset($r['gambar_mime'])) unset($r['gambar_mime']); if(isset($r['gambar_size'])) unset($r['gambar_size']); } unset($r);
  $etag='"'.md5(json_encode($rows).$eid).'"';
  header('ETag: '.$etag); header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}
// GET pengumuman gambar — hidden-by-default, siswa klik show baru fetch (auth: anggota/pembina/admin)
if(routeMatch('/ekskul/:id/pengumuman/:pid/gambar',$uri,$pm) && $method==='GET'){
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  $ek=pdo()->prepare('SELECT status, pembina_id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $ek->execute([$eid]); $erow=$ek->fetch();
  if(!$erow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $cu=currentUser();
  if(!$cu || $cu['role']==='siswa'){
    if($erow['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  } else if($cu['role']==='pembina'){
    if((int)$erow['pembina_id'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  }
  // anggota guard: siswa harus terdaftar/anggota, pembina/admin bypass
  if($cu && $cu['role']==='siswa'){
    $chk=pdo()->prepare('SELECT 1 FROM registrations WHERE ekskul_id=? AND user_id=? AND deleted_at IS NULL AND status IN ("diterima","menunggu") LIMIT 1'); $chk->execute([$eid,$cu['id']]); if(!$chk->fetch()){ $chk2=pdo()->prepare('SELECT 1 FROM ekskul WHERE id=? AND pembina_id=?'); $chk2->execute([$eid,$cu['id']]); if(!$chk2->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403); }
  }
  $st=pdo()->prepare('SELECT gambar_path, gambar_mime FROM ekskul_pengumuman WHERE id=? AND ekskul_id=?'); $st->execute([$pid,$eid]); $row=$st->fetch(); if(!$row || empty($row['gambar_path'])) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Gambar tidak ada']],404);
  $path=uploadPath($row['gambar_path']); if(!file_exists($path)) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'File hilang']],404);
  $mime=$row['gambar_mime'] ?: (mime_content_type($path) ?: 'image/jpeg');
  header('Content-Type: '.$mime); header('Cache-Control: private, max-age=3600'); header('Content-Length: '.filesize($path));
  readfile($path); exit;
}
if(routeMatch('/ekskul/:id/pengumuman',$uri,$pm) && $method==='POST'){
  requireLogin();
  $eid=(int)$pm['id'];
  if(!isPembinaOf($eid) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya Pembina/Admin ekskul ini']],403);
  $b=getBody();
  // support FormData (multipart) — isi may be in $_POST
  if(isset($_POST['isi']) && trim($_POST['isi'])!=='') $b['isi']=$_POST['isi'];
  if(isset($_POST['content']) && empty($b['isi'])) $b['isi']=$_POST['content'];
  if(isset($_POST['is_pinned'])) $b['is_pinned']=$_POST['is_pinned'];
  $isi=trim($b['isi']??$b['content']??'');
  if(mb_strlen($isi)<3) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pengumuman minimal 3 karakter']],422);
  if(mb_strlen($isi)>2000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pengumuman maksimal 2000 karakter']],422);
  $isPinned = isset($b['is_pinned']) ? (int)!empty($b['is_pinned']) : 1;
  // handle optional gambar upload (hidden-by-default for siswa)
  $gambarPath=null; $gambarMime=null; $gambarSize=null;
  $file=null;
  if(!empty($_FILES['gambar']) && ($_FILES['gambar']['error']??1)===0) $file=$_FILES['gambar'];
  else if(!empty($_FILES['image']) && ($_FILES['image']['error']??1)===0) $file=$_FILES['image'];
  if($file){
    if($file['size']>2*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gambar maksimal 2MB']],422);
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    $allowedExt=['jpg','jpeg','png','webp']; if(!in_array($ext,$allowedExt,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gambar hanya JPG/PNG/WEBP']],422);
    $mime=mime_content_type($file['tmp_name']) ?: $file['type'];
    $allowedMime=['image/jpeg','image/png','image/webp']; if(!in_array($mime,$allowedMime,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tipe gambar tidak diizinkan']],422);
    $baseDir=uploadPath('pengumuman_'.$eid); if(!is_dir($baseDir)) @mkdir($baseDir,0775,true);
    $safe=preg_replace('/[^A-Za-z0-9_\-]/','_', pathinfo($file['name'],PATHINFO_FILENAME));
    $stored=$baseDir.'/'.uniqid('peng_').'_'.$safe.'.'.$ext;
    if(!@move_uploaded_file($file['tmp_name'],$stored)){ @copy($file['tmp_name'],$stored); }
    $gambarPath='uploads/pengumuman_'.$eid.'/'.basename($stored); $gambarMime=$mime; $gambarSize=(int)$file['size'];
  }
  // anti-spam: rate 10/min per user per ekskul (file)
  $rlKey='pengumuman_'.$eid.'_'.currentUser()['id']; $ip=$_SERVER['REMOTE_ADDR']??'cli'; $f=sys_get_temp_dir().'/rl_'.$rlKey.'_'.md5($ip).'.json'; $now=time(); $cnt=0; $win=$now;
  if(file_exists($f)){ $j=@json_decode(@file_get_contents($f),true); if($j && ($now - (int)($j['start']??0) < 60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
  if($cnt>=10){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering posting, coba 1 menit']],429); }
  @file_put_contents($f, json_encode(['count'=>$cnt+1,'start'=>$win]));
  try{ pdo()->prepare('INSERT INTO ekskul_pengumuman(ekskul_id,isi,is_pinned,created_by,gambar_path,gambar_mime,gambar_size) VALUES (?,?,?,?,?,?,?)')->execute([$eid,$isi,$isPinned,currentUser()['id'],$gambarPath,$gambarMime,$gambarSize]); }catch(Exception $e){
    // fallback if columns not yet migrated (old DB)
    pdo()->prepare('INSERT INTO ekskul_pengumuman(ekskul_id,isi,is_pinned,created_by) VALUES (?,?,?,?)')->execute([$eid,$isi,$isPinned,currentUser()['id']]);
  }
  $id=pdo()->lastInsertId();
  try{
    $ekName=pdo()->prepare('SELECT nama FROM ekskul WHERE id=?'); $ekName->execute([$eid]); $en=$ekName->fetchColumn()?:('Ekskul #'.$eid);
    $gk='pengumuman:ekskul:'.$eid.':'.date('Y-m-d');
    notifyAdmins('Pengumuman ekskul: '.$en, '\"'.mb_substr($isi,0,80).'\" — '.(currentUser()['nama']??'Pembina'), 'pengumuman', $gk, $id);
  }catch(Exception $e){}
  $row=pdo()->prepare('SELECT p.*, u.nama creator_nama, u.role creator_role, p.created_by AS creator_id FROM ekskul_pengumuman p LEFT JOIN users u ON u.id=p.created_by WHERE p.id=?'); $row->execute([$id]); $r=$row->fetch();
  $r['isi']=e($r['isi']); $r['creator_nama']=$r['creator_nama']?e($r['creator_nama']):null; $r['creator_role']=$r['creator_role']??null; $has=!empty($r['gambar_path']); $r['has_gambar']=$has; $r['gambar_url']=$has?'/api/ekskul/'.$eid.'/pengumuman/'.$r['id'].'/gambar':null; if(isset($r['gambar_path'])) unset($r['gambar_path']); if(isset($r['gambar_mime'])) unset($r['gambar_mime']); if(isset($r['gambar_size'])) unset($r['gambar_size']);
  jsonOut(['success'=>true,'data'=>$r],201);
}
if(routeMatch('/ekskul/:id/pengumuman/:pid',$uri,$pm) && ($method==='PATCH' || $method==='PUT' || $method==='POST')){
  requireLogin();
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!isPembinaOf($eid) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya Pembina/Admin']],403);
  $st=pdo()->prepare('SELECT * FROM ekskul_pengumuman WHERE id=? AND ekskul_id=?'); $st->execute([$pid,$eid]); $existing=$st->fetch(); if(!$existing) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Pengumuman tidak ada']],404);
  $b=getBody();
  // PATCH + multipart/form-data tidak mengisi $_POST/$_FILES di PHP — parse manual agar isi+gambar tidak hilang
  if(in_array($method,['PATCH','PUT']) && empty($_POST)){
    $ct=$_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if(stripos($ct,'multipart/form-data')!==false && preg_match('/boundary=([^;]+)/',$ct,$mm)){
      $boundary=trim($mm[1],'"'); $raw=@file_get_contents('php://input');
      if($raw && $boundary){ $parts=explode('--'.$boundary,$raw); foreach($parts as $part){ if(strpos($part,'Content-Disposition')===false) continue; if(!preg_match('/name=\"([^\"]+)\"/',$part,$nm)) continue; $name=$nm[1]; if(strpos($part,'filename=')!==false){ preg_match('/filename=\"([^\"]*)\"/',$part,$fm); $filename=$fm[1]??'upload'; $pos=strpos($part,"\r\n\r\n"); if($pos!==false){ $content=substr($part,$pos+4); $content=rtrim($content,"\r\n"); $tmp=tempnam(sys_get_temp_dir(),'patch_'); @file_put_contents($tmp,$content); $mime='application/octet-stream'; if(preg_match('/Content-Type:\s*([^\r\n]+)/i',$part,$cm)) $mime=trim($cm[1]); $_FILES[$name]=['name'=>$filename,'type'=>$mime,'tmp_name'=>$tmp,'error'=>0,'size'=>strlen($content)]; } } else { $pos=strpos($part,"\r\n\r\n"); if($pos!==false){ $val=substr($part,$pos+4); $val=rtrim($val,"\r\n"); $_POST[$name]=$val; } } } }
    }
  }
  if(isset($_POST['isi']) && trim($_POST['isi'])!=='') $b['isi']=$_POST['isi'];
  if(isset($_POST['content']) && empty($b['isi'])) $b['isi']=$_POST['content'];
  if(isset($_POST['remove_gambar'])) $b['remove_gambar']=$_POST['remove_gambar'];
  $isi=trim($b['isi']??$b['content']??'');
  if(mb_strlen($isi)<3) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pengumuman minimal 3 karakter']],422);
  if(mb_strlen($isi)>2000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pengumuman maksimal 2000 karakter']],422);
  // handle gambar: new upload replaces, remove_gambar clears
  $file=null;
  if(!empty($_FILES['gambar']) && ($_FILES['gambar']['error']??1)===0) $file=$_FILES['gambar'];
  else if(!empty($_FILES['image']) && ($_FILES['image']['error']??1)===0) $file=$_FILES['image'];
  $removeGambar = !empty($b['remove_gambar']) && ($b['remove_gambar']=='1' || $b['remove_gambar']=='true');
  $gambarPath=$existing['gambar_path']??null; $gambarMime=$existing['gambar_mime']??null; $gambarSize=$existing['gambar_size']??null;
  if($file){
    if($file['size']>2*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gambar maksimal 2MB']],422);
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    $allowedExt=['jpg','jpeg','png','webp']; if(!in_array($ext,$allowedExt,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gambar hanya JPG/PNG/WEBP']],422);
    $mime=mime_content_type($file['tmp_name']) ?: $file['type'];
    $allowedMime=['image/jpeg','image/png','image/webp']; if(!in_array($mime,$allowedMime,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tipe gambar tidak diizinkan']],422);
    $baseDir=uploadPath('pengumuman_'.$eid); if(!is_dir($baseDir)) @mkdir($baseDir,0775,true);
    $safe=preg_replace('/[^A-Za-z0-9_\-]/','_', pathinfo($file['name'],PATHINFO_FILENAME));
    $stored=$baseDir.'/'.uniqid('peng_').'_'.$safe.'.'.$ext;
    if(!@move_uploaded_file($file['tmp_name'],$stored)){ @copy($file['tmp_name'],$stored); }
    // delete old file
    if(!empty($gambarPath)){ $old=uploadPath($gambarPath); if(file_exists($old)) @unlink($old); }
    $gambarPath='uploads/pengumuman_'.$eid.'/'.basename($stored); $gambarMime=$mime; $gambarSize=(int)$file['size'];
  } else if($removeGambar){
    if(!empty($gambarPath)){ $old=uploadPath($gambarPath); if(file_exists($old)) @unlink($old); }
    $gambarPath=null; $gambarMime=null; $gambarSize=null;
  }
  try{
    pdo()->prepare('UPDATE ekskul_pengumuman SET isi=?, gambar_path=?, gambar_mime=?, gambar_size=? WHERE id=? AND ekskul_id=?')->execute([$isi,$gambarPath,$gambarMime,$gambarSize,$pid,$eid]);
  }catch(Exception $e){
    pdo()->prepare('UPDATE ekskul_pengumuman SET isi=? WHERE id=? AND ekskul_id=?')->execute([$isi,$pid,$eid]);
  }
  $row=pdo()->prepare('SELECT p.*, u.nama creator_nama, u.role creator_role, p.created_by AS creator_id FROM ekskul_pengumuman p LEFT JOIN users u ON u.id=p.created_by WHERE p.id=?'); $row->execute([$pid]); $r=$row->fetch();
  $r['isi']=e($r['isi']); $r['creator_nama']=$r['creator_nama']?e($r['creator_nama']):null; $r['creator_role']=$r['creator_role']??null; $has=!empty($r['gambar_path']); $r['has_gambar']=$has; $r['gambar_url']=$has?'/api/ekskul/'.$eid.'/pengumuman/'.$r['id'].'/gambar':null; if(isset($r['gambar_path'])) unset($r['gambar_path']); if(isset($r['gambar_mime'])) unset($r['gambar_mime']); if(isset($r['gambar_size'])) unset($r['gambar_size']);
  jsonOut(['success'=>true,'data'=>$r]);
}
if(routeMatch('/ekskul/:id/pengumuman/:pid',$uri,$pm) && $method==='DELETE'){
  requireLogin();
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!isPembinaOf($eid) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya Pembina/Admin']],403);
  $st=pdo()->prepare('SELECT gambar_path FROM ekskul_pengumuman WHERE id=? AND ekskul_id=?'); $st->execute([$pid,$eid]); $frow=$st->fetch(); if(!$frow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Pengumuman tidak ada']],404);
  pdo()->prepare('DELETE FROM ekskul_pengumuman WHERE id=?')->execute([$pid]);
  if(!empty($frow['gambar_path'])){ $fp=uploadPath($frow['gambar_path']); if(file_exists($fp)) @unlink($fp); }
  jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/ekskul/:id/pengumuman/:pid/pin',$uri,$pm) && $method==='POST'){
  requireLogin();
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!isPembinaOf($eid) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya Pembina/Admin']],403);
  $b=getBody(); $pin = isset($b['is_pinned']) ? (int)!empty($b['is_pinned']) : null;
  if($pin===null){ $cur=pdo()->prepare('SELECT is_pinned FROM ekskul_pengumuman WHERE id=? AND ekskul_id=?'); $cur->execute([$pid,$eid]); $row=$cur->fetch(); if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Pengumuman tidak ada']],404); $pin = $row['is_pinned'] ? 0 : 1; }
  pdo()->prepare('UPDATE ekskul_pengumuman SET is_pinned=? WHERE id=? AND ekskul_id=?')->execute([$pin,$pid,$eid]);
  jsonOut(['success'=>true,'data'=>['is_pinned'=>$pin]]);
}

// === LOGIN — rate limit percobaan gagal (per-IP + per-email, window 300s, max 10) ===
function loginRateLimit($email, $mode='check'){
  $buckets=array(
    'ip_'.md5((string)getRealIp()),
    'mail_'.md5(strtolower(trim((string)$email))),
  );
  $t=time();
  foreach($buckets as $b){
    $file=sys_get_temp_dir().'/login_rl_'.$b.'.json';
    if($mode==='reset'){ @unlink($file); continue; }
    $fh=@fopen($file,'c+'); if(!$fh) continue;
    @flock($fh,LOCK_EX);
    $cnt=0; $win=$t; $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null;
    if($j && isset($j['start']) && ($t-(int)$j['start']<300)){ $cnt=(int)($j['count']??0); $win=(int)$j['start']; }
    if($mode==='fail') $cnt++;
    if($mode==='check' && $cnt>=10){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: '.max(1,300-($t-$win))); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu banyak percobaan login. Coba lagi nanti.']],429); }
    @ftruncate($fh,0); @rewind($fh); @fwrite($fh,json_encode(['count'=>$cnt,'start'=>$win])); @fflush($fh);
    @flock($fh,LOCK_UN); @fclose($fh);
  }
}

// === LAPORAN — Rekap & Export (PRD 40,96,231,68) — Opsi A-A-A (performa+security) ===
function laporanRateLimit($key='laporan'){
  $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';
  $file=sys_get_temp_dir()."/rl_{$key}_".md5($ip).".json";
  $now=time(); $cnt=0; $win=$now;
  $fh=@fopen($file,'c+'); if($fh){ @flock($fh,LOCK_EX); $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null; if($j && isset($j['start']) && ($now - (int)$j['start'] < 60)){ $cnt=(int)($j['count']??0); $win=(int)$j['start']; } else { $cnt=0; $win=$now; } if($cnt>=60){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu banyak request, coba 1 menit']],429); } $cnt++; @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt,'start'=>$win])); @fflush($fh); @flock($fh,LOCK_UN); @fclose($fh); }
}
if($uri==='/laporan/rekap' && $method==='GET'){
  // --- dashboard agregat (keep for Dashboard.vue) ---
  $isDashboard = isset($_GET['dashboard']) && $_GET['dashboard']=='1';
  if($isDashboard){
    requireLogin();
    if(!in_array(currentUser()['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
    $role=currentUser()['role']; $uid=currentUser()['id'];
    $cacheKey="dashboard_rekap_{$role}_{$uid}";
    $cacheFile=sys_get_temp_dir()."/{$cacheKey}.json";
    $useApcu = function_exists('apcu_enabled') && apcu_enabled();
    $cached=null; $cachedTime=0;
    if($useApcu){ $cached=apcu_fetch($cacheKey,$ok); if(!$ok) $cached=null; $cachedTime = $cached['_cached_at'] ?? 0; }
    else if(file_exists($cacheFile)){ $raw=@file_get_contents($cacheFile); $cached=$raw?@json_decode($raw,true):null; $cachedTime=$cached['_cached_at'] ?? (@filemtime($cacheFile) ?: 0); }
    $isFresh = $cached && (time() - (int)$cachedTime < 60);
    if($isFresh){
      $etag='"'.md5(json_encode($cached['data'])).'"';
      header('ETag: '.$etag); header('Cache-Control: private, max-age=60'); header('Content-Type: application/json');
      if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
      if(($_GET['format']??'')==='csv'){
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="rekap-dashboard-'.date('Ymd').'.csv"');
        $out=fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out,['Metrik','Nilai']); foreach($cached['data']['counts'] as $k=>$v) fputcsv($out,[$k,$v]);
        fputcsv($out,[]); fputcsv($out,['Pendaftaran Terbaru','Nama','Ekskul','Status','Tanggal']);
        foreach($cached['data']['recent'] as $r) fputcsv($out,['',$r['nama']??'',$r['ekskul_nama']??'',$r['status']??'',$r['created_at']??'']);
        fputcsv($out,[]); fputcsv($out,['Jadwal Hari Ini','Tanggal','Jam','Lokasi','Sumber','Nama']);
        foreach($cached['data']['today'] as $t) fputcsv($out,['',$t['tanggal']??'',$t['jam_mulai'].'-'.$t['jam_selesai'],$t['lokasi']??'',$t['tipe']??'',$t['nama']??'']);
        fclose($out); exit;
      }
      jsonOut(['success'=>true,'data'=>$cached['data'],'meta'=>['cached'=>true,'etag'=>$etag]]);
    }
    $pdo=pdo();
    $counts=[];
    if($role==='pembina'){
      $st=$pdo->prepare('SELECT COUNT(*) c FROM ekskul WHERE pembina_id=?'); $st->execute([$uid]); $counts['total_ekskul']=(int)($st->fetch()['c'] ?? 0);
      $ids=scopedEkskulIds($uid);
      if($ids){
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $st=$pdo->prepare("SELECT COUNT(*) c FROM registrations WHERE ekskul_id IN ($ph) AND status='menunggu' AND deleted_at IS NULL"); $st->execute($ids); $counts['pending_registrations']=(int)($st->fetch()['c'] ?? 0);
      } else { $counts['pending_registrations']=0; }
    } else {
      $st=$pdo->query('SELECT COUNT(*) c FROM ekskul'); $counts['total_ekskul']=(int)($st->fetch()['c'] ?? 0);
      $st=$pdo->prepare('SELECT COUNT(*) c FROM registrations WHERE status=? AND deleted_at IS NULL'); $st->execute(['menunggu']); $counts['pending_registrations']=(int)($st->fetch()['c'] ?? 0);
    }
    $st=$pdo->query('SELECT COUNT(*) c FROM users WHERE role="siswa"'); $counts['total_siswa']=(int)($st->fetch()['c'] ?? 0);
    $st=$pdo->query('SELECT COUNT(*) c FROM events'); $counts['total_event']=(int)($st->fetch()['c'] ?? 0);
    if($role==='pembina' && !empty($ids)){
      $ph=implode(',',array_fill(0,count($ids),'?'));
      $st=$pdo->prepare("SELECT r.id, r.ekskul_id, r.status, r.created_at, u.nama, e.nama ekskul_nama FROM registrations r JOIN users u ON u.id=r.user_id JOIN ekskul e ON e.id=r.ekskul_id WHERE r.ekskul_id IN ($ph) AND r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 5"); $st->execute($ids); $recent=$st->fetchAll();
    } else {
      $st=$pdo->prepare('SELECT r.id, r.ekskul_id, r.status, r.created_at, u.nama, e.nama ekskul_nama FROM registrations r JOIN users u ON u.id=r.user_id JOIN ekskul e ON e.id=r.ekskul_id WHERE r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 5'); $st->execute(); $recent=$st->fetchAll();
    }
    $todayDate=date('Y-m-d');
    if($role==='pembina' && !empty($ids)){
      $ph=implode(',',array_fill(0,count($ids),'?'));
      $st=$pdo->prepare("SELECT tanggal, jam_mulai, jam_selesai, lokasi, tipe, ekskul_nama nama FROM (SELECT s.tanggal, s.jam_mulai, s.jam_selesai, s.lokasi, s.tipe, e.nama ekskul_nama FROM schedules s JOIN ekskul e ON e.id=s.ekskul_id WHERE s.tanggal=? AND s.ekskul_id IN ($ph) UNION ALL SELECT tanggal, waktu jam_mulai, ADDTIME(waktu,'01:00:00') jam_selesai, lokasi, 'event' tipe, nama ekskul_nama FROM events WHERE tanggal=? AND status='approved') u ORDER BY jam_mulai");
      $st->execute(array_merge([$todayDate], $ids, [$todayDate])); $today=$st->fetchAll();
    } else {
      $st=$pdo->prepare("SELECT tanggal, jam_mulai, jam_selesai, lokasi, tipe, ekskul_nama nama FROM (SELECT s.tanggal, s.jam_mulai, s.jam_selesai, s.lokasi, s.tipe, e.nama ekskul_nama FROM schedules s JOIN ekskul e ON e.id=s.ekskul_id WHERE s.tanggal=? UNION ALL SELECT tanggal, waktu jam_mulai, ADDTIME(waktu,'01:00:00') jam_selesai, lokasi, 'event' tipe, nama ekskul_nama FROM events WHERE tanggal=? AND status='approved') u ORDER BY jam_mulai");
      $st->execute([$todayDate,$todayDate]); $today=$st->fetchAll();
    }
    $data=['counts'=>$counts,'recent'=>$recent,'today'=>$today,'today_date'=>$todayDate];
    $payload=['data'=>$data,'_cached_at'=>time()];
    if($useApcu) apcu_store($cacheKey, $payload, 60);
    else @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $etag='"'.md5(json_encode($data)).'"';
    header('ETag: '.$etag); header('Cache-Control: private, max-age=60'); header('Content-Type: application/json');
    if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
    if(($_GET['format']??'')==='csv'){
      header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="rekap-dashboard-'.date('Ymd').'.csv"');
      $out=fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
      fputcsv($out,['Metrik','Nilai']); foreach($counts as $k=>$v) fputcsv($out,[$k,$v]);
      fputcsv($out,[]); fputcsv($out,['Pendaftaran Terbaru','Nama','Ekskul','Status','Tanggal']);
      foreach($recent as $r) fputcsv($out,['',$r['nama']??'',$r['ekskul_nama']??'',$r['status']??'',$r['created_at']??'']);
      fputcsv($out,[]); fputcsv($out,['Jadwal Hari Ini','Tanggal','Jam','Lokasi','Sumber','Nama']);
      foreach($today as $t) fputcsv($out,['',$t['tanggal']??'',$t['jam_mulai'].'-'.$t['jam_selesai'],$t['lokasi']??'',$t['tipe']??'',$t['nama']??'']);
      fclose($out); exit;
    }
    jsonOut(['success'=>true,'data'=>$data,'meta'=>['cached'=>false,'etag'=>$etag]]);
  }
  // --- NEW: Laporan Rekap (PRD 40,96,231,68) — filter ekskul_id/event_id/from/to + paginate + KPI + ETag + scoped ---
  laporanRateLimit('laporan_rekap');
  requireLogin();
  $cu=currentUser();
  if(!in_array($cu['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak — siswa tidak boleh akses laporan']],403);
  // strict validation — injection OR 1=1 -> 422 (only digits allowed)
  $ekskul_raw = isset($_GET['ekskul_id']) ? trim((string)$_GET['ekskul_id']) : '';
  $event_raw = isset($_GET['event_id']) ? trim((string)$_GET['event_id']) : '';
  // also support ?ekskul= & ?event= aliases from older spec
  if($ekskul_raw==='' && isset($_GET['ekskul'])) $ekskul_raw=trim((string)$_GET['ekskul']);
  if($event_raw==='' && isset($_GET['event'])) $event_raw=trim((string)$_GET['event']);
  if($ekskul_raw!=='' && !ctype_digit($ekskul_raw)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ekskul_id tidak valid']],422);
  if($event_raw!=='' && !ctype_digit($event_raw)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'event_id tidak valid']],422);
  $ekskul_id = $ekskul_raw!=='' ? (int)$ekskul_raw : null;
  $event_id = $event_raw!=='' ? (int)$event_raw : null;
  $from = trim($_GET['from']??'');
  $to = trim($_GET['to']??'');
  // default bulan ini if both empty
  if($from==='' && $to===''){ $from=date('Y-m-01'); $to=date('Y-m-t'); }
  if($from!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || !strtotime($from))) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'from harus YYYY-MM-DD']],422);
  if($to!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to) || !strtotime($to))) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'to harus YYYY-MM-DD']],422);
  if($from!=='' && $to!=='' && strtotime($from) > strtotime($to)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'from tidak boleh > to']],422);
  if($from==='') $from='1970-01-01';
  if($to==='') $to=date('Y-m-d');
  // pagination: default 50 max 100 (allowlist 20/50/100 for back-compat callers eksplisit)
  $rawLimit=(int)($_GET['limit']??50); $allowedLimits=[20,50,100];
  if(!in_array($rawLimit,$allowedLimits,true)) $rawLimit = $rawLimit<=20?20:($rawLimit<=50?50:100);
  $limit=$rawLimit; $page=max(1,(int)($_GET['page']??1)); $off=($page-1)*$limit;
  // search/sort/kelas params (server-side, PDO prepared)
  $qSearch=trim($_GET['q']??$_GET['search']??'');
  $kelasFilter=trim($_GET['kelas']??'');
  $kehadiranFilter=trim($_GET['kehadiran']??''); // hadir|alpa|izin|all
  $sortParam=trim($_GET['sort']??'nama_asc');
  // scoped pembina
  if($cu['role']==='pembina'){
    if($ekskul_id){
      $chk=pdo()->prepare('SELECT 1 FROM ekskul WHERE id=? AND pembina_id=? AND deleted_at IS NULL'); $chk->execute([$ekskul_id,$cu['id']]);
      if(!$chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak akses ekskul ini (scoped pembina)']],403);
    }
    if($event_id){
      $chk=pdo()->prepare('SELECT 1 FROM events WHERE id=? AND created_by=? AND deleted_at IS NULL'); $chk->execute([$event_id,$cu['id']]);
      if(!$chk->fetch()){
        // also allow if event taut ke ekskul binaan via event_ekskul
        $chk2=null;
        try{ $chk2=pdo()->prepare('SELECT 1 FROM event_ekskul ee JOIN ekskul e ON e.id=ee.ekskul_id WHERE ee.event_id=? AND e.pembina_id=? AND e.deleted_at IS NULL LIMIT 1'); $chk2->execute([$event_id,$cu['id']]); }catch(Exception $e){ $chk2=null; }
        if(!$chk2 || !$chk2->fetch()){
          $ex=pdo()->prepare('SELECT 1 FROM events WHERE id=? AND deleted_at IS NULL'); $ex->execute([$event_id]);
          if($ex->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak akses event ini (scoped pembina — bukan creator & bukan event binaan)']],403);
        }
      }
    }
  }
  // exists check -> 422 if id not found
  if($ekskul_id){
    $e=pdo()->prepare('SELECT id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $e->execute([$ekskul_id]);
    if(!$e->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ekskul_id tidak ditemukan']],422);
  }
  if($event_id){
    $e=pdo()->prepare('SELECT id FROM events WHERE id=? AND deleted_at IS NULL'); $e->execute([$event_id]);
    if(!$e->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'event_id tidak ditemukan']],422);
  }
  // legacy format=csv (old spec) — redirect to export
  if(($_GET['format']??'')==='csv'){
    jsonOut(['success'=>false,'error'=>['code'=>'MOVED','message'=>'Gunakan GET /api/laporan/export?tipe=ekskul|event&id=&from=&to=']],422);
  }
  $pdo=pdo();
  $ekskulRows=[]; $eventRows=[]; $totalEkskul=0; $totalEvent=0;
  $weightedPersen=0; $chartData=null; $rankingData=[]; $alertData=[];
  $sesiCnt=0; $sumH=0; $den=0; // init: dipakai KPI walau tanpa ekskul_id (fix 500 event-only)
  // helper to build ekskul where
  if($ekskul_id){
    // total before filter (for KPI weighted denominator) — but filtered total for pagination
    // count with search/kelas filter
    $where='r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu")';
    $cntPar=[$ekskul_id];
    if($qSearch!==''){ $where.=' AND (u.nama LIKE ? OR u.email LIKE ?)'; $like='%'.$qSearch.'%'; $cntPar[]=$like; $cntPar[]=$like; }
    if($kelasFilter!=='' && $kelasFilter!=='all'){ $where.=' AND u.kelas=?'; $cntPar[]=$kelasFilter; }
    $ctSql="SELECT COUNT(*) c FROM users u JOIN registrations r ON r.user_id=u.id AND $where";
    // need to handle where contains r.ekskul_id — adjust
    // simpler: count via same join
    $ct=pdo()->prepare($ctSql); $ct->execute($cntPar); $totalEkskul=(int)($ct->fetch()['c']??0);
    // fetch rows with attendance breakdown
    $sortMap=['nama_asc'=>'u.nama ASC','nama_desc'=>'u.nama DESC','persen_desc'=>'persen DESC, u.nama ASC','persen_asc'=>'persen ASC, u.nama ASC','kelas_asc'=>'u.kelas ASC, u.nama ASC','kelas_desc'=>'u.kelas DESC, u.nama ASC'];
    $orderSql=$sortMap[$sortParam]??'u.nama ASC';
    // Build having filter for kehadiran
    $having='';
    if(in_array($kehadiranFilter,['hadir','alpa','izin'],true)){
      // hadir = persen >=75, alpa = persen <50, izin = 50-74
      if($kehadiranFilter==='hadir') $having=' HAVING persen >= 75';
      else if($kehadiranFilter==='alpa') $having=' HAVING persen < 50';
      else $having=' HAVING persen >= 50 AND persen < 75';
    }
    // Main query: per-user total_sesi, hadir, alpa, izin, persen
    // Use LEFT JOIN attendance with sub filtered schedule ids
    $q="SELECT u.id, u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COALESCE(SUM(CASE WHEN a.status='hadir' THEN 1 ELSE 0 END),0) AS hadir, COALESCE(SUM(CASE WHEN a.status='alpa' THEN 1 ELSE 0 END),0) AS alpa, COALESCE(SUM(CASE WHEN a.status='izin' THEN 1 ELSE 0 END),0) AS izin, CASE WHEN (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) >0 THEN ROUND(COALESCE(SUM(CASE WHEN a.status='hadir' THEN 1 ELSE 0 END),0) *100.0 / (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?),1) ELSE 0 END AS persen FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN (\"diterima\",\"menunggu\") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) WHERE 1=1";
    $parMain=[$ekskul_id,$from,$to, $ekskul_id,$from,$to, $ekskul_id,$from,$to, $ekskul_id, $ekskul_id,$from,$to];
    if($qSearch!==''){ $q.=' AND (u.nama LIKE ? OR u.email LIKE ?)'; $like='%'.$qSearch.'%'; $parMain[]=$like; $parMain[]=$like; }
    if($kelasFilter!=='' && $kelasFilter!=='all'){ $q.=' AND u.kelas=?'; $parMain[]=$kelasFilter; }
    $q.=' GROUP BY u.id, u.nama, u.email, u.kelas'.$having.' ORDER BY '.$orderSql.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
    $st=$pdo->prepare($q); $st->execute($parMain);
    $rows=$st->fetchAll();
    foreach($rows as &$r){
      $r['total_sesi']=(int)$r['total_sesi']; $r['hadir']=(int)$r['hadir']; $r['alpa']=(int)$r['alpa']; $r['izin']=(int)$r['izin']; $r['persen']=(float)$r['persen'];
      $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['kelas']=$r['kelas']?e($r['kelas']):null;
    } unset($r);
    $ekskulRows=$rows;
    // KPI weighted jujur: total hadir / total sesi across ALL filtered users (not just page)
    $sumH=0; $den=0; $sesiCnt=0;
    try{
      $qW="SELECT COALESCE(SUM(CASE WHEN a.status='hadir' THEN 1 ELSE 0 END),0) AS sum_hadir, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) * COUNT(DISTINCT u.id) AS denom FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN (\"diterima\",\"menunggu\") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) WHERE 1=1";
      $pW=[$ekskul_id,$from,$to, $ekskul_id, $ekskul_id,$from,$to];
      if($qSearch!==''){ $qW.=' AND (u.nama LIKE ? OR u.email LIKE ?)'; $qW.=""; $pW[]='%'.$qSearch.'%'; $pW[]='%'.$qSearch.'%'; }
      if($kelasFilter!=='' && $kelasFilter!=='all'){ $qW.=' AND u.kelas=?'; $pW[]=$kelasFilter; }
      $stW=$pdo->prepare($qW); $stW->execute($pW); $w=$stW->fetch();
      $sumH=(int)($w['sum_hadir']??0); $den=(int)($w['denom']??0);
      $weightedPersen=$den? round($sumH/$den*100,1):0;
      $stS=$pdo->prepare("SELECT COUNT(*) c FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?");
      $stS->execute([$ekskul_id,$from,$to]); $sesiCnt=(int)($stS->fetch()['c']??0);
    }catch(Exception $e){ $weightedPersen=0; $sumH=0; $den=0; $sesiCnt=0; }
    // Chart trend: X=minggu Y=% hadir (mingguan, within from-to)
    try{
      $chartQ="SELECT DATE_FORMAT(s.tanggal, '%x-W%v') AS wk, MIN(s.tanggal) AS wk_start, COUNT(DISTINCT s.id) AS sesi, COUNT(DISTINCT r.user_id) AS anggota, COALESCE(SUM(CASE WHEN a.status='hadir' THEN 1 ELSE 0 END),0) AS hadir FROM schedules s CROSS JOIN (SELECT user_id FROM registrations WHERE ekskul_id=? AND deleted_at IS NULL AND status IN ('diterima','menunggu')) r LEFT JOIN attendance a ON a.schedule_id=s.id AND a.user_id=r.user_id WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ? GROUP BY wk ORDER BY wk";
      $stC=$pdo->prepare($chartQ); $stC->execute([$ekskul_id,$ekskul_id,$from,$to]);
      $chartRows=$stC->fetchAll();
      $labels=[]; $values=[];
      foreach($chartRows as $cr){
        $sesi=(int)$cr['sesi']; $anggota=(int)$cr['anggota']; $hadirC=(int)$cr['hadir'];
        $pct = ($sesi*$anggota)? round($hadirC/($sesi*$anggota)*100,1):0;
        $labels[]=$cr['wk']; $values[]=$pct;
      }
      $chartData=['labels'=>$labels,'values'=>$values];
    }catch(Exception $e){ $chartData=['labels'=>[],'values'=>[]]; }
    // Ranking top3 + alert <75 max 5 — computed from full set not paginated
    try{
      $rankQ="SELECT u.id, u.nama, ROUND(COALESCE(SUM(CASE WHEN a.status='hadir' THEN 1 ELSE 0 END),0)*100.0 / NULLIF((SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?),0),1) AS persen FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ('diterima','menunggu') LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama ORDER BY persen DESC, u.nama ASC LIMIT 3";
      $stR=$pdo->prepare($rankQ); $stR->execute([$ekskul_id,$from,$to, $ekskul_id, $ekskul_id,$from,$to]); $rankingData=$stR->fetchAll();
      foreach($rankingData as &$x){ $x['nama']=e($x['nama']); $x['persen']=$x['persen']===null?0:(float)$x['persen']; } unset($x);
      $alertQ="SELECT u.id, u.nama, u.kelas, ROUND(COALESCE(SUM(CASE WHEN a.status='hadir' THEN 1 ELSE 0 END),0)*100.0 / NULLIF((SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?),0),1) AS persen FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ('diterima','menunggu') LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.kelas HAVING persen < 75 ORDER BY persen ASC LIMIT 5";
      $stA=$pdo->prepare($alertQ); $stA->execute([$ekskul_id,$from,$to, $ekskul_id, $ekskul_id,$from,$to]); $alertData=$stA->fetchAll();
      foreach($alertData as &$x){ $x['nama']=e($x['nama']); $x['kelas']=$x['kelas']?e($x['kelas']):null; $x['persen']=(float)$x['persen']; } unset($x);
    }catch(Exception $e){ $rankingData=[]; $alertData=[]; }
  }
  // session_id 3-way (Opsi B reuse single endpoint, tanpa N+1) — extract & validasi mirip /laporan/export
  $reqSid = trim((string)($_GET['session_id'] ?? $_POST['session_id'] ?? ''));
  $evHasSession = false; $evSesiNama = null; $evSessionIdInt = 0;
  if($reqSid !== '' && $event_id){
    if($reqSid === 'all'){
      $evHasSession = true;
    } else if(ctype_digit($reqSid)){
      $evSessionIdInt = (int)$reqSid;
      $ss = pdo()->prepare('SELECT id, nama FROM event_attendance_sessions WHERE id=? AND event_id=?');
      $ss->execute([$evSessionIdInt, $event_id]);
      $evRow = $ss->fetch();
      if(!$evRow) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Sesi tidak ditemukan untuk event ini']],422);
      $evSesiNama = $evRow['nama'];
      $evHasSession = true;
      $reqSid = (string)$evSessionIdInt;
    } else {
      jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'session_id tidak valid (harus id atau all)']],422);
    }
  } else if($reqSid !== '' && !$event_id){
    $reqSid = '';
    $evHasSession = false;
  }
  if($event_id){
    $whereE='p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ?';
    $parE=[$event_id,$from,$to];
    if($qSearch!==''){ $whereE.=' AND (u.nama LIKE ? OR u.email LIKE ?)'; $like='%'.$qSearch.'%'; $parE[]=$like; $parE[]=$like; }
    if($kelasFilter!=='' && $kelasFilter!=='all'){ $whereE.=' AND u.kelas=?'; $parE[]=$kelasFilter; }
    $ct=pdo()->prepare("SELECT COUNT(*) c FROM event_participants p JOIN users u ON u.id=p.user_id WHERE $whereE");
    $ct->execute($parE); $totalEvent=(int)($ct->fetch()['c']??0);
    $orderE='p.created_at DESC';
    if($sortParam==='nama_asc') $orderE='u.nama ASC';
    else if($sortParam==='nama_desc') $orderE='u.nama DESC';
    else if($sortParam==='kelas_asc') $orderE='u.kelas ASC';
    if($evHasSession && $reqSid !== 'all'){
      // per-sesi: hitung peserta tetap via whereE, rows tambah kehadiran_sesi + scanned_at tanpa N+1 (EXISTS + subquery)
      $q='SELECT u.id, u.nama, u.email, u.kelas, p.status, p.hadir, p.created_at AS tgl_daftar, CASE WHEN EXISTS(SELECT 1 FROM event_attendance WHERE session_id=? AND user_id=u.id) THEN \'hadir\' ELSE \'tidak_absen\' END AS kehadiran_sesi, (SELECT scanned_at FROM event_attendance WHERE session_id=? AND user_id=u.id) AS scanned_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE '.$whereE.' ORDER BY '.$orderE.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
      $parQ=array_merge([$evSessionIdInt, $evSessionIdInt], $parE);
      $st=$pdo->prepare($q); $st->execute($parQ);
      $rows=$st->fetchAll();
      foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['status']=e($r['status']); $r['kelas']=$r['kelas']?e($r['kelas']):null; $r['kehadiran_sesi']=e($r['kehadiran_sesi']); $r['sesi_nama']=e($evSesiNama); } unset($r);
      $eventRows=$rows;
    } else if($evHasSession && $reqSid === 'all'){
      // semua sesi: agregat sesi_hadir / total_sesi tanpa N+1
      $q='SELECT u.id, u.nama, u.email, u.kelas, p.status, p.hadir, p.created_at AS tgl_daftar, (SELECT COUNT(*) FROM event_attendance a JOIN event_attendance_sessions s ON s.id=a.session_id WHERE s.event_id=? AND a.user_id=u.id) AS sesi_hadir, (SELECT COUNT(*) FROM event_attendance_sessions WHERE event_id=?) AS total_sesi FROM event_participants p JOIN users u ON u.id=p.user_id WHERE '.$whereE.' ORDER BY '.$orderE.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
      $parQ=array_merge([$event_id, $event_id], $parE);
      $st=$pdo->prepare($q); $st->execute($parQ);
      $rows=$st->fetchAll();
      foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['status']=e($r['status']); $r['kelas']=$r['kelas']?e($r['kelas']):null; $r['sesi_hadir']=(int)$r['sesi_hadir']; $r['total_sesi']=(int)$r['total_sesi']; } unset($r);
      $eventRows=$rows;
    } else {
      $q='SELECT u.id, u.nama, u.email, u.kelas, p.status, p.hadir, p.created_at AS tgl_daftar FROM event_participants p JOIN users u ON u.id=p.user_id WHERE '.$whereE.' ORDER BY '.$orderE.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
      $st=$pdo->prepare($q); $st->execute($parE);
      $rows=$st->fetchAll();
      foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['status']=e($r['status']); $r['kelas']=$r['kelas']?e($r['kelas']):null; } unset($r);
      $eventRows=$rows;
    }
  }
  // KPI 4 cards — persen weighted jujur (Opsi A: sesi/hadir dari backend full-set, bukan page-average)
  $avgHadir = $ekskul_id ? $weightedPersen : 0;
  $kpi=['anggota_aktif'=>$totalEkskul,'persen_hadir_rata'=>$avgHadir,'persen_hadir_weighted'=>$avgHadir,'event_peserta'=>$totalEvent,'siap_export'=>($totalEkskul>0 || $totalEvent>0),'total_sesi'=>$sesiCnt,'total_hadir'=>$sumH,'total_denom'=>$den];
  $isFiltered = ($qSearch!=='' || ($kelasFilter!=='' && $kelasFilter!=='all') || $kehadiranFilter!=='');
  $dataOut=['kpi'=>$kpi,'ekskul'=>['rows'=>$ekskulRows,'total'=>$totalEkskul,'page'=>$page,'limit'=>$limit],'event'=>['rows'=>$eventRows,'total'=>$totalEvent,'page'=>$page,'limit'=>$limit,'session_id'=>($event_id && $reqSid!=='' ? $reqSid : ''),'sesi_nama'=>$evSesiNama],'filters'=>['ekskul_id'=>$ekskul_id,'event_id'=>$event_id,'from'=>$from,'to'=>$to,'q'=>$qSearch,'kelas'=>$kelasFilter,'kehadiran'=>$kehadiranFilter,'sort'=>$sortParam,'session_id'=>($event_id && $reqSid!=='' ? $reqSid : '')],'insight'=>['chart'=>$chartData,'ranking'=>$rankingData,'alert'=>$alertData,'scope'=>'global','filtered'=>$isFiltered]];
  // Cache private no-store + ETag 304 (jujur, tidak misleading)
  $etagPayload=json_encode([$ekskul_id,$event_id,$from,$to,$page,$limit,$qSearch,$kelasFilter,$kehadiranFilter,$sortParam,$reqSid,$totalEkskul,$totalEvent,$cu['id']??0,$kpi, md5(json_encode($ekskulRows)), md5(json_encode($eventRows))]);
  $etag='"'.md5($etagPayload).'"';
  header('ETag: '.$etag);
  header('Cache-Control: private, no-store, max-age=0, must-revalidate');
  header('X-Total-Count: '.max($totalEkskul,$totalEvent));
  header('Content-Type: application/json');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$dataOut,'meta'=>['total_ekskul'=>$totalEkskul,'total_event'=>$totalEvent,'page'=>$page,'limit'=>$limit,'pages'=>max(1, (int)ceil(max($totalEkskul,$totalEvent)/$limit))]]);
}
// === helper: app_settings kop sekolah ===
// hemat bandwidth Ramah: SELECT v WHERE k=? per key (tanpa load SEMUA incl LONGTEXT cert_layout + TTD base64 ~1MB).
// getAppSetting(k) = 1 query ringan; getAppSettingsMap(keys=null) = whitelist keys (default: kecil, TANPA ttd_kepsek/cert_layout).
// ponytail: bila butuh full-map lagi, panggil getAppSettingsMap([]) eksplisit; upgrade ke cache APCu bila hit tinggi.
function getAppSetting(string $k): ?string{
  try{
    $st=pdo()->prepare('SELECT v FROM app_settings WHERE k=?'); $st->execute([$k]); $r=$st->fetch();
    return isset($r['v']) ? (string)$r['v'] : null;
  }catch(Exception $e){ return null; }
}
function getAppSettingsMap(?array $keys=null){
  static $smallKeys=['sekolah_nama','sekolah_alamat','sekolah_telp','kepsek_nama','kepsek_nip','kop_logo','app_url','forgot_password_method','sertifikat_secret','login_hero_title','login_hero_desc','login_hero_badges','login_greet','login_sub','login_hero_media','login_hero_type','login_hero_images','login_hero_interval','login_hero_overlay','login_hero_meta','cert_fonts','cert_bg_path','cert_bg_updated_at','cert_bg_w','cert_bg_h','cert_bg_mime','cert_bg_size'];
  try{
    if($keys!==null && count($keys)===0){ $rows=pdo()->query('SELECT k, v FROM app_settings')->fetchAll(); $m=[]; foreach($rows as $r) $m[$r['k']]=$r['v']; return $m; } // escape hatch eksplisit
    $want=$keys??$smallKeys;
    if(!$want) return [];
    $ph=implode(',',array_fill(0,count($want),'?'));
    $st=pdo()->prepare("SELECT k, v FROM app_settings WHERE k IN ($ph)"); $st->execute(array_values($want));
    $m=[]; foreach($st->fetchAll() as $r) $m[$r['k']]=$r['v']; return $m;
  }catch(Exception $e){ return []; }
}
function setAppSettingsMap(array $kv){ $pdo=pdo(); foreach($kv as $k=>$v){ try{ $pdo->prepare('INSERT INTO app_settings(k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k,$v]); }catch(Exception $e){ try{ $pdo->prepare('INSERT OR REPLACE INTO app_settings(k,v) VALUES (?,?)')->execute([$k,$v]); }catch(Exception $e2){} } } }
// === LAPORAN EXPORT STREAM (PRD 40,96,231) — csv/xlsx/pdf Ber-Kop + audit_logs ===
if($uri==='/laporan/export' && in_array($method,['GET','POST'],true)){
  laporanRateLimit('laporan_export');
  requireLogin();
  $cu=currentUser();
  if(!in_array($cu['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak — siswa tidak boleh export']],403);
  $tipe=trim($_GET['tipe']??$_POST['tipe']??'');
  $id_raw=trim((string)($_GET['id']??$_POST['id']??($_GET['ekskul_id']??$_POST['ekskul_id']??'')));
  if($id_raw==='' && isset($_GET['event_id'])) $id_raw=trim((string)$_GET['event_id']);
  if($id_raw==='' && isset($_GET['id_event'])) $id_raw=trim((string)$_GET['id_event']);
  $from=trim($_GET['from']??$_POST['from']??'');
  $to=trim($_GET['to']??$_POST['to']??'');
  if($from==='' ) $from=date('Y-m-01');
  if($to==='' ) $to=date('Y-m-t');
  $format=strtolower(trim($_GET['format']??$_POST['format']??'csv'));
  if($format==='excel') $format='xlsx';
  if(!in_array($format,['csv','xlsx','pdf'],true)) $format='csv';
  if(!in_array($tipe,['ekskul','event'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tipe harus ekskul|event']],422);
  if($id_raw==='' || !ctype_digit($id_raw)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'id wajib angka dan ada']],422);
  $id=(int)$id_raw;
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || !strtotime($from)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'from harus YYYY-MM-DD']],422);
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to) || !strtotime($to)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'to harus YYYY-MM-DD']],422);
  if(strtotime($from) > strtotime($to)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'from tidak boleh > to']],422);
  $sessionId=null;
  if(isset($_GET['session_id']) && $_GET['session_id']!=='') $sessionId=$_GET['session_id'];
  elseif(isset($_POST['session_id']) && $_POST['session_id']!=='') $sessionId=$_POST['session_id'];
  // filter kelas export (opsional): ikutkan di semua query + kop output bila diisi
  $kelasExp=trim($_GET['kelas']??$_POST['kelas']??'');
  if($kelasExp==='all') $kelasExp='';
  $hasKelas=$kelasExp!=='';
  $kelasSuffix=$hasKelas?' | Kelas: '.$kelasExp:'';
  $namaTarget=''; $ekskulNama='';
  $evSessionRows=[];
  if($sessionId && $sessionId!=='all'){
    $ss=pdo()->prepare('SELECT id, nama FROM event_attendance_sessions WHERE id=? AND event_id=?');
    $ss->execute([(int)$sessionId, (int)$id]);
    $evSessionRow=$ss->fetch();
    if(!$evSessionRow) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Sesi tidak ditemukan untuk event ini']],422);
  }
  if($tipe==='ekskul'){
    $e=pdo()->prepare('SELECT id, nama FROM ekskul WHERE id=? AND deleted_at IS NULL'); $e->execute([$id]); $row=$e->fetch(); if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ekskul id tidak ditemukan']],422); $namaTarget=$row['nama']; $ekskulNama=$row['nama'];
    if($cu['role']==='pembina'){ $ch=pdo()->prepare('SELECT 1 FROM ekskul WHERE id=? AND pembina_id=?'); $ch->execute([$id,$cu['id']]); if(!$ch->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak export ekskul ini (scoped)']],403); }
  } else {
    // event hanya admin/kepsek — pembina tidak boleh export event
    if($cu['role']==='pembina') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Event hanya untuk admin']],403);
    $e=pdo()->prepare('SELECT id, nama FROM events WHERE id=? AND deleted_at IS NULL'); $e->execute([$id]); $row=$e->fetch(); if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'event id tidak ditemukan']],422); $namaTarget=$row['nama'];
    if($sessionId && $sessionId!=='all' && !empty($evSessionRow['nama'])) $namaTarget.=' - '.$evSessionRow['nama'];
    if($cu['role']==='pembina'){ $ch=pdo()->prepare('SELECT 1 FROM events WHERE id=? AND created_by=?'); $ch->execute([$id,$cu['id']]); if(!$ch->fetch()){
      $chk2=null; try{ $chk2=pdo()->prepare('SELECT 1 FROM event_ekskul ee JOIN ekskul e ON e.id=ee.ekskul_id WHERE ee.event_id=? AND e.pembina_id=? AND e.deleted_at IS NULL LIMIT 1'); $chk2->execute([$id,$cu['id']]); }catch(Exception $e){ $chk2=null; }
      if(!$chk2 || !$chk2->fetch()){ $ex=pdo()->prepare('SELECT 1 FROM events WHERE id=?'); $ex->execute([$id]); if($ex->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak export event ini (scoped — bukan creator & bukan event binaan)']],403); }
    } }
  }
  // audit insert (sebelum stream, no auto-email)
  try{
    $ip=$_SERVER['REMOTE_ADDR']??null;
    $detail=json_encode(['tipe'=>$tipe,'id'=>$id,'nama'=>$namaTarget,'from'=>$from,'to'=>$to,'format'=>$format,'kelas'=>$kelasExp], JSON_UNESCAPED_UNICODE);
    $pdo0=pdo();
    try{ $pdo0->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$cu['id'],'export_laporan','laporan_'.$tipe,$id,$detail,$ip]); }
    catch(Exception $e){ try{ $pdo0->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$cu['id'],'export_laporan','laporan_'.$tipe,$id,$detail]); }catch(Exception $e2){} }
  }catch(Exception $e){}
  // pseudo-queue zero-infra: xlsx/pdf -> 202 {job_id} (CSV tetap sync stabil)
  // dev/local: worker cron tidak jalan di php -S — eksekusi inline 1 job ini langsung
  // agar FE tidak stuck polling. Produksi (cron aktif) tetap async via claim berikutnya.
  if($format!=='csv' && function_exists('jobs_enqueue')){
    try{
      $qid=jobs_enqueue(pdo(),'export_laporan',['tipe'=>$tipe,'id'=>$id,'from'=>$from,'to'=>$to,'format'=>$format,'session_id'=>$sessionId,'kelas'=>$kelasExp,'by_name'=>$cu['nama']??$cu['email']??'','by_id'=>$cu['id']]);
      if($qid){
        try{
          $pdoJ=pdo();
          if(!function_exists('handleExportLaporan') && is_file(__DIR__.'/job_handlers.php')) require_once __DIR__.'/job_handlers.php';
          if(function_exists('jobs_dispatch') && function_exists('jobs_done')){
            $jobRow=function_exists('jobs_get')?jobs_get($pdoJ,$qid):null;
            if($jobRow && ($jobRow['status']??'')==='pending'){
              $nowLit=$pdoJ->quote(date('Y-m-d H:i:s'));
              $claim=$pdoJ->prepare("UPDATE jobs SET status='running', attempts=attempts+1 WHERE id=? AND status='pending' AND run_at<=$nowLit");
              $claim->execute([$qid]);
              if($claim->rowCount()>0){
                $jobRow=function_exists('jobs_get')?jobs_get($pdoJ,$qid):$jobRow;
                $res=jobs_dispatch($pdoJ,$jobRow);
                if(!empty($res['ok'])){ jobs_done($pdoJ,$qid,json_encode($res,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
                else if(function_exists('jobs_fail')){ jobs_fail($pdoJ,$qid,(string)($res['error']??'handler gagal')); }
              }
            }
          }
        }catch(Throwable $eInline){}
        jsonOut(['success'=>true,'queued'=>true,'data'=>['job_id'=>$qid,'tipe'=>$tipe,'format'=>$format]],202);
      }
    }catch(Exception $e){}
  }
  $settings=getAppSettingsMap(['sekolah_nama','sekolah_alamat','sekolah_telp','kepsek_nama','kepsek_nip']);
  $sekolahNama=$settings['sekolah_nama']??'SMA Negeri 1';
  $sekolahAlamat=$settings['sekolah_alamat']??'Jl. Pendidikan No. 1, Jakarta';
  $sekolahTelp=$settings['sekolah_telp']??'021-12345678';
  $kepsekNama=$settings['kepsek_nama']??'Drs. H. Ahmad Sulaiman, M.Pd';
  $kepsekNip=$settings['kepsek_nip']??'19650101 199003 1 001';
  if(function_exists('set_time_limit')) @set_time_limit(0);
  if(ob_get_level()) @ob_end_clean();
  header_remove('Content-Type');
  $pdo=pdo();
  $wasBuffered=null;
  try{ $wasBuffered=$pdo->getAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')); $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),false);}catch(Exception $e){}
  // sanitize namaTarget for filename
  $safeName=preg_replace('/[\/\\\\:*?"<>|]+/','-',$namaTarget); $safeName=trim($safeName,'- .'); if($safeName==='') $safeName=$tipe.'-'.$id;
  $fileBase='Rekap '.ucfirst($tipe).' - '.$safeName.' - '.date('Y-m');
  // CSV
  if($format==='csv'){
    $filename=$fileBase.'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo chr(0xEF).chr(0xBB).chr(0xBF);
    $out=fopen('php://output','w');
    // kop as comment rows
    fputcsv($out, [$sekolahNama]); fputcsv($out, [$sekolahAlamat.' | Telp: '.$sekolahTelp]);
    fputcsv($out, ['REKAP '.strtoupper($tipe).' — '.$namaTarget.' | Periode: '.$from.' s/d '.$to.$kelasSuffix]);
    fputcsv($out, []);
    if($tipe==='ekskul'){
      fputcsv($out, ['No','Nama','Email','Kelas','Total Sesi','Hadir','Alpa','Izin','Persen']);
      $q='SELECT u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COALESCE(SUM(CASE WHEN a.status="hadir" THEN 1 ELSE 0 END),0) AS hadir, COALESCE(SUM(CASE WHEN a.status="alpa" THEN 1 ELSE 0 END),0) AS alpa, COALESCE(SUM(CASE WHEN a.status="izin" THEN 1 ELSE 0 END),0) AS izin FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC';
      $st=$pdo->prepare($hasKelas?str_replace(' GROUP BY ',' WHERE u.kelas=? GROUP BY ',$q):$q); $st->execute($hasKelas?[$id,$from,$to, $id, $id,$from,$to, $kelasExp]:[$id,$from,$to, $id, $id,$from,$to]);
      $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
        $total=(int)$row['total_sesi']; $hadir=(int)$row['hadir']; $alpa=(int)$row['alpa']; $izin=(int)$row['izin']; $persen=$total? round($hadir/$total*100,1):0;
        fputcsv($out, [$no++,$row['nama'],$row['email'],$row['kelas']??'', $total,$hadir,$alpa,$izin,$persen.'%']);
        if(ob_get_level()) @ob_flush(); @flush();
      }
    } else {
      if($sessionId && $sessionId!=='all'){
        fputcsv($out, ['No','Peserta','Email','Kelas','Sesi','Waktu Scan']);
        $q='SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE a.session_id=? ORDER BY a.scanned_at DESC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[(int)$sessionId, $kelasExp]:[(int)$sessionId]);
        $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
          fputcsv($out, [$no++,$row['nama'],$row['email'],$row['kelas']??'',$row['nama_sesi'],$row['scanned_at']]);
          if(ob_get_level()) @ob_flush(); @flush();
        }
      } elseif($sessionId==='all'){
        fputcsv($out, ['No','Peserta','Email','Kelas','Sesi','Waktu Scan']);
        $q='SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE s.event_id=? ORDER BY s.id ASC, a.scanned_at ASC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[(int)$id, $kelasExp]:[(int)$id]);
        $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
          fputcsv($out, [$no++,$row['nama'],$row['email'],$row['kelas']??'',$row['nama_sesi'],$row['scanned_at']]);
          if(ob_get_level()) @ob_flush(); @flush();
        }
      } else {
        fputcsv($out, ['No','Peserta','Email','Kelas','Status','Tgl Daftar']);
        $q='SELECT u.nama, u.email, u.kelas, p.status, p.created_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[$id,$from,$to, $kelasExp]:[$id,$from,$to]);
        $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
          fputcsv($out, [$no++,$row['nama'],$row['email'],$row['kelas']??'',$row['status'],$row['created_at']]);
          if(ob_get_level()) @ob_flush(); @flush();
        }
      }
    }
    fputcsv($out, []); fputcsv($out, ['Jakarta, '.date('d F Y')]); fputcsv($out, ['Kepala Sekolah,']); fputcsv($out, []); fputcsv($out, [$kepsekNama]); fputcsv($out, ['NIP. '.$kepsekNip]);
    fclose($out);
    if($wasBuffered!==null){ try{ $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),$wasBuffered);}catch(Exception $e){} }
    exit;
  }
  // XLSX via PhpSpreadsheet
  if($format==='xlsx'){
    $filename=$fileBase.'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    $ss=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet=$ss->getActiveSheet();
    $sheet->setTitle(substr('Rekap '.ucfirst($tipe),0,31));
    // kop
    $sheet->mergeCells('A1:I1'); $sheet->setCellValue('A1', $sekolahNama);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->mergeCells('A2:I2'); $sheet->setCellValue('A2', $sekolahAlamat.' | Telp: '.$sekolahTelp);
    $sheet->getStyle('A2')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF71717A'));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->mergeCells('A3:I3'); $sheet->setCellValue('A3', 'REKAP '.strtoupper($tipe).' — '.$namaTarget);
    $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->mergeCells('A4:I4'); $sheet->setCellValue('A4', 'Periode: '.$from.' s/d '.$to.$kelasSuffix.' | Dicetak: '.date('d/m/Y H:i').' | Oleh: '.($cu['nama']??$cu['email']??''));
    $sheet->getStyle('A4')->getFont()->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF71717A'));
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(20);
    // header row
    $hdrRow=6;
    if($tipe==='ekskul'){
      $headers=['No','Nama','Email','Kelas','Total Sesi','Hadir','Alpa','Izin','% Hadir'];
      $col='A'; foreach($headers as $h){ $sheet->setCellValue($col.$hdrRow, $h); $col++; }
      $sheet->getStyle('A'.$hdrRow.':I'.$hdrRow)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
      $sheet->getStyle('A'.$hdrRow.':I'.$hdrRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF18181B');
      $sheet->getStyle('A'.$hdrRow.':I'.$hdrRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $sheet->freezePane('A7');
      $q='SELECT u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COALESCE(SUM(CASE WHEN a.status="hadir" THEN 1 ELSE 0 END),0) AS hadir, COALESCE(SUM(CASE WHEN a.status="alpa" THEN 1 ELSE 0 END),0) AS alpa, COALESCE(SUM(CASE WHEN a.status="izin" THEN 1 ELSE 0 END),0) AS izin FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC';
      $st=$pdo->prepare($hasKelas?str_replace(' GROUP BY ',' WHERE u.kelas=? GROUP BY ',$q):$q); $st->execute($hasKelas?[$id,$from,$to, $id, $id,$from,$to, $kelasExp]:[$id,$from,$to, $id, $id,$from,$to]);
      $r=$hdrRow+1; $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
        $total=(int)$row['total_sesi']; $hadir=(int)$row['hadir']; $alpa=(int)$row['alpa']; $izin=(int)$row['izin']; $persen=$total? round($hadir/$total*100,1):0;
        $sheet->setCellValue('A'.$r, $no++); $sheet->setCellValue('B'.$r, $row['nama']); $sheet->setCellValue('C'.$r, $row['email']); $sheet->setCellValue('D'.$r, $row['kelas']??'-');
        $sheet->setCellValue('E'.$r, $total); $sheet->setCellValue('F'.$r, $hadir); $sheet->setCellValue('G'.$r, $alpa); $sheet->setCellValue('H'.$r, $izin); $sheet->setCellValue('I'.$r, $persen.'%');
        $sheet->getStyle('A'.$r.':I'.$r)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        if($persen < 75) $sheet->getStyle('I'.$r)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFDC2626'))->setBold(true);
        $r++;
      }
      // auto width
      foreach(['A'=>6,'B'=>22,'C'=>24,'D'=>12,'E'=>11,'F'=>8,'G'=>8,'H'=>8,'I'=>10] as $col=>$w) $sheet->getColumnDimension($col)->setWidth($w);
      $sheet->getStyle('A'.$hdrRow.':I'.($r-1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFE4E4E7'));
      // ttd
      $tt=$r+2; $sheet->mergeCells('F'.$tt.':I'.$tt); $sheet->setCellValue('F'.$tt, 'Jakarta, '.date('d F Y')); $sheet->getStyle('F'.$tt)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $tt2=$tt+1; $sheet->mergeCells('F'.$tt2.':I'.$tt2); $sheet->setCellValue('F'.$tt2, 'Kepala Sekolah,'); $sheet->getStyle('F'.$tt2)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $sheet->getStyle('F'.$tt.':I'.$tt2)->getFont()->setSize(10);
      $tt3=$tt+5; $sheet->mergeCells('F'.$tt3.':I'.$tt3); $sheet->setCellValue('F'.$tt3, $kepsekNama); $sheet->getStyle('F'.$tt3)->getFont()->setBold(true)->setSize(11); $sheet->getStyle('F'.$tt3)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $tt4=$tt+6; $sheet->mergeCells('F'.$tt4.':I'.$tt4); $sheet->setCellValue('F'.$tt4, 'NIP. '.$kepsekNip); $sheet->getStyle('F'.$tt4)->getFont()->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF71717A')); $sheet->getStyle('F'.$tt4)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $sheet->setPrintGridlines(false); $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE); $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4); $sheet->getPageSetup()->setFitToWidth(1); $sheet->getPageSetup()->setFitToHeight(0);
    } else {
      if($sessionId && $sessionId!=='all'){
        $headers=['No','Peserta','Email','Kelas','Sesi','Waktu Scan'];
        $col='A'; foreach($headers as $h){ $sheet->setCellValue($col.$hdrRow, $h); $col++; }
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getFont()->setBold(true)->setColor((new \PhpOffice\PhpSpreadsheet\Style\Color())->setARGB('FFFFFFFF'));
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF18181B');
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A7');
        $q='SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE a.session_id=? ORDER BY a.scanned_at DESC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[(int)$sessionId, $kelasExp]:[(int)$sessionId]);
        $r=$hdrRow+1; $no=1;
        while($row=$st->fetch(PDO::FETCH_ASSOC)){
          $sheet->setCellValue('A'.$r, $no++);
          $sheet->setCellValue('B'.$r, $row['nama']);
          $sheet->setCellValue('C'.$r, $row['email']);
          $sheet->setCellValue('D'.$r, $row['kelas']??'-');
          $sheet->setCellValue('E'.$r, $row['nama_sesi']);
          $sheet->setCellValue('F'.$r, $row['scanned_at']);
          $r++;
        }
      } elseif($sessionId==='all'){
        $headers=['No','Peserta','Email','Kelas','Sesi','Waktu Scan'];
        $col='A'; foreach($headers as $h){ $sheet->setCellValue($col.$hdrRow, $h); $col++; }
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getFont()->setBold(true)->setColor((new \PhpOffice\PhpSpreadsheet\Style\Color())->setARGB('FFFFFFFF'));
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF18181B');
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A7');
        $q='SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE s.event_id=? ORDER BY s.id ASC, a.scanned_at ASC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[(int)$id, $kelasExp]:[(int)$id]);
        $r=$hdrRow+1; $no=1;
        while($row=$st->fetch(PDO::FETCH_ASSOC)){
          $sheet->setCellValue('A'.$r, $no++);
          $sheet->setCellValue('B'.$r, $row['nama']);
          $sheet->setCellValue('C'.$r, $row['email']);
          $sheet->setCellValue('D'.$r, $row['kelas']??'-');
          $sheet->setCellValue('E'.$r, $row['nama_sesi']);
          $sheet->setCellValue('F'.$r, $row['scanned_at']);
          $r++;
        }
      } else {
        $headers=['No','Peserta','Email','Kelas','Status','Tgl Daftar'];
        $col='A'; foreach($headers as $h){ $sheet->setCellValue($col.$hdrRow, $h); $col++; }
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getFont()->setBold(true)->setColor((new \PhpOffice\PhpSpreadsheet\Style\Color())->setARGB('FFFFFFFF'));
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF18181B');
        $sheet->getStyle('A'.$hdrRow.':F'.$hdrRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A7');
        $q='SELECT u.nama, u.email, u.kelas, p.status, p.created_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[$id,$from,$to, $kelasExp]:[$id,$from,$to]);
        $r=$hdrRow+1; $no=1;
        while($row=$st->fetch(PDO::FETCH_ASSOC)){
          $sheet->setCellValue('A'.$r, $no++);
          $sheet->setCellValue('B'.$r, $row['nama']);
          $sheet->setCellValue('C'.$r, $row['email']);
          $sheet->setCellValue('D'.$r, $row['kelas']??'-');
          $sheet->setCellValue('E'.$r, $row['status']);
          $sheet->setCellValue('F'.$r, $row['created_at']);
          $r++;
        }
      }
      foreach(['A'=>6,'B'=>22,'C'=>24,'D'=>12,'E'=>12,'F'=>18] as $col=>$w) $sheet->getColumnDimension($col)->setWidth($w);
      $sheet->getStyle('A'.$hdrRow.':F'.($r-1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFE4E4E7'));
      $tt=$r+2; $sheet->mergeCells('D'.$tt.':F'.$tt); $sheet->setCellValue('D'.$tt, 'Jakarta, '.date('d F Y')); $sheet->getStyle('D'.$tt)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $tt2=$tt+1; $sheet->mergeCells('D'.$tt2.':F'.$tt2); $sheet->setCellValue('D'.$tt2, 'Kepala Sekolah,'); $sheet->getStyle('D'.$tt2)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $tt3=$tt+5; $sheet->mergeCells('D'.$tt3.':F'.$tt3); $sheet->setCellValue('D'.$tt3, $kepsekNama); $sheet->getStyle('D'.$tt3)->getFont()->setBold(true)->setSize(11); $sheet->getStyle('D'.$tt3)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $tt4=$tt+6; $sheet->mergeCells('D'.$tt4.':F'.$tt4); $sheet->setCellValue('D'.$tt4, 'NIP. '.$kepsekNip); $sheet->getStyle('D'.$tt4)->getFont()->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF71717A')); $sheet->getStyle('D'.$tt4)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT); $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    }
    $writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $writer->save('php://output');
    if($wasBuffered!==null){ try{ $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),$wasBuffered);}catch(Exception $e){} }
    exit;
  }
    // PDF via Dompdf — unbuffered sweep: while(fetch) + build HTML incremental, tanpa fetchAll
    if($format==='pdf'){
    $filename=$fileBase.'.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    ob_start();
    $html='<!doctype html><html><head><meta charset="utf-8"><style>
      @page{ margin:28px 24px 24px 24px; size:A4 '.($tipe==='ekskul'?'landscape':'portrait').'; }
      body{ font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size:9px; color:#18181b; }
      .kop{ text-align:center; border-bottom:2px solid #18181b; padding-bottom:10px; margin-bottom:10px; }
      .kop h1{ font-size:16px; margin:0; letter-spacing:.02em; }
      .kop p{ margin:2px 0; font-size:9px; color:#52525b; }
      .judul{ text-align:center; font-weight:700; font-size:12px; margin:10px 0 2px; }
      .periode{ text-align:center; font-size:9px; color:#71717a; margin-bottom:10px; }
      table{ width:100%; border-collapse:collapse; font-size:9px; }
      th{ background:#18181b; color:#fff; padding:7px 6px; text-align:left; font-size:8px; letter-spacing:.04em; }
      td{ padding:6px; border-bottom:1px solid #e4e4e7; vertical-align:top; }
      tr:nth-child(even) td{ background:#fafafa; }
      .pct-low{ color:#dc2626; font-weight:700; }
      .ttd{ margin-top:22px; text-align:right; font-size:10px; line-height:1.4; }
      .ttd .nama{ font-weight:700; margin-top:54px; }
      .ttd .nip{ color:#71717a; font-size:9px; }
      .foot{ margin-top:10px; font-size:7px; color:#a1a1aa; text-align:center; }
    </style></head><body>';
    $html.='<div class="kop"><h1>'.htmlspecialchars($sekolahNama).'</h1><p>'.htmlspecialchars($sekolahAlamat).' &nbsp;|&nbsp; Telp: '.htmlspecialchars($sekolahTelp).'</p></div>';
    $html.='<div class="judul">REKAP '.strtoupper(htmlspecialchars($tipe)).' — '.htmlspecialchars($namaTarget).'</div>';
    $html.='<div class="periode">Periode: '.htmlspecialchars($from).' s/d '.htmlspecialchars($to).htmlspecialchars($kelasSuffix).' &nbsp;|&nbsp; Dicetak: '.date('d/m/Y H:i').' &nbsp;|&nbsp; Oleh: '.htmlspecialchars($cu['nama']??$cu['email']??'').'</div>';
    $html.='<table><thead><tr>';
    if($tipe==='ekskul'){
      $html.='<th style="width:28px">No</th><th>Nama</th><th>Email</th><th>Kelas</th><th style="text-align:center">Sesi</th><th style="text-align:center">Hadir</th><th style="text-align:center">Alpa</th><th style="text-align:center">Izin</th><th style="text-align:center">% Hadir</th>';
      $html.='</tr></thead><tbody>';
      // Opsi A: duplicated from index.php:2431 (route CSV ekskul) — copy query, bukan extract lib
      $q='SELECT u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COALESCE(SUM(CASE WHEN a.status="hadir" THEN 1 ELSE 0 END),0) AS hadir, COALESCE(SUM(CASE WHEN a.status="alpa" THEN 1 ELSE 0 END),0) AS alpa, COALESCE(SUM(CASE WHEN a.status="izin" THEN 1 ELSE 0 END),0) AS izin FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC';
      $st=$pdo->prepare($hasKelas?str_replace(' GROUP BY ',' WHERE u.kelas=? GROUP BY ',$q):$q); $st->execute($hasKelas?[$id,$from,$to, $id, $id,$from,$to, $kelasExp]:[$id,$from,$to, $id, $id,$from,$to]);
      $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
        $total=(int)$row['total_sesi']; $hadir=(int)$row['hadir']; $alpa=(int)($row['alpa']??0); $izin=(int)($row['izin']??0); $persen=$total? round($hadir/$total*100,1):0; $cls=$persen<75?'pct-low':'';
        $html.='<tr><td>'.($no++).'</td><td>'.htmlspecialchars($row['nama']).'</td><td style="font-size:8px">'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['kelas']??'-').'</td><td style="text-align:center">'.$total.'</td><td style="text-align:center">'.$hadir.'</td><td style="text-align:center">'.$alpa.'</td><td style="text-align:center">'.$izin.'</td><td style="text-align:center" class="'.$cls.'">'.$persen.'%</td></tr>';
        unset($row);
      }
      $st->closeCursor();
    } else {
      if($sessionId && $sessionId!=='all'){
        $html.='<th style="width:28px">No</th><th>Peserta</th><th>Email</th><th>Kelas</th><th>Sesi</th><th>Waktu Scan</th>';
        $html.='</tr></thead><tbody>';
        // Opsi A: duplicated from index.php:2441 (route CSV event per-sesi)
        $q='SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE a.session_id=? ORDER BY a.scanned_at DESC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[(int)$sessionId, $kelasExp]:[(int)$sessionId]);
        $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
          $html.='<tr><td>'.($no++).'</td><td>'.htmlspecialchars($row['nama']).'</td><td style="font-size:8px">'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['kelas']??'-').'</td><td>'.htmlspecialchars($row['nama_sesi']).'</td><td>'.htmlspecialchars($row['scanned_at']).'</td></tr>';
          unset($row);
        }
        $st->closeCursor();
      } elseif($sessionId==='all'){
        $html.='<th style="width:28px">No</th><th>Peserta</th><th>Email</th><th>Kelas</th><th>Sesi</th><th>Waktu Scan</th>';
        $html.='</tr></thead><tbody>';
        // Opsi A: duplicated from index.php:2449 (route CSV event all-sesi)
        $q='SELECT u.nama, u.email, u.kelas, s.nama AS nama_sesi, a.scanned_at FROM event_attendance a JOIN users u ON u.id=a.user_id JOIN event_attendance_sessions s ON s.id=a.session_id WHERE s.event_id=? ORDER BY s.id ASC, a.scanned_at ASC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[(int)$id, $kelasExp]:[(int)$id]);
        $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
          $html.='<tr><td>'.($no++).'</td><td>'.htmlspecialchars($row['nama']).'</td><td style="font-size:8px">'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['kelas']??'-').'</td><td>'.htmlspecialchars($row['nama_sesi']).'</td><td>'.htmlspecialchars($row['scanned_at']).'</td></tr>';
          unset($row);
        }
        $st->closeCursor();
      } else {
        $html.='<th style="width:28px">No</th><th>Peserta</th><th>Email</th><th>Kelas</th><th>Status</th><th>Tgl Daftar</th>';
        $html.='</tr></thead><tbody>';
        // Opsi A: duplicated from index.php:2457 (route CSV event default)
        $q='SELECT u.nama, u.email, u.kelas, p.status, p.created_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC';
        $st=$pdo->prepare($hasKelas?str_replace(' ORDER BY ',' AND u.kelas=? ORDER BY ',$q):$q); $st->execute($hasKelas?[$id,$from,$to, $kelasExp]:[$id,$from,$to]);
        $no=1; while($row=$st->fetch(PDO::FETCH_ASSOC)){
          $html.='<tr><td>'.($no++).'</td><td>'.htmlspecialchars($row['nama']).'</td><td style="font-size:8px">'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['kelas']??'-').'</td><td>'.htmlspecialchars($row['status']).'</td><td>'.htmlspecialchars($row['created_at']).'</td></tr>';
          unset($row);
        }
        $st->closeCursor();
      }
    }
    $html.='</tbody></table>';
    $html.='<div class="ttd"><div>Jakarta, '.date('d F Y').'</div><div>Kepala Sekolah,</div><div class="nama">'.htmlspecialchars($kepsekNama).'</div><div class="nip">NIP. '.htmlspecialchars($kepsekNip).'</div></div>';
    $html.='<div class="foot">Dokumen resmi — dicetak dari Sistem Ekskul & Event &nbsp;|&nbsp; '.htmlspecialchars($sekolahNama).'</div>';
    $html.='</body></html>';
    ob_end_clean();
    $opts=new \Dompdf\Options(); $opts->set('isRemoteEnabled', false); $opts->set('isHtml5ParserEnabled', true); $opts->set('defaultFont', 'DejaVu Sans');
    $dom=new \Dompdf\Dompdf($opts); $dom->loadHtml($html); $dom->setPaper('A4', $tipe==='ekskul'?'landscape':'portrait'); $dom->render();
    if($wasBuffered!==null){ try{ $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),$wasBuffered);}catch(Exception $e){} }
    $dom->stream($filename, ['Attachment'=>true]);
    exit;
  }
  jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'format tidak didukung']],422);
}
// === LAPORAN AUDIT LOG + SETTINGS KOP ===
if($uri==='/laporan/audit' && $method==='GET'){
  requireLogin(); $cu=currentUser();
  if(!in_array($cu['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
  $page=max(1,(int)($_GET['page']??1)); $limit=min(50,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $pdo=pdo();
  $where="target_type LIKE 'laporan_%'"; $par=[];
  if($cu['role']==='pembina'){ $where.=' AND user_id=?'; $par[]=$cu['id']; }
  $ct=$pdo->prepare("SELECT COUNT(*) c FROM audit_log WHERE $where"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  $st=$pdo->prepare("SELECT a.*, u.nama, u.email FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE $where ORDER BY a.created_at DESC LIMIT $limit OFFSET $off"); $st->execute($par); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['nama']=$r['nama']?e($r['nama']):null; $r['email']=$r['email']?e($r['email']):null; } unset($r);
  header('Cache-Control: private, no-store, max-age=0');
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>max(1,(int)ceil($total/$limit))]]);
}
if($uri==='/settings' && $method==='GET'){
  requireLogin();
  $cu=currentUser();
  if(!in_array($cu['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
  $m=getAppSettingsMap();
  // TTD hemat: has_ttd via per-key SELECT ringan (tanpa load base64 ~1MB); bytes via /api/ttd + ETag
  $hasTtd=false;
  try{
    $ttdProbe=getAppSetting('ttd_kepsek');
    $hasTtd=is_string($ttdProbe) && str_starts_with($ttdProbe,'data:image/');
    if(!$hasTtd){
      foreach(['png','jpg','jpeg','webp'] as $ext){ $fp=uploadPath('ttd/ttd_kepsek.'.$ext); if(is_file($fp) && filesize($fp)>0){ $hasTtd=true; break; } }
      if(!$hasTtd){ $fp2=uploadPath('ttd_kepsek.png'); if(is_file($fp2) && filesize($fp2)>0) $hasTtd=true; }
    }
  }catch(Exception $e){}
  $ttdUrl=$hasTtd?'/api/ttd':null;
  // never leak secret — ttd only for admin/kepsek/pembina already gated
  $lhImgs=loginHeroImages();
  $lhIv=(int)($m['login_hero_interval']??'4'); if($lhIv<2) $lhIv=2; if($lhIv>10) $lhIv=10;
  $lhUrls=array_map(fn($r)=>'/api/login-hero?f='.rawurlencode(basename($r)), $lhImgs);
  $lhOverlay=loginHeroOverlay(); $lhMeta=loginHeroMeta();
  $certLayoutRaw=trim((string)(getAppSetting('cert_layout')??''));
  $data=[
    'sekolah_nama'=>$m['sekolah_nama']??'','sekolah_alamat'=>$m['sekolah_alamat']??'','sekolah_telp'=>$m['sekolah_telp']??'',
    'kepsek_nama'=>$m['kepsek_nama']??'','kepsek_nip'=>$m['kepsek_nip']??'','kop_logo'=>$m['kop_logo']??'','app_url'=>$m['app_url']??'',
    'forgot_password_method'=>in_array($m['forgot_password_method']??'temp',['temp','link'],true)?$m['forgot_password_method']:'temp',
    'ttd_url'=>$ttdUrl,'has_ttd'=>$hasTtd,
    'ttd_kepsek'=>null, // deprecated: pakai ttd_url /api/ttd (hindari base64 ~1MB di JSON); null agar FE fallback ke URL
    'login_hero_title'=>$m['login_hero_title']??'','login_hero_desc'=>$m['login_hero_desc']??'','login_hero_badges'=>$m['login_hero_badges']??'',
    'login_greet'=>$m['login_greet']??'','login_sub'=>$m['login_sub']??'','login_hero_media'=>$m['login_hero_media']??'','login_hero_type'=>$m['login_hero_type']??'',
    'login_hero_images'=>$lhImgs,'login_hero_urls'=>$lhUrls,'login_hero_interval'=>(string)$lhIv,'login_hero_overlay'=>(string)$lhOverlay,'login_hero_meta'=>$lhMeta,
    'cert_layout'=>$certLayoutRaw,'cert_layout_parsed'=>($certLayoutRaw!==''?(json_decode($certLayoutRaw,true)?:null):null),
  ];
  $etag='"'.md5(json_encode($data,JSON_UNESCAPED_UNICODE)).'"';
  header('ETag: '.$etag); header('Cache-Control: private, max-age=60, stale-while-revalidate=300'); header('Vary: Cookie');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$data]);
}
// GET /ttd — serve TTD kepsek bytes (gated admin/kepsek/pembina, ETag/304; FE pakai ttd_url, bukan data-URI di /settings)
if($uri==='/ttd' && $method==='GET'){
  requireLogin();
  $cu=currentUser();
  if(!in_array($cu['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
  $bin=null; $mime='image/png';
  try{
    $raw=getAppSetting('ttd_kepsek');
    if(is_string($raw) && str_starts_with($raw,'data:image/')){
      $parts=explode(',', $raw, 2);
      $mimeProbe=substr($raw, 5, strpos($raw,';')-5);
      if(in_array($mimeProbe,['image/png','image/jpeg','image/webp'],true)) $mime=$mimeProbe;
      $bin=@base64_decode($parts[1]??'', true) ?: null;
    }
  }catch(Exception $e){}
  if(!$bin){
    foreach(['png','jpg','jpeg','webp'] as $ext){
      $fp=uploadPath('ttd/ttd_kepsek.'.$ext);
      if(is_file($fp) && filesize($fp)>0 && filesize($fp)<800000){ $bin=@file_get_contents($fp); $mime=$ext==='png'?'image/png':($ext==='webp'?'image/webp':'image/jpeg'); break; }
    }
    if(!$bin){ $fp2=uploadPath('ttd_kepsek.png'); if(is_file($fp2) && filesize($fp2)>0){ $bin=@file_get_contents($fp2); $mime='image/png'; } }
  }
  if(!$bin){ http_response_code(404); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'TTD belum dipasang']]); exit; }
  $etag='"'.md5($bin).'"';
  header('Content-Type: '.$mime); header('Content-Length: '.strlen($bin));
  header('Cache-Control: private, max-age=86400'); header('ETag: '.$etag); header('X-Content-Type-Options: nosniff');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  echo $bin; exit;
}
// TTD Kepsek scan upload — dual opsi: ttd scan (image) + barcode/QR verify
if(routeMatch('/settings/ttd',$uri) && $method==='POST'){
  sertifikatRateLimit('ttd_upload',20);
  requireRole('admin');
  csrfCheck();
  $ct=$_SERVER['CONTENT_TYPE']??'';
  $rawBody=null; $mime=''; $size=0; $dataUri='';
  // support JSON {image: data:image/png;base64,...} atau multipart file
  if(strpos($ct,'multipart/form-data')!==false && isset($_FILES['ttd'])){
    $f=$_FILES['ttd'];
    if($f['error']!==UPLOAD_ERR_OK) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Upload gagal code '.$f['error']]],422);
    if($f['size']>2*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'TTD maksimal 2MB']],422);
    $tmp=$f['tmp_name'];
    $info=@getimagesize($tmp);
    if(!$info) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File bukan gambar valid']],422);
    $mime=$info['mime']??'';
    if(!in_array($mime,['image/png','image/jpeg','image/jpg','image/webp'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'TTD hanya PNG/JPG/WEBP']],422);
    $bin=@file_get_contents($tmp);
    if(!$bin) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gagal baca file']],422);
    $b64=base64_encode($bin);
    $dataUri='data:'.$mime.';base64,'.$b64;
  } else {
    $b=getBody();
    $img=trim((string)($b['image']??$b['ttd']??$b['dataUri']??''));
    if($img==='') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'image wajib (data:image/...;base64,...) atau file ttd']],422);
    if(!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+/=]+$#',$img)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Format harus data:image/png|jpeg|webp;base64,...']],422);
    if(strlen($img)>2*1024*1024 + 100) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'TTD maksimal 2MB (base64 ~2.7MB)']],422);
    $parts=explode(',', $img, 2); $b64=$parts[1]??''; $bin=@base64_decode($b64, true);
    if(!$bin) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Base64 tidak valid']],422);
    if(strlen($bin)>2*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'TTD maksimal 2MB']],422);
    $info=@getimagesizefromstring($bin);
    if(!$info) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gambar tidak valid']],422);
    $mime=$info['mime']??'';
    if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'TTD hanya PNG/JPG/WEBP']],422);
    $dataUri=$img;
    // normalize mime prefix if jpg
    if(strpos($dataUri,'data:image/jpg;')===0) $dataUri=str_replace('data:image/jpg;','data:image/jpeg;',$dataUri);
  }
  // opsional resize cap 800x400 via GD to keep PDF ringan
  try{
    if(function_exists('imagecreatefromstring') && strlen($dataUri)< 1500000){
      $parts=explode(',', $dataUri,2); $bin=@base64_decode($parts[1], true);
      if($bin){ $im=@imagecreatefromstring($bin); if($im){ $w=imagesx($im); $h=imagesy($im); if($w>800 || $h>400){ $nw=min(800,$w); $nh=(int)($h*$nw/$w); if($nh>400){ $nh=400; $nw=(int)($w*$nh/$h); } $dst=imagecreatetruecolor($nw,$nh); imagealphablending($dst,false); imagesavealpha($dst,true); $trans=imagecolorallocatealpha($dst,255,255,255,127); imagefilledrectangle($dst,0,0,$nw,$nh,$trans); imagecopyresampled($dst,$im,0,0,0,0,$nw,$nh,$w,$h); ob_start(); imagepng($dst); $png=ob_get_clean(); imagedestroy($dst); imagedestroy($im); $dataUri='data:image/png;base64,'.base64_encode($png); } else { imagedestroy($im); } } }
    }
  }catch(Exception $e){}
  // OPSI B hybrid: save to file api/uploads/ttd/ttd_kepsek.png (no DB bloat, no truncate), DB keep LONGTEXT compat but primary is file
  try{
    $parts=explode(',', $dataUri,2); $bin=@base64_decode($parts[1]??'', true);
    if($bin){
      $dir=uploadPath('ttd');
      $ext=$mime==='image/jpeg'?'jpg':($mime==='image/webp'?'webp':'png');
      // always save as png after resize for consistency; if not resized keep original ext
      $savePath=$dir.'/ttd_kepsek.png';
      // if already png else keep ext
      if($ext!=='png' && strlen($dataUri)<600000) $savePath=$dir.'/ttd_kepsek.'.$ext;
      @file_put_contents($savePath, $bin);
      // also clean old variants
      foreach(['png','jpg','jpeg','webp'] as $e2){ $p=$dir.'/ttd_kepsek.'.$e2; if($p!==$savePath && is_file($p)) @unlink($p); }
      $legacy=uploadPath('ttd_kepsek.png'); if(is_file($legacy) && $legacy!==$savePath) @unlink($legacy);
    }
  }catch(Exception $e){}
  setAppSettingsMap(['ttd_kepsek'=>$dataUri]);
  try{ $pdo=pdo(); $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'upload_ttd','app_settings', json_encode(['has_ttd'=>true,'mime'=>$mime,'size'=>strlen($dataUri)], JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['has_ttd'=>true,'ttd_kepsek'=>$dataUri]]);
}
if(routeMatch('/settings/ttd',$uri) && $method==='DELETE'){
  sertifikatRateLimit('ttd_delete',20);
  requireRole('admin');
  csrfCheck();
  setAppSettingsMap(['ttd_kepsek'=>'']);
  // also delete file fallback — prevent ghost TTD after DB clear
  try{ foreach(['png','jpg','jpeg','webp'] as $e2){ $pp=uploadPath('ttd/ttd_kepsek.'.$e2); if(is_file($pp)) @unlink($pp); } $p2=uploadPath('ttd_kepsek.png'); if(is_file($p2)) @unlink($p2); }catch(Exception $e){}
  try{ $pdo=pdo(); $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'delete_ttd','app_settings', json_encode(['has_ttd'=>false], JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['has_ttd'=>false]]);
}

// === COVER — Upload/Hapus/Serve cover untuk ekskul & event (Opsi A) ===
function coverResolveTable(string $tipe): ?string{
  if($tipe==='ekskul') return 'ekskul';
  if($tipe==='event') return 'events';
  return null;
}
function coverGuard(string $tipe, int $id): array{
  // returns [ok:bool, error|null, code:int]
  $u=currentUser();
  if(!$u) return [false,'Belum login',401];
  if(!in_array($u['role'],['admin','pembina'],true)) return [false,'Hanya admin/pembina',403];
  if($tipe==='ekskul'){
    if($u['role']==='admin') return [true,null,0];
    if(!isPembinaOf($id)) return [false,'Bukan pembina ekskul ini',403];
    return [true,null,0];
  }
  if($tipe==='event'){
    // event hanya admin — pembina tidak punya akses cover event
    if($u['role']==='admin') return [true,null,0];
    return [false,'Hanya admin',403];
  }
  return [false,'Tipe tidak valid',422];
}
// POST /cover/:tipe/:id — upload cover (multipart file 'cover')
if(routeMatch('/cover/:tipe/:id',$uri,$pm) && $method==='POST'){
  sertifikatRateLimit('cover_upload',20);
  $tipe=$pm['tipe']; $id=(int)$pm['id'];
  $table=coverResolveTable($tipe);
  if(!$table) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tipe harus ekskul/event']],422);
  [$ok,$err,$code]=coverGuard($tipe,$id);
  if(!$ok) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>$err]],$code);
  if(!isset($_FILES['cover']) || $_FILES['cover']['error']===UPLOAD_ERR_NO_FILE) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File cover wajib (field name: cover)']],422);
  $f=$_FILES['cover'];
  if($f['error']!==UPLOAD_ERR_OK) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Upload gagal code '.$f['error']]],422);
  if($f['size']>2*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Cover maksimal 2MB']],422);
  $tmp=$f['tmp_name'];
  $info=@getimagesize($tmp);
  if(!$info) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File bukan gambar valid']],422);
  $mime=$info['mime']??'';
  if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Cover hanya PNG/JPG/WEBP']],422);
  $ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
  // verify record exists + not deleted
  $chk=pdo()->prepare("SELECT id FROM {$table} WHERE id=? AND deleted_at IS NULL");
  $chk->execute([$id]); if(!$chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>($tipe==='ekskul'?'Ekskul':'Event').' tidak ada']],404);
  // store: api/uploads/covers/{tipe}_{id}.{ext}
  $dir=uploadPath('covers');
  // delete old variants (replace)
  foreach(['png','jpg','jpeg','webp'] as $e2){
    $p=$dir.'/'.$tipe.'_'.$id.'.'.$e2;
    if(is_file($p) && $e2!==($ext==='jpg'?'jpeg':$ext)) @unlink($p);
    // also remove exact ext if different
  }
  $savePath=$dir.'/'.$tipe.'_'.$id.'.'.$ext;
  // delete existing same-name to overwrite cleanly
  if(is_file($savePath)) @unlink($savePath);
  if(!move_uploaded_file($tmp,$savePath)) jsonOut(['success'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal menyimpan file cover']],500);
  // store relative path (relative to api/) — DB: uploads/covers/{tipe}_{id}.{ext}
  $relPath='uploads/covers/'.$tipe.'_'.$id.'.'.$ext;
  $upd=pdo()->prepare("UPDATE {$table} SET cover_path=? WHERE id=?");
  $upd->execute([$relPath,$id]);
  try{ $pdo=pdo(); $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'upload_cover',$table,$id,json_encode(['tipe'=>$tipe,'ext'=>$ext,'size'=>$f['size']],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  if($tipe==='ekskul') cacheDelPrefix('ekskul'); else { cacheDelPrefix('events'); cacheDelPrefix('kalender'); }
  jsonOut(['success'=>true,'data'=>['cover_url'=>'/api/covers/'.$tipe.'/'.$id]]);
}
// DELETE /cover/:tipe/:id — hapus cover
if(routeMatch('/cover/:tipe/:id',$uri,$pm) && $method==='DELETE'){
  sertifikatRateLimit('cover_delete',20);
  $tipe=$pm['tipe']; $id=(int)$pm['id'];
  $table=coverResolveTable($tipe);
  if(!$table) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tipe harus ekskul/event']],422);
  [$ok,$err,$code]=coverGuard($tipe,$id);
  if(!$ok) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>$err]],$code);
  // fetch current cover_path
  $st=pdo()->prepare("SELECT cover_path FROM {$table} WHERE id=? AND deleted_at IS NULL");
  $st->execute([$id]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>($tipe==='ekskul'?'Ekskul':'Event').' tidak ada']],404);
  $cur=$row['cover_path']??null;
  if($cur){
    // unlink file if exists
    $abs=uploadPath($cur);
    if(is_file($abs)) @unlink($abs);
    // also try variant exts in case path mismatch
    foreach(['png','jpg','jpeg','webp'] as $e2){
      $p=uploadPath('covers/'.$tipe.'_'.$id.'.'.$e2);
      if(is_file($p)) @unlink($p);
    }
  }
  $upd=pdo()->prepare("UPDATE {$table} SET cover_path=NULL WHERE id=?");
  $upd->execute([$id]);
  try{ $pdo=pdo(); $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'delete_cover',$table,$id,json_encode(['tipe'=>$tipe],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  if($tipe==='ekskul') cacheDelPrefix('ekskul'); else { cacheDelPrefix('events'); cacheDelPrefix('kalender'); }
  jsonOut(['success'=>true,'data'=>['success'=>true]]);
}
// GET /covers/:tipe/:id — serve cover (PUBLIC, no login)
if(routeMatch('/covers/:tipe/:id',$uri,$pm) && $method==='GET'){
  $tipe=$pm['tipe']; $id=(int)$pm['id'];
  $table=coverResolveTable($tipe);
  if(!$table){ http_response_code(404); exit; }
  $st=pdo()->prepare($table==='ekskul' ? "SELECT cover_path, status, is_dummy, pembina_id FROM ekskul WHERE id=? AND deleted_at IS NULL" : "SELECT cover_path FROM events WHERE id=? AND deleted_at IS NULL");
  $st->execute([$id]); $row=$st->fetch();
  if(!$row || empty($row['cover_path'])){ http_response_code(404); exit; }
  // ekskul visibility = same as detail: non-approved/dummy hidden unless admin/kepsek/owner-pembina (anti ID-enumeration leak)
  if($table==='ekskul'){
    if(!empty($row['is_dummy'])){ http_response_code(404); exit; }
    if(($row['status']??'')!=='approved'){
      $cu2=currentUser();
      $ok2=$cu2 && ($cu2['role']==='admin' || $cu2['role']==='kepsek' || ($cu2['role']==='pembina' && (int)($row['pembina_id']??0)===(int)$cu2['id']));
      if(!$ok2){ http_response_code(404); exit; }
    }
  }
  $rel=$row['cover_path'];
  $abs=uploadPath($rel);
  if(!is_file($abs)){ http_response_code(404); exit; }
  // derive ext/mime
  $ext=strtolower(pathinfo($abs,PATHINFO_EXTENSION));
  $mimeMap=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
  $mime=$mimeMap[$ext]??'application/octet-stream';
  $mtime=filemtime($abs);
  $etag='"'.md5($rel.'.'.$mtime).'"';
  // 304
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  header('Content-Type: '.$mime);
  header('Content-Length: '.filesize($abs));
  header('Cache-Control: public, max-age=86400');
  header('ETag: '.$etag);
  // inline (no download)
  readfile($abs);
  exit;
}
// === STATIC: serve api/uploads/* — needed for php -S ( .htaccess RewriteCond !-f only works on Apache) ===
if(in_array($method,['GET','HEAD'],true) && str_starts_with($uri,'/uploads/')){
  $rel=ltrim($uri,'/');
  if(str_contains($rel,'..') || str_contains($rel,"\0")){ http_response_code(400); exit; }
  // whitelist subdirs to prevent arbitrary file leak
  $allowedPrefixes=['uploads/cert_bg/','uploads/covers/','uploads/ttd/','uploads/hero/','uploads/avatars/','uploads/event_','uploads/pengumuman_'];
  $ok=false; foreach($allowedPrefixes as $pref){ if(str_starts_with($rel,$pref)){ $ok=true; break; } }
  if(!$ok){ http_response_code(404); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Not allowed']]); exit; }
  $abs=uploadPath($rel);
  if(!is_file($abs)){ http_response_code(404); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'File not found: '.$rel]]); exit; }
  $ext=strtolower(pathinfo($abs,PATHINFO_EXTENSION));
  $mimeMap=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','pdf'=>'application/pdf'];
  $mime=$mimeMap[$ext]??'application/octet-stream';
  $mtime=filemtime($abs); $etag='"'.md5($rel.'.'.$mtime).'"';
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  header('Content-Type: '.$mime); header('Content-Length: '.filesize($abs)); header('Cache-Control: public, max-age=3600'); header('ETag: '.$etag);
  if($method!=='HEAD') readfile($abs); exit;
}
// alias /cert_bg for convenience (redirect to file)
if(in_array($method,['GET','HEAD'],true) && ($uri==='/cert_bg' || $uri==='/cert_bg.png')){
  $abs=uploadPath('cert_bg/cert_bg.png');
  if(!is_file($abs)){ http_response_code(404); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'cert_bg.png not found']]); exit; }
  $mtime=filemtime($abs); $etag='"'.md5('cert_bg.'.$mtime).'"';
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  header('Content-Type: image/png'); header('Content-Length: '.filesize($abs)); header('Cache-Control: public, max-age=3600'); header('ETag: '.$etag);
  if($method!=='HEAD') readfile($abs); exit;
}

if($uri==='/settings' && in_array($method,['PUT','POST'],true)){
  requireRole('admin');
  csrfCheck();
  $b=getBody();
  $fields=['sekolah_nama','sekolah_alamat','sekolah_telp','kepsek_nama','kepsek_nip','kop_logo','app_url','forgot_password_method','login_hero_title','login_hero_desc','login_hero_badges','login_greet','login_sub','login_hero_interval','login_hero_overlay'];
  $kv=[];
  foreach($fields as $f){
    if(isset($b[$f])){
      $v=trim((string)$b[$f]);
      if($f==='forgot_password_method'){
        if(!in_array($v,['temp','link'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'forgot_password_method harus temp|link']],422);
        $kv[$f]=$v; continue;
      }
      if(in_array($f,['sekolah_nama','kepsek_nama']) && mb_strlen($v)<3) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$f minimal 3 karakter"]],422);
      if($f==='sekolah_telp' && $v!=='' && mb_strlen($v)<5) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'sekolah_telp minimal 5']],422);
      if($f==='app_url' && $v!=='' && !filter_var($v, FILTER_VALIDATE_URL)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'app_url harus URL valid (https://...)']],422);
      // interval carousel hero: 2-10 detik
      if($f==='login_hero_interval'){
        $iv=(int)$v; if($iv<2||$iv>10) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'login_hero_interval 2-10 detik']],422);
        $v=(string)$iv;
      }
      else if($f==='login_hero_overlay'){
        $ov=(float)$v; if($ov<0||$ov>0.8) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'login_hero_overlay 0-0.8']],422);
        $v=(string)round($ov,2);
      }
      // login texts: strip tags anti-XSS stored, cap panjang
      else if(str_starts_with($f,'login_')){
        $v=strip_tags($v);
        $max=$f==='login_hero_desc'||$f==='login_sub'?500:($f==='login_hero_badges'?300:200);
        if(mb_strlen($v)>$max) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$f maksimal $max"]],422);
      }
      else if(mb_strlen($v)>500) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$f maksimal 500"]],422);
      $kv[$f]=$v;
    }
  }
  if(!$kv) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tidak ada field']],422);
  setAppSettingsMap($kv);
  try{ $pdo=pdo(); $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'update_settings','app_settings', json_encode($kv, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>getAppSettingsMap()]);
}

// === CERT LAYOUT — posisi teks dinamis (admin only) ===
if(routeMatch('/settings/cert_layout',$uri) && $method==='POST'){
  sertifikatRateLimit('cert_layout_save',20);
  requireRole('admin');
  csrfCheck();
  $b=getBody();
  $raw=trim((string)($b['cert_layout']??$b['layout']??''));
  if($raw==='') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'cert_layout wajib (JSON string)']],422);
  if(strlen($raw)>20000) jsonOut(['success'=>false,'error'=>['code'=>'PAYLOAD_TOO_LARGE','message'=>'cert_layout terlalu besar (>20KB)']],413);
  $decoded=json_decode($raw,true);
  if(!is_array($decoded)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'cert_layout harus JSON object valid']],422);
  $allowed=['nama','label','deskripsi','ttd','qr','nomor','bg_w','bg_h','bg_mime','bg_aspect','bg_updated_at'];
  foreach(array_keys($decoded) as $k){ if(!in_array($k,$allowed,true) && !str_starts_with($k,'bg_')) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"field '$k' tidak dikenal"]],422); }
  foreach(['nama','label','deskripsi','ttd','qr','nomor'] as $fk){
    if(isset($decoded[$fk])){
      if(!is_array($decoded[$fk])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk harus object"]],422);
      foreach($decoded[$fk] as $prop=>$val){
        if(!in_array($prop,['x','y','w','h','font_pt','align','color','bold','italic','size','font_weight','uppercase','letter_spacing'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.$prop tidak dikenal"]],422);
        if(in_array($prop,['x','y','w','h','font_pt','size'],true) && !is_numeric($val)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.$prop harus angka"]],422);
        // font_weight: string (normal|bold) atau bool
        if($prop==='font_weight' && !is_string($val) && !is_bool($val)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.font_weight harus string/bool"]],422);
        if($prop==='uppercase' && !is_bool($val)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.uppercase harus bool"]],422);
        if($prop==='letter_spacing' && !is_numeric($val) && !is_string($val)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.letter_spacing invalid"]],422);
      }
      if(isset($decoded[$fk]['x']) && ($decoded[$fk]['x']<0 || $decoded[$fk]['x']>297)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.x 0-297"]],422);
      if(isset($decoded[$fk]['y']) && ($decoded[$fk]['y']<0 || $decoded[$fk]['y']>210)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.y 0-210"]],422);
      if(isset($decoded[$fk]['w']) && ($decoded[$fk]['w']<=0 || $decoded[$fk]['w']>297)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.w 1-297"]],422);
      if(isset($decoded[$fk]['h']) && ($decoded[$fk]['h']<=0 || $decoded[$fk]['h']>210)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.h 1-210"]],422);
      if(isset($decoded[$fk]['font_pt']) && ($decoded[$fk]['font_pt']<4 || $decoded[$fk]['font_pt']>80)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"$fk.font_pt 4-80"]],422);
    }
  }
  // preserve bg_* keys if caller didn't send them but DB already has them
  try{
    $cur=json_decode(trim((string)(getAppSetting('cert_layout')??'')),true);
    if(is_array($cur)){
      foreach(['bg_w','bg_h','bg_mime','bg_aspect','bg_updated_at'] as $bk){
        if(!isset($decoded[$bk]) && isset($cur[$bk])) $decoded[$bk]=$cur[$bk];
      }
    }
  }catch(Exception $e){}
  $toStore=json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $kvStore=['cert_layout'=>$toStore];
  // MAJOR fonts persist server (global): Admin layout save ikut simpan fonts_json → app_settings.cert_fonts
  // LOG global incoming
  try{ $gLog='[CERT_FONT] saveCertLayout global incoming raw='.json_encode($b['fonts_json']??null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); error_log($gLog); certLogAppend($gLog); }catch(Exception $e){}
  $fjRaw=array_key_exists('fonts_json',$b) ? (is_string($b['fonts_json'])?$b['fonts_json']:json_encode($b['fonts_json'])) : '';
  $fjRaw=trim((string)$fjRaw);
  if($fjRaw!==''){
    if(strlen($fjRaw)>2000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'fonts_json terlalu besar (max 2KB)']],422);
    $fo=json_decode($fjRaw,true);
    if(!is_array($fo)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'fonts_json harus JSON object']],422);
    $canon=[];
    foreach($fo as $fk=>$fv){
      if(!in_array($fk,['nama','label','deskripsi','nomor','ttd_nama'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"fonts_json.$fk tidak dikenal"]],422);
      $fv=trim((string)$fv);
      if($fv===''||strcasecmp($fv,'DejaVu Sans')===0) continue;
      if(mb_strlen($fv,'UTF-8')>40 || !preg_match('/^[A-Za-z0-9 \\-]+$/',$fv)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"fonts_json.$fk tidak valid"]],422);
      $canon[$fk]=$fv;
    }
    try{ $gCanLog='[CERT_FONT] saveCertLayout global canon='.json_encode($canon,JSON_UNESCAPED_UNICODE).' out='.( $canon?json_encode($canon,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'EMPTY'); error_log($gCanLog); certLogAppend($gCanLog); }catch(Exception $e){}
    $kvStore['cert_fonts']=$canon ? json_encode($canon, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '';
  } else {
    // fonts_json not sent: log preserve behavior
    try{ $gEmpty='[CERT_FONT] saveCertLayout global fonts_json not sent -> preserve (no cert_fonts update)'; error_log($gEmpty); certLogAppend($gEmpty); }catch(Exception $e){}
  }
  setAppSettingsMap($kvStore);
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'update_cert_layout','app_settings', json_encode(['keys'=>array_keys($decoded)], JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['cert_layout'=>$toStore,'parsed'=>$decoded]]);
}
if(routeMatch('/settings/cert_layout',$uri) && $method==='GET'){
  requireLogin();
  if(!in_array(currentUser()['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
  $raw=trim((string)(getAppSetting('cert_layout')??''));
  $parsed=$raw!=='' ? (json_decode($raw,true)?:null) : null;
  $fontsRaw=trim((string)(getAppSetting('cert_fonts')??''));
  $fontsParsed=$fontsRaw!=='' ? (json_decode($fontsRaw,true)?:null) : null;
  if(!is_array($fontsParsed)) $fontsParsed=null;
  header('Cache-Control: private, no-store');
  jsonOut(['success'=>true,'data'=>['cert_layout'=>$raw,'parsed'=>$parsed,'cert_fonts'=>$fontsRaw,'fonts_parsed'=>$fontsParsed]]);
}

// === LOGIN HERO — teks+multi-foto dinamis (publik baca, admin tulis) ===
// ponytail: hero multi-foto (max 5, foto only, no video) via JSON array di login_hero_media; interval via login_hero_interval (2-10s, default 4). Legacy single string tetap dibaca (wrap array). Upgrade path: tabel login_hero_images bila butuh reorder/drag.
// Opsi A+B: + login_hero_meta (JSON {basename:{alt,link}}), login_hero_overlay (0-0.8), reorder via login_hero_order, auto-compress WEBP
function loginHeroDefaults(): array{
  return [
    'login_hero_title'=>'Belajar, Berkarya, dan Bertumbuh Bersama.',
    'login_hero_desc'=>'Sistem informasi ekstrakurikuler terpadu — presensi QR, kalender kegiatan terpusat, dan persetujuan berjenjang. Tertib, transparan, dan terdata.',
    'login_hero_badges'=>'Kuota Tersedia · 18/20|Kalender Terpusat|Presensi QR',
    'login_greet'=>'Selamat Datang',
    'login_sub'=>'Silakan masuk untuk mengakses layanan ekstrakurikuler dan kegiatan sekolah.',
    'login_hero_interval'=>'4',
    'login_hero_overlay'=>'0.42',
    'login_hero_meta'=>'{}',
  ];
}
// helper: parse login_hero_media (JSON array baru | string legacy | kosong) → array rel paths
function loginHeroImages(): array{
  $raw=trim((string)(getAppSetting('login_hero_media')??''));
  if($raw==='') return [];
  if(str_starts_with($raw,'[')){ $a=json_decode($raw,true); if(!is_array($a)) return []; return array_values(array_filter(array_map(fn($x)=>is_string($x)?trim($x):'', $a), fn($x)=>$x!=='' && !str_contains($x,'..'))); }
  if(str_contains($raw,'..')) return [];
  return [$raw];
}
function loginHeroMeta(): array{
  $raw=trim((string)(getAppSetting('login_hero_meta')??''));
  if($raw==='' || $raw==='{}') return [];
  $a=json_decode($raw,true); if(!is_array($a)) return [];
  $out=[];
  foreach($a as $k=>$v){
    $base=basename(trim((string)$k));
    if($base===''||str_contains($base,'..')) continue;
    if(!is_array($v)) continue;
    $alt=trim((string)($v['alt']??'')); if(mb_strlen($alt)>120) $alt=mb_substr($alt,0,120);
    $link=trim((string)($v['link']??'')); if($link!=='' && !filter_var($link, FILTER_VALIDATE_URL)) $link='';
    // strip tags alt
    $alt=strip_tags($alt);
    $out[$base]=['alt'=>$alt,'link'=>$link];
  }
  return $out;
}
function loginHeroOverlay(): float{
  $v=trim((string)(getAppSetting('login_hero_overlay')??'0.42'));
  $f=(float)$v; if($f<0) $f=0; if($f>0.8) $f=0.8; return round($f,2);
}
function loginHeroCompress(string $srcPath, string $mime, int $maxW=1920): array{
  // auto-compress ke WEBP 82 quality, max 1920. Return [path, ext] — fallback keep original bila GD tak ada/gagal
  if(!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) return [$srcPath, pathinfo($srcPath,PATHINFO_EXTENSION)];
  $bin=@file_get_contents($srcPath); if(!$bin) return [$srcPath, pathinfo($srcPath,PATHINFO_EXTENSION)];
  $im=@imagecreatefromstring($bin); if(!$im) return [$srcPath, pathinfo($srcPath,PATHINFO_EXTENSION)];
  $w=imagesx($im); $h=imagesy($im);
  $nw=$w; $nh=$h;
  if($w>$maxW){ $nw=$maxW; $nh=(int)($h*$nw/$w); }
  if($nw!==$w || $nh!==$h){
    $dst=imagecreatetruecolor($nw,$nh);
    imagealphablending($dst,false); imagesavealpha($dst,true);
    $trans=imagecolorallocatealpha($dst,0,0,0,127); imagefilledrectangle($dst,0,0,$nw,$nh,$trans);
    imagecopyresampled($dst,$im,0,0,0,0,$nw,$nh,$w,$h);
    imagedestroy($im); $im=$dst;
  }
  $webpPath=preg_replace('/\.(png|jpg|jpeg)$/i','.webp',$srcPath);
  if($webpPath===$srcPath) $webpPath=$srcPath.'.webp';
  $ok=@imagewebp($im,$webpPath,82);
  imagedestroy($im);
  if($ok && is_file($webpPath) && filesize($webpPath)>0){
    // hapus original bila beda path & lebih besar
    if($webpPath!==$srcPath) @unlink($srcPath);
    return [$webpPath,'webp'];
  }
  return [$srcPath, pathinfo($srcPath,PATHINFO_EXTENSION)];
}
// GET /login-settings — PUBLIC (login page belum auth), teks only + media URLs array + interval, no secret
if($uri==='/login-settings' && $method==='GET'){
  $m=getAppSettingsMap(); $d=loginHeroDefaults();
  $imgs=loginHeroImages();
  $iv=(int)($m['login_hero_interval']??$d['login_hero_interval']); if($iv<2) $iv=2; if($iv>10) $iv=10;
  $ov=loginHeroOverlay(); $meta=loginHeroMeta();
  $urls=array_map(fn($r)=>'/api/login-hero?f='.rawurlencode(basename($r)), $imgs);
  $slides=array_map(function($r) use($meta){
    $base=basename($r);
    return ['url'=>'/api/login-hero?f='.rawurlencode($base),'alt'=>$meta[$base]['alt']??'','link'=>$meta[$base]['link']??'','basename'=>$base];
  }, $imgs);
  header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
  $data=[
    'tag'=>'',
    'title'=>$m['login_hero_title']??$d['login_hero_title'],
    'desc'=>$m['login_hero_desc']??$d['login_hero_desc'],
    'badges'=>array_values(array_filter(array_map('trim',explode('|',$m['login_hero_badges']??$d['login_hero_badges'])))),
    'greet'=>$m['login_greet']??$d['login_greet'],
    'sub'=>$m['login_sub']??$d['login_sub'],
    'media_urls'=>$urls,
    'media_url'=>$urls[0]??'',
    'media_type'=>count($urls)>0?'image':'',
    'has_media'=>count($urls)>0,
    'interval'=>$iv,
    'overlay'=>$ov,
    'slides'=>$slides,
    'meta'=>$meta,
  ];
  $etag='"'.md5(json_encode($data,JSON_UNESCAPED_UNICODE)).'"';
  header('ETag: '.$etag);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$data]);
}
// GET /login-hero — serve foto hero (PUBLIC, pola sama cover). ?f=basename opsional untuk multi-foto, fallback legacy single
if($uri==='/login-hero' && $method==='GET'){
  $imgs=loginHeroImages();
  $req=trim($_GET['f']??'');
  if($req!==''){
    $req=basename($req);
    $rel='';
    foreach($imgs as $im){ if(basename($im)===$req){ $rel=$im; break; } }
    if($rel===''){ http_response_code(404); exit; }
  } else {
    $rel=$imgs[0]??'';
  }
  if($rel==='' || str_contains($rel,'..')){ http_response_code(404); exit; }
  $abs=uploadPath($rel);
  if(!is_file($abs)){ http_response_code(404); exit; }
  $ext=strtolower(pathinfo($abs,PATHINFO_EXTENSION));
  $mimeMap=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
  $mime=$mimeMap[$ext]??'application/octet-stream';
  if(!isset($mimeMap[$ext])){ http_response_code(404); exit; }
  $mtime=filemtime($abs); $etag='"'.md5($rel.'.'.$mtime).'"';
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  header('Content-Type: '.$mime);
  header('Content-Length: '.filesize($abs));
  header('Cache-Control: public, max-age=86400');
  header('ETag: '.$etag);
  header('X-Content-Type-Options: nosniff');
  readfile($abs); exit;
}
// POST /settings/login-hero — upload foto (admin, CSRF, FOTO ONLY max 5). Tambah 1 foto per request.
if($uri==='/settings/login-hero' && $method==='POST'){
  sertifikatRateLimit('login_hero',20);
  requireRole('admin');
  csrfCheck();
  $imgs=loginHeroImages();
  if(count($imgs)>=5) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maksimal 5 foto hero']],422);
  if(!isset($_FILES['hero']) || $_FILES['hero']['error']===UPLOAD_ERR_NO_FILE) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File hero wajib (field: hero)']],422);
  $f=$_FILES['hero'];
  if($f['error']!==UPLOAD_ERR_OK) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Upload gagal code '.$f['error']]],422);
  if($f['size']>5*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hero maksimal 5MB (kompres dulu)']],422);
  $tmp=$f['tmp_name'];
  $finfo=@finfo_open(FILEINFO_MIME_TYPE); $mime=$finfo?@finfo_file($finfo,$tmp):''; @finfo_close($finfo);
  $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
  if(!isset($allowed[$mime])){
    // fallback getimagesize untuk image
    $info=@getimagesize($tmp); $mime=$info['mime']??'';
    if(!isset($allowed[$mime])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hero hanya foto PNG/JPG/WEBP (video dihapus)']],422);
  }
  // cegah SVG/PHP polyglot: image harus lolos getimagesize
  $info=@getimagesize($tmp);
  if(!$info) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File bukan gambar valid']],422);
  $ext=$allowed[$mime];
  $dir=uploadPath('hero');
  $name='login_hero_'.bin2hex(random_bytes(4)).'.'.$ext;
  $savePath=$dir.'/'.$name;
  if(!move_uploaded_file($tmp,$savePath)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Gagal menyimpan hero']],500);
  // auto-compress ke WEBP (max 1920) — Opsi B
  [$savePath,$ext]=loginHeroCompress($savePath,$mime);
  $name=basename($savePath);
  $imgs[]='uploads/hero/'.$name;
  setAppSettingsMap(['login_hero_media'=>json_encode($imgs,JSON_UNESCAPED_UNICODE),'login_hero_type'=>'image']);
  try{ $pdo=pdo(); $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'upload_login_hero','app_settings',json_encode(['ext'=>$ext,'count'=>count($imgs),'size'=>$f['size']],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['media_url'=>'/api/login-hero?f='.rawurlencode($name),'media_type'=>'image','count'=>count($imgs)]]);
}
// DELETE /settings/login-hero — hapus 1 foto (?f=basename) atau semua bila tanpa param, fallback gradient
if($uri==='/settings/login-hero' && $method==='DELETE'){
  sertifikatRateLimit('login_hero_del',20);
  requireRole('admin');
  csrfCheck();
  $imgs=loginHeroImages();
  $req=trim($_GET['f']??'');
  if($req!==''){
    $req=basename($req);
    $kept=[]; $deleted=false;
    foreach($imgs as $im){ if(basename($im)===$req && !$deleted){ @unlink(uploadPath($im)); $deleted=true; } else $kept[]=$im; }
    $imgs=$kept;
  } else {
    // legacy single tanpa param: hapus semua (termasuk file legacy login_hero.*)
    foreach($imgs as $im){ @unlink(uploadPath($im)); }
    $imgs=[];
    try{ foreach(['png','jpg','jpeg','webp','mp4','webm'] as $e2){ $p=uploadPath('hero/login_hero.'.$e2); if(is_file($p)) @unlink($p); } }catch(Exception $e){}
  }
  // clean meta untuk file yang dihapus
  $meta=loginHeroMeta(); $changed=false;
  if($req!==''){ if(isset($meta[$req])){ unset($meta[$req]); $changed=true; } }
  else { if(!empty($meta)){ $meta=[]; $changed=true; } }
  if($changed) setAppSettingsMap(['login_hero_meta'=>json_encode($meta,JSON_UNESCAPED_UNICODE)]);
  setAppSettingsMap(['login_hero_media'=>count($imgs)>0?json_encode(array_values($imgs),JSON_UNESCAPED_UNICODE):'','login_hero_type'=>count($imgs)>0?'image':'']);
  try{ $pdo=pdo(); $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'delete_login_hero','app_settings',json_encode(['has_media'=>count($imgs)>0,'count'=>count($imgs)],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['has_media'=>count($imgs)>0,'count'=>count($imgs)]]);
}
// PUT /settings/login-hero/order — drag-reorder foto (Opsi A) body {order:[basename,...]}
if($uri==='/settings/login-hero/order' && in_array($method,['PUT','POST'],true)){
  sertifikatRateLimit('login_hero_order',20);
  requireRole('admin'); csrfCheck();
  $b=getBody(); $order=$b['order']??$b['order[]']??null;
  if(!is_array($order)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'order harus array basename']],422);
  $imgs=loginHeroImages();
  $map=[]; foreach($imgs as $im) $map[basename($im)]=$im;
  if(count($order)!==count($imgs)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'order harus berisi semua foto ('.count($imgs).')']],422);
  $new=[];
  foreach($order as $bn){
    $bn=basename(trim((string)$bn));
    if(!isset($map[$bn])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'basename tidak ditemukan: '.$bn]],422);
    if(in_array($map[$bn],$new,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'duplikat basename: '.$bn]],422);
    $new[]=$map[$bn];
  }
  setAppSettingsMap(['login_hero_media'=>json_encode(array_values($new),JSON_UNESCAPED_UNICODE)]);
  jsonOut(['success'=>true,'data'=>['order'=>array_map('basename',$new)]]);
}
// PUT /settings/login-hero/meta — alt/link per foto (Opsi B) body {basename, alt, link}
if($uri==='/settings/login-hero/meta' && in_array($method,['PUT','POST'],true)){
  sertifikatRateLimit('login_hero_meta',20);
  requireRole('admin'); csrfCheck();
  $b=getBody();
  $bn=basename(trim((string)($b['basename']??$b['file']??'')));
  if($bn==='') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'basename wajib']],422);
  $imgs=loginHeroImages(); $found=false; foreach($imgs as $im) if(basename($im)===$bn){ $found=true; break; }
  if(!$found) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'foto tidak ditemukan']],422);
  $alt=strip_tags(trim((string)($b['alt']??''))); if(mb_strlen($alt)>120) $alt=mb_substr($alt,0,120);
  $link=trim((string)($b['link']??'')); if($link!=='' && !filter_var($link, FILTER_VALIDATE_URL)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'link harus URL valid (https://...)']],422);
  $meta=loginHeroMeta(); $meta[$bn]=['alt'=>$alt,'link'=>$link];
  setAppSettingsMap(['login_hero_meta'=>json_encode($meta,JSON_UNESCAPED_UNICODE)]);
  jsonOut(['success'=>true,'data'=>['basename'=>$bn,'alt'=>$alt,'link'=>$link]]);
}

// === USERS — Kelola Users (PRD 73/207-212, PRD231/233, bug m0034 fix) ===
function usersRateLimit($key='users'){
  $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';
  $file=sys_get_temp_dir()."/rl_{$key}_".md5($ip).".json";
  $now=time(); $cnt=0; $win=$now;
  $fh=@fopen($file,'c+'); if($fh){ @flock($fh,LOCK_EX); $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null; if($j && isset($j['start']) && ($now - (int)$j['start'] < 60)){ $cnt=(int)($j['count']??0); $win=(int)$j['start']; } else { $cnt=0; $win=$now; } if($cnt>=60){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu banyak request, coba 1 menit']],429); } $cnt++; @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt,'start'=>$win])); @fflush($fh); @flock($fh,LOCK_UN); @fclose($fh); }
}
function validateUserPayload($nama,$email,$password,$role,&$err,$kelas=null,$nip=null,$isUpdate=false){
  $n=trim((string)$nama); $em=trim((string)$email); $r=trim((string)($role??''));
  if(!$isUpdate || $nama!==null){
    if(mb_strlen($n)<2) { $err='Nama minimal 2 karakter'; return false; }
    if(preg_match('/^\s*(test|dummy|asdf)\s*$/i',$n)) { $err='Nama tidak boleh test/dummy'; return false; }
  }
  if(!$isUpdate || $email!==null){
    if(!filter_var($em, FILTER_VALIDATE_EMAIL)) { $err='Email tidak valid'; return false; }
  }
  if(!$isUpdate || ($password!==null && $password!=='')){
    if(!$isUpdate && ($password===null || $password==='')) { $err='Password wajib'; return false; }
    if($password!==null && $password!==''){
      if(mb_strlen($password)<8) { $err='Password minimal 8 karakter'; return false; }
      // disarankan huruf besar + angka — tidak hard-fail, tapi hint (task)
    }
  }
  $allowed=['admin','pembina','siswa','kepsek'];
  if($role!==null && $role!=='' && !in_array($r,$allowed,true)) { $err='Role tidak valid'; return false; }
  if($role!==null){
    if($r==='siswa'){
      // kelas opsional tapi jika diisi minimal 2
      if($kelas!==null && $kelas!=='' && mb_strlen(trim((string)$kelas))<2){ $err='Kelas minimal 2 karakter'; return false; }
    }
    if(in_array($r,['pembina','kepsek'],true)){
      // nip opsional tapi jika diisi minimal 5
      if($nip!==null && $nip!=='' && mb_strlen(trim((string)$nip))<5){ $err='NIP minimal 5 karakter'; return false; }
    }
  }
  return true;
}
if($uri==='/users/kelas-list' && $method==='GET'){
  requireRole('admin');
  usersRateLimit('users_get');
  try{
    $st=pdo()->prepare("SELECT DISTINCT kelas FROM users WHERE deleted_at IS NULL AND role='siswa' AND kelas IS NOT NULL AND TRIM(kelas)<>'' ORDER BY kelas ASC");
    $st->execute();
    $rows=array_column($st->fetchAll(),'kelas');
    $rows=array_map(function($v){ return e(trim($v)); }, $rows);
    $rows=array_values(array_filter($rows, function($v){ return $v!==''; }));
    $etag='W/"'.md5(json_encode($rows)).'"';
    header('ETag: '.$etag);
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
    jsonOut(['success'=>true,'data'=>$rows]);
  }catch(Exception $e){ jsonOut(['success'=>true,'data'=>[]]); }
}
// === USERS — Activity sparkline (7/14/30 hari) — admin only, ETag + rate ===
if($uri==='/users/activity' && $method==='GET'){
  requireRole('admin');
  usersRateLimit('users_activity');
  $days=max(7,min(30,(int)($_GET['days']??14)));
  try{
    $st=pdo()->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) GROUP BY d ORDER BY d ASC");
    $st->execute(); $createdRows=$st->fetchAll();
    $st2=pdo()->prepare("SELECT DATE(last_login_at) d, COUNT(*) c FROM users WHERE deleted_at IS NULL AND last_login_at IS NOT NULL AND last_login_at >= DATE_SUB(NOW(), INTERVAL $days DAY) GROUP BY d ORDER BY d ASC");
    $st2->execute(); $loginRows=$st2->fetchAll();
    $mapC=[]; foreach($createdRows as $r) $mapC[$r['d']]=(int)$r['c'];
    $mapL=[]; foreach($loginRows as $r) if($r['d']) $mapL[$r['d']]=(int)$r['c'];
    $labels=[]; $created=[]; $logins=[];
    for($i=$days-1;$i>=0;$i--){
      $d=date('Y-m-d', strtotime("-$i days"));
      $labels[]=$d;
      $created[]=$mapC[$d]??0;
      $logins[]=$mapL[$d]??0;
    }
    $maxUpd=''; try{ $mu=pdo()->prepare("SELECT MAX(updated_at) m FROM users WHERE deleted_at IS NULL"); $mu->execute(); $maxUpd=$mu->fetch()['m']??''; }catch(Exception $e){}
    $etag='W/\"act-'.md5(json_encode($created).json_encode($logins).$maxUpd.$days).'\"';
    header('ETag: '.$etag); header('Cache-Control: private, max-age=30, must-revalidate');
    if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
    jsonOut(['success'=>true,'data'=>['labels'=>$labels,'created'=>$created,'logins'=>$logins,'days'=>$days]]);
  }catch(Exception $e){ jsonOut(['success'=>true,'data'=>['labels'=>[],'created'=>[],'logins'=>[],'days'=>$days]]); }
}
// === USERS — Bulk kelas create — admin only, 1..100 per request ===
if($uri==='/users/bulk-kelas' && $method==='POST'){
  requireRole('admin');
  usersRateLimit('users_bulk_kelas');
  $b=getBody();
  $kelas=trim($b['kelas']??''); $count=(int)($b['count']??$b['jumlah']??0);
  $prefix=trim($b['prefix']??'siswa'); $domain=trim($b['domain']??'sekolah.test');
  $password=$b['password']??'';
  if(mb_strlen($kelas)<2) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Kelas minimal 2 karakter']],422);
  if($count<1 || $count>100) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Jumlah 1..100']],422);
  if(!preg_match('/^[a-z0-9]+$/i',$prefix)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Prefix hanya alphanumerik']],422);
  if(!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i',$domain)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Domain tidak valid']],422);
  if($password!=='' && mb_strlen($password)<8) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Password minimal 8']],422);
  if($password==='') $password='Siswa123!'.substr(bin2hex(random_bytes(2)),0,4);
  $pdo=pdo();
  $existing=[]; try{ $all=$pdo->query("SELECT LOWER(email) e FROM users WHERE deleted_at IS NULL")->fetchAll(); foreach($all as $r) $existing[strtolower($r['e'])]=true; }catch(Exception $e){}
  $success=0; $failed=[]; $createdIds=[];
  $pdo->beginTransaction();
  try{
    for($i=1;$i<=$count;$i++){
      $nama="Siswa ".$kelas." ".$i;
      $email=strtolower($prefix.$i.".".preg_replace('/[^a-z0-9]/','',strtolower($kelas))."@".$domain);
      $low=strtolower($email);
      if(isset($existing[$low])){ $failed[]=['row'=>$i,'email'=>$email,'reason'=>'Email duplikat']; continue; }
      $err=''; if(!validateUserPayload($nama,$email,$password,'siswa',$err,$kelas,null,false)){ $failed[]=['row'=>$i,'email'=>$email,'reason'=>$err]; continue; }
      $hash=password_hash($password,PASSWORD_BCRYPT);
      try{
        $st=$pdo->prepare('INSERT INTO users(nama,email,password_hash,role,kelas,status) VALUES (?,?,?,?,?,?)');
        $st->execute([$nama,$email,$hash,'siswa',$kelas,'aktif']);
        $existing[$low]=true; $success++; $createdIds[]=(int)$pdo->lastInsertId();
      }catch(PDOException $e){
        $msg=$e->getMessage(); if(strpos($msg,'Duplicate')!==false || $e->getCode()=='23000') $failed[]=['row'=>$i,'email'=>$email,'reason'=>'Email duplikat'];
        else $failed[]=['row'=>$i,'email'=>$email,'reason'=>'DB error'];
      }
    }
    $pdo->commit();
  }catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'ERROR','message'=>$e->getMessage()]],500); }
  $ip=$_SERVER['REMOTE_ADDR']??''; try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'bulk_kelas','user',0, json_encode(['kelas'=>$kelas,'count'=>$count,'success'=>$success,'failed'=>count($failed)],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){ try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'bulk_kelas','user',0, json_encode(['kelas'=>$kelas,'success'=>$success],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  jsonOut(['success'=>true,'data'=>['success'=>$success,'failed'=>$failed,'ids'=>$createdIds,'kelas'=>$kelas,'password_hint'=>$password]]);
}
if($uri==='/users' && $method==='GET'){
  requireRole('admin');
  // rate limit GET juga (PRD: 60/min per IP)
  usersRateLimit('users_get');
  $q=trim($_GET['q']??$_GET['search']??'');
  $role=trim($_GET['role']??'all');
  $statusFilter=trim($_GET['status']??'all');
  $kelasFilter=trim($_GET['kelas']??'all');
  $sort=trim($_GET['sort']??'newest');
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $allowedRoles=['admin','pembina','siswa','kepsek'];
  $allowedStatus=['aktif','suspended','nonaktif'];
  // WHERE builder — PDO prepared 100%, no concat user input
  $where=['u.deleted_at IS NULL']; $par=[];
  if($q!==''){ $where[]='(u.nama LIKE ? OR u.email LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; }
  if($role!=='' && $role!=='all'){
    if(!in_array($role,$allowedRoles,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Role filter tidak valid']],422);
    $where[]='u.role=?'; $par[]=$role;
  }
  if($statusFilter!=='' && $statusFilter!=='all'){
    if(!in_array($statusFilter,$allowedStatus,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Status filter tidak valid']],422);
    $where[]='u.status=?'; $par[]=$statusFilter;
  }
  if($kelasFilter!=='' && $kelasFilter!=='all'){
    $where[]='u.kelas=?'; $par[]=$kelasFilter;
  }
  $order=' ORDER BY u.created_at DESC, u.id DESC';
  if($sort==='nama') $order=' ORDER BY u.nama ASC';
  else if($sort==='role') $order=' ORDER BY FIELD(u.role,"admin","kepsek","pembina","siswa"), u.nama ASC';
  else if($sort==='newest') $order=' ORDER BY u.created_at DESC, u.id DESC';
  else if($sort==='oldest') $order=' ORDER BY u.created_at ASC, u.id ASC';
  else if($sort==='email') $order=' ORDER BY u.email ASC';
  else if($sort==='status') $order=' ORDER BY FIELD(u.status,"aktif","suspended","nonaktif"), u.nama ASC';
  $whereSql=implode(' AND ',$where);
  // total COUNT(*)
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM users u WHERE $whereSql"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // stats for header cards (unfiltered total + per role) + archived count + status counts
  $stats=['total'=>0,'siswa'=>0,'pembina'=>0,'admin_kepsek'=>0];
  $archivedTotal=0; $statusCounts=['aktif'=>0,'suspended'=>0,'nonaktif'=>0];
  try{
    $s=pdo()->query("SELECT role, COUNT(*) c FROM users WHERE deleted_at IS NULL GROUP BY role");
    foreach($s->fetchAll() as $r){ if($r['role']==='siswa') $stats['siswa']=(int)$r['c']; else if($r['role']==='pembina') $stats['pembina']=(int)$r['c']; else if(in_array($r['role'],['admin','kepsek'],true)) $stats['admin_kepsek']+=(int)$r['c']; }
    $stats['total']=$stats['siswa']+$stats['pembina']+$stats['admin_kepsek'];
    $ac=pdo()->query("SELECT COUNT(*) c FROM users WHERE deleted_at IS NOT NULL")->fetch(); $archivedTotal=(int)($ac['c']??0);
    $sc=pdo()->query("SELECT status, COUNT(*) c FROM users WHERE deleted_at IS NULL GROUP BY status"); foreach($sc->fetchAll() as $r){ if(isset($statusCounts[$r['status']])) $statusCounts[$r['status']]=(int)$r['c']; }
  }catch(Exception $e){}
  // data — SELECT only needed cols, no SELECT *, no N+1, no password_hash
  $sql="SELECT u.id,u.nama,u.email,u.role,u.nip,u.kelas,u.status,u.created_at,u.last_login_at,u.updated_at FROM users u WHERE $whereSql $order LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  // ESCAPE XSS in nama/email for consumers that dangerouslySetInnerHTML
  foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['nip']=$r['nip']?e($r['nip']):null; $r['kelas']=$r['kelas']?e($r['kelas']):null; }
  unset($r);
  // headers: private no-cache + weak ETag berbasis total+max(updated_at) + Pragma + Vary
  $maxUpd=''; try{ $mu=pdo()->prepare("SELECT MAX(updated_at) m FROM users WHERE deleted_at IS NULL"); $mu->execute(); $maxUpd=$mu->fetch()['m']??''; }catch(Exception $e){}
  $uid=currentUser()['id']??0;
  $etag='W/"'.md5($total.'|'.$maxUpd.'|'.$q.'|'.$role.'|'.$kelasFilter.'|'.$statusFilter.'|'.$sort.'|'.$page.'|'.$limit.'|'.$uid).'"';
  header('ETag: '.$etag);
  header('Cache-Control: private, no-store, max-age=0, must-revalidate');
  header('Pragma: no-cache');
  header('Vary: Cookie');
  header('X-Total-Count: '.$total);
  header('X-Page: '.$page);
  header('X-Limit: '.$limit);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit),'stats'=>$stats,'archivedTotal'=>$archivedTotal,'statusCounts'=>$statusCounts]]);
}
// POST /users — admin only, CSRF already guarded above, rate limit, validasi 422, 409 duplicate, bcrypt, audit
if($uri==='/users' && $method==='POST'){
  requireRole('admin');
  usersRateLimit('users_post');
  $b=getBody();
  $nama=trim($b['nama']??''); $email=trim($b['email']??''); $password=$b['password']??''; $role=trim($b['role']??'');
  $kelas=trim($b['kelas']??''); $nip=trim($b['nip']??'');
  if($role==='') $role='siswa';
  $err=''; if(!validateUserPayload($nama,$email,$password,$role,$err,$kelas?:null,$nip?:null,false)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>$err]],422);
  // unique email check (including soft-deleted? we allow reuse if deleted — so check deleted_at IS NULL)
  $chk=pdo()->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL'); $chk->execute([$email]); if($chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Email sudah ada']],409);
  $hash=password_hash($password, PASSWORD_BCRYPT);
  try{
    $st=pdo()->prepare('INSERT INTO users(nama,email,password_hash,role,nip,kelas,status) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$nama,$email,$hash,$role, $nip?:null, $kelas?:null, 'aktif']);
  }catch(PDOException $e){
    if(strpos($e->getMessage(),'Duplicate')!==false || $e->getCode()=='23000') jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Email sudah ada']],409);
    throw $e;
  }
  $id=pdo()->lastInsertId();
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'create','user',$id, json_encode(['email'=>$email,'role'=>$role],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['id'=>$id,'nama'=>$nama,'email'=>$email,'role'=>$role,'kelas'=>$kelas,'nip'=>$nip,'password'=>$password]],201);
}
if(routeMatch('/users/:id',$uri,$pm) && $method==='GET'){
  requireRole('admin');
  usersRateLimit('users_get');
  $id=(int)$pm['id'];
  $st=pdo()->prepare('SELECT id,nama,email,role,nip,kelas,status,created_at,last_login_at,updated_at FROM users WHERE id=? AND deleted_at IS NULL'); $st->execute([$id]); $r=$st->fetch();
  if(!$r) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  $r['nama']=e($r['nama']); $r['email']=e($r['email']);
  jsonOut(['success'=>true,'data'=>$r]);
}
if(routeMatch('/users/:id',$uri,$pm) && in_array($method,['PUT','PATCH'],true)){
  requireRole('admin');
  usersRateLimit('users_put');
  $id=(int)$pm['id']; $b=getBody();
  $cur=pdo()->prepare('SELECT id,nama,email,role FROM users WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $curRow=$cur->fetch();
  if(!$curRow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  // prevent admin editing own role to non-admin accidentally? allow but warn — not blocked
  $nama=array_key_exists('nama',$b)?trim($b['nama']):$curRow['nama'];
  $email=array_key_exists('email',$b)?trim($b['email']):$curRow['email'];
  $role=array_key_exists('role',$b)?trim($b['role']):$curRow['role'];
  $password=array_key_exists('password',$b)?$b['password']:null;
  $kelas=array_key_exists('kelas',$b)?trim($b['kelas']):null;
  $nip=array_key_exists('nip',$b)?trim($b['nip']):null;
  $err=''; if(!validateUserPayload($nama,$email,$password??'', $role,$err,$kelas,$nip,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>$err]],422);
  if($password!==null && $password!=='' && mb_strlen($password)<8) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Password minimal 8 karakter']],422);
  // email unique if changed
  if(strtolower($email)!==strtolower($curRow['email'])){
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Email tidak valid']],422);
    $chk=pdo()->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL AND id<>?'); $chk->execute([$email,$id]); if($chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Email sudah ada']],409);
  }
  $sets=[]; $par=[];
  if(array_key_exists('nama',$b)){ $sets[]='nama=?'; $par[]=$nama; }
  if(array_key_exists('email',$b)){ $sets[]='email=?'; $par[]=$email; }
  if(array_key_exists('role',$b)){ $sets[]='role=?'; $par[]=$role; }
  if(array_key_exists('kelas',$b)){ $sets[]='kelas=?'; $par[]=$kelas?:null; }
  if(array_key_exists('nip',$b)){ $sets[]='nip=?'; $par[]=$nip?:null; }
  if($password!==null && $password!==''){ $sets[]='password_hash=?'; $par[] = password_hash($password, PASSWORD_BCRYPT); }
  if(!$sets) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tidak ada field']],422);
  $sets[]='updated_at=NOW()';
  $par[]=$id; pdo()->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($par);
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'update','user',$id, json_encode($b,JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['id'=>$id]]);
}
if(routeMatch('/users/:id',$uri,$pm) && $method==='DELETE'){
  requireRole('admin');
  usersRateLimit('users_del');
  $id=(int)$pm['id'];
  $cu=currentUser();
  if((int)$cu['id']===$id) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak boleh hapus diri sendiri']],403);
  $cur=pdo()->prepare('SELECT id,email FROM users WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  $now=date('Y-m-d H:i:s');
  // soft-delete: set deleted_at + suffix email to keep UNIQUE (email+deleted still unique constraint would block otherwise)
  // strategy: keep email but UNIQUE is on email column — deleted rows still count. So we suffix email with _deleted_{id}_{time}
  // to allow reuse of email after soft-delete while keeping audit.
  $suffix='_deleted_'.$id.'_'.time();
  pdo()->prepare('UPDATE users SET deleted_at=?, email=CONCAT(email,?), updated_at=? WHERE id=? AND deleted_at IS NULL')->execute([$now,$suffix,$now,$id]);
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$cu['id'],'delete','user',$id, json_encode(['email'=>$row['email'],'deleted_at'=>$now],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/users/:id/reset-password',$uri,$pm) && $method==='POST'){
  requireRole('admin');
  usersRateLimit('users_reset');
  $id=(int)$pm['id']; $b=getBody(); $newPass=$b['password']??$b['new_password']??'';
  if(mb_strlen($newPass)<8) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Password minimal 8 karakter']],422);
  $cur=pdo()->prepare('SELECT id FROM users WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); if(!$cur->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  $hash=password_hash($newPass, PASSWORD_BCRYPT);
  pdo()->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$hash,$id]);
  try{ $ip=$_SERVER['REMOTE_ADDR']??''; pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'reset_password','user',$id, json_encode(['reset_at'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'reset_password','user',$id, json_encode(['reset_at'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  jsonOut(['success'=>true,'data'=>null]);
}
// === LUPA PASSWORD 2 METODE (tanpa SMTP) ===
// Metode dibaca dari app_settings.forgot_password_method: 'temp' | 'link'.
// - POST /auth/forgot-password (PUBLIK): siswa kirim email -> notif ke semua admin (klik -> /admin/users/:id/reset).
// - POST /users/:id/forgot-reset (ADMIN): reset sesuai metode aktif -> temp: generate password sementara + set langsung;
//   link: generate token sekali pakai (hash sha256 di DB, expiry 30 menit) -> FE tampilkan link copyable.
// - GET /auth/reset-info?token= (PUBLIK): cek validitas token (tanpa bocorkan user).
// - POST /auth/reset-confirm (PUBLIK): siswa buat password baru via token sekali pakai.
function forgotGenTempPass(int $len=10): string{
  $alph='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  $out=''; $max=strlen($alph)-1;
  for($i=0;$i<$len;$i++){ try{ $out.=$alph[random_int(0,$max)]; }catch(Throwable $e){ $out.=$alph[mt_rand(0,$max)]; } }
  return $out;
}
function forgotMethod(): string{
  $m=null; try{ $m=getAppSetting('forgot_password_method'); }catch(Throwable $e){}
  return in_array($m,['temp','link'],true) ? $m : 'temp';
}
function forgotRateLimit(string $key='forgot'){
  $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';
  $file=sys_get_temp_dir()."/rl_{$key}_".md5($ip).".json";
  $now=time(); $cnt=0; $win=$now;
  $fh=@fopen($file,'c+'); if($fh){ @flock($fh,LOCK_EX); $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null; if($j && isset($j['start']) && ($now-(int)$j['start']<300)){ $cnt=(int)($j['count']??0); $win=(int)$j['start']; } else { $cnt=0; $win=$now; } if($cnt>=10){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 300'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu banyak permintaan, coba 5 menit lagi']],429); } $cnt++; @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt,'start'=>$win])); @fflush($fh); @flock($fh,LOCK_UN); @fclose($fh); }
}
if($uri==='/auth/forgot-password' && $method==='POST'){
  forgotRateLimit('forgot');
  $b=getBody(); $email=trim((string)($b['email']??''));
  // respons generik anti-enumerasi: selalu sukses meski email tidak ada
  $done=function() use ($email){ jsonOut(['success'=>true,'data'=>null,'message'=>'Tenang, permintaanmu sudah diteruskan ke admin. Admin akan segera membantu mereset password-mu.']); };
  if($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) $done();
  try{
    $st=pdo()->prepare('SELECT id,nama,email FROM users WHERE email=? AND deleted_at IS NULL'); $st->execute([$email]); $u=$st->fetch();
    if(!$u) $done();
    $uid=(int)$u['id'];
    $method=forgotMethod();
    $label=$method==='link'?'Link Reset Sekali Pakai':'Password Sementara';
    notifyAdmins('Permintaan reset password: '.($u['nama']??$email), 'Siswa '.($u['nama']??'').' ('.$email.') meminta reset password. Metode aktif: '.$label.'. Buka Users lalu Reset user ini.', 'forgot_password', 'forgot_'.$uid, $uid);
    try{ $ip=$_SERVER['REMOTE_ADDR']??''; pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([null,'forgot_request','user',$uid, json_encode(['email'=>$email,'method'=>$method],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){}
  }catch(Throwable $e){}
  $done();
}
if(routeMatch('/users/:id/forgot-reset',$uri,$pm) && $method==='POST'){
  requireRole('admin');
  usersRateLimit('users_reset');
  $id=(int)$pm['id'];
  $cu=currentUser();
  $st=pdo()->prepare('SELECT id,nama,email FROM users WHERE id=? AND deleted_at IS NULL'); $st->execute([$id]); $u=$st->fetch();
  if(!$u) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  $method=forgotMethod();
  if($method==='temp'){
    $tmp=forgotGenTempPass(10);
    $hash=password_hash($tmp, PASSWORD_BCRYPT);
    pdo()->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$hash,$id]);
    try{ pdo()->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$id]); }catch(Exception $e){}
    try{ $ip=$_SERVER['REMOTE_ADDR']??''; pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$cu['id'],'forgot_reset_temp','user',$id, json_encode(['reset_at'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){}
    jsonOut(['success'=>true,'data'=>['method'=>'temp','user_id'=>$id,'nama'=>$u['nama']??'','email'=>$u['email']??'','temp_password'=>$tmp]]);
  }
  // link: token mentah ke admin (copyable), yang disimpan hanya hash sha256
  try{ pdo()->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$id]); }catch(Exception $e){}
  try{ $raw=bin2hex(random_bytes(32)); }catch(Throwable $e){ $raw=bin2hex(openssl_random_pseudo_bytes(32)); }
  $th=hash('sha256',$raw);
  $exp=date('Y-m-d H:i:s', time()+30*60);
  try{ pdo()->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at,created_by) VALUES (?,?,?,?)')->execute([$id,$th,$exp,$cu['id']]); }
  catch(Exception $e){ jsonOut(['success'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal membuat link reset']],500); }
  try{ $ip=$_SERVER['REMOTE_ADDR']??''; pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$cu['id'],'forgot_reset_link','user',$id, json_encode(['reset_at'=>date('Y-m-d H:i:s'),'expires_at'=>$exp],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){}
  $appUrl=''; try{ $appUrl=(string)(getAppSetting('app_url')??''); }catch(Throwable $e){}
  $base=$appUrl!==''?rtrim($appUrl,'/'):'';
  $link=$base.'/reset-password?token='.$raw;
  jsonOut(['success'=>true,'data'=>['method'=>'link','user_id'=>$id,'nama'=>$u['nama']??'','email'=>$u['email']??'','token'=>$raw,'reset_link'=>$link,'expires_at'=>$exp,'expires_minutes'=>30]]);
}
if($uri==='/auth/reset-info' && $method==='GET'){
  $tok=trim((string)($_GET['token']??''));
  if($tok==='' || !preg_match('/^[a-f0-9]{64}$/i',$tok)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Token tidak valid']],422);
  $th=hash('sha256',strtolower($tok));
  try{
    $st=pdo()->prepare('SELECT id,expires_at,used_at FROM password_resets WHERE token_hash=?'); $st->execute([$th]); $r=$st->fetch();
    if(!$r) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Link tidak ditemukan / sudah dipakai']],404);
    if(!empty($r['used_at'])) jsonOut(['success'=>false,'error'=>['code'=>'GONE','message'=>'Link sudah dipakai']],410);
    if(strtotime($r['expires_at']) < time()) jsonOut(['success'=>false,'error'=>['code'=>'GONE','message'=>'Link kadaluarsa (30 menit)']],410);
    jsonOut(['success'=>true,'data'=>['valid'=>true]]);
  }catch(Exception $e){ jsonOut(['success'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal cek token']],500); }
}
if($uri==='/auth/reset-confirm' && $method==='POST'){
  forgotRateLimit('reset_confirm');
  $b=getBody(); $tok=trim((string)($b['token']??'')); $p1=(string)($b['password']??$b['new_password']??''); $p2=(string)($b['konfirmasi']??$b['password_confirm']??$p1);
  if($tok==='' || !preg_match('/^[a-f0-9]{64}$/i',$tok)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Token tidak valid']],422);
  if(mb_strlen($p1)<8) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Password minimal 8 karakter']],422);
  if($p1!==$p2) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Konfirmasi tidak cocok']],422);
  $th=hash('sha256',strtolower($tok));
  try{
    $st=pdo()->prepare('SELECT id,user_id,expires_at,used_at FROM password_resets WHERE token_hash=?'); $st->execute([$th]); $r=$st->fetch();
    if(!$r) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Link tidak ditemukan / sudah dipakai']],404);
    if(!empty($r['used_at'])) jsonOut(['success'=>false,'error'=>['code'=>'GONE','message'=>'Link sudah dipakai']],410);
    if(strtotime($r['expires_at']) < time()) jsonOut(['success'=>false,'error'=>['code'=>'GONE','message'=>'Link kadaluarsa (30 menit)']],410);
    $uid=(int)$r['user_id'];
    $hash=password_hash($p1, PASSWORD_BCRYPT);
    pdo()->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$hash,$uid]);
    pdo()->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=?')->execute([(int)$r['id']]);
    try{ pdo()->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$uid]); }catch(Exception $e){}
    try{ $ip=$_SERVER['REMOTE_ADDR']??''; pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([null,'reset_confirm','user',$uid, json_encode(['reset_at'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){}
    jsonOut(['success'=>true,'data'=>null,'message'=>'Password berhasil diganti, silakan login']);
  }catch(Exception $e){ if(headers_sent()) exit; jsonOut(['success'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal reset password']],500); }
}
// === PROFILE SELF-SERVICE — Ganti Password + Foto Profil (semua role login, CSRF via global guard) ===
function avatarProcess(string $tmp, string $dest, int $maxDim=512, int $q=80): bool{
  if(!function_exists('imagecreatefromstring')) return false;
  $bin=@file_get_contents($tmp); if($bin===false||$bin==='') return false;
  $im=@imagecreatefromstring($bin); if(!$im) return false;
  $w=imagesx($im); $h=imagesy($im);
  if($w<=0||$h<=0){ imagedestroy($im); return false; }
  $nw=$w; $nh=$h;
  if(max($w,$h)>$maxDim){ $sc=$maxDim/max($w,$h); $nw=max(1,(int)round($w*$sc)); $nh=max(1,(int)round($h*$sc)); }
  if($nw!==$w||$nh!==$h){
    $dst=imagecreatetruecolor($nw,$nh);
    imagealphablending($dst,false); imagesavealpha($dst,true);
    $trans=imagecolorallocatealpha($dst,0,0,0,127); imagefilledrectangle($dst,0,0,$nw,$nh,$trans);
    imagecopyresampled($dst,$im,0,0,0,0,$nw,$nh,$w,$h);
    imagedestroy($im); $im=$dst;
  }
  imagealphablending($im,false); imagesavealpha($im,true);
  if(substr($dest,-5)==='.webp' && function_exists('imagewebp')) $ok=@imagewebp($im,$dest,$q);
  else $ok=@imagejpeg($im,$dest,$q);
  imagedestroy($im);
  return !empty($ok) && is_file($dest);
}
// POST /auth/change-password — body: password_lama, password_baru, konfirmasi. Tetap login.
if($uri==='/auth/change-password' && $method==='POST'){
  requireLogin();
  usersRateLimit('change_password');
  $b=getBody();
  $lama=(string)($b['password_lama']??'');
  $baru=(string)($b['password_baru']??$b['new_password']??'');
  $konf=(string)($b['konfirmasi']??$b['konfirmasi_password']??$b['password_confirm']??'');
  if($lama===''||$baru===''||$konf==='') jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Password lama, password baru, dan konfirmasi wajib diisi']],400);
  if(mb_strlen($baru)<8) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Password baru minimal 8 karakter']],400);
  if($baru!==$konf) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Konfirmasi password tidak sama']],400);
  if(hash_equals($baru,$lama)) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Password baru tidak boleh sama dengan password lama']],400);
  $uid=(int)currentUser()['id'];
  $st=pdo()->prepare('SELECT id,password_hash FROM users WHERE id=? AND deleted_at IS NULL'); $st->execute([$uid]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ditemukan']],404);
  if(!password_verify($lama,$row['password_hash'])) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'AUTH','message'=>'Password lama salah']],401);
  $hash=password_hash($baru,PASSWORD_BCRYPT);
  pdo()->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$hash,$uid]);
  // revoke remember-me perangkat lain, pertahankan cookie sesi ini
  try{
    $raw=$_COOKIE['remember_me']??'';
    if($raw && strpos($raw,':')!==false){ [$sel]=explode(':',$raw,2); pdo()->prepare('DELETE FROM remember_tokens WHERE user_id=? AND selector<>?')->execute([$uid,$sel]); }
  }catch(Exception $e){}
  try{ $ip=$_SERVER['REMOTE_ADDR']??''; pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$uid,'change_password','user',$uid,json_encode(['at'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$uid,'change_password','user',$uid,'{}']); }catch(Exception $e2){} }
  session_regenerate_id(true);
  jsonOut(['success'=>true,'ok'=>true,'message'=>'Password berhasil diganti']);
}
// POST /me/avatar — field `avatar`, max 2MB, resize GD max 512px -> webp/jpg q80
if($uri==='/me/avatar' && $method==='POST'){
  requireLogin();
  sertifikatRateLimit('avatar_upload',20);
  if(!isset($_FILES['avatar']) || $_FILES['avatar']['error']===UPLOAD_ERR_NO_FILE) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'File avatar wajib (field name: avatar)']],422);
  $f=$_FILES['avatar'];
  if($f['error']!==UPLOAD_ERR_OK) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Upload gagal code '.$f['error']]],422);
  if($f['size']>2*1024*1024) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Avatar maksimal 2MB']],422);
  $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));
  $blocked=['php','phtml','phar','sh','exe','js','html','htm'];
  if(in_array($ext,$blocked,true)) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Ekstensi .'.$ext.' tidak diperbolehkan']],422);
  $tmp=$f['tmp_name'];
  $info=@getimagesize($tmp);
  if(!$info) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'File bukan gambar valid']],422);
  $mime=$info['mime']??'';
  $fi=@finfo_open(FILEINFO_MIME_TYPE); $fm=$fi?@finfo_file($fi,$tmp):''; if($fi) @finfo_close($fi);
  if($fm && strpos($fm,'image/')===0) $mime=$fm;
  if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'VALIDATION','message'=>'Avatar hanya JPG/PNG/WEBP']],422);
  $uid=(int)currentUser()['id'];
  $dir=uploadPath('avatars');
  $oext=function_exists('imagewebp')?'webp':'jpg';
  $name='user_'.$uid.'_'.bin2hex(random_bytes(6)).'.'.$oext;
  $savePath=$dir.'/'.$name;
  $ok=avatarProcess($tmp,$savePath,512,80);
  if(!$ok){ // fallback: simpan asli yang sudah tervalidasi gambar
    $oext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
    $name='user_'.$uid.'_'.bin2hex(random_bytes(6)).'.'.$oext;
    $savePath=$dir.'/'.$name;
    if(@move_uploaded_file($tmp,$savePath)) $ok=true; else $ok=@copy($tmp,$savePath);
  }
  if(!$ok||!is_file($savePath)) jsonOut(['success'=>false,'ok'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal menyimpan avatar']],500);
  $rel='uploads/avatars/'.$name;
  try{
    $cur=pdo()->prepare('SELECT foto FROM users WHERE id=?'); $cur->execute([$uid]); $old=$cur->fetch()['foto']??null;
    if($old && $old!==$rel && str_starts_with($old,'uploads/avatars/') && !str_contains($old,'..')){ $abs=uploadPath($old); if(is_file($abs)) @unlink($abs); }
    foreach(glob($dir.'/user_'.$uid.'_*')?:[] as $p){ if($p!==$savePath && is_file($p)) @unlink($p); }
  }catch(Exception $e){}
  pdo()->prepare('UPDATE users SET foto=?, updated_at=NOW() WHERE id=?')->execute([$rel,$uid]);
  $_SESSION['user']['foto']=$rel; $_SESSION['user']['foto_url']='/api/avatar/'.$uid;
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$uid,'upload_avatar','user',$uid,json_encode(['size'=>$f['size'],'mime'=>$mime],JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  jsonOut(['success'=>true,'ok'=>true,'foto_url'=>'/api/avatar/'.$uid,'data'=>['foto_url'=>'/api/avatar/'.$uid,'foto'=>$rel]]);
}
// DELETE /me/avatar — hapus file + set NULL
if($uri==='/me/avatar' && $method==='DELETE'){
  requireLogin();
  $uid=(int)currentUser()['id'];
  $cur=pdo()->prepare('SELECT foto FROM users WHERE id=?'); $cur->execute([$uid]); $old=$cur->fetch()['foto']??null;
  if($old && str_starts_with($old,'uploads/avatars/') && !str_contains($old,'..')){ $abs=uploadPath($old); if(is_file($abs)) @unlink($abs); }
  pdo()->prepare('UPDATE users SET foto=NULL, updated_at=NOW() WHERE id=?')->execute([$uid]);
  $_SESSION['user']['foto']=null; $_SESSION['user']['foto_url']=null;
  jsonOut(['success'=>true,'ok'=>true,'message'=>'Avatar dihapus']);
}
// GET /avatar/:id — serve publik, ETag/304 (pola GET /covers/:tipe/:id)
if(routeMatch('/avatar/:id',$uri,$pm) && $method==='GET'){
  $id=(int)$pm['id'];
  $st=pdo()->prepare("SELECT foto FROM users WHERE id=? AND deleted_at IS NULL"); $st->execute([$id]); $row=$st->fetch();
  $rel=$row['foto']??'';
  if(!$rel || !str_starts_with($rel,'uploads/avatars/') || str_contains($rel,'..') || str_contains($rel,"\0")){ http_response_code(404); exit; }
  $abs=uploadPath($rel);
  if(!is_file($abs)){ http_response_code(404); exit; }
  $ext=strtolower(pathinfo($abs,PATHINFO_EXTENSION));
  $mimeMap=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
  $mime=$mimeMap[$ext]??'application/octet-stream';
  $mtime=filemtime($abs);
  $etag='"'.md5($rel.'.'.$mtime).'"';
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  header('Content-Type: '.$mime);
  header('Content-Length: '.filesize($abs));
  header('Cache-Control: public, max-age=86400');
  header('ETag: '.$etag);
  readfile($abs);
  exit;
}
// === USERS PRODUCTION: suspend, archived, restore, purge, export, import, bulk, logs ===
if(routeMatch('/users/:id/suspend',$uri,$pm) && $method==='POST'){
  requireRole('admin');
  usersRateLimit('users_suspend');
  $id=(int)$pm['id']; $b=getBody();
  $action=trim($b['action']??$b['status']??'');
  // toggle if no action: suspended -> aktif, else aktif -> suspended
  $cur=pdo()->prepare('SELECT id,status,nama FROM users WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  if((int)currentUser()['id']===$id) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak boleh suspend diri sendiri']],403);
  $curr=$row['status']??'aktif';
  $next='';
  if($action==='suspend' || $action==='suspended') $next='suspended';
  else if($action==='activate' || $action==='aktif') $next='aktif';
  else if($action==='nonaktif') $next='nonaktif';
  else { // toggle
    if($curr==='aktif') $next='suspended';
    else $next='aktif';
  }
  if(!in_array($next,['aktif','suspended','nonaktif'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Status tidak valid']],422);
  pdo()->prepare('UPDATE users SET status=?, updated_at=NOW() WHERE id=?')->execute([$next,$id]);
  $ip=$_SERVER['REMOTE_ADDR']??'';
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'suspend','user',$id, json_encode(['from'=>$curr,'to'=>$next,'user_nama'=>$row['nama']],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'suspend','user',$id, json_encode(['from'=>$curr,'to'=>$next],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  // notify admin if suspend/nonaktif (not on activate)
  if($next==='suspended' || $next==='nonaktif'){
    try{ notifyAdmins('User di-suspend: '.$row['nama'], $row['nama'].' ('.$next.') — oleh '.(currentUser()['nama']??'Admin'), 'suspend', 'suspend:daily:'.date('Y-m-d'), $id); }catch(Exception $e){}
  }
  jsonOut(['success'=>true,'data'=>['id'=>$id,'status'=>$next]]);
}
if($uri==='/users/archived' && $method==='GET'){
  requireRole('admin');
  usersRateLimit('users_archived');
  $q=trim($_GET['q']??$_GET['search']??'');
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $where=['u.deleted_at IS NOT NULL']; $par=[];
  if($q!==''){ $where[]='(u.nama LIKE ? OR u.email LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; }
  $whereSql=implode(' AND ',$where);
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM users u WHERE $whereSql"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  $sql="SELECT u.id,u.nama,u.email,u.role,u.nip,u.kelas,u.status,u.created_at,u.deleted_at FROM users u WHERE $whereSql ORDER BY u.deleted_at DESC LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); } unset($r);
  $uid=currentUser()['id']??0; $etag='W/"arch-'.md5($total.'|'.$page.'|'.$limit.'|'.$q.'|'.$uid).'"';
  header('ETag: '.$etag); header('Cache-Control: private, no-store, max-age=0, must-revalidate'); header('Pragma: no-cache'); header('Vary: Cookie'); header('X-Total-Count: '.$total);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit)]]);
}
if(routeMatch('/users/:id/restore',$uri,$pm) && $method==='POST'){
  requireRole('admin');
  usersRateLimit('users_restore');
  $id=(int)$pm['id'];
  $cur=pdo()->prepare('SELECT id,email,nama,deleted_at FROM users WHERE id=? AND deleted_at IS NOT NULL'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User arsip tidak ada']],404);
  // recover original email: strip _deleted_{id}_{time} suffix
  $origEmail=preg_replace('/_deleted_\d+_\d+$/','',$row['email']);
  // check duplicate active email
  $chk=pdo()->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL AND id<>?'); $chk->execute([$origEmail,$id]); if($chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Email asli sudah dipakai user aktif, ganti email dulu']],409);
  pdo()->prepare('UPDATE users SET deleted_at=NULL, email=?, updated_at=NOW() WHERE id=?')->execute([$origEmail,$id]);
  $ip=$_SERVER['REMOTE_ADDR']??'';
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'restore','user',$id, json_encode(['email'=>$origEmail,'nama'=>$row['nama']],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'restore','user',$id, json_encode(['email'=>$origEmail],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  jsonOut(['success'=>true,'data'=>['id'=>$id,'email'=>$origEmail]]);
}
if(routeMatch('/users/:id/purge',$uri,$pm) && $method==='DELETE'){
  requireRole('admin');
  usersRateLimit('users_purge');
  $id=(int)$pm['id']; $b=getBody();
  $confirm=trim($b['confirm']??$b['nama']??'');
  $cur=pdo()->prepare('SELECT id,nama,email,deleted_at FROM users WHERE id=? AND deleted_at IS NOT NULL'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User arsip tidak ada']],404);
  if($confirm!==$row['nama']) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Konfirmasi nama tidak cocok. Ketik: '.$row['nama']]],422);
  if((int)currentUser()['id']===$id) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak boleh purge diri sendiri']],403);
  pdo()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
  $ip=$_SERVER['REMOTE_ADDR']??'';
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'purge','user',$id, json_encode(['nama'=>$row['nama'],'email'=>$row['email']],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){ try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'purge','user',$id, json_encode(['nama'=>$row['nama']],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  try{ notifyAdmins('User di-purge: '.$row['nama'], $row['nama'].' dihapus permanen — oleh '.(currentUser()['nama']??'Admin'), 'purge', 'purge:daily:'.date('Y-m-d'), $id); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>null]);
}
if($uri==='/users/bulk-restore' && $method==='POST'){
  requireRole('admin');
  $b=getBody();
  $ids=$b['ids']??null;
  if(!is_array($ids) || count($ids)===0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids wajib array non-empty']],422);
  if(count($ids)>100) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maks 100 ids']],422);
  $restored=0; $failed=[];
  foreach($ids as $rawId){
    $id=(int)$rawId;
    if($id<=0){ $failed[]=['id'=>$rawId,'reason'=>'ID tidak valid']; continue; }
    $cur=pdo()->prepare('SELECT id,email,nama,deleted_at FROM users WHERE id=? AND deleted_at IS NOT NULL'); $cur->execute([$id]); $row=$cur->fetch();
    if(!$row){ $failed[]=['id'=>$id,'reason'=>'User arsip tidak ada']; continue; }
    $origEmail=preg_replace('/_deleted_\d+_\d+$/','',$row['email']);
    $chk=pdo()->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL AND id<>?'); $chk->execute([$origEmail,$id]); if($chk->fetch()){ $failed[]=['id'=>$id,'reason'=>'Email sudah dipakai user aktif']; continue; }
    pdo()->prepare('UPDATE users SET deleted_at=NULL, email=?, updated_at=NOW() WHERE id=?')->execute([$origEmail,$id]);
    $restored++;
  }
  jsonOut(['success'=>true,'restored'=>$restored,'failed'=>$failed]);
}
if($uri==='/users/bulk-purge' && $method==='POST'){
  requireRole('admin');
  $b=getBody();
  $ids=$b['ids']??null;
  if(!is_array($ids) || count($ids)===0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids wajib array non-empty']],422);
  if(count($ids)>100) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maks 100 ids']],422);
  $purged=0; $failed=[];
  foreach($ids as $rawId){
    $id=(int)$rawId;
    if($id<=0){ $failed[]=['id'=>$rawId,'reason'=>'ID tidak valid']; continue; }
    $cur=pdo()->prepare('SELECT id FROM users WHERE id=? AND deleted_at IS NOT NULL'); $cur->execute([$id]); $row=$cur->fetch();
    if(!$row){ $failed[]=['id'=>$id,'reason'=>'User arsip tidak ada']; continue; }
    pdo()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    $purged++;
  }
  jsonOut(['success'=>true,'purged'=>$purged,'failed'=>$failed]);
}
if($uri==='/users/export' && $method==='GET'){
  requireRole('admin');
  // rate limit export 5/menit per IP
  $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';
  $f=sys_get_temp_dir()."/rl_export_".md5($ip).".json"; $now=time(); $cnt=0; $start=$now;
  $fh=@fopen($f,'c+'); if($fh){ @flock($fh,LOCK_EX); $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null; if($j && ($now-(int)($j['start']??0)<60)){$cnt=(int)($j['count']??0); $start=(int)$j['start'];} if($cnt>=5){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Export max 5/menit']],429);} @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt+1,'start'=>$start])); @flock($fh,LOCK_UN); @fclose($fh); }
  $q=trim($_GET['q']??''); $role=trim($_GET['role']??'all'); $statusF=trim($_GET['status']??'all'); $kelasF=trim($_GET['kelas']??'all'); $format=trim($_GET['format']??'csv');
  if($format!=='' && $format!=='csv') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Format hanya csv']],422);
  $where=['deleted_at IS NULL']; $par=[];
  if($q!==''){ $where[]='(nama LIKE ? OR email LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; }
  if($role!=='' && $role!=='all'){ if(!in_array($role,['admin','pembina','siswa','kepsek'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Role tidak valid']],422); $where[]='role=?'; $par[]=$role; }
  if($statusF!=='' && $statusF!=='all'){ if(!in_array($statusF,['aktif','suspended','nonaktif'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Status tidak valid']],422); $where[]='status=?'; $par[]=$statusF; }
  if($kelasF!=='' && $kelasF!=='all'){ $where[]='kelas=?'; $par[]=$kelasF; }
  $whereSql=implode(' AND ',$where);
  $pdo=pdo();
  // audit export
  $ip2=$_SERVER['REMOTE_ADDR']??''; try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'export','user',0, json_encode(['q'=>$q,'role'=>$role,'status'=>$statusF],JSON_UNESCAPED_UNICODE),$ip2]); }catch(Exception $e){}
  $wasBuffered=null; try{ $wasBuffered=$pdo->getAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')); $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),false); }catch(Exception $e){}
  if(ob_get_level()) @ob_end_clean();
  header_remove('Content-Type');
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="users-'.date('Y-m-d').'.csv"');
  header('Cache-Control: private, no-store, max-age=0, must-revalidate'); header('Pragma: no-cache');
  echo "\xEF\xBB\xBF";
  $out=fopen('php://output','w');
  fputcsv($out, ['Nama','Email','Role','Kelas/NIP','Status','Terdaftar','Last Login']);
  $st=$pdo->prepare("SELECT nama,email,role,kelas,nip,status,created_at,last_login_at FROM users WHERE $whereSql ORDER BY created_at DESC");
  $st->execute($par);
  while($row=$st->fetch(PDO::FETCH_ASSOC)){
    $kelasNip = $row['role']==='siswa' ? ($row['kelas']??'') : ($row['nip']??'');
    fputcsv($out, [$row['nama'],$row['email'],$row['role'],$kelasNip,$row['status']??'aktif',$row['created_at'],$row['last_login_at']??'']);
    if(ob_get_level()) @ob_flush(); @flush();
  }
  fclose($out);
  if($wasBuffered!==null){ try{ $pdo->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : @constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'),$wasBuffered);}catch(Exception $e){} }
  exit;
}
if($uri==='/users/bulk' && $method==='POST'){
  requireRole('admin');
  usersRateLimit('users_bulk');
  $b=getBody();
  $ids=$b['ids']??[]; $action=trim($b['action']??''); $value=trim($b['value']??'');
  if(!is_array($ids) || count($ids)===0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids wajib array']],422);
  if(count($ids)>100) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maks 100 ids per request']],422);
  $allowedActions=['suspend','activate','delete','change_role'];
  if(!in_array($action,$allowedActions,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'action harus: '.implode(',',$allowedActions)]],422);
  $ids=array_values(array_unique(array_map('intval',$ids)));
  if(in_array((int)currentUser()['id'],$ids,true) && in_array($action,['delete','suspend'],true) && count($ids)===1) {
    // single self check handled per id below, but early block for delete self
  }
  $pdo=pdo(); $results=[]; $now=date('Y-m-d H:i:s'); $ip=$_SERVER['REMOTE_ADDR']??'';
  $pdo->beginTransaction();
  try{
    foreach($ids as $uid){
      if($action==='delete' && (int)$uid===(int)currentUser()['id']){ $results[]=['id'=>$uid,'status'=>'failed','reason'=>'Tidak boleh hapus diri sendiri']; continue; }
      $cur=$pdo->prepare('SELECT id,nama,email,status,deleted_at FROM users WHERE id=?'); $cur->execute([$uid]); $row=$cur->fetch();
      if(!$row){ $results[]=['id'=>$uid,'status'=>'failed','reason'=>'Tidak ada']; continue; }
      if($action==='delete'){
        if($row['deleted_at']!==null){ $results[]=['id'=>$uid,'status'=>'failed','reason'=>'Sudah dihapus']; continue; }
        $suffix='_deleted_'.$uid.'_'.time().rand(100,999);
        $pdo->prepare('UPDATE users SET deleted_at=?, email=CONCAT(email,?), updated_at=? WHERE id=?')->execute([$now,$suffix,$now,$uid]);
        $results[]=['id'=>$uid,'status'=>'ok','action'=>'delete'];
      } else if($action==='suspend'){
        if($row['deleted_at']!==null){ $results[]=['id'=>$uid,'status'=>'failed','reason'=>'User terhapus']; continue; }
        $pdo->prepare('UPDATE users SET status=?, updated_at=NOW() WHERE id=?')->execute(['suspended',$uid]);
        $results[]=['id'=>$uid,'status'=>'ok','action'=>'suspend'];
      } else if($action==='activate'){
        if($row['deleted_at']!==null){ $results[]=['id'=>$uid,'status'=>'failed','reason'=>'User terhapus']; continue; }
        $pdo->prepare('UPDATE users SET status=?, updated_at=NOW() WHERE id=?')->execute(['aktif',$uid]);
        $results[]=['id'=>$uid,'status'=>'ok','action'=>'activate'];
      } else if($action==='change_role'){
        if(!in_array($value,['admin','pembina','siswa','kepsek'],true)){ $results[]=['id'=>$uid,'status'=>'failed','reason'=>'Role tidak valid']; continue; }
        if($row['deleted_at']!==null){ $results[]=['id'=>$uid,'status'=>'failed','reason'=>'User terhapus']; continue; }
        $pdo->prepare('UPDATE users SET role=?, updated_at=NOW() WHERE id=?')->execute([$value,$uid]);
        $results[]=['id'=>$uid,'status'=>'ok','action'=>'change_role','value'=>$value];
      }
    }
    $pdo->commit();
  }catch(Exception $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonOut(['success'=>false,'error'=>['code'=>'ERROR','message'=>$e->getMessage()]],500);
  }
  // notify admin for bulk destructive (grouped daily)
  $okCount=count(array_filter($results, fn($r)=>($r['status']??'')==='ok'));
  if($okCount>0 && in_array($action,['suspend','delete','purge'],true)){
    try{ notifyAdmins('Bulk '.$action.': '.$okCount.' user', $okCount.' user di-'.$action.' via bulk — oleh '.(currentUser()['nama']??'Admin'), 'bulk', 'bulk:'.$action.':daily:'.date('Y-m-d')); }catch(Exception $e){}
  }
  // audit bulk
  try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([currentUser()['id'],'bulk','user',0, json_encode(['action'=>$action,'value'=>$value,'ids'=>$ids,'results'=>$results],JSON_UNESCAPED_UNICODE),$ip]); }catch(Exception $e){ try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'bulk','user',0, json_encode(['action'=>$action,'ids'=>$ids],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e2){} }
  jsonOut(['success'=>true,'data'=>['results'=>$results]]);
}
if(routeMatch('/users/:id/logs',$uri,$pm) && $method==='GET'){
  requireRole('admin');
  usersRateLimit('users_logs');
  $id=(int)$pm['id']; $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM audit_log WHERE target_type='user' AND target_id=?"); $ct->execute([$id]); $total=(int)($ct->fetch()['c']??0);
  $st=pdo()->prepare("SELECT a.*, u.nama actor_nama FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.target_type='user' AND a.target_id=? ORDER BY a.created_at DESC LIMIT $limit OFFSET $off"); $st->execute([$id]);
  $rows=$st->fetchAll();
  header('Cache-Control: private, no-store, max-age=0, must-revalidate'); header('Pragma: no-cache');
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit)]]);
}
if($uri==='/audit-logs' && $method==='GET'){
  requireRole('admin');
  usersRateLimit('audit_logs');
  $target=trim($_GET['target']??''); $action=trim($_GET['action']??''); $q=trim($_GET['q']??'');
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $where=[]; $par=[];
  if($target!=='' && $target!=='all'){ $where[]='a.target_type=?'; $par[]=$target; }
  if($action!=='' && $action!=='all'){ $where[]='a.action=?'; $par[]=$action; }
  if($q!==''){ $where[]='(a.detail LIKE ? OR u.nama LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; }
  $whereSql=$where? 'WHERE '.implode(' AND ',$where) : '';
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM audit_log a LEFT JOIN users u ON u.id=a.user_id $whereSql"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  $sql="SELECT a.*, u.nama actor_nama FROM audit_log a LEFT JOIN users u ON u.id=a.user_id $whereSql ORDER BY a.created_at DESC LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  header('Cache-Control: private, no-store, max-age=0, must-revalidate'); header('Pragma: no-cache'); header('X-Total-Count: '.$total);
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit)]]);
}
// === PRESENCE HEARTBEAT + POLLS (A-A-A) ===
if($uri==='/presence/heartbeat' && $method==='POST'){
  requireLogin();
  $uid=currentUser()['id'];
  // rate 30/min per user (heartbeat 60s -> 1/min, buffer burst 30)
  $f=sys_get_temp_dir()."/presence_rl_{$uid}.json"; $now=time(); $cnt=0; $win=$now;
  $fh=@fopen($f,'c+'); if($fh){ @flock($fh,LOCK_EX); $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null; if($j && ($now-(int)($j['start']??0)<60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } if($cnt>=30){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering heartbeat']],429); } @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt+1,'start'=>$win])); @flock($fh,LOCK_UN); @fclose($fh); }
   $pdo=pdo(); $c=$GLOBALS['config']??($GLOBALS['__app_config']??[]);
  $pdo->prepare("UPDATE users SET last_seen=NOW() WHERE id=?")->execute([$uid]);
  jsonOut(['success'=>true,'data'=>['last_seen'=>date('Y-m-d H:i:s')]]);
}
// === USERS DETAIL (drawer) — ekskul + kehadiran + sertifikat ringkas ===
if(routeMatch('/users/:id/detail',$uri,$pm) && $method==='GET'){
  requireRole('admin');
  usersRateLimit('users_detail');
  $uid=(int)$pm['id'];
  $u=pdo()->prepare('SELECT id,nama,email,role,nip,kelas,status,created_at,last_login_at,updated_at FROM users WHERE id=? AND deleted_at IS NULL'); $u->execute([$uid]); $row=$u->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  $row['nama']=e($row['nama']); $row['email']=e($row['email']);
  // ekskul terdaftar
  $ekskul=[]; try{ $st=pdo()->prepare('SELECT e.id,e.nama,e.status, r.status reg_status, r.created_at FROM registrations r JOIN ekskul e ON e.id=r.ekskul_id WHERE r.user_id=? AND r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 20'); $st->execute([$uid]); $ekskul=$st->fetchAll(); foreach($ekskul as &$e){ $e['nama']=e($e['nama']); } unset($e); }catch(Exception $e){}
  // event peserta
  $events=[]; try{ $st=pdo()->prepare('SELECT ev.id,ev.nama,ep.status,ep.hadir,ep.created_at FROM event_participants ep JOIN events ev ON ev.id=ep.event_id WHERE ep.user_id=? ORDER BY ep.created_at DESC LIMIT 20'); $st->execute([$uid]); $events=$st->fetchAll(); foreach($events as &$ev){ $ev['nama']=e($ev['nama']); } unset($ev); }catch(Exception $e){}
  // kehadiran ringkas (last 30 hari)
  $att=['hadir'=>0,'izin'=>0,'alpa'=>0,'total'=>0]; try{
    $st=pdo()->prepare("SELECT a.status, COUNT(*) c FROM attendance a JOIN schedules s ON s.id=a.schedule_id WHERE a.user_id=? AND s.tanggal >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY a.status");
    $st->execute([$uid]); foreach($st->fetchAll() as $r){ if(isset($att[$r['status']])) $att[$r['status']]=(int)$r['c']; }
    $st2=pdo()->prepare('SELECT COUNT(*) c FROM attendance a JOIN schedules s ON s.id=a.schedule_id WHERE a.user_id=?'); $st2->execute([$uid]); $att['total']=(int)($st2->fetch()['c']??0);
  }catch(Exception $e){}
  // sertifikat
  $certs=[]; try{ $st=pdo()->prepare('SELECT id,tipe,target_id,nomor,hash,issued_at FROM certificates WHERE user_id=? ORDER BY issued_at DESC LIMIT 10'); $st->execute([$uid]); $certs=$st->fetchAll(); }catch(Exception $e){}
  header('Cache-Control: private, no-store');
  jsonOut(['success'=>true,'data'=>['user'=>$row,'ekskul'=>$ekskul,'events'=>$events,'att'=>$att,'certs'=>$certs]]);
}

// === SERTIFIKAT — event-only verifiable (admin-only generate, 1x hadir event, HMAC hash, QR+TTD kepsek) ===
// event-only: ENUM DB dibiarkan agar data lama ekskul tidak rusak — cukup tolak di API
function sertifikatRateLimit($key='sertifikat',$limit=30){
  $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';
  $f=sys_get_temp_dir()."/rl_{$key}_".md5($ip).".json"; $now=time(); $cnt=0; $start=$now;
  $fh=@fopen($f,'c+'); if($fh){ @flock($fh,LOCK_EX); $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null; if($j && ($now-(int)($j['start']??0)<60)){ $cnt=(int)($j['count']??0); $start=(int)$j['start']; } if($cnt>=$limit){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering, tunggu 1 menit']],429); } @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt+1,'start'=>$start])); @fflush($fh); @flock($fh,LOCK_UN); @fclose($fh); }
}
function getSertifikatSecret(){ $m=getAppSettingsMap(); $s=$m['sertifikat_secret']??''; if($s===''){ $s=bin2hex(random_bytes(32)); try{ setAppSettingsMap(['sertifikat_secret'=>$s]); }catch(Exception $e){} } return $s; }
// event-only: cabang ekskul dihapus, API tolak tipe!='event'; ENUM DB dibiarkan agar data lama tidak rusak
function isEligibleForSertifikat(int $uid, string $tipe, int $tid): array {
  $pdo=pdo();
  if($tipe!=='event') return [false,'Hanya event yang didukung',null];
  $row=$pdo->prepare('SELECT ep.hadir, e.id AS ev_ok FROM events e LEFT JOIN event_participants ep ON ep.event_id=e.id AND ep.user_id=? WHERE e.id=? AND e.deleted_at IS NULL'); $row->execute([$uid,$tid]); $r=$row->fetch();
  if(!$r || $r['ev_ok']===null) return [false,'Event tidak ditemukan',null];
  if($r['hadir']===null) return [false,'Belum terdaftar di event ini',null];
  if((int)($r['hadir']??0)!==1) return [false,'Belum ditandai hadir di event ini (hadir=0)',null];
  return [true,'OK',null];
}
function buildSertifikatNomor(int $id, string $tipe): string {
  // event-only: prefix EVT saja
  $prefix='EVT';
  return sprintf('%04d/SERT-%s/%s/%05d', (int)date('Y'), $prefix, date('m'), $id);
}
if(routeMatch('/sertifikat/generate',$uri) && $method==='POST'){
  sertifikatRateLimit('sertifikat_gen',20);
  requireRole('admin');
  $b=getBody();
  $uid=(int)($b['user_id']??$b['uid']??0);
  $tipe=trim(strtolower($b['tipe']??$b['type']??''));
  $tid=(int)($b['target_id']??$b['event_id']??0);
  if(!$uid||!$tid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'user_id & target_id wajib']],422);
  // event-only
  if($tipe!=='event') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya event yang didukung']],422);
  $u=pdo()->prepare('SELECT id,nama,email,kelas FROM users WHERE id=? AND deleted_at IS NULL'); $u->execute([$uid]); $urow=$u->fetch();
  if(!$urow) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'User tidak ditemukan']],422);
  // label payload: trim, max 50, default PESERTA jika kosong, sanitize bebas tapi aman
  $labelRaw = trim((string)($b['label'] ?? ''));
  if($labelRaw === '') $labelRaw = 'PESERTA';
  // sanitize: hapus control chars, collapse whitespace, batasi 50 char (unicode safe)
  $labelRaw = preg_replace('/[\x00-\x1F\x7F]/u','', $labelRaw);
  $labelRaw = trim(preg_replace('/\s+/u',' ', $labelRaw));
  // whitelist bebas: izinkan huruf/angka/spasi/-_/.  tapi jangan strip brutal; cukup potong panjang & strip tags
  $labelRaw = strip_tags($labelRaw);
  if(mb_strlen($labelRaw,'UTF-8') > 50) $labelRaw = mb_substr($labelRaw,0,50,'UTF-8');
  if(trim($labelRaw)==='') $labelRaw='PESERTA';
  $label = $labelRaw;
  $kelasSnapshot = trim((string)($urow['kelas'] ?? ''));
  if($kelasSnapshot==='') $kelasSnapshot=null;
  $pdo=pdo();
  // hemat: 1 query — event exists + eligibility (ep.hadir) + duplicate check (cert), tanpa 3x roundtrip
  $elig=$pdo->prepare('SELECT e.id AS ev_id, ep.hadir, c.id AS cert_id, c.hash AS cert_hash, c.nomor AS cert_nomor FROM events e LEFT JOIN event_participants ep ON ep.event_id=e.id AND ep.user_id=? LEFT JOIN certificates c ON c.user_id=? AND c.tipe=? AND c.target_id=? WHERE e.id=? AND e.deleted_at IS NULL'); $elig->execute([$uid,$uid,$tipe,$tid,$tid]); $er=$elig->fetch();
  if(!$er || $er['ev_id']===null) jsonOut(['success'=>false,'error'=>['code'=>'NOT_ELIGIBLE','message'=>'Event tidak ditemukan','detail'=>null]],409);
  if($er['cert_id']!==null) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sertifikat sudah diterbitkan','hash'=>$er['cert_hash'],'nomor'=>$er['cert_nomor'],'id'=>$er['cert_id']]],409);
  if($er['hadir']===null) jsonOut(['success'=>false,'error'=>['code'=>'NOT_ELIGIBLE','message'=>'Belum terdaftar di event ini','detail'=>null]],409);
  if((int)($er['hadir']??0)!==1) jsonOut(['success'=>false,'error'=>['code'=>'NOT_ELIGIBLE','message'=>'Belum ditandai hadir di event ini (hadir=0)','detail'=>null]],409);
  // atomic insert with transaction + FOR UPDATE guard
  try{
    $pdo->beginTransaction();
    // re-check duplicate inside tx with lock
    $chk=$pdo->prepare('SELECT id FROM certificates WHERE user_id=? AND tipe=? AND target_id=? FOR UPDATE'); $chk->execute([$uid,$tipe,$tid]); if($chk->fetch()){ $pdo->rollBack(); jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sertifikat sudah diterbitkan (race)']],409); }
    $secret=getSertifikatSecret();
    $raw=bin2hex(random_bytes(32)); // 64 hex
    $hash=hash_hmac('sha256',$raw.$uid.$tipe.$tid.microtime(true),$secret);
    // hash 64 hex from hmac
    $hash=substr($hash,0,64);
    // ensure hash unique retry
    $tries=0; while($tries<3){ $hchk=$pdo->prepare('SELECT 1 FROM certificates WHERE hash=?'); $hchk->execute([$hash]); if(!$hchk->fetch()) break; $hash=hash_hmac('sha256',bin2hex(random_bytes(32)).$uid.$tipe.$tid.microtime(true),$secret); $hash=substr($hash,0,64); $tries++; }
    $nomor='TMP';
    $issuedBy=currentUser()['id'];
    $isSqlite=false; try{ $isSqlite=(pdo()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
    // INSERT dengan kelas_snapshot + label (backward compat fallback jika kolom belum ada)
    $insertOk=false;
    try{
      if($isSqlite){
        pdo()->prepare('INSERT INTO certificates(user_id,tipe,target_id,kelas_snapshot,label,hash,nomor,issued_by,issued_at) VALUES (?,?,?,?,?,?,?,?,datetime("now"))')->execute([$uid,$tipe,$tid,$kelasSnapshot,$label,$hash,$nomor,$issuedBy]);
      } else {
        pdo()->prepare('INSERT INTO certificates(user_id,tipe,target_id,kelas_snapshot,label,hash,nomor,issued_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$uid,$tipe,$tid,$kelasSnapshot,$label,$hash,$nomor,$issuedBy]);
      }
      $insertOk=true;
    }catch(Exception $eIns){
      // fallback lama tanpa snapshot jika migrasi belum jalan (jaga backward compat)
      if(stripos($eIns->getMessage(),'kelas_snapshot')!==false || stripos($eIns->getMessage(),'label')!==false){
        if($isSqlite){
          pdo()->prepare('INSERT INTO certificates(user_id,tipe,target_id,hash,nomor,issued_by,issued_at) VALUES (?,?,?,?,?,?,datetime("now"))')->execute([$uid,$tipe,$tid,$hash,$nomor,$issuedBy]);
        } else {
          pdo()->prepare('INSERT INTO certificates(user_id,tipe,target_id,hash,nomor,issued_by) VALUES (?,?,?,?,?,?)')->execute([$uid,$tipe,$tid,$hash,$nomor,$issuedBy]);
        }
        $insertOk=true;
      } else { throw $eIns; }
    }
    $newId=(int)$pdo->lastInsertId();
    $nomorReal=buildSertifikatNomor($newId,$tipe);
    $pdo->prepare('UPDATE certificates SET nomor=? WHERE id=?')->execute([$nomorReal,$newId]);
    $pdo->commit();
    // audit
    try{ $ip=$_SERVER['REMOTE_ADDR']??null; $det=json_encode(['user_id'=>$uid,'tipe'=>$tipe,'target_id'=>$tid,'nomor'=>$nomorReal,'hash'=>$hash],JSON_UNESCAPED_UNICODE); try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$issuedBy,'generate','certificate',$newId,$det,$ip]); }catch(Exception $e){ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$issuedBy,'generate','certificate',$newId,$det]); } }catch(Exception $e){}
    jsonOut(['success'=>true,'data'=>['id'=>$newId,'hash'=>$hash,'nomor'=>$nomorReal,'user'=>$urow,'tipe'=>$tipe,'target_id'=>$tid]],201);
  }catch(PDOException $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    $msg=$e->getMessage(); if($e->getCode()=='23000' || strpos($msg,'UNIQUE')!==false) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sertifikat sudah diterbitkan (constraint)']],409);
    throw $e;
  }catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
// exact routes SEBELUM generik /:id (hindari shadowing: 'eligible' match [^/]+ -> (int)0 -> 404)
// eligible students pre-check for batch generate
if($uri==='/sertifikat/eligible' && $method==='GET'){
  requireRole('admin');
  sertifikatRateLimit('cert_eligible',30);
  $tipe=trim(strtolower($_GET['tipe']??''));
  $tid=(int)($_GET['target_id']??0);
  // event-only
  if($tipe!=='event') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya event yang didukung']],422);
  if(!$tid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'target_id wajib']],422);
  $users=[];
  // 1 query: cek event 1x di luar loop + JOIN users/event_participants/LEFT JOIN certificates (tanpa 3N loop)
  $evChk=pdo()->prepare('SELECT id FROM events WHERE id=? AND deleted_at IS NULL'); $evChk->execute([$tid]);
  if(!$evChk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ditemukan']],404);
  // event-only: hanya event participants
  $st=pdo()->prepare('SELECT u.id,u.nama,u.kelas,ep.hadir, c.id cert_id, c.nomor cert_nomor FROM users u JOIN event_participants ep ON ep.user_id=u.id AND ep.event_id=? LEFT JOIN certificates c ON c.user_id=u.id AND c.tipe=? AND c.target_id=? WHERE ep.status="diterima" AND u.deleted_at IS NULL ORDER BY u.nama');
  $st->execute([$tid,$tipe,$tid]);
  foreach($st->fetchAll() as $u){
    $hasCert=$u['cert_id']!==null; $existingId=$hasCert?(int)$u['cert_id']:null; $existingNomor=$hasCert?$u['cert_nomor']:null;
    if((int)($u['hadir']??0)!==1){ $ok=false; $msg='Belum ditandai hadir di event ini (hadir=0)'; }
    else { $ok=true; $msg='OK'; }
    $users[]=['user_id'=>(int)$u['id'],'nama'=>$u['nama'],'kelas'=>$u['kelas']??'','eligible'=>$ok,'reason'=>$msg,'detail'=>null,'has_cert'=>$hasCert,'existing_id'=>$existingId,'existing_nomor'=>$existingNomor];
  }
  header('Cache-Control: private, no-store');
  jsonOut(['success'=>true,'data'=>$users,'meta'=>['tipe'=>$tipe,'target_id'=>$tid,'total'=>count($users),'eligible'=>count(array_filter($users,fn($x)=>$x['eligible'])),'has_cert'=>count(array_filter($users,fn($x)=>$x['has_cert']))]]);
}
if(routeMatch('/sertifikat/:id/pdf',$uri,$pm) && $method==='GET'){
  requireLogin();
  $id=(int)$pm['id'];
  $st=pdo()->prepare('SELECT c.*, u.nama user_nama, u.email, u.kelas live_kelas FROM certificates c JOIN users u ON u.id=c.user_id WHERE c.id=?'); $st->execute([$id]); $cert=$st->fetch();
  if(!$cert) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sertifikat tidak ada']],404);
  $cu=currentUser(); if((int)$cert['user_id']!==(int)$cu['id'] && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pemilik atau admin']],403);
  $tipe=$cert['tipe']; $tid=(int)$cert['target_id'];
  // event-only: nama target selalu dari events (data lama ekskul tetap bisa render PDF via fallback)
  $t=pdo()->prepare('SELECT nama,tanggal FROM events WHERE id=?'); $t->execute([$tid]); $er=$t->fetch(); $tn=$er['nama']??('Event #'.$tid); $evTanggal=$er['tanggal']??'';
  $settings=getAppSettingsMap(['sekolah_nama','kepsek_nama','kepsek_nip']);
  $sekolahNama=$settings['sekolah_nama']??'SMA Negeri 1';
  $kepsekNama=$settings['kepsek_nama']??'Drs. H. Ahmad Sulaiman, M.Pd';
  $kepsekNip=$settings['kepsek_nip']??'19650101 199003 1 001';
  $ttdKepsek=trim((string)(getAppSetting('ttd_kepsek')??''));
  $hasTtdScan=$ttdKepsek!=='' && str_starts_with($ttdKepsek,'data:image/');
  if(!$hasTtdScan){
    foreach(['png','jpg','jpeg','webp'] as $ext){
      $fp=uploadPath('ttd/ttd_kepsek.'.$ext);
      if(is_file($fp) && filesize($fp)>0 && filesize($fp)<800000){
        $mime2=$ext==='png'?'image/png':($ext==='webp'?'image/webp':'image/jpeg');
        $b64=@base64_encode(@file_get_contents($fp));
        if($b64){ $ttdKepsek='data:'.$mime2.';base64,'.$b64; $hasTtdScan=true; break; }
      }
    }
    if(!$hasTtdScan){
      $fp2=uploadPath('ttd_kepsek.png');
      if(is_file($fp2) && filesize($fp2)>0){ $b64=@base64_encode(@file_get_contents($fp2)); if($b64){ $ttdKepsek='data:image/png;base64,'.$b64; $hasTtdScan=true; } }
    }
  }
  if($hasTtdScan && strlen($ttdKepsek)> 800000) { $hasTtdScan=false; $ttdKepsek=''; }
  // --- cert_layout + bg base64 (pixel-perfect mm absolute) ---
  $defLayout=[
    'nama'=>['x'=>148.5,'y'=>88,'w'=>180,'align'=>'center','font_pt'=>28,'font_weight'=>'bold','color'=>'#0E1442'],
    'label'=>['x'=>148.5,'y'=>135,'w'=>180,'align'=>'center','font_pt'=>18,'font_weight'=>'bold','color'=>'#b8860b','uppercase'=>true],
    'deskripsi'=>['x'=>148.5,'y'=>150,'w'=>200,'align'=>'center','font_pt'=>11,'color'=>'#444444'],
    'ttd'=>['x'=>55,'y'=>175,'w'=>60,'align'=>'center','font_pt'=>10],
    'qr'=>['x'=>242,'y'=>175,'w'=>30,'h'=>30,'align'=>'center'],
    'nomor'=>['x'=>148.5,'y'=>195,'w'=>180,'align'=>'center','font_pt'=>7,'color'=>'#666666'],
  ];
  $layoutJson = trim((string)(getAppSetting('cert_layout') ?? ''));
  $layout = json_decode($layoutJson, true);
  if(!is_array($layout)) $layout=[];
  foreach($defLayout as $k=>$v){
    if(!isset($layout[$k]) || !is_array($layout[$k])) $layout[$k]=$v;
    else $layout[$k]=array_merge($v, $layout[$k]);
  }
  // --- template-aware: read certificate_templates for this tipe+target_id ---
  $tplLabelDefault=null; $tplDeskripsiOverride=null; $tplBgUrl='';
  try{
    $tplSt=pdo()->prepare('SELECT * FROM certificate_templates WHERE tipe=? AND target_id=?');
    $tplSt->execute([$tipe,$tid]); $tpl=$tplSt->fetch();
    if($tpl){
      $tplLabelDefault=trim((string)($tpl['label_default']??'')); if($tplLabelDefault==='') $tplLabelDefault=null;
      $tplDeskripsiOverride=trim((string)($tpl['deskripsi_override']??'')); if($tplDeskripsiOverride==='') $tplDeskripsiOverride=null;
      $tplBgUrl=trim((string)($tpl['cert_bg_url']??''));
      if((int)($tpl['use_custom_layout']??0)===1){
        $tplLayoutJson=trim((string)($tpl['layout_json']??''));
        $tplLayout=json_decode($tplLayoutJson,true);
        if(is_array($tplLayout)){
          foreach($defLayout as $k=>$v){
            if(isset($tplLayout[$k]) && is_array($tplLayout[$k])){
              $layout[$k]=array_merge($v, $tplLayout[$k]);
            }
          }
        }
      }
    }
  }catch(Exception $e){}
  // background base64 embed (prefer file api/uploads/cert_bg/cert_bg.png)
  $bgDataUri='';
  $bgPathSetting = trim((string)($settings['cert_bg_path'] ?? 'api/uploads/cert_bg/cert_bg.png'));
  $candidates=[
    uploadPath('cert_bg/cert_bg.png'),
    uploadPath($bgPathSetting),
    __DIR__.'/../'.$bgPathSetting,
  ];
  foreach($candidates as $fp){
    $fpNorm=str_replace(['\\','//'],'/',$fp);
    if(is_file($fpNorm) && filesize($fpNorm)>0){
      $raw=@file_get_contents($fpNorm);
      if($raw!==false){ $b64=base64_encode($raw); $bgDataUri='data:image/png;base64,'.$b64; break; }
    }
  }
  // per-event bg override (takes precedence over global cert_bg)
  if($tplBgUrl!==''){
    $tplBgPath=preg_replace('/\?.*$/','',$tplBgUrl);
    $bgOverrideCandidates=[
      __DIR__.'/'.$tplBgPath,
      __DIR__.'/../'.$tplBgPath,
    ];
    foreach($bgOverrideCandidates as $fp){
      $fpNorm=str_replace(['\\','//'],'/',$fp);
      if(is_file($fpNorm) && filesize($fpNorm)>0){
        $raw=@file_get_contents($fpNorm);
        if($raw!==false){ $b64=base64_encode($raw); $bgDataUri='data:image/png;base64,'.$b64; break; }
      }
    }
    // latent: isRemoteEnabled=false + remote http ditolak — bg URL yang gagal resolve jadi spacer diam
    if($bgDataUri==='' && $tplBgUrl!=='') trigger_error("cert bg override tidak ter-resolve, fallback spacer: {$tplBgUrl}", E_USER_WARNING);
  }
  // TTD: http URL tidak pernah di-embed (hanya data:image/ di settings/file lokal) — warning, bukan spacer diam
  if(!$hasTtdScan && $ttdKepsek!=='' && !str_starts_with($ttdKepsek,'data:image/')) trigger_error('cert TTD bukan data:image/, dirender spacer', E_USER_WARNING);
  // display values: label fallback PESERTA (kelas diabaikan — dihapus total dari render)
  unset($layout['kelas']);
  $displayLabel = trim((string)($cert['label'] ?? 'PESERTA'));
  if($displayLabel==='') $displayLabel='PESERTA';
  // template label_default overrides cert.label (but cert.label still wins if explicitly set at generate time)
  if($tplLabelDefault!==null && ($cert['label']??'')===''){ $displayLabel=$tplLabelDefault; }
  if(!empty($layout['label']['uppercase'])) $displayLabel = mb_strtoupper($displayLabel,'UTF-8');
  // verifyUrl
  $appBase=trim($settings['app_url']??'');
  if($appBase==='' || !preg_match('#^https?://#i',$appBase)){
    $scheme=$isHttps?'https':'http';
    $host=trim($_SERVER['HTTP_HOST']??'');
    $fromHost=($host!=='' && preg_match('/^[a-z0-9.\-:]+$/i',$host)) ? $scheme.'://'.$host : '';
    $requestOrigin=isset($_SERVER['HTTP_ORIGIN'])?trim($_SERVER['HTTP_ORIGIN']):'';
    $originOk=false;
    if($requestOrigin!=='' && preg_match('#^https?://#i',$requestOrigin)){
      if(in_array($requestOrigin,$corsAllowedOrigins,true)) $originOk=true;
      else if($fromHost!=='' && $requestOrigin===$fromHost) $originOk=true;
    }
    if($originOk){
      $appBase=$requestOrigin;
    } else if($fromHost!==''){
      $appBase=$fromHost;
    } else {
      $envBase=getenv('APP_BASE_URL');
      $appBase=(is_string($envBase)&&trim($envBase)!=='' ? rtrim(trim($envBase),'/') : 'http://127.0.0.1:5173');
    }
  }
  $verifyUrl=rtrim($appBase,'/').'/verify/'.$cert['hash'];
  // QR real (phpqrcode vendored) + fallback GD placeholder — no leak, quiet zone 4
  $qrDataUri='';
  try{
    if(!class_exists('QRcode',false)){
      $qrLib=__DIR__.'/lib/phpqrcode.php';
      if(is_file($qrLib) && filesize($qrLib)>100000) require_once $qrLib;
    }
    if(class_exists('QRcode',false)){
      ob_start();
      @QRcode::png($verifyUrl, false, QR_ECLEVEL_M, 4, 2);
      $png=ob_get_clean();
      if(is_string($png) && strlen($png)>200 && substr($png,1,3)==='PNG') $qrDataUri='data:image/png;base64,'.base64_encode($png);
    }
    if($qrDataUri==='' && function_exists('imagecreatetruecolor')){
      $sz=220; $im=imagecreatetruecolor($sz,$sz); $white=imagecolorallocate($im,255,255,255); $black=imagecolorallocate($im,0,0,0); $gray=imagecolorallocate($im,100,100,100); $red=imagecolorallocate($im,180,30,30);
      imagefilledrectangle($im,0,0,$sz,$sz,$white);
      imagerectangle($im,0,0,$sz-1,$sz-1,$black);
      for($i=0;$i<8;$i++) for($j=0;$j<8;$j++) if((($i+$j)%2)==0) imagefilledrectangle($im,10+$i*6,10+$j*6,14+$i*6,14+$j*6,$black);
      imagestring($im,2,10,85,substr($cert['hash'],0,22), $black);
      imagestring($im,2,10,100,substr($cert['hash'],22,22), $black);
      ob_start(); imagepng($im); $png=ob_get_clean(); imagedestroy($im);
      $qrDataUri='data:image/png;base64,'.base64_encode($png);
    }
  }catch(Exception $e){}
  if(ob_get_level()) @ob_end_clean(); header_remove('Content-Type');
  header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="sertifikat-'.$cert['nomor'].'.pdf"'); header('Cache-Control: no-store');
  // description + shared template html (single source of truth: api/cert_template.php)
  $descText = 'atas kehadiran pada event'.' <b>'.e($tn).'</b>'.(isset($evTanggal)&&$evTanggal!==''?' pada '.e($evTanggal):'').' sesuai kriteria kehadiran yang ditetapkan sekolah.';
  if($tplDeskripsiOverride!==null){
    $descText=str_replace(['{{target_nama}}','{{tanggal}}'],[e($tn),e($evTanggal??'')],$tplDeskripsiOverride);
  }
  // selectable fonts via ?font_nama=&font_label=&font_deskripsi=&font_nomor=&font_ttd_nama= (legacy: &font_ttd=)
  // prioritas: query > template fonts_json > global app_settings.cert_fonts > default DejaVu Sans; whitelist di cert_template.php. PDF: show_icons=false.
  $tplFonts=[];
  try{
    $tfRaw=trim((string)($tpl['fonts_json']??''));
    if($tfRaw!==''){ $tfDec=json_decode($tfRaw,true); if(is_array($tfDec)) $tplFonts=$tfDec; }
  }catch(Exception $e){}
  $globFonts=[];
  try{
    $gfRaw=trim((string)($settings['cert_fonts']??''));
    if($gfRaw!==''){ $gfDec=json_decode($gfRaw,true); if(is_array($gfDec)) $globFonts=$gfDec; }
  }catch(Exception $e){}
  // LOG fonts_json sources + query params (helps debug NULL / fallback) — always log to cert_font.log
  try{
    $certFontLogLine = '[CERT_FONT] pdfFonts src template=' . json_encode($tplFonts, JSON_UNESCAPED_UNICODE) . ' global=' . json_encode($globFonts, JSON_UNESCAPED_UNICODE) . ' query=' . json_encode($_GET, JSON_UNESCAPED_UNICODE);
    error_log($certFontLogLine);
    certLogAppend($certFontLogLine);
  }catch(Exception $e){}
  $pickFont=function(string $q, string $tk, string $gk) use ($tplFonts,$globFonts): string {
    if($q!=='') return $q;
    $t=trim((string)($tplFonts[$tk]??'')); if($t!=='' && strcasecmp($t,'DejaVu Sans')!==0) return $t;
    if($t!=='' && strcasecmp($t,'DejaVu Sans')===0) return ''; // let sanFont fallback (nama->Great Vibes)
    $g=trim((string)($globFonts[$gk]??'')); if($g!=='' && strcasecmp($g,'DejaVu Sans')!==0) return $g;
    if($g!=='' && strcasecmp($g,'DejaVu Sans')===0) return '';
    return '';
  };
  $pdfFonts = [
    'nama'      => $pickFont(trim((string)($_GET['font_nama'] ?? '')),'nama','nama'),
    'label'     => $pickFont(trim((string)($_GET['font_label'] ?? '')),'label','label'),
    'deskripsi' => $pickFont(trim((string)($_GET['font_deskripsi'] ?? '')),'deskripsi','deskripsi'),
    'nomor'     => $pickFont(trim((string)($_GET['font_nomor'] ?? '')),'nomor','nomor'),
    'ttd_nama'  => $pickFont(trim((string)($_GET['font_ttd_nama'] ?? $_GET['font_ttd'] ?? '')),'ttd_nama','ttd_nama'),
  ];
  // FIX: empty nama harus fallback Great Vibes di sanFont (jangan kirim '' yang nanti = DejaVu untuk label logic)
  // tetapi pdfFonts='' memang trigger sanFont fallback — jadi untuk nama kosong kita tetap biarkan '' (sanFont akan fallback Great Vibes)
  $html = cert_render_html([
    'nama' => $cert['user_nama'],
    'label' => $displayLabel,
    'deskripsi_html' => $descText,
    'ttd' => ['date' => 'Jakarta, '.date('d F Y'), 'img_data_uri' => $hasTtdScan ? $ttdKepsek : '', 'nama' => $kepsekNama, 'nip' => $kepsekNip],
    'qr_data_uri' => $qrDataUri, 'qr_url' => $verifyUrl,
    'nomor' => $cert['nomor'], 'hash' => $cert['hash'], 'issued_at' => $cert['issued_at'],
    'bg_data_uri' => $bgDataUri, 'bg_fallback_html' => '',
  ], $layout, ['is_pdf' => true, 'fonts' => $pdfFonts, 'show_icons' => false]);
  $fontWarnings = $GLOBALS['__cert_font_warnings'] ?? [];
  $hasFallback = !empty($fontWarnings);
  if($hasFallback){
    $warnJson = json_encode($fontWarnings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    header('X-Cert-Font-Warnings: ' . substr($warnJson,0,4000));
    // also log
    error_log('[CERT_FONT] pdf fallback warnings cert_id=' . $cert['id'] . ' warns=' . $warnJson . ' fontsIn=' . json_encode($pdfFonts, JSON_UNESCAPED_UNICODE));
  }
  $opts=new \Dompdf\Options(); $opts->set('isRemoteEnabled', true); $opts->set('isHtml5ParserEnabled', true); $opts->set('defaultFont','DejaVu Sans'); $opts->set('isFontSubsettingEnabled', true); $opts->set('chroot', [__DIR__, __DIR__.'/fonts']);
  $dom=new \Dompdf\Dompdf($opts); $dom->loadHtml($html); $dom->setPaper('A4','landscape'); $dom->render();
  $dom->stream('sertifikat-'.$cert['nomor'].'.pdf',['Attachment'=>true]); exit;
}
if($uri==='/me/certificates' && $method==='GET'){
  requireLogin();
  sertifikatRateLimit('me_cert',60);
  $cu=currentUser(); $uid=(int)$cu['id'];
  $page=max(1,(int)($_GET['page']??1)); $limit=min(50,max(1,(int)($_GET['limit']??10))); $off=($page-1)*$limit;
  $tipe=trim($_GET['tipe']??''); $q=trim($_GET['q']??$_GET['search']??'');
  $where=['c.user_id=?']; $par=[$uid];
  // event-only: filter tipe lain ditolak
  if($tipe!==''&&$tipe!=='event') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya event yang didukung']],422);
  if($tipe==='event'){ $where[]='c.tipe=?'; $par[]=$tipe; }
  if($q!==''){ $where[]='(c.nomor LIKE ? OR c.hash LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; }
  $whereSql='WHERE '.implode(' AND ',$where);
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM certificates c $whereSql"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // event-only: lookup nama selalu dari events
  $sql="SELECT c.*, (SELECT nama FROM events WHERE id=c.target_id) AS target_nama FROM certificates c $whereSql ORDER BY c.issued_at DESC LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['target_nama']=$r['target_nama']?e($r['target_nama']):null; } unset($r);
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>max(1,(int)ceil($total/$limit))]]);
}
if(routeMatch('/sertifikat/:id',$uri,$pm) && $method==='GET'){
  requireRole('admin');
  $id=(int)$pm['id'];
  $st=pdo()->prepare('SELECT c.*, u.nama user_nama, u.email FROM certificates c JOIN users u ON u.id=c.user_id WHERE c.id=?'); $st->execute([$id]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sertifikat tidak ada']],404);
  jsonOut(['success'=>true,'data'=>$row]);
}
if(routeMatch('/sertifikat/:id',$uri,$pm) && $method==='DELETE'){
  requireRole('admin');
  csrfCheck();
  $id=(int)$pm['id'];
  if(!$id) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ID tidak valid']],422);
  $st=pdo()->prepare('SELECT id FROM certificates WHERE id=?'); $st->execute([$id]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sertifikat tidak ada']],404);
  pdo()->prepare('DELETE FROM certificates WHERE id=?')->execute([$id]);
  try{ $ip=$_SERVER['REMOTE_ADDR']??null; $cu=currentUser(); $det=json_encode(['id'=>$id],JSON_UNESCAPED_UNICODE); pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$cu['id'],'delete','certificate',$id,$det,$ip]); }catch(Exception $e){}
  jsonOut(['success'=>true,'id'=>$id]);
}
if($uri==='/sertifikat' && $method==='GET'){
  requireRole('admin');
  sertifikatRateLimit('sertifikat_list',60);
  $page=max(1,(int)($_GET['page']??1)); $limit=min(50,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $tipe=trim($_GET['tipe']??'');
  $q=trim($_GET['q']??$_GET['search']??'');
  $ekskul_id=trim($_GET['ekskul_id']??'');
  $event_id=trim($_GET['event_id']??'');
  $target_id=trim($_GET['target_id']??'');
  $where=[]; $par=[];
  // event-only: tipe selain event ditolak; ekskul_id diabaikan/ditolak
  if($tipe!==''&&$tipe!=='event') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya event yang didukung']],422);
  if($tipe==='event'){ $where[]='c.tipe=?'; $par[]=$tipe; }
  if($ekskul_id!=='' && ctype_digit($ekskul_id)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya event yang didukung']],422);
  if($event_id!=='' && ctype_digit($event_id)){ $where[]="c.tipe='event' AND c.target_id=?"; $par[]=(int)$event_id; }
  elseif($target_id!=='' && ctype_digit($target_id)){ $where[]='c.target_id=?'; $par[]=(int)$target_id; }
  if($q!==''){ $where[]='(u.nama LIKE ? OR u.email LIKE ? OR c.nomor LIKE ? OR c.hash LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; $par[]=$like; $par[]=$like; }
  $whereSql=$where? 'WHERE '.implode(' AND ',$where):'';
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM certificates c JOIN users u ON u.id=c.user_id $whereSql"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // event-only: lookup nama selalu dari events
  $sql="SELECT c.*, u.nama user_nama, u.email, (SELECT nama FROM events WHERE id=c.target_id) AS target_nama FROM certificates c JOIN users u ON u.id=c.user_id $whereSql ORDER BY c.issued_at DESC LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['user_nama']=e($r['user_nama']); $r['target_nama']=$r['target_nama']?e($r['target_nama']):null; } unset($r);
  $etag='"'.md5(json_encode($rows).$total.$page).'"'; header('ETag: '.$etag); header('Cache-Control: private, no-store');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>max(1,(int)ceil($total/$limit))]]);
}
if($uri==='/sertifikat/delete-batch' && $method==='POST'){
  requireRole('admin');
  csrfCheck();
  $b=getBody();
  $ids=$b['ids']??[];
  if(!is_array($ids)||count($ids)===0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids array wajib (min 1)']],422);
  if(count($ids)>500) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'max 500 ids per batch']],422);
  $idList=array_values(array_filter(array_unique(array_map('intval',$ids))));
  if(count($idList)===0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids tidak valid']],422);
  $requested=count($idList);
  $ph=implode(',',array_fill(0,$requested,'?'));
  $st=pdo()->prepare("SELECT id FROM certificates WHERE id IN ($ph)"); $st->execute($idList); $found=array_map('intval',array_column($st->fetchAll(),'id'));
  $deleted=0;
  if(count($found)>0){
    $fh=implode(',',array_fill(0,count($found),'?'));
    pdo()->prepare("DELETE FROM certificates WHERE id IN ($fh)")->execute($found);
    $deleted=count($found);
    try{ $ip=$_SERVER['REMOTE_ADDR']??null; $cu=currentUser(); $det=json_encode(['ids'=>$found,'requested'=>$requested],JSON_UNESCAPED_UNICODE); pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([$cu['id'],'delete_batch','certificate',$det,$ip]); }catch(Exception $e){}
  }
  jsonOut(['success'=>true,'deleted'=>$deleted,'requested'=>$requested,'missing'=>$requested-$deleted]);
}
if(routeMatch('/verify/:hash',$uri,$pm) && $method==='GET'){
  // public verify — no auth, rate 60/min
  sertifikatRateLimit('verify',60);
  $hash=trim($pm['hash']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$hash)) jsonOut(['success'=>false,'error'=>['code'=>'INVALID','message'=>'Hash tidak valid']],404);
  $st=pdo()->prepare('SELECT c.*, u.nama user_nama, u.email FROM certificates c JOIN users u ON u.id=c.user_id WHERE c.hash=?'); $st->execute([$hash]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sertifikat tidak ditemukan / tidak valid']],404);
  $tid=(int)$row['target_id']; $tipe=$row['tipe'];
  // event-only: lookup nama selalu dari events
  $t=pdo()->prepare('SELECT nama,tanggal FROM events WHERE id=?'); $t->execute([$tid]); $er=$t->fetch(); $tn=$er['nama']??''; $evTanggal=$er['tanggal']??'';
  $settings=getAppSettingsMap(['sekolah_nama','kepsek_nama']);
  $data=['hash'=>$row['hash'],'nomor'=>$row['nomor'],'tipe'=>$row['tipe'],'target_id'=>$row['target_id'],'target_nama'=>$tn??'','user_nama'=>$row['user_nama'],'email'=>$row['email'],'issued_at'=>$row['issued_at'],'sekolah_nama'=>$settings['sekolah_nama']??'','kepsek_nama'=>$settings['kepsek_nama']??''];
  if(isset($evTanggal)) $data['tanggal']=$evTanggal;
  header('Cache-Control: public, max-age=60');
  jsonOut(['success'=>true,'data'=>$data]);
}
if(routeMatch('/certificates/verify/:hash',$uri,$pm) && $method==='GET'){
  // alias
  sertifikatRateLimit('verify',60);
  $hash=trim($pm['hash']??'');
  if(!preg_match('/^[a-f0-9]{64}$/',$hash)) jsonOut(['success'=>false,'error'=>['code'=>'INVALID','message'=>'Hash tidak valid']],404);
  $st=pdo()->prepare('SELECT c.*, u.nama user_nama FROM certificates c JOIN users u ON u.id=c.user_id WHERE c.hash=?'); $st->execute([$hash]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Sertifikat tidak ditemukan']],404);
  jsonOut(['success'=>true,'data'=>$row]);
}

// === CERT TEMPLATES — per-event template (layout + label + deskripsi override) ===
// event-only
if(routeMatch('/sertifikat/template/:tipe/:id',$uri,$pm) && in_array($method,['GET','POST'],true)){
  $tipe=trim(strtolower($pm['tipe']??''));
  $tid=(int)$pm['id'];
  // event-only
  if($tipe!=='event') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya event yang didukung']],422);
  if(!$tid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'id wajib']],422);
  // verify target exists (event-only)
  $t=pdo()->prepare('SELECT id,nama FROM events WHERE id=?'); $t->execute([$tid]);
  $target=$t->fetch();
  if(!$target) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ditemukan']],404);

  if($method==='GET'){
    requireLogin();
    $st=pdo()->prepare('SELECT * FROM certificate_templates WHERE tipe=? AND target_id=?');
    $st->execute([$tipe,$tid]); $row=$st->fetch();
    // always return target_nama + parsed layout + parsed fonts for convenience
    $layoutJson=trim((string)($row['layout_json']??''));
    $parsedLayout=json_decode($layoutJson,true); if(!is_array($parsedLayout)) $parsedLayout=null;
    $fontsRaw=trim((string)($row['fonts_json']??''));
    $parsedFonts=$fontsRaw!=='' ? (json_decode($fontsRaw,true)?:null) : null;
    if(!is_array($parsedFonts)) $parsedFonts=null;
    header('Cache-Control: private, no-store');
    jsonOut(['success'=>true,'data'=>[
      'tipe'=>$tipe,'target_id'=>$tid,'target_nama'=>$target['nama'],
      'template'=>$row?:null,
      'layout_parsed'=>$parsedLayout,
      'fonts_parsed'=>$parsedFonts,
      'fonts_json'=>$row['fonts_json']??null,
      'use_custom_layout'=>(int)($row['use_custom_layout']??0),
      'label_default'=>$row['label_default']??null,
      'deskripsi_override'=>$row['deskripsi_override']??null,
      'cert_bg_url'=>$row['cert_bg_url']??null,
    ]]);
  }

  // POST = upsert
  sertifikatRateLimit('cert_template',20);
  requireRole('admin');
  csrfCheck();
  $b=getBody();
  // validate fields
  $labelDefault=trim((string)($b['label_default']??''));
  if($labelDefault!==''){ $labelDefault=strip_tags($labelDefault); if(mb_strlen($labelDefault,'UTF-8')>50) $labelDefault=mb_substr($labelDefault,0,50,'UTF-8'); }
  $deskripsiOverride=trim((string)($b['deskripsi_override']??''));
  if($deskripsiOverride!==''){ $deskripsiOverride=strip_tags($deskripsiOverride,'<b><i><br>'); if(mb_strlen($deskripsiOverride,'UTF-8')>500) $deskripsiOverride=mb_substr($deskripsiOverride,0,500,'UTF-8'); }
  // MAJOR fonts persist server: fonts_json {nama,label,deskripsi,nomor,ttd_nama} — whitelist mirror cert_template.php $allowedFonts
  // LOG incoming fonts_json raw for debugging NULL issue
  try{ $incLog = '[CERT_FONT] saveEventTemplate incoming tipe='.$tipe.' tid='.$tid.' raw_fonts_json='.json_encode($b['fonts_json']??null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).' body_keys='.json_encode(array_keys($b), JSON_UNESCAPED_UNICODE); error_log($incLog); certLogAppend($incLog); }catch(Exception $e){}
  $allowedFontKeys=['nama','label','deskripsi','nomor','ttd_nama'];
  $fontsSent=array_key_exists('fonts_json',$b) && $b['fonts_json']!==null && $b['fonts_json']!=='';
  $fontsJsonOut=null; // null = preserve existing (diisi dari $ex0 di bawah bila tidak sent)
  if($fontsSent){
    $fj=is_string($b['fonts_json'])?$b['fonts_json']:json_encode($b['fonts_json']);
    if(strlen($fj)>2000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'fonts_json terlalu besar (max 2KB)']],422);
    $fo=json_decode($fj,true);
    if(!is_array($fo)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'fonts_json harus JSON object']],422);
    $canon=[];
    foreach($fo as $fk=>$fv){
      if(!in_array($fk,$allowedFontKeys,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"fonts_json.$fk tidak dikenal"]],422);
      $fv=trim((string)$fv);
      // Jangan buang Great Vibes — itu default non-DejaVu yang harus disimpan agar tidak NULL
      // Hanya skip DejaVu Sans (fallback aman) dan string kosong
      if($fv===''||strcasecmp($fv,'DejaVu Sans')===0) continue;
      if(mb_strlen($fv,'UTF-8')>40 || !preg_match('/^[A-Za-z0-9 \\-]+$/',$fv)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>"fonts_json.$fk tidak valid"]],422);
      $canon[$fk]=$fv;
    }
    // canon bisa empty jika semua DejaVu — simpan null (preserve akan fallback ke default Great Vibes via sanFont)
    // tapi log agar tidak silent
    try{ $saveLog='[CERT_FONT] saveEventTemplate canon='.json_encode($canon,JSON_UNESCAPED_UNICODE).' out='.( $canon?json_encode($canon,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'NULL'); error_log($saveLog); certLogAppend($saveLog); }catch(Exception $e){}
    $fontsJsonOut=$canon ? json_encode($canon, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
  }
  $useCustomLayout=!empty($b['use_custom_layout'])?1:0;
  $certBgRaw=trim((string)($b['cert_bg_url']??''));
  $certBgUrl=$certBgRaw; $bgWarning=null;
  if($certBgRaw!=='' && !preg_match('#^[\w/\-.]+\.png(\?[^ ]*)?$#i',$certBgRaw)){ $certBgUrl=''; $bgWarning='cert_bg_url tidak valid, dipakai background global'; } // tidak silent: info via warning
  // preserve existing agar null tidak me-wipe kolom
  $ex0=null; try{ $q0=pdo()->prepare('SELECT * FROM certificate_templates WHERE tipe=? AND target_id=?'); $q0->execute([$tipe,$tid]); $ex0=$q0->fetch()?:null; }catch(Exception $e){}
  if(!$fontsSent) $fontsJsonOut=$ex0['fonts_json']??null; // tidak dikirim = preserve
  $layoutSent=array_key_exists('layout_json',$b) && $b['layout_json']!==null;
  if($useCustomLayout && !$layoutSent) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'layout_json wajib saat use_custom_layout=1']],422);
  // validate layout_json if provided and use_custom_layout=1
  $layoutJsonOut=$ex0['layout_json']??'';
  if($layoutSent){
    $lj=is_string($b['layout_json'])?$b['layout_json']:json_encode($b['layout_json']);
    $lo=json_decode($lj,true);
    if(!is_array($lo)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'layout_json harus JSON object']],422);
    // validate each field (reuse cert_layout allowlist)
    $allowProps=['x','y','w','h','font_pt','align','color','bold','italic','size','font_weight','uppercase','letter_spacing'];
    $allowFields=['nama','label','deskripsi','ttd','qr','nomor'];
    foreach($lo as $k=>$v){
      if(in_array($k,$allowFields,true)){
        if(!is_array($v)) continue;
        foreach($v as $pk=>$pv){
          if(!in_array($pk,$allowProps,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>$k.'.'.$pk.' tidak dikenal']],422);
          if($pk==='x' && (float)$pv>=0 && (float)$pv<=297) {}
          elseif($pk==='y' && (float)$pv>=0 && (float)$pv<=210) {}
          elseif($pk==='w' && (float)$pv>=1 && (float)$pv<=297) {}
          elseif($pk==='h' && (float)$pv>=1 && (float)$pv<=210) {}
          elseif($pk==='font_pt' && (float)$pv>=4 && (float)$pv<=80) {}
          elseif($pk==='align' && in_array($pv,['left','center','right'],true)) {}
          elseif($pk==='color' && is_string($pv)) {}
          elseif($pk==='bold' || $pk==='italic') {}
          elseif($pk==='size') {}
          elseif($pk==='font_weight' && (is_string($pv)||is_bool($pv))) {}
          elseif($pk==='uppercase' && is_bool($pv)) {}
          elseif($pk==='letter_spacing' && (is_numeric($pv)||is_string($pv))) {}
        }
      } elseif(in_array($k,['bg_w','bg_h','bg_mime','bg_aspect','bg_updated_at'],true)) {
        // allow bg_* metadata keys pass through
      } else {
        // unknown top-level key — allow but don't store (we only store known fields)
      }
    }
    $layoutJsonOut=json_encode($lo, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(strlen($layoutJsonOut)>20000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'layout_json terlalu besar (max 20KB)']],422);
  }
  // upsert (toleran kolom fonts_json belum ada: coba full, fallback tanpa fonts_json)
  $cu=currentUser();
  $isSqlite=false; try{ $isSqlite=(pdo()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
  $insFullOk=false;
  try{
    if($isSqlite){
      pdo()->prepare('INSERT INTO certificate_templates(tipe,target_id,label_default,deskripsi_override,layout_json,cert_bg_url,fonts_json,use_custom_layout,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))
        ON CONFLICT(tipe,target_id) DO UPDATE SET label_default=excluded.label_default, deskripsi_override=excluded.deskripsi_override, layout_json=excluded.layout_json, cert_bg_url=excluded.cert_bg_url, fonts_json=excluded.fonts_json, use_custom_layout=excluded.use_custom_layout, updated_at=datetime("now")')
        ->execute([$tipe,$tid,$labelDefault?:null,$deskripsiOverride?:null,$layoutJsonOut?:null,$certBgUrl?:null,$fontsJsonOut,$useCustomLayout,$cu['id']]);
    } else {
      pdo()->prepare('INSERT INTO certificate_templates(tipe,target_id,label_default,deskripsi_override,layout_json,cert_bg_url,fonts_json,use_custom_layout,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE label_default=VALUES(label_default), deskripsi_override=VALUES(deskripsi_override), layout_json=VALUES(layout_json), cert_bg_url=VALUES(cert_bg_url), fonts_json=VALUES(fonts_json), use_custom_layout=VALUES(use_custom_layout), updated_at=NOW()')
        ->execute([$tipe,$tid,$labelDefault?:null,$deskripsiOverride?:null,$layoutJsonOut?:null,$certBgUrl?:null,$fontsJsonOut,$useCustomLayout,$cu['id']]);
    }
    $insFullOk=true;
  }catch(Exception $e){ $insFullOk=false; }
  if(!$insFullOk){
    try{
      if($isSqlite){
        pdo()->prepare('INSERT INTO certificate_templates(tipe,target_id,label_default,deskripsi_override,layout_json,cert_bg_url,use_custom_layout,created_by,created_at,updated_at)
          VALUES (?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))
          ON CONFLICT(tipe,target_id) DO UPDATE SET label_default=excluded.label_default, deskripsi_override=excluded.deskripsi_override, layout_json=excluded.layout_json, cert_bg_url=excluded.cert_bg_url, use_custom_layout=excluded.use_custom_layout, updated_at=datetime("now")')
          ->execute([$tipe,$tid,$labelDefault?:null,$deskripsiOverride?:null,$layoutJsonOut?:null,$certBgUrl?:null,$useCustomLayout,$cu['id']]);
      } else {
        pdo()->prepare('INSERT INTO certificate_templates(tipe,target_id,label_default,deskripsi_override,layout_json,cert_bg_url,use_custom_layout,created_by,created_at,updated_at)
          VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
          ON DUPLICATE KEY UPDATE label_default=VALUES(label_default), deskripsi_override=VALUES(deskripsi_override), layout_json=VALUES(layout_json), cert_bg_url=VALUES(cert_bg_url), use_custom_layout=VALUES(use_custom_layout), updated_at=NOW()')
          ->execute([$tipe,$tid,$labelDefault?:null,$deskripsiOverride?:null,$layoutJsonOut?:null,$certBgUrl?:null,$useCustomLayout,$cu['id']]);
      }
    }catch(Exception $e){
      // fallback: try UPDATE if INSERT failed (existing row)
      try{ pdo()->prepare('UPDATE certificate_templates SET label_default=?,deskripsi_override=?,layout_json=?,cert_bg_url=?,use_custom_layout=?,updated_at=NOW() WHERE tipe=? AND target_id=?')->execute([$labelDefault?:null,$deskripsiOverride?:null,$layoutJsonOut?:null,$certBgUrl?:null,$useCustomLayout,$tipe,$tid]); }catch(Exception $e2){}
    }
  }
  // reload
  $st=pdo()->prepare('SELECT * FROM certificate_templates WHERE tipe=? AND target_id=?'); $st->execute([$tipe,$tid]); $row=$st->fetch();
  try{ $ip=$_SERVER['REMOTE_ADDR']??null; $det=json_encode(['tipe'=>$tipe,'target_id'=>$tid,'use_custom_layout'=>$useCustomLayout,'has_layout'=>$layoutJsonOut!=='','has_bg'=>$certBgUrl!=='','has_fonts'=>$fontsJsonOut!==''],JSON_UNESCAPED_UNICODE); pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)')->execute([$cu['id'],'upsert_cert_template','certificate_template',$tid,$det,$ip]); }catch(Exception $e){}
  header('Cache-Control: no-store');
  $lp=json_decode(trim((string)($row['layout_json']??'')),true); if(!is_array($lp)) $lp=null;
  $fp=json_decode(trim((string)($row['fonts_json']??'')),true); if(!is_array($fp)) $fp=null;
  $resp=['template'=>$row,'target_nama'=>$target['nama'],'layout_parsed'=>$lp,'fonts_parsed'=>$fp,'fonts_json'=>$row['fonts_json']??null,'use_custom_layout'=>(int)($row['use_custom_layout']??0),'cert_bg_url'=>$row['cert_bg_url']??null];
  if($bgWarning) $resp['warning']=$bgWarning;
  jsonOut(['success'=>true,'data'=>$resp]);
}

// batch generate — loop per cert, idempotent, return per-row status
if($uri==='/sertifikat/generate-batch' && $method==='POST'){
  sertifikatRateLimit('sertifikat_batch',5);
  requireRole('admin');
  csrfCheck();
  $b=getBody();
  $tipe=trim(strtolower($b['tipe']??$b['type']??''));
  $tid=(int)($b['target_id']??$b['event_id']??0);
  $userIds=$b['user_ids']??[];
  $labelRaw=trim((string)($b['label']??''));
  if($labelRaw==='') $labelRaw='PESERTA';
  $labelRaw=strip_tags(preg_replace('/[\x00-\x1F\x7F]/u','',preg_replace('/\s+/u',' ', $labelRaw)));
  if(mb_strlen($labelRaw,'UTF-8')>50) $labelRaw=mb_substr($labelRaw,0,50,'UTF-8');
  if(trim($labelRaw)==='') $labelRaw='PESERTA';
  // event-only
  if($tipe!=='event') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya event yang didukung']],422);
  if(!$tid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'target_id wajib']],422);
  if(!is_array($userIds)||count($userIds)===0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'user_ids array wajib (min 1)']],422);
  if(count($userIds)>500) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'max 500 user per batch']],422);
  $userIds=array_unique(array_map('intval',$userIds));
  // shared-hosting tanpa cron: sertifikat kecil (<=50 user) jalan sync langsung,
  // batch besar -> 202 {job_id} diproses worker cron (bila ada).
  if(count($userIds) <= 50){
    // lanjut ke eksekusi sync di bawah (skip blok queue)
  } else if(function_exists('jobs_enqueue')){
    try{
      $qid=jobs_enqueue(pdo(),'sertifikat_batch',['tipe'=>$tipe,'target_id'=>$tid,'user_ids'=>array_values($userIds),'label'=>$labelRaw,'issued_by'=>currentUser()['id']]);
      if($qid) jsonOut(['success'=>true,'queued'=>true,'data'=>['job_id'=>$qid,'total'=>count($userIds)]],202);
    }catch(Exception $e){}
  }
  $results=[]; $created=0; $skipped=0; $failed=0;
  $secret=getSertifikatSecret();
  $issuedBy=currentUser()['id'];
  $userIds=array_values($userIds);
  // batch reads: event 1x + 1 query users LEFT JOIN participants/certificates — tanpa 3 query/user
  $evChk=pdo()->prepare('SELECT id FROM events WHERE id=? AND deleted_at IS NULL'); $evChk->execute([$tid]); $evExists=(bool)$evChk->fetch();
  $uidPh=implode(',',array_fill(0,count($userIds),'?'));
  $batchMap=[]; try{ $bAll=pdo()->prepare("SELECT u.id,u.nama,u.email,u.kelas,ep.hadir,c.id AS cert_id,c.hash AS cert_hash,c.nomor AS cert_nomor FROM users u LEFT JOIN event_participants ep ON ep.event_id=? AND ep.user_id=u.id LEFT JOIN certificates c ON c.tipe=? AND c.target_id=? AND c.user_id=u.id WHERE u.id IN ($uidPh) AND u.deleted_at IS NULL"); $bAll->execute(array_merge([$tid,$tipe,$tid],$userIds)); foreach($bAll->fetchAll() as $br){ $batchMap[(int)$br['id']]=$br; } }catch(Exception $e){}
  foreach($userIds as $uid){
    $uid=(int)$uid; if(!$uid){ $results[]=['user_id'=>0,'ok'=>false,'error'=>'invalid user_id']; $failed++; continue; }
    // fetch user + dup + eligibility dari 1 query JOIN (pesan identik isEligibleForSertifikat)
    $brow=$batchMap[$uid]??null;
    $urow=$brow? ['id'=>$brow['id'],'nama'=>$brow['nama'],'email'=>$brow['email'],'kelas'=>$brow['kelas']]:null;
    if(!$urow){ $results[]=['user_id'=>$uid,'ok'=>false,'error'=>'user not found']; $failed++; continue; }
    if($brow['cert_id']!==null){ $results[]=['user_id'=>$uid,'ok'=>true,'exists'=>true,'id'=>(int)$brow['cert_id'],'hash'=>$brow['cert_hash'],'nomor'=>$brow['cert_nomor'],'nama'=>$urow['nama']]; $skipped++; continue; }
    if(!$evExists){ $results[]=['user_id'=>$uid,'ok'=>false,'error'=>'not eligible: Event tidak ditemukan','nama'=>$urow['nama']]; $failed++; continue; }
    if($brow['hadir']===null){ $results[]=['user_id'=>$uid,'ok'=>false,'error'=>'not eligible: Belum terdaftar di event ini','nama'=>$urow['nama']]; $failed++; continue; }
    if((int)($brow['hadir']??0)!==1){ $results[]=['user_id'=>$uid,'ok'=>false,'error'=>'not eligible: Belum ditandai hadir di event ini (hadir=0)','nama'=>$urow['nama']]; $failed++; continue; }
    // insert (per-row transaction)
    try{
      $pdo=pdo(); $pdo->beginTransaction();
      $chk=$pdo->prepare('SELECT id FROM certificates WHERE user_id=? AND tipe=? AND target_id=? FOR UPDATE'); $chk->execute([$uid,$tipe,$tid]); if($chk->fetch()){ $pdo->rollBack(); $results[]=['user_id'=>$uid,'ok'=>true,'exists'=>true,'nama'=>$urow['nama'],'error'=>'race duplicate']; $skipped++; continue; }
      $raw=bin2hex(random_bytes(32));
      $hash=substr(hash_hmac('sha256',$raw.$uid.$tipe.$tid.microtime(true),$secret),0,64);
      $tries=0; while($tries<3){ $hchk=$pdo->prepare('SELECT 1 FROM certificates WHERE hash=?'); $hchk->execute([$hash]); if(!$hchk->fetch()) break; $hash=substr(hash_hmac('sha256',bin2hex(random_bytes(32)).$uid.$tipe.$tid.microtime(true),$secret),0,64); $tries++; }
      $kelasSnapshot=trim((string)($urow['kelas']??'')); if($kelasSnapshot==='') $kelasSnapshot=null;
      $isSqlite=false; try{ $isSqlite=($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'); }catch(Exception $e){}
      $nomor='TMP';
      try{
        if($isSqlite){
          $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,kelas_snapshot,label,hash,nomor,issued_by,issued_at) VALUES (?,?,?,?,?,?,?,?,"now")')->execute([$uid,$tipe,$tid,$kelasSnapshot,$labelRaw,$hash,$nomor,$issuedBy]);
        } else {
          $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,kelas_snapshot,label,hash,nomor,issued_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$uid,$tipe,$tid,$kelasSnapshot,$labelRaw,$hash,$nomor,$issuedBy]);
        }
      }catch(Exception $eIns){
        if(stripos($eIns->getMessage(),'kelas_snapshot')!==false || stripos($eIns->getMessage(),'label')!==false){
          if($isSqlite){ $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,hash,nomor,issued_by,issued_at) VALUES (?,?,?,?,?,?,"now")')->execute([$uid,$tipe,$tid,$hash,$nomor,$issuedBy]); }
          else { $pdo->prepare('INSERT INTO certificates(user_id,tipe,target_id,hash,nomor,issued_by) VALUES (?,?,?,?,?,?)')->execute([$uid,$tipe,$tid,$hash,$nomor,$issuedBy]); }
        } else { throw $eIns; }
      }
      $newId=(int)$pdo->lastInsertId();
      $nomorReal=buildSertifikatNomor($newId,$tipe);
      $pdo->prepare('UPDATE certificates SET nomor=? WHERE id=?')->execute([$nomorReal,$newId]);
      $pdo->commit();
      try{ $pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([$issuedBy,'generate_batch','certificate',$newId,json_encode(['user_id'=>$uid,'tipe'=>$tipe,'target_id'=>$tid,'nomor'=>$nomorReal])]); }catch(Exception $e){}
      $results[]=['user_id'=>$uid,'ok'=>true,'exists'=>false,'id'=>$newId,'hash'=>$hash,'nomor'=>$nomorReal,'nama'=>$urow['nama']]; $created++;
    }catch(PDOException $e){
      if(pdo()->inTransaction()) pdo()->rollBack();
      $results[]=['user_id'=>$uid,'ok'=>false,'error'=>'db: '.$e->getMessage(),'nama'=>$urow['nama']]; $failed++;
    }catch(Exception $e){
      if(pdo()->inTransaction()) pdo()->rollBack();
      $results[]=['user_id'=>$uid,'ok'=>false,'error'=>$e->getMessage(),'nama'=>$urow['nama']]; $failed++;
    }
  }
  header('Cache-Control: no-store');
  jsonOut(['success'=>true,'data'=>['results'=>$results,'created'=>$created,'skipped'=>$skipped,'failed'=>$failed,'total'=>count($userIds)]]);
}

// === CERT BG — Upload PNG baru timpa api/uploads/cert_bg/cert_bg.png (admin only) ===
if(($uri==='/admin/cert_bg' || $uri==='/settings/cert_bg') && $method==='POST'){
  sertifikatRateLimit('cert_bg_upload',10);
  requireRole('admin');
  csrfCheck();
  // field: cert_bg (primary), fallback: file / bg / image / cover — match frontend FormData
  $file=null; $fieldUsed='';
  foreach(['cert_bg','file','bg','image','cover'] as $k){
    if(!empty($_FILES[$k]) && isset($_FILES[$k]['tmp_name']) && $_FILES[$k]['error']!==UPLOAD_ERR_NO_FILE){ $file=$_FILES[$k]; $fieldUsed=$k; break; }
    // also handle nested array case (should not happen)
    if(!empty($_FILES[$k]['name']) && is_array($_FILES[$k]['name'])){ /* not supported */ }
  }
  if(!$file || empty($file['tmp_name'])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File cert_bg wajib (field: cert_bg, PNG max 5MB)']],400);
  $errCode=(int)($file['error']??UPLOAD_ERR_OK);
  if($errCode!==UPLOAD_ERR_OK){
    if(in_array($errCode,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)) jsonOut(['success'=>false,'error'=>['code'=>'PAYLOAD_TOO_LARGE','message'=>'File terlalu besar, maksimal 5MB']],413);
    jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Upload gagal code '.$errCode]],400);
  }
  $size=(int)($file['size']??0);
  if($size>5*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'PAYLOAD_TOO_LARGE','message'=>'File terlalu besar, maksimal 5MB (terkirim '.round($size/1024/1024,2).'MB)']],413);
  if($size===0) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File kosong']],400);
  $origName=(string)($file['name']??'cert_bg.png');
  $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
  // strict: only png
  if($ext!=='' && $ext!=='png') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Hanya PNG diizinkan (dapat .'.$ext.')']],400);
  $tmp=$file['tmp_name'];
  if(!is_uploaded_file($tmp) && !is_file($tmp)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File upload tidak valid']],400);
  $info=@getimagesize($tmp);
  if(!$info) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File bukan gambar PNG valid']],400);
  $mime=$info['mime']??'';
  if($mime!=='image/png') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'MIME harus image/png (dapat '.$mime.')']],400);
  $w=(int)($info[0]??0); $h=(int)($info[1]??0);
  $warning=null;
  // adaptif: allow any resolusi >=500px, aspect apapun — hanya warning info
  if($w<500 || $h<500) $warning='Dimensi '.$w.'x'.$h.' sangat kecil (<500px), hasil cetak bisa pecah — dianjurkan minimal 2000x1400';
  elseif($w<2000 || $h<1400) $warning='Dimensi '.$w.'x'.$h.' kurang ideal, dianjurkan minimal 2000x1400 (3500x2475 @300dpi) — tetap dipakai, akan di-cover center agar pixel-perfect';
  // any aspect ratio accepted — preview/PDF uses cover
  // also double-check via finfo
  $finfo=@finfo_open(FILEINFO_MIME_TYPE); $fMime=$finfo?@finfo_file($finfo,$tmp):''; @finfo_close($finfo);
  if($fMime && $fMime!=='image/png') jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File bukan PNG valid (finfo: '.$fMime.')']],400);
  $dir=uploadPath('cert_bg');
  if(!is_dir($dir)) jsonOut(['success'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal membuat folder uploads/cert_bg']],500);
  $dest=$dir.'/cert_bg.png';
  // backup lama
  if(is_file($dest)){
    $backup=$dir.'/cert_bg_backup_'.date('Ymd_His').'.png';
    @copy($dest,$backup);
    // keep max 3 backups
    $backs=glob($dir.'/cert_bg_backup_*.png')?:[];
    if(count($backs)>3){ sort($backs); foreach(array_slice($backs,0,count($backs)-3) as $old) @unlink($old); }
  }
  // move
  $moved=false;
  if(is_uploaded_file($tmp)) $moved=@move_uploaded_file($tmp,$dest);
  if(!$moved){ $moved=@copy($tmp,$dest); if($moved) @unlink($tmp); }
  if(!$moved || !is_file($dest)) jsonOut(['success'=>false,'error'=>['code'=>'SERVER','message'=>'Gagal menyimpan cert_bg.png']],500);
  @chmod($dest,0644);
  $finalSize=@filesize($dest)?:$size;
  $finalInfo=@getimagesize($dest); $fw=(int)($finalInfo[0]??$w); $fh=(int)($finalInfo[1]??$h);
  $fmime=$finalInfo['mime']??$mime; $aspect=$fh>0? round($fw/$fh,4): null; $ts=time(); $url='api/uploads/cert_bg/cert_bg.png?t='.$ts;
  // update app_settings — simpan dimensi real + mime/size + timestamp
  try{ setAppSettingsMap(['cert_bg_path'=>'api/uploads/cert_bg/cert_bg.png','cert_bg_updated_at'=>date('Y-m-d H:i:s'),'cert_bg_w'=>(string)$fw,'cert_bg_h'=>(string)$fh,'cert_bg_mime'=>$fmime,'cert_bg_size'=>(string)$finalSize]); }catch(Exception $e){}
  // inject bg_w/bg_h/aspect ke cert_layout JSON jika ada (jaga tidak error jika tidak ada)
  try{
    $certLayoutRaw2=trim((string)(getAppSetting('cert_layout')??''));
    $lo=json_decode($certLayoutRaw2, true);
    if(is_array($lo)){
      $lo['bg_w']=$fw; $lo['bg_h']=$fh; $lo['bg_mime']=$fmime; $lo['bg_aspect']=$aspect; $lo['bg_updated_at']=date('Y-m-d H:i:s');
      setAppSettingsMap(['cert_layout'=>json_encode($lo, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
  }catch(Exception $e){}
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,detail,ip) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'upload_cert_bg','app_settings', json_encode(['field'=>$fieldUsed,'path'=>'api/uploads/cert_bg/cert_bg.png','url'=>$url,'size'=>$finalSize,'w'=>$fw,'h'=>$fh,'aspect'=>$aspect,'mime'=>$fmime,'warning'=>$warning],JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR']??null]); }catch(Exception $e){}
  // bust opcache
  if(function_exists('opcache_invalidate')) @opcache_invalidate($dest,true);
  jsonOut(['success'=>true,'ok'=>true,'path'=>'api/uploads/cert_bg/cert_bg.png','url'=>$url,'size'=>$finalSize,'w'=>$fw,'h'=>$fh,'aspect'=>$aspect,'mime'=>$fmime,'warning'=>$warning]);
}

// === JOBS QUEUE API (zero-infra) — require-safe, tanpa sentuh monolit lain ===
// shared-hosting tanpa cron: claim 1 job pending milik requester saat polling
// GET /jobs/:id (recovery inline; aman via UPDATE atomik status=running).
if(!function_exists('jobs_enqueue') && is_file(__DIR__.'/jobs.php')) require_once __DIR__.'/jobs.php';
if(!function_exists('jobs_recover_inline') && is_file(__DIR__.'/job_handlers.php')) require_once __DIR__.'/job_handlers.php';
if(!function_exists('jobs_recover_inline')){
function jobs_recover_inline(PDO $pdo, array $job): void {
  try{
    if(($job['status']??'')!=='pending') return;
    if(!function_exists('jobs_dispatch') || !function_exists('jobs_done')) return;
    $nowLit=$pdo->quote(date('Y-m-d H:i:s'));
    $claim=$pdo->prepare("UPDATE jobs SET status='running', attempts=attempts+1 WHERE id=? AND status='pending' AND run_at<=$nowLit");
    $claim->execute([(int)$job['id']]);
    if($claim->rowCount()<=0) return;
    $row=function_exists('jobs_get')?jobs_get($pdo,(int)$job['id']):$job;
    $res=jobs_dispatch($pdo,$row);
    if(!empty($res['ok'])){ jobs_done($pdo,(int)$job['id'],json_encode($res,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    else if(function_exists('jobs_fail')){ jobs_fail($pdo,(int)$job['id'],(string)($res['error']??'handler gagal')); }
  }catch(Throwable $eInline){}
}
}
if(routeMatch('/jobs/:id/download',$uri,$pm) && $method==='GET'){
  requireLogin(); $cu=currentUser();
  $jid=(int)($pm['id']??0); if(!$jid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'job id wajib']],422);
  $job=function_exists('jobs_get')?jobs_get(pdo(),$jid):null;
  if(!$job) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Job tidak ditemukan']],404);
  $p=json_decode((string)($job['payload']??'{}'),true); if(!is_array($p)) $p=[];
  $owner=(int)($p['by_id']??0);
  if(!in_array($cu['role'],['admin','kepsek'],true) && $owner!==0 && $owner!==(int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan job milikmu']],403);
  if(($job['status']??'')!=='done') jsonOut(['success'=>false,'error'=>['code'=>'NOT_READY','message'=>'Job belum selesai: '.($job['status']??'?')]],409);
  $meta=null; try{ $raw=@file_get_contents(__DIR__.'/uploads/exports/job-'.$jid.'.json'); $meta=$raw?@json_decode($raw,true):null; }catch(Exception $e){}
  $file=__DIR__.'/uploads/exports/job-'.$jid.'.'.(($job['type']??'')==='export_laporan'?(strtolower((string)($p['format']??'csv'))==='xlsx'?'xlsx':(strtolower((string)($p['format']??'csv'))==='pdf'?'pdf':'csv')):'csv');
  if(!is_file($file)) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'File hasil belum ada']],404);
  if(ob_get_level()) @ob_end_clean(); header_remove('Content-Type');
  $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
  $ct=$ext==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':($ext==='pdf'?'application/pdf':'text/csv; charset=utf-8');
  header('Content-Type: '.$ct); header('Content-Disposition: attachment; filename="job-'.$jid.'.'.$ext.'"'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Content-Length: '.filesize($file));
  if($ext==='csv') echo chr(0xEF).chr(0xBB).chr(0xBF);
  readfile($file); exit;
}
if(routeMatch('/jobs/:id',$uri,$pm) && $method==='GET'){
  requireLogin(); $cu=currentUser();
  $jid=(int)($pm['id']??0); if(!$jid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'job id wajib']],422);
  $job=function_exists('jobs_get')?jobs_get(pdo(),$jid):null;
  if(!$job) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Job tidak ditemukan']],404);
  $p=json_decode((string)($job['payload']??'{}'),true); if(!is_array($p)) $p=[];
  $owner=(int)($p['by_id']??0);
  if(!in_array($cu['role'],['admin','kepsek'],true) && $owner!==0 && $owner!==(int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan job milikmu']],403);
  // shared-hosting tanpa cron: coba pulihkan 1x saat polling, lalu baca ulang status
  if(($job['status']??'')==='pending') try{ jobs_recover_inline(pdo(),$job); $job=jobs_get(pdo(),$jid)?:$job; }catch(Throwable $eRec){}
  $meta=null; try{ $raw=@file_get_contents(__DIR__.'/uploads/exports/job-'.$jid.'.json'); $meta=$raw?@json_decode($raw,true):null; }catch(Exception $e){}
  header('Cache-Control: no-store');
  jsonOut(['success'=>true,'data'=>['id'=>(int)$job['id'],'type'=>$job['type'],'status'=>$job['status'],'attempts'=>(int)($job['attempts']??0),'run_at'=>$job['run_at']??null,'error'=>$job['error']??null,'meta'=>$meta]]);
}

// === SOCIAL (posts/comments/likes/uploads/notifications/profile/admin files) ===
require __DIR__.'/social_routes.php';

// legacy: GET /users?role=pembina searchable dropdown — allow pembina search via ?role=pembina
if($uri==='/users' && $method==='GET' && isset($_GET['role']) && $_GET['role']==='pembina'){
  // allow admin and pembina to search pembina list for combobox
  requireLogin();
  $cu=currentUser();
  if(!in_array($cu['role'],['admin','pembina','kepsek'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
  $q=trim($_GET['search']??$_GET['q']??'');
  $limit=min(20,max(1,(int)($_GET['limit']??20)));
  $where=['role=?','deleted_at IS NULL']; $par=['pembina'];
  if($q!==''){ $where[]='(nama LIKE ? OR email LIKE ? OR nip LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; $par[]=$like; }
  $sql="SELECT id,nama,email,nip FROM users WHERE ".implode(' AND ',$where)." ORDER BY nama ASC LIMIT $limit";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); } unset($r);
  jsonOut(['success'=>true,'data'=>$rows]);
}

// 404
jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Route '.$method.' '.$uri.' not found']],404);
