<?php
date_default_timezone_set('Asia/Jakarta');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax']);
session_start();
header('Content-Type: application/json');
// CORS for Vite dev
if(isset($_SERVER['HTTP_ORIGIN'])){ header('Access-Control-Allow-Origin: '.$_SERVER['HTTP_ORIGIN']); header('Access-Control-Allow-Credentials: true'); header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token'); header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS'); }
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){ http_response_code(204); exit; }

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';

$uri=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$uri=preg_replace('#^/api#','',$uri);
$uri=rtrim($uri,'/'); if($uri==='') $uri='/';
$method=$_SERVER['REQUEST_METHOD']??'GET';

function routeMatch($pattern,$uri,&$params=[]){
  $rx='#^'.preg_replace('#:([\w]+)#','(?P<$1>[^/]+)',$pattern).'$#';
  if(preg_match($rx,$uri,$m)){ foreach($m as $k=>$v) if(!is_int($k)) $params[$k]=$v; return true; }
  return false;
}
function getBody(){ if(isset($GLOBALS['_json_body'])) return $GLOBALS['_json_body']; $b=file_get_contents('php://input'); $j=json_decode($b,true); return is_array($j)?$j:[]; }

// public
if($uri==='/' && $method==='GET') jsonOut(['success'=>true,'data'=>['name'=>'Ekskul API','version'=>'1.0'],'message'=>'OK']);
if(routeMatch('/csrf',$uri) && $method==='GET') jsonOut(['success'=>true,'data'=>['csrf'=>ensureCsrfToken()]]);
if(routeMatch('/auth/login',$uri) && $method==='POST'){
  $b=getBody(); $email=trim($b['email']??''); $pass=$b['password']??'';
  if(!$email||!$pass) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Email & password wajib']],422);
  $u=pdo()->prepare('SELECT * FROM users WHERE email=?'); $u->execute([$email]); $user=$u->fetch();
  if(!$user||!password_verify($pass,$user['password_hash'])) jsonOut(['success'=>false,'error'=>['code'=>'AUTH','message'=>'Email/password salah']],401);
  session_regenerate_id(true); $_SESSION['user']=['id'=>$user['id'],'nama'=>$user['nama'],'email'=>$user['email'],'role'=>$user['role']];
  ensureCsrfToken();
  jsonOut(['success'=>true,'data'=>['user'=>$_SESSION['user'],'csrf'=>$_SESSION['csrf']]]);
}
if(routeMatch('/auth/logout',$uri) && $method==='POST'){ session_destroy(); jsonOut(['success'=>true,'data'=>null,'message'=>'Logged out']); }
if(routeMatch('/auth/me',$uri) && $method==='GET'){
  if(empty($_SESSION['user'])) jsonOut(['success'=>false,'error'=>['code'=>'UNAUTHORIZED','message'=>'Belum login']],401);
  jsonOut(['success'=>true,'data'=>['user'=>$_SESSION['user'],'csrf'=>ensureCsrfToken()]]);
}

// CSRF guard — exempt public auth routes (login/csrf) which are handled above
$csrfExempt = ['/auth/login','/csrf','/'];
$isCsrfExempt=false; foreach($csrfExempt as $p){ if($uri===$p) $isCsrfExempt=true; }
$publicGets = ['/ekskul','/events','/announcements','/kalender'];
$isPublicGet=false;
if($method==='GET'){
  foreach($publicGets as $p){ if($uri===$p || str_starts_with($uri,$p.'/') || $uri==='/kalender') { $isPublicGet=true; break; } }
  if(routeMatch('/ekskul/:id',$uri) && $method==='GET') $isPublicGet=true;
  if(routeMatch('/events/:id',$uri) && $method==='GET') $isPublicGet=true;
}
if(!$isPublicGet && !$isCsrfExempt){
  if(in_array($method,['POST','PUT','PATCH','DELETE'],true)){
    $h=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!$h){ $tmp=getBody(); $h=$tmp['_csrf']??''; $GLOBALS['_json_body']=$tmp; }

    if(empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$h)) jsonOut(['success'=>false,'error'=>['code'=>'CSRF','message'=>'CSRF invalid, refresh token']],403);
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
  // siswa/public only approved — admin/kepsek/pembina can filter all
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
  $cntExpr="(SELECT COUNT(*) FROM registrations r2 WHERE r2.ekskul_id=e.id AND r2.status IN ('diterima','menunggu') AND r2.deleted_at IS NULL)";
  $kuotaWhere='';
  if($kuota==='tersedia'){
    $kuotaWhere=" AND (e.kuota - $cntExpr) > 3";
  } else if($kuota==='hampir'){
    $kuotaWhere=" AND (e.kuota - $cntExpr) BETWEEN 1 AND 3";
  } else if($kuota==='penuh'){
    $kuotaWhere=" AND (e.kuota - $cntExpr) <= 0";
  }
  // sort
  $order=' ORDER BY e.updated_at DESC';
  if($sort==='nama_asc') $order=' ORDER BY e.nama ASC';
  else if($sort==='terisi_desc') $order=' ORDER BY terisi DESC, e.nama ASC';
  // total — need LEFT JOIN users u when search uses u.nama
  $joinU = ($search!=='') ? ' LEFT JOIN users u ON u.id=e.pembina_id' : '';
  $cq='SELECT COUNT(*) c FROM ekskul e'.$joinU.' WHERE '.implode(' AND ',$where).$kuotaWhere;
  $ct=pdo()->prepare($cq); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // data: 1x JOIN + subquery terisi (realtime), no N+1, PDO 100% prepared
  $q='SELECT e.*, u.nama pembina_nama, (SELECT COUNT(*) FROM registrations r WHERE r.ekskul_id=e.id AND r.status IN ("diterima","menunggu") AND r.deleted_at IS NULL) AS terisi FROM ekskul e LEFT JOIN users u ON u.id=e.pembina_id WHERE '.implode(' AND ',$where).$kuotaWhere.$order.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
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
  // Cache: ETag per user when my_status present (private), else public
  $cacheKey=md5(json_encode(['q'=>$q,'par'=>$par,'total'=>$total,'page'=>$page,'limit'=>$limit,'search'=>$search,'status'=>$status,'kuota'=>$kuota,'sort'=>$sort,'role'=>$cu['role']??'guest','uid'=>$cu['id']??0]));
  $etag='"'.md5(json_encode($rows).$total.$page.($cu['id']??'guest')).'"';
  $cc = ($cu && $cu['role']==='siswa') ? 'private, max-age=60, stale-while-revalidate=300' : 'public, max-age=60, stale-while-revalidate=300';
  header('ETag: '.$etag); header('Cache-Control: '.$cc);
  header('X-Total-Count: '.$total); header('X-Page: '.$page); header('X-Limit: '.$limit);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=> (int)ceil($total/$limit)]],200);
}
if(routeMatch('/ekskul/:id',$uri,$pm) && $method==='GET'){
  // JOIN users pembina, 1 query — PRD :93 / task Backend GET /api/ekskul/:id + anti-leak pembina/admin scoping
  $st=pdo()->prepare('SELECT e.*, u.nama pembina_nama, (SELECT COUNT(*) FROM registrations r WHERE r.ekskul_id=e.id AND r.status IN ("diterima","menunggu") AND r.deleted_at IS NULL) AS terisi FROM ekskul e LEFT JOIN users u ON u.id=e.pembina_id WHERE e.id=? AND e.deleted_at IS NULL'); $st->execute([$pm['id']]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $isDev = getenv('APP_ENV')==='local' || getenv('APP_ENV')==='dev' || ($_SERVER['APP_ENV']??'')==='local';
  $cu=currentUser();
  if(!empty($row['is_dummy'])){
    $allowDummy = $isDev || ($cu && in_array($cu['role'],['admin','pembina'],true) && !empty($_GET['include_dummy']));
    if(!$allowDummy) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  }
  // leak guard: public/siswa hanya approved; pembina hanya own jika pending/rejected
  if(!$cu || $cu['role']==='siswa'){
    if($row['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  } else if($cu['role']==='pembina'){
    if($row['status']!=='approved' && (int)$row['pembina_id'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  }
  $row['my_status']=null;
  if($cu && $cu['role']==='siswa'){
    $chk=pdo()->prepare('SELECT status FROM registrations WHERE user_id=? AND ekskul_id=? AND deleted_at IS NULL'); $chk->execute([$cu['id'],$row['id']]);
    $fr=$chk->fetch(); if($fr) $row['my_status']=$fr['status'];
  }
  $etag='"'.md5(json_encode($row).($cu['id']??'guest')).'"';
  $cc = ($cu && $cu['role']==='siswa') ? 'private, max-age=60, stale-while-revalidate=300' : 'public, max-age=60, stale-while-revalidate=300';
  header('ETag: '.$etag); header('Cache-Control: '.$cc);
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
  jsonOut(['success'=>true,'data'=>['id'=>pdo()->lastInsertId(),'status'=>$status,'is_dummy'=>$isDummy]],201);
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
  jsonOut(['success'=>true,'data'=>['id'=>$id]]);
}
if(routeMatch('/ekskul/:id',$uri,$pm) && $method==='DELETE'){
  requireRole('admin'); pdo()->prepare('UPDATE ekskul SET deleted_at=NOW(), updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$pm['id']]); jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/ekskul/:id/toggle-approval',$uri,$pm) && $method==='POST'){
  requireLogin(); $id=$pm['id'];
  if(!isPembinaOf($id) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina']],403);
  // toggle requires_approval — only admin & pembina owner, kepsek not needed here but allowed
  $cur=pdo()->prepare('SELECT requires_approval FROM ekskul WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $next = $row['requires_approval'] ? 0 : 1;
  pdo()->prepare('UPDATE ekskul SET requires_approval=?, updated_at=NOW() WHERE id=?')->execute([$next,$id]);
  jsonOut(['success'=>true,'data'=>['requires_approval'=>$next]]);
}
// approvals — HANYA kepsek (admin tidak boleh approve ekskul/event, sesuai PRD)
if(routeMatch('/ekskul/:id/approve',$uri,$pm) && $method==='POST'){
  requireRole('kepsek'); $b=getBody(); $act=$b['action']??'approve'; $st=$act==='reject'?'rejected':'approved';
  pdo()->prepare('UPDATE ekskul SET status=? WHERE id=?')->execute([$st,$pm['id']]); jsonOut(['success'=>true,'data'=>['status'=>$st]]);
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
  // is_online via last_seen > 3 menit
  $st=pdo()->prepare("SELECT r.*, u.nama, u.email, u.last_seen, CASE WHEN u.last_seen IS NOT NULL AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 1 ELSE 0 END AS is_online FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.ekskul_id=? AND r.deleted_at IS NULL ORDER BY is_online DESC, r.created_at DESC"); 
  // sqlite compat: if db_type sqlite fallback to datetime('now','-3 minutes')
  $c=$GLOBALS['config']??require __DIR__.'/config.php';
  if(($c['db_type']??'mysql')==='sqlite'){
    $st=pdo()->prepare("SELECT r.*, u.nama, u.email, u.last_seen, CASE WHEN u.last_seen IS NOT NULL AND u.last_seen >= datetime('now','-3 minutes') THEN 1 ELSE 0 END AS is_online FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.ekskul_id=? AND r.deleted_at IS NULL ORDER BY is_online DESC, r.created_at DESC");
  }
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
  jsonOut(['success'=>true,'data'=>['message'=>'Pendaftaran dibatalkan','prev_status'=>$prevStatus]]);
}

// === SCHEDULES === GET /api/ekskul/:id/schedules + Cache 60s+ETag + anti-leak pembina
if(routeMatch('/ekskul/:id/schedules',$uri,$pm) && $method==='GET'){
  // anti-leak: same visibility as GET /ekskul/:id
  $ek=pdo()->prepare('SELECT status, pembina_id FROM ekskul WHERE id=? AND deleted_at IS NULL'); $ek->execute([$pm['id']]); $erow=$ek->fetch();
  if(!$erow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  $cu=currentUser();
  if(!$cu || $cu['role']==='siswa'){
    if($erow['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Ekskul tidak ada']],404);
  } else if($cu['role']==='pembina'){
    if($erow['status']!=='approved' && (int)$erow['pembina_id'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  }
  $st=pdo()->prepare('SELECT * FROM schedules WHERE ekskul_id=? ORDER BY tanggal ASC, jam_mulai ASC'); $st->execute([$pm['id']]); $rows=$st->fetchAll();
  $etag='"'.md5(json_encode($rows).$pm['id']).'"';
  header('ETag: '.$etag); header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
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
  jsonOut(['success'=>true,'data'=>['id'=>$newId]],201);
}
if(routeMatch('/schedules/:id',$uri,$pm) && $method==='DELETE'){
  requireLogin(); $s=pdo()->prepare('SELECT * FROM schedules WHERE id=?'); $s->execute([$pm['id']]); $row=$s->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Jadwal tidak ada']],404);
  if(!isPembinaOf($row['ekskul_id']) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina']],403);
  pdo()->prepare('DELETE FROM schedules WHERE id=?')->execute([$pm['id']]); jsonOut(['success'=>true,'data'=>null]);
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
  $st=pdo()->prepare('SELECT a.*, u.nama FROM attendance a JOIN users u ON u.id=a.user_id WHERE a.schedule_id=?'); $st->execute([$sid]); jsonOut(['success'=>true,'data'=>$st->fetchAll()]);
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
  $b=getBody(); $sid=$b['schedule_id']??0; if(!$sid) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'schedule_id wajib']],422);
  $s=pdo()->prepare('SELECT * FROM schedules WHERE id=?'); $s->execute([$sid]); $sc=$s->fetch();
  if(!$sc) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Jadwal tidak ada']],404);
  if(!isPembinaOf($sc['ekskul_id'])) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan ekskul binaan']],403);
  $token=bin2hex(random_bytes(16)); $exp=date('Y-m-d H:i:s',time()+300);
  pdo()->prepare('INSERT INTO attendance_tokens(token,ekskul_id,schedule_id,expiry,used) VALUES (?,?,?,?,0)')->execute([$token,$sc['ekskul_id'],$sid,$exp]);
  jsonOut(['success'=>true,'data'=>['token'=>$token,'expiry'=>$exp]]);
}
if(routeMatch('/attendance/scan',$uri) && $method==='POST'){
  requireRole('siswa'); $b=getBody(); $token=$b['token']??'';
  if(!$token) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'token wajib']],422);
  $t=pdo()->prepare('SELECT * FROM attendance_tokens WHERE token=?'); $t->execute([$token]); $row=$t->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'INVALID','message'=>'Token tidak valid']],404);
  if($row['used']) jsonOut(['success'=>false,'error'=>['code'=>'USED','message'=>'Token sudah dipakai']],409);
  if(strtotime($row['expiry'])<time()) jsonOut(['success'=>false,'error'=>['code'=>'EXPIRED','message'=>'QR expired 5 menit']],410);
  // cek anggota
  $reg=pdo()->prepare('SELECT 1 FROM registrations WHERE user_id=? AND ekskul_id=? AND status="diterima" AND deleted_at IS NULL'); $reg->execute([currentUser()['id'],$row['ekskul_id']]);
  if(!$reg->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_MEMBER','message'=>'Bukan anggota ekskul ini']],403);
  pdo()->prepare('UPDATE attendance_tokens SET used=1 WHERE token=?')->execute([$token]);
  pdo()->prepare('INSERT INTO attendance(schedule_id,user_id,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status="hadir"')->execute([$row['schedule_id'],currentUser()['id'],'hadir']);
  jsonOut(['success'=>true,'data'=>['schedule_id'=>$row['schedule_id'],'ekskul_id'=>$row['ekskul_id']]]);
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
  return true;
}
if($uri==='/events' && $method==='GET'){
  // query: ?page&limit&search&status&sort (sort: tanggal_asc|tanggal_desc|updated_desc)
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $search=trim($_GET['search']??''); $status=trim($_GET['status']??''); $sort=trim($_GET['sort']??'tanggal_asc');
  $cu=currentUser();
  $where=[]; $par=[];
  $where[]='e.deleted_at IS NULL';
  // scoped pembina: hanya event buatannya
  $scopedPembina = ($cu && $cu['role']==='pembina');
  if($scopedPembina){ $where[]='e.created_by=?'; $par[]=$cu['id']; }
  // public/siswa only approved — admin/kepsek/pembina bisa filter all
  if(!$cu || $cu['role']==='siswa'){
    $where[]="e.status='approved'";
  } else if($status && in_array($status,['pending','approved','rejected'],true)){
    $where[]='e.status=?'; $par[]=$status;
  }
  if($search!==''){
    $where[]='e.nama LIKE ?';
    $par[]='%'.$search.'%';
  }
  // total
  $cq='SELECT COUNT(*) c FROM events e WHERE '.implode(' AND ',$where);
  $ct=pdo()->prepare($cq); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // sort: Terdekat=tanggal_asc, Terlengkap=terisi_desc (kuota terisi terbanyak)
  $order=' ORDER BY e.tanggal ASC, e.waktu ASC'; // terdekat default
  if($sort==='tanggal_desc') $order=' ORDER BY e.tanggal DESC, e.waktu DESC';
  else if($sort==='updated_desc') $order=' ORDER BY e.updated_at DESC';
  else if($sort==='nama_asc') $order=' ORDER BY e.nama ASC';
  else if($sort==='terisi_desc') $order=' ORDER BY terisi DESC, e.tanggal ASC';
  // data: 1x JOIN users + subquery terisi
  $q='SELECT e.*, u.nama creator, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id=e.id) AS terisi FROM events e LEFT JOIN users u ON u.id=e.created_by WHERE '.implode(' AND ',$where).$order.' LIMIT '.((int)$limit).' OFFSET '.((int)$off);
  $st=pdo()->prepare($q); $st->execute($par);
  $rows=$st->fetchAll();
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
  // ETag tanpa uid untuk CDN HIT — is_registered tidak masuk ETag
  $rowsForEtag = array_map(function($r){ $c=$r; unset($c['is_registered']); return $c; }, $rows);
  $etagBase = md5(json_encode($rowsForEtag).$total.$page.$search.$status.$sort);
  $etag='"'.$etagBase.'"';
  if(!$cu){
    header('ETag: '.$etag); header('Cache-Control: public, max-age=60, stale-while-revalidate=300'); header('Vary: Accept-Encoding');
  } else {
    // login: private + Vary Cookie/Authorization agar is_registered tetap benar per user
    header('ETag: '.$etag); header('Cache-Control: private, max-age=60, stale-while-revalidate=300'); header('Vary: Cookie, Authorization');
  }
  header('X-Total-Count: '.$total); header('X-Page: '.$page); header('X-Limit: '.$limit);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit)]]);
}
if(routeMatch('/events/:id',$uri,$pm) && $method==='GET'){
  $st=pdo()->prepare('SELECT e.*, u.nama creator, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id=e.id) AS terisi FROM events e LEFT JOIN users u ON u.id=e.created_by WHERE e.id=? AND e.deleted_at IS NULL'); $st->execute([$pm['id']]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  // anti-leak: public/siswa hanya approved; pembina hanya own jika pending/rejected
  $cu=currentUser();
  if(!$cu || $cu['role']==='siswa'){
    if($row['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  } else if($cu['role']==='pembina'){
    if($row['status']!=='approved' && (int)$row['created_by'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembuat event ini']],403);
  }
  $row['is_registered']=0;
  if($cu && $cu['role']==='siswa'){
    $chk=pdo()->prepare('SELECT 1 FROM event_participants WHERE event_id=? AND user_id=?'); $chk->execute([$row['id'],$cu['id']]); if($chk->fetch()) $row['is_registered']=1;
  }
  $rowForEtag = $row; unset($rowForEtag['is_registered']);
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
  requireRole('admin','pembina'); $b=getBody();
  $nama=trim($b['nama']??''); $tanggal=trim($b['tanggal']??''); $waktu=trim($b['waktu']??$b['jam_mulai']??'');
  $waktuSelesai=trim($b['waktu_selesai']??$b['jam_selesai']??''); $lokasi=trim($b['lokasi']??'');
  $kuota=(int)($b['kuota']??0); if($kuota===0 && isset($b['kuota']) && $b['kuota']!=='') $kuota=(int)$b['kuota']; if(empty($b['kuota']) && $b['kuota']!=='0') $kuota=(int)($b['kuota']??50);
  // normalize empty kuota default 50 but still validate >0
  if(!isset($b['kuota']) || $b['kuota']==='' || $b['kuota']===null) $kuota=50;
  else $kuota=(int)$b['kuota'];
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
  pdo()->prepare('INSERT INTO events(nama,deskripsi,tanggal,waktu,waktu_selesai,lokasi,kuota,rundown,status,created_by,registration_start,registration_end) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$nama,$b['deskripsi']??null,$tanggal,$waktu, $waktuSelesai?:null, $lokasi?:null, $kuota, $b['rundown']??null, $status, currentUser()['id'], $regStart, $regEnd]);
  $id=pdo()->lastInsertId();
  // audit log
  try{
    $detail=['nama'=>$nama];
    if($force) $detail=array_merge($detail,['forced_by'=>currentUser()['id'],'forced_at'=>date('Y-m-d H:i:s'),'payload'=>$b,'conflicts_snapshot'=>$forceConfSnapshot]);
    pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],$force?'force_create':'create','event',$id, json_encode($detail,JSON_UNESCAPED_UNICODE)]);
  }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['id'=>$id,'status'=>$status]],201);
}
if(routeMatch('/events/:id',$uri,$pm) && in_array($method,['PUT','PATCH'],true)){
  requireRole('admin','pembina'); $b=getBody(); $id=$pm['id'];
  // scoped pembina: hanya boleh edit miliknya
  $cur=pdo()->prepare('SELECT * FROM events WHERE id=? AND deleted_at IS NULL'); $cur->execute([$id]); $curRow=$cur->fetch();
  if(!$curRow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  if(currentUser()['role']==='pembina' && (int)$curRow['created_by'] !== (int)currentUser()['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pembuat event yang bisa edit']],403);
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
  // periode validate
  $regStart = array_key_exists('registration_start',$b)? ($b['registration_start']?:null) : $curRow['registration_start'];
  $regEnd = array_key_exists('registration_end',$b)? ($b['registration_end']?:null) : $curRow['registration_end'];
  if($regStart && $regEnd && strtotime($regStart) && strtotime($regEnd) && strtotime($regStart) >= strtotime($regEnd)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Periode mulai harus < periode akhir']],422);
  $fields=['nama','deskripsi','tanggal','waktu','waktu_selesai','lokasi','kuota','rundown','registration_start','registration_end'];
  // alias jam_mulai/jam_selesai -> waktu/waktu_selesai
  if(array_key_exists('jam_mulai',$b) && !array_key_exists('waktu',$b)) { $b['waktu']=$b['jam_mulai']; }
  if(array_key_exists('jam_selesai',$b) && !array_key_exists('waktu_selesai',$b)) { $b['waktu_selesai']=$b['jam_selesai']; }
  $sets=[]; $par=[]; foreach($fields as $f) if(array_key_exists($f,$b)){ $sets[]="$f=?"; $par[]=$b[$f]===''?null:$b[$f]; }
  if(!$sets) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tidak ada field']],422);
  $sets[]='updated_at=NOW()';
  $par[]=$id; pdo()->prepare('UPDATE events SET '.implode(',',$sets).' WHERE id=?')->execute($par);
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'update','event',$id, json_encode($b,JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>null]);
}
if(routeMatch('/events/:id',$uri,$pm) && $method==='DELETE'){ requireRole('admin'); $id=$pm['id']; $now=date('Y-m-d H:i:s'); pdo()->prepare('UPDATE events SET deleted_at=?, updated_at=? WHERE id=? AND deleted_at IS NULL')->execute([$now,$now,$id]); try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'delete','event',$id, json_encode(['deleted_at'=>$now],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){} jsonOut(['success'=>true,'data'=>null]); }
if(routeMatch('/events/:id/approve',$uri,$pm) && $method==='POST'){
  requireRole('kepsek'); $b=getBody(); $act=$b['action']??'approve'; $st=$act==='reject'?'rejected':'approved';
  pdo()->prepare('UPDATE events SET status=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$st,$pm['id']]); try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'approve','event',$pm['id'], json_encode(['status'=>$st],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){} jsonOut(['success'=>true,'data'=>['status'=>$st]]);
}
if(routeMatch('/events/:id/daftar',$uri,$pm) && $method==='POST'){
  requireRole('siswa'); $eid=$pm['id']; $uid=currentUser()['id'];
  $e=pdo()->prepare('SELECT * FROM events WHERE id=? AND deleted_at IS NULL'); $e->execute([$eid]); $row=$e->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  if($row['status']!=='approved') jsonOut(['success'=>false,'error'=>['code'=>'NOT_OPEN','message'=>'Event belum approved — Menunggu approval']],422);
  // periode daftar
  if(!empty($row['registration_start']) && strtotime($row['registration_start'])>time()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_OPEN','message'=>'Pendaftaran belum dibuka']],422);
  if(!empty($row['registration_end']) && strtotime($row['registration_end'])<time()) jsonOut(['success'=>false,'error'=>['code'=>'CLOSED','message'=>'Pendaftaran sudah ditutup']],422);
  $c=pdo()->prepare('SELECT COUNT(*) c FROM event_participants WHERE event_id=?'); $c->execute([$eid]); $terisi=$c->fetch()['c'];
  if($terisi >= (int)$row['kuota']) jsonOut(['success'=>false,'error'=>['code'=>'FULL','message'=>'Kuota penuh']],409);
  $ex=pdo()->prepare('SELECT 1 FROM event_participants WHERE event_id=? AND user_id=?'); $ex->execute([$eid,$uid]);
  if($ex->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sudah daftar']],409);
  pdo()->prepare('INSERT INTO event_participants(event_id,user_id,status,hadir) VALUES (?,?,?,0)')->execute([$eid,$uid,'diterima']);
  jsonOut(['success'=>true,'data'=>null],201);
}
if(routeMatch('/events/:id/peserta',$uri,$pm) && $method==='GET'){
  requireLogin();
  // leak guard: only admin or creator pembina can lihat daftar peserta
  $ev=pdo()->prepare('SELECT created_by FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$pm['id']]); $erow=$ev->fetch();
  if(!$erow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $cu=currentUser();
  if($cu['role']==='pembina' && (int)$erow['created_by'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembuat event ini']],403);
  if(!in_array($cu['role'],['admin','pembina','kepsek'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403);
  $st=pdo()->prepare('SELECT p.*, u.nama, u.email FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? ORDER BY p.created_at DESC'); $st->execute([$pm['id']]);
  jsonOut(['success'=>true,'data'=>$st->fetchAll()]);
}
if(routeMatch('/events/:id/hadir',$uri,$pm) && $method==='POST'){
  requireLogin(); if(!in_array(currentUser()['role'],['admin','pembina'],true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pembina/admin']],403);
  $ev=pdo()->prepare('SELECT created_by FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$pm['id']]); $erow=$ev->fetch();
  if(!$erow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $cu=currentUser();
  if($cu['role']==='pembina' && (int)$erow['created_by'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembuat event ini']],403);
  $b=getBody(); $uid=$b['user_id']??0; $hadir=(int)!empty($b['hadir']);
  pdo()->prepare('UPDATE event_participants SET hadir=? WHERE event_id=? AND user_id=?')->execute([$hadir,$pm['id'],$uid]);
  jsonOut(['success'=>true,'data'=>null]);
}
if($uri==='/me/event-registrations' && $method==='GET'){
  requireRole('siswa'); $st=pdo()->prepare('SELECT p.*, e.nama event_nama, e.tanggal, e.waktu, e.waktu_selesai, e.lokasi FROM event_participants p JOIN events e ON e.id=p.event_id WHERE p.user_id=? ORDER BY e.tanggal DESC'); $st->execute([currentUser()['id']]); $rows=$st->fetchAll(); foreach($rows as &$r){ $r['event_nama']=e($r['event_nama']); $r['lokasi']=$r['lokasi']?e($r['lokasi']):null; } unset($r); $etag='"'.md5(json_encode($rows).currentUser()['id']).'"'; header('ETag: '.$etag); header('Cache-Control: private, max-age=60'); if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; } jsonOut(['success'=>true,'data'=>$rows]);
}
if(routeMatch('/events/:id/batal',$uri,$pm) && $method==='POST'){
  requireRole('siswa'); $eid=(int)$pm['id']; $uid=currentUser()['id'];
  $chk=pdo()->prepare('SELECT 1 FROM event_participants WHERE event_id=? AND user_id=?'); $chk->execute([$eid,$uid]); if(!$chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Belum terdaftar di event ini']],404);
  $ev=pdo()->prepare('SELECT tanggal FROM events WHERE id=? AND deleted_at IS NULL'); $ev->execute([$eid]); $row=$ev->fetch(); if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Event tidak ada']],404);
  $evTs=strtotime($row['tanggal'].' 00:00:00'); $now0=strtotime(date('Y-m-d').' 00:00:00');
  if($evTs!==false && $now0!==false && ($evTs - $now0) <= 86400) jsonOut(['success'=>false,'error'=>['code'=>'TOO_LATE','message'=>'H-1 tidak bisa batal online']],409);
  pdo()->prepare('DELETE FROM event_participants WHERE user_id=? AND event_id=?')->execute([$uid,$eid]);
  jsonOut(['success'=>true,'data'=>['message'=>'Event dibatalkan']]);
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
  // --- ETag from max updated + payload hash (cheap vs md5(json) full) ---
  $maxUpd='';
  foreach($rows as $r){ $u=$r['ekskul_updated']??$r['created_at']??''; if($u>$maxUpd) $maxUpd=$u; }
  // TODO V1.1: cross-midnight (jam_selesai < jam_mulai) & filter pembina/lokasi sama belum ditangani — tunda, current overlap cukup untuk shared hosting MVP
  $etag='"'.md5($bulan.'|'.$maxUpd.'|'.count($rows).'|'.md5(json_encode($conflicts)).'|'.($cu['id']??'guest')).'"';
  header('ETag: '.$etag);
  header('Cache-Control: private, max-age=60, stale-while-revalidate=300');
  header('Vary: Cookie');
  header('Content-Type: application/json');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  // payload <50KB guard: LIMIT 200 already
  jsonOut(['success'=>true,'data'=>$data,'meta'=>['total'=>count($rows),'bulan'=>$bulan,'conflicts'=>count($conflicts)]]);
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
  jsonOut(['success'=>true,'data'=>['id'=>pdo()->lastInsertId()]],201);
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
    if($erow['status']!=='approved' && (int)$erow['pembina_id'] !== (int)$cu['id']) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Bukan pembina ekskul ini']],403);
  }
  $st=pdo()->prepare('SELECT p.*, u.nama creator_nama FROM ekskul_pengumuman p LEFT JOIN users u ON u.id=p.created_by WHERE p.ekskul_id=? ORDER BY p.is_pinned DESC, p.created_at DESC LIMIT 50'); $st->execute([$eid]); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['isi']=e($r['isi']); $r['creator_nama']=$r['creator_nama']?e($r['creator_nama']):null; } unset($r);
  $etag='"'.md5(json_encode($rows).$eid).'"';
  header('ETag: '.$etag); header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}
if(routeMatch('/ekskul/:id/pengumuman',$uri,$pm) && $method==='POST'){
  requireLogin();
  $eid=(int)$pm['id'];
  if(!isPembinaOf($eid) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya Pembina/Admin ekskul ini']],403);
  $b=getBody(); $isi=trim($b['isi']??$b['content']??'');
  if(mb_strlen($isi)<3) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pengumuman minimal 3 karakter']],422);
  if(mb_strlen($isi)>2000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pengumuman maksimal 2000 karakter']],422);
  $isPinned = isset($b['is_pinned']) ? (int)!empty($b['is_pinned']) : 1;
  // anti-spam: rate 10/min per user per ekskul (file)
  $rlKey='pengumuman_'.$eid.'_'.currentUser()['id']; $ip=$_SERVER['REMOTE_ADDR']??'cli'; $f=sys_get_temp_dir().'/rl_'.$rlKey.'_'.md5($ip).'.json'; $now=time(); $cnt=0; $win=$now;
  if(file_exists($f)){ $j=@json_decode(@file_get_contents($f),true); if($j && ($now - (int)($j['start']??0) < 60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
  if($cnt>=10){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering posting, coba 1 menit']],429); }
  @file_put_contents($f, json_encode(['count'=>$cnt+1,'start'=>$win]));
  pdo()->prepare('INSERT INTO ekskul_pengumuman(ekskul_id,isi,is_pinned,created_by) VALUES (?,?,?,?)')->execute([$eid,$isi,$isPinned,currentUser()['id']]);
  $id=pdo()->lastInsertId();
  $row=pdo()->prepare('SELECT p.*, u.nama creator_nama FROM ekskul_pengumuman p LEFT JOIN users u ON u.id=p.created_by WHERE p.id=?'); $row->execute([$id]); $r=$row->fetch();
  $r['isi']=e($r['isi']); $r['creator_nama']=$r['creator_nama']?e($r['creator_nama']):null;
  jsonOut(['success'=>true,'data'=>$r],201);
}
if(routeMatch('/ekskul/:id/pengumuman/:pid',$uri,$pm) && $method==='DELETE'){
  requireLogin();
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!isPembinaOf($eid) && currentUser()['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya Pembina/Admin']],403);
  $st=pdo()->prepare('SELECT id FROM ekskul_pengumuman WHERE id=? AND ekskul_id=?'); $st->execute([$pid,$eid]); if(!$st->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Pengumuman tidak ada']],404);
  pdo()->prepare('DELETE FROM ekskul_pengumuman WHERE id=?')->execute([$pid]);
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
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  // scoped pembina
  if($cu['role']==='pembina'){
    if($ekskul_id){
      $chk=pdo()->prepare('SELECT 1 FROM ekskul WHERE id=? AND pembina_id=? AND deleted_at IS NULL'); $chk->execute([$ekskul_id,$cu['id']]);
      if(!$chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak akses ekskul ini (scoped pembina)']],403);
    }
    if($event_id){
      $chk=pdo()->prepare('SELECT 1 FROM events WHERE id=? AND created_by=? AND deleted_at IS NULL'); $chk->execute([$event_id,$cu['id']]);
      if(!$chk->fetch()){
        $ex=pdo()->prepare('SELECT 1 FROM events WHERE id=? AND deleted_at IS NULL'); $ex->execute([$event_id]);
        if($ex->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak akses event ini (scoped pembina)']],403);
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
  // 1 query agregat per tipe (SELECT only needed cols) — 2 queries total = 1 roundtrip HTTP
  if($ekskul_id){
    $ct=pdo()->prepare('SELECT COUNT(*) c FROM registrations r WHERE r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu")');
    $ct->execute([$ekskul_id]); $totalEkskul=(int)($ct->fetch()['c']??0);
    $q='SELECT u.id, u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COUNT(a.id) AS hadir FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.status="hadir" AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC LIMIT '.((int)$limit).' OFFSET '.((int)$off);
    $st=$pdo->prepare($q); $st->execute([$ekskul_id,$from,$to, $ekskul_id, $ekskul_id,$from,$to]);
    $rows=$st->fetchAll();
    foreach($rows as &$r){
      $r['total_sesi']=(int)$r['total_sesi']; $r['hadir']=(int)$r['hadir']; $r['persen']=$r['total_sesi']? round($r['hadir']/$r['total_sesi']*100,1):0;
      $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['kelas']=$r['kelas']?e($r['kelas']):null;
    } unset($r);
    $ekskulRows=$rows;
  }
  if($event_id){
    // total with date filter for pagination
    $ct=pdo()->prepare('SELECT COUNT(*) c FROM event_participants p WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ?');
    $ct->execute([$event_id,$from,$to]); $totalEvent=(int)($ct->fetch()['c']??0);
    $q='SELECT u.id, u.nama, u.email, u.kelas, p.status, p.hadir, p.created_at AS tgl_daftar FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC LIMIT '.((int)$limit).' OFFSET '.((int)$off);
    $st=$pdo->prepare($q); $st->execute([$event_id,$from,$to]);
    $rows=$st->fetchAll();
    foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['status']=e($r['status']); } unset($r);
    $eventRows=$rows;
  }
  // KPI 4 cards
  $avgHadir = 0;
  if($ekskulRows){ $s=array_sum(array_column($ekskulRows,'persen')); $avgHadir = count($ekskulRows)? round($s/count($ekskulRows),1):0; }
  $kpi=['anggota_aktif'=>$totalEkskul,'persen_hadir_rata'=>$avgHadir,'event_peserta'=>$totalEvent,'siap_export'=>($totalEkskul>0 || $totalEvent>0)];
  $dataOut=['kpi'=>$kpi,'ekskul'=>['rows'=>$ekskulRows,'total'=>$totalEkskul,'page'=>$page,'limit'=>$limit],'event'=>['rows'=>$eventRows,'total'=>$totalEvent,'page'=>$page,'limit'=>$limit],'filters'=>['ekskul_id'=>$ekskul_id,'event_id'=>$event_id,'from'=>$from,'to'=>$to]];
  // Cache + ETag md5(json) -> 304 (from spec)
  $etagPayload=json_encode([$ekskul_id,$event_id,$from,$to,$page,$limit,$totalEkskul,$totalEvent,$cu['id']??0,$kpi, md5(json_encode($ekskulRows)), md5(json_encode($eventRows))]);
  $etag='"'.md5($etagPayload).'"';
  header('ETag: '.$etag);
  header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
  header('X-Total-Count: '.max($totalEkskul,$totalEvent));
  header('Content-Type: application/json');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$dataOut,'meta'=>['total_ekskul'=>$totalEkskul,'total_event'=>$totalEvent,'page'=>$page,'limit'=>$limit,'pages'=>max(1, (int)ceil(max($totalEkskul,$totalEvent)/$limit))]]);
}
// === LAPORAN EXPORT STREAM (PRD 40,96,231) — text/csv BOM, fputcsv per row, no load all, scoped, rate 60/min ===
if($uri==='/laporan/export' && in_array($method,['GET','POST'],true)){
  laporanRateLimit('laporan_export');
  requireLogin();
  $cu=currentUser();
  if(!in_array($cu['role'], ['admin','kepsek','pembina'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak — siswa tidak boleh export']],403);
  $tipe=trim($_GET['tipe']??$_POST['tipe']??'');
  $id_raw=trim((string)($_GET['id']??$_POST['id']??($_GET['ekskul_id']??$_POST['ekskul_id']??'')));
  // also support ?event_id
  if($id_raw==='' && isset($_GET['event_id'])) $id_raw=trim((string)$_GET['event_id']);
  if($id_raw==='' && isset($_GET['id_event'])) $id_raw=trim((string)$_GET['id_event']);
  // if tipe not given but id present, infer via param presence? default to ekskul
  $from=trim($_GET['from']??$_POST['from']??'');
  $to=trim($_GET['to']??$_POST['to']??'');
  if($from==='' ) $from=date('Y-m-01');
  if($to==='' ) $to=date('Y-m-t');
  if(!in_array($tipe,['ekskul','event'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tipe harus ekskul|event']],422);
  if($id_raw==='' || !ctype_digit($id_raw)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'id wajib angka dan ada']],422);
  $id=(int)$id_raw;
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || !strtotime($from)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'from harus YYYY-MM-DD']],422);
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to) || !strtotime($to)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'to harus YYYY-MM-DD']],422);
  if(strtotime($from) > strtotime($to)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'from tidak boleh > to']],422);
  // scoped + exists
  if($tipe==='ekskul'){
    $e=pdo()->prepare('SELECT id, nama FROM ekskul WHERE id=? AND deleted_at IS NULL'); $e->execute([$id]); if(!$e->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ekskul id tidak ditemukan']],422);
    if($cu['role']==='pembina'){
      $ch=pdo()->prepare('SELECT 1 FROM ekskul WHERE id=? AND pembina_id=?'); $ch->execute([$id,$cu['id']]); if(!$ch->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak export ekskul ini (scoped)']],403);
    }
  } else {
    $e=pdo()->prepare('SELECT id, nama FROM events WHERE id=? AND deleted_at IS NULL'); $e->execute([$id]); if(!$e->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'event id tidak ditemukan']],422);
    if($cu['role']==='pembina'){
      $ch=pdo()->prepare('SELECT 1 FROM events WHERE id=? AND created_by=?'); $ch->execute([$id,$cu['id']]); if(!$ch->fetch()){
        $ex=pdo()->prepare('SELECT 1 FROM events WHERE id=?'); $ex->execute([$id]); if($ex->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak berhak export event ini (scoped)']],403);
      }
    }
  }
  // stream — clear any previous JSON header, set CSV headers + BOM
  if(function_exists('set_time_limit')) @set_time_limit(0);
  if(ob_get_level()) @ob_end_clean();
  header_remove('Content-Type');
  $filename='rekap-'.date('Y-m').'.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('X-Content-Type-Options: nosniff');
  echo chr(0xEF).chr(0xBB).chr(0xBF);
  $out=fopen('php://output','w');
  $pdo=pdo();
  // MySQL unbuffered for memory safety on 500 rows (no load all)
  $wasBuffered=null;
  if(($GLOBALS['config']['db_type']??'mysql')==='mysql' || (require __DIR__.'/config.php')['db_type']==='mysql'){
    try{ $wasBuffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);}catch(Exception $e){}
  }
  if($tipe==='ekskul'){
    fputcsv($out, ['nama','email','kelas','total_sesi','hadir','persen']);
    $q='SELECT u.nama, u.email, u.kelas, (SELECT COUNT(*) FROM schedules s WHERE s.ekskul_id=? AND s.tanggal BETWEEN ? AND ?) AS total_sesi, COUNT(a.id) AS hadir FROM users u JOIN registrations r ON r.user_id=u.id AND r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ("diterima","menunggu") LEFT JOIN attendance a ON a.user_id=u.id AND a.status="hadir" AND a.schedule_id IN (SELECT id FROM schedules WHERE ekskul_id=? AND tanggal BETWEEN ? AND ?) GROUP BY u.id, u.nama, u.email, u.kelas ORDER BY u.nama ASC';
    $st=$pdo->prepare($q); $st->execute([$id,$from,$to, $id, $id,$from,$to]);
    while($row=$st->fetch(PDO::FETCH_ASSOC)){
      $total=(int)$row['total_sesi']; $hadir=(int)$row['hadir']; $persen=$total? round($hadir/$total*100,1):0;
      fputcsv($out, [$row['nama'],$row['email'],$row['kelas']??'', $total,$hadir,$persen]);
      if(ob_get_level()) @ob_flush(); @flush();
    }
  } else {
    fputcsv($out, ['peserta','email','status','tgl_daftar']);
    $q='SELECT u.nama, u.email, p.status, p.created_at FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC';
    $st=$pdo->prepare($q); $st->execute([$id,$from,$to]);
    while($row=$st->fetch(PDO::FETCH_ASSOC)){
      fputcsv($out, [$row['nama'],$row['email'],$row['status'],$row['created_at']]);
      if(ob_get_level()) @ob_flush(); @flush();
    }
  }
  fclose($out);
  if($wasBuffered!==null){ try{ $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$wasBuffered);}catch(Exception $e){} }
  exit;
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
if($uri==='/users' && $method==='GET'){
  requireRole('admin');
  // rate limit GET juga (PRD: 60/min per IP)
  usersRateLimit('users_get');
  $q=trim($_GET['q']??$_GET['search']??'');
  $role=trim($_GET['role']??'all');
  $sort=trim($_GET['sort']??'newest');
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $allowedRoles=['admin','pembina','siswa','kepsek'];
  // WHERE builder — PDO prepared 100%, no concat user input
  $where=['u.deleted_at IS NULL']; $par=[];
  if($q!==''){ $where[]='(u.nama LIKE ? OR u.email LIKE ?)'; $like='%'.$q.'%'; $par[]=$like; $par[]=$like; }
  if($role!=='' && $role!=='all'){
    if(!in_array($role,$allowedRoles,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Role filter tidak valid']],422);
    $where[]='u.role=?'; $par[]=$role;
  }
  $order=' ORDER BY u.created_at DESC, u.id DESC';
  if($sort==='nama') $order=' ORDER BY u.nama ASC';
  else if($sort==='role') $order=' ORDER BY FIELD(u.role,"admin","kepsek","pembina","siswa"), u.nama ASC';
  else if($sort==='newest') $order=' ORDER BY u.created_at DESC, u.id DESC';
  $whereSql=implode(' AND ',$where);
  // total COUNT(*)
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM users u WHERE $whereSql"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  // stats for header cards (unfiltered total + per role)
  $stats=['total'=>0,'siswa'=>0,'pembina'=>0,'admin_kepsek'=>0];
  try{
    $s=pdo()->query("SELECT role, COUNT(*) c FROM users WHERE deleted_at IS NULL GROUP BY role");
    foreach($s->fetchAll() as $r){ if($r['role']==='siswa') $stats['siswa']=(int)$r['c']; else if($r['role']==='pembina') $stats['pembina']=(int)$r['c']; else if(in_array($r['role'],['admin','kepsek'],true)) $stats['admin_kepsek']+=(int)$r['c']; }
    $stats['total']=$stats['siswa']+$stats['pembina']+$stats['admin_kepsek'];
  }catch(Exception $e){}
  // data — SELECT only needed cols, no SELECT *, no N+1, no password_hash
  $sql="SELECT u.id,u.nama,u.email,u.role,u.nip,u.kelas,u.status,u.created_at FROM users u WHERE $whereSql $order LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  // ESCAPE XSS in nama/email for consumers that dangerouslySetInnerHTML
  foreach($rows as &$r){ $r['nama']=e($r['nama']); $r['email']=e($r['email']); $r['nip']=$r['nip']?e($r['nip']):null; $r['kelas']=$r['kelas']?e($r['kelas']):null; }
  unset($r);
  // headers: X-Total-Count + pagination + Cache + ETag md5(list) -> 304
  $payloadForEtag=json_encode(['rows'=>$rows,'total'=>$total,'q'=>$q,'role'=>$role,'sort'=>$sort,'page'=>$page,'limit'=>$limit]);
  $etag='"'.md5($payloadForEtag).'"';
  header('ETag: '.$etag);
  header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
  header('X-Total-Count: '.$total);
  header('X-Page: '.$page);
  header('X-Limit: '.$limit);
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit),'stats'=>$stats]]);
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
  jsonOut(['success'=>true,'data'=>['id'=>$id]],201);
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
  try{ pdo()->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)')->execute([currentUser()['id'],'reset_password','user',$id, json_encode(['reset_at'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>null]);
}
// === PRESENCE HEARTBEAT + POLLS (A-A-A) ===
if($uri==='/presence/heartbeat' && $method==='POST'){
  requireLogin();
  $uid=currentUser()['id'];
  // rate 30/min per user (heartbeat 60s -> 1/min, buffer burst 30)
  $f=sys_get_temp_dir()."/presence_rl_{$uid}.json"; $now=time(); $cnt=0; $win=$now;
  $fh=@fopen($f,'c+'); if($fh){ @flock($fh,LOCK_EX); $raw=@stream_get_contents($fh); $j=$raw?@json_decode($raw,true):null; if($j && ($now-(int)($j['start']??0)<60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } if($cnt>=30){ @flock($fh,LOCK_UN); @fclose($fh); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering heartbeat']],429); } @ftruncate($fh,0); @rewind($fh); @fwrite($fh, json_encode(['count'=>$cnt+1,'start'=>$win])); @flock($fh,LOCK_UN); @fclose($fh); }
  $pdo=pdo(); $c=$GLOBALS['config']??require __DIR__.'/config.php';
  $isSqlite = ($c['db_type']??'mysql')==='sqlite';
  if($isSqlite) $pdo->prepare("UPDATE users SET last_seen=datetime('now') WHERE id=?")->execute([$uid]);
  else $pdo->prepare("UPDATE users SET last_seen=NOW() WHERE id=?")->execute([$uid]);
  jsonOut(['success'=>true,'data'=>['last_seen'=>date('Y-m-d H:i:s')]]);
}
if(routeMatch('/ekskul/:id/polls',$uri,$pm) && $method==='GET'){
  requireLogin(); $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $rows=pdo()->prepare("SELECT ep.*, u.nama author_nama FROM ekskul_polls ep LEFT JOIN users u ON u.id=ep.user_id WHERE ep.ekskul_id=? ORDER BY ep.created_at DESC LIMIT 50"); $rows->execute([$eid]); $polls=$rows->fetchAll();
  foreach($polls as &$pl){
    $opts=pdo()->prepare("SELECT po.id, po.label, po.sort_order, (SELECT COUNT(*) FROM poll_votes pv WHERE pv.option_id=po.id) AS votes FROM poll_options po WHERE po.poll_id=? ORDER BY po.sort_order ASC, po.id ASC"); $opts->execute([$pl['id']]); $pl['options']=$opts->fetchAll();
    $total=array_sum(array_column($pl['options'],'votes'));
    foreach($pl['options'] as &$o){ $o['votes']=(int)$o['votes']; $o['percent']=$total? round($o['votes']/$total*100,1):0; } unset($o);
    $pl['total_votes']=$total;
    $my=pdo()->prepare("SELECT option_id FROM poll_votes WHERE poll_id=? AND user_id=?"); $my->execute([$pl['id'], currentUser()['id']]); $m=$my->fetch(); $pl['my_vote']=$m? (int)$m['option_id']:null;
    $pl['question']=e($pl['question']); $pl['author_nama']=e($pl['author_nama']??'');
  } unset($pl);
  $etag='"'.md5(json_encode($polls).$eid).'"'; header('ETag: '.$etag); header('Cache-Control: private, max-age=15, stale-while-revalidate=30');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$polls]);
}
if(routeMatch('/ekskul/:id/polls',$uri,$pm) && $method==='POST'){
  requireLogin(); socialRateLimit('poll_create',10); $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $b=getBody(); $q=trim($b['question']??$b['judul']??''); $optsRaw=$b['options']??$b['choices']??[];
  if(mb_strlen($q)<5) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pertanyaan minimal 5 karakter']],422);
  if(mb_strlen($q)>500) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Pertanyaan maksimal 500']],422);
  if(!is_array($optsRaw) || count($optsRaw)<2) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Minimal 2 opsi']],422);
  if(count($optsRaw)>6) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maksimal 6 opsi']],422);
  $clean=[]; foreach($optsRaw as $o){ $t=trim((string)$o); if($t==='') continue; if(mb_strlen($t)>100) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Opsi maksimal 100 karakter']],422); $clean[]=$t; }
  if(count($clean)<2) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Minimal 2 opsi valid']],422);
  // dedup case-insensitive
  $low=array_map(fn($x)=>mb_strtolower($x),$clean); if(count($low)!==count(array_unique($low))) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Opsi tidak boleh duplikat']],422);
  $postId=isset($b['post_id']) ? (int)$b['post_id'] : null;
  if($postId){ $chk=pdo()->prepare("SELECT 1 FROM ekskul_posts WHERE id=? AND ekskul_id=? AND deleted_at IS NULL"); $chk->execute([$postId,$eid]); if(!$chk->fetch()) $postId=null; }
  pdo()->prepare("INSERT INTO ekskul_polls(ekskul_id,post_id,user_id,question) VALUES (?,?,?,?)")->execute([$eid,$postId,currentUser()['id'],$q]);
  $pid=pdo()->lastInsertId();
  foreach($clean as $i=>$lab){ pdo()->prepare("INSERT INTO poll_options(poll_id,label,sort_order) VALUES (?,?,?)")->execute([$pid,$lab,$i]); }
  jsonOut(['success'=>true,'data'=>['id'=>(int)$pid]],201);
}
if(routeMatch('/polls/:id/vote',$uri,$pm) && $method==='POST'){
  requireLogin(); socialRateLimit('poll_vote',20); $pollId=(int)$pm['id'];
  $st=pdo()->prepare("SELECT ekskul_id, closed_at FROM ekskul_polls WHERE id=?"); $st->execute([$pollId]); $poll=$st->fetch();
  if(!$poll) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Poll tidak ada']],404);
  if(!empty($poll['closed_at']) && strtotime($poll['closed_at'])<time()) jsonOut(['success'=>false,'error'=>['code'=>'CLOSED','message'=>'Poll sudah ditutup']],410);
  if(!canAccessEkskulSocial((int)$poll['ekskul_id'])) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $b=getBody(); $optId=(int)($b['option_id']??$b['optionId']??0);
  if(!$optId) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'option_id wajib']],422);
  $chk=pdo()->prepare("SELECT 1 FROM poll_options WHERE id=? AND poll_id=?"); $chk->execute([$optId,$pollId]); if(!$chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Opsi tidak valid untuk poll ini']],422);
  // one vote per user, allow change (UPSERT)
  try{
    pdo()->prepare("INSERT INTO poll_votes(poll_id,option_id,user_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE option_id=VALUES(option_id)")->execute([$pollId,$optId,currentUser()['id']]);
  } catch(Exception $e){
    // sqlite fallback: INSERT OR REPLACE
    try{ pdo()->prepare("INSERT OR REPLACE INTO poll_votes(poll_id,option_id,user_id) VALUES (?,?,?)")->execute([$pollId,$optId,currentUser()['id']]); }catch(Exception $e2){ throw $e; }
  }
  // agregat fresh
  $opts=pdo()->prepare("SELECT po.id, po.label, (SELECT COUNT(*) FROM poll_votes pv WHERE pv.option_id=po.id) AS votes FROM poll_options po WHERE po.poll_id=? ORDER BY po.sort_order ASC"); $opts->execute([$pollId]); $rows=$opts->fetchAll();
  $total=array_sum(array_column($rows,'votes')); foreach($rows as &$r){ $r['votes']=(int)$r['votes']; $r['percent']=$total? round($r['votes']/$total*100,1):0; } unset($r);
  jsonOut(['success'=>true,'data'=>['options'=>$rows,'total'=>$total,'my_vote'=>$optId]]);
}
if(routeMatch('/polls/:id',$uri,$pm) && $method==='DELETE'){
  requireLogin(); $pollId=(int)$pm['id'];
  $st=pdo()->prepare("SELECT ekskul_id, user_id FROM ekskul_polls WHERE id=?"); $st->execute([$pollId]); $poll=$st->fetch();
  if(!$poll) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Poll tidak ada']],404);
  $cu=currentUser();
  if((int)$poll['user_id']!==(int)$cu['id'] && !isPembinaOf((int)$poll['ekskul_id']) && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pemilik/pembina']],403);
  pdo()->prepare("DELETE FROM ekskul_polls WHERE id=?")->execute([$pollId]);
  jsonOut(['success'=>true,'data'=>null]);
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
