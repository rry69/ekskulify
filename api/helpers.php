<?php
// pseudo-queue zero-infra: jobs_enqueue tersedia tanpa require monolit index.php
if(!function_exists('jobs_enqueue') && is_file(__DIR__.'/jobs.php')) require_once __DIR__.'/jobs.php';
function jsonIn(): array { $b=file_get_contents('php://input'); $j=json_decode($b,true); return is_array($j)?$j:[]; }
function jsonOut($data,int $code=200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($data,JSON_UNESCAPED_UNICODE); exit; }
function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function requireLogin(){ if(empty($_SESSION['user'])) jsonOut(['success'=>false,'error'=>['code'=>'UNAUTHORIZED','message'=>'Login dulu']],401); }
function requireRole(...$roles){ requireLogin(); if(!in_array($_SESSION['user']['role'],$roles,true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Akses ditolak']],403); }
function currentUser(){ return $_SESSION['user']??null; }
function csrfCheck(){
  $m=$_SERVER['REQUEST_METHOD']??'GET';
  if(in_array($m,['POST','PUT','PATCH','DELETE'],true)){
    $h=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!$h) {
      $body=jsonIn();
      $GLOBALS['_json_body']=$body;
      $h=$body['_csrf']??'';
    }
    if(empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$h)) jsonOut(['success'=>false,'error'=>['code'=>'CSRF_INVALID','message'=>'CSRF invalid']],403);
  }
}
function ensureCsrfToken(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
// === Remember-me (Opsi B): selector:validator, validator disimpan hash, rotate tiap dipakai ===
function rememberEnabled(){ return true; }
function issueRememberToken(int $userId, int $days=30){
  $pdo=pdo();
  $selector=bin2hex(random_bytes(9));   // 18 hex chars
  $validator=bin2hex(random_bytes(32)); // 64 hex chars
  $hash=hash('sha256',$validator);
  $exp=date('Y-m-d H:i:s', time()+$days*86400);
  try{ $pdo->prepare("DELETE FROM remember_tokens WHERE user_id=? AND expires_at < NOW()")->execute([$userId]); }catch(Exception $e){}
  $pdo->prepare('INSERT INTO remember_tokens(selector,validator_hash,user_id,expires_at) VALUES (?,?,?,?)')->execute([$selector,$hash,$userId,$exp]);
  try{
    $ids=$pdo->prepare('SELECT id FROM remember_tokens WHERE user_id=? ORDER BY created_at DESC LIMIT 100'); $ids->execute([$userId]);
    $all=array_column($ids->fetchAll(),'id');
    if(count($all)>5){ $del=array_slice($all,5); $ph=implode(',',array_fill(0,count($del),'?')); $pdo->prepare("DELETE FROM remember_tokens WHERE id IN ($ph)")->execute($del); }
  }catch(Exception $e){}
  $val=$selector.':'.$validator;
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
  setcookie('remember_me',$val,['expires'=>time()+$days*86400,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>$secure]);
}
function tryRememberLogin(){
  if(!empty($_SESSION['user'])) return;
  $raw=$_COOKIE['remember_me']??'';
  if(!$raw || strpos($raw,':')===false) return;
  [$selector,$validator]=explode(':',$raw,2);
  if(strlen($selector)!==18 || strlen($validator)!==64) return;
  try{
    $pdo=pdo();
    $st=$pdo->prepare('SELECT * FROM remember_tokens WHERE selector=?'); $st->execute([$selector]); $row=$st->fetch();
    if(!$row) return;
    $now=time();
    if(strtotime($row['expires_at']) < $now){ $pdo->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$selector]); return; }
    if(!hash_equals($row['validator_hash'], hash('sha256',$validator))){
      try{ $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$row['user_id']]); }catch(Exception $e){}
      setcookie('remember_me','',['expires'=>time()-3600,'path'=>'/']);
      return;
    }
    $u=$pdo->prepare('SELECT id,nama,email,role,status FROM users WHERE id=? AND deleted_at IS NULL'); $u->execute([$row['user_id']]); $user=$u->fetch();
    if(!$user || ($user['status']??'aktif')!=='aktif'){ $pdo->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$selector]); return; }
    $pdo->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$selector]);
    // race fix: no regenerate here — only at login (index.php:45)
    $_SESSION['user']=['id'=>$user['id'],'nama'=>$user['nama'],'email'=>$user['email'],'role'=>$user['role']];
    ensureCsrfToken();
    issueRememberToken((int)$user['id']);
  }catch(Exception $e){}
}
function revokeRememberToken(){
  $raw=$_COOKIE['remember_me']??'';
  if($raw && strpos($raw,':')!==false){
    [$selector]=explode(':',$raw,2);
    try{ pdo()->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$selector]); }catch(Exception $e){}
  }
  setcookie('remember_me','',['expires'=>time()-3600,'path'=>'/']);
}
function revokeAllRememberTokens(int $userId){
  try{ pdo()->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$userId]); }catch(Exception $e){}
}
function notifyAdmins(string $title, string $message, string $type='admin_alert', ?string $groupKey=null, ?int $relatedId=null){
  // sync-first: tulis langsung agar notif pasti sampai meski worker jobs mati;
  // queue hanya best-effort tambahan (abaikan bila gagal).
  notifyAdminsSync($title,$message,$type,$groupKey,$relatedId);
  if(function_exists('jobs_enqueue')){ try{ jobs_enqueue(pdo(),'notify',['audience'=>'admins','title'=>$title,'message'=>$message,'type'=>$type,'group_key'=>$groupKey,'related_id'=>$relatedId]); }catch(Throwable $e){} }
}
function notifyAdminsSync(string $title, string $message, string $type='admin_alert', ?string $groupKey=null, ?int $relatedId=null){
  try{
    $pdo=pdo();
    $admins=$pdo->query("SELECT id FROM users WHERE role='admin' AND deleted_at IS NULL")->fetchAll();
    if(!$admins) return;
    foreach($admins as $a){
      $uid=(int)$a['id'];
      if($groupKey){
        try{
        $chk=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND group_key=? AND is_read=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
        $chk->execute([$uid,$groupKey]);
        $row=$chk->fetch();
        if($row){
          $pdo->prepare("UPDATE notifications SET title=?, message=?, type=?, related_id=?, created_at=NOW() WHERE id=?")->execute([$title,$message,$type,$relatedId,$row['id']]);
          continue;
        }
        }catch(Exception $e){}
      }
      try{ $pdo->prepare("INSERT INTO notifications(user_id,title,message,type,group_key,related_id) VALUES (?,?,?,?,?,?)")->execute([$uid,$title,$message,$type,$groupKey,$relatedId]); }
      catch(Exception $e){
        try{ $pdo->prepare("INSERT INTO notifications(user_id,title,message,type) VALUES (?,?,?,?)")->execute([$uid,$title,$message,$type]); }catch(Exception $e2){}
      }
    }
  }catch(Exception $e){}
}
function notifyKepsek(string $title, string $message, string $type='admin_alert', ?string $groupKey=null, ?int $relatedId=null){
  // shared-hosting tanpa cron: tulis sync dulu agar notif pasti sampai;
  // queue hanya best-effort tambahan (abaikan bila gagal).
  notifyKepsekSync($title,$message,$type,$groupKey,$relatedId);
  if(function_exists('jobs_enqueue')){ try{ jobs_enqueue(pdo(),'notify',['audience'=>'kepsek','title'=>$title,'message'=>$message,'type'=>$type,'group_key'=>$groupKey,'related_id'=>$relatedId]); }catch(Throwable $e){} }
  return;
}
function notifyKepsekSync(string $title, string $message, string $type='admin_alert', ?string $groupKey=null, ?int $relatedId=null){
  try{
    $pdo=pdo();
    $users=$pdo->query("SELECT id FROM users WHERE role='kepsek' AND deleted_at IS NULL")->fetchAll();
    foreach($users as $u){
      $uid=(int)$u['id'];
      if($groupKey){
        try{
        $chk=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND group_key=? AND is_read=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
        $chk->execute([$uid,$groupKey]);
        $row=$chk->fetch();
        if($row){ $pdo->prepare("UPDATE notifications SET title=?, message=?, type=?, related_id=?, created_at=NOW() WHERE id=?")->execute([$title,$message,$type,$relatedId,$row['id']]); continue; }
        }catch(Exception $e){}
      }
      try{ $pdo->prepare("INSERT INTO notifications(user_id,title,message,type,group_key,related_id) VALUES (?,?,?,?,?,?)")->execute([$uid,$title,$message,$type,$groupKey,$relatedId]); }
      catch(Exception $e){ $pdo->prepare("INSERT INTO notifications(user_id,title,message,type) VALUES (?,?,?,?)")->execute([$uid,$title,$message,$type]); }
    }
  }catch(Exception $e){}
}
// === Config accessor (memoized) — config.php return array; kunci baru scale-ready ===
function configValue(string $key, $default = null){
  static $cfg = null;
  if($cfg === null){
    $tmp = @require __DIR__.'/config.php';
    $cfg = is_array($tmp) ? $tmp : ($GLOBALS['__app_config'] ?? []);
    // also handle require_once returning true on re-include
    if(!is_array($cfg) || empty($cfg)) $cfg = $GLOBALS['__app_config'] ?? [];
  }
  return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}
function cacheDriver(): string { return configValue('cache_driver','auto'); }
function redisCacheConn(): ?Redis {
  static $conn=null; static $tried=false;
  if($tried) return $conn;
  $tried=true;
  if(!class_exists('Redis')){ @error_log('cache_driver=redis tapi ekstensi phpredis tidak ada; fallback ke file cache',0); return null; }
  try{
    $conn=new Redis();
    $conn->connect(configValue('redis_host','127.0.0.1'), (int)configValue('redis_port',6379), 1.0);
    return $conn;
  }catch(Exception $e){ $conn=null; }
  return $conn;
}
// === Server-side cache: APCu bila ada (PHP-FPM/CLI), fallback file + flock atomic di sys_get_temp_dir() / api/cache/ ===
function cacheBaseDir(): string {
  $dir = sys_get_temp_dir();
  if(!empty($dir) && is_writable($dir)) return $dir;
  $dir = __DIR__.'/cache';
  if(!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}
function cacheKey(string $ns, mixed ...$parts): string {
  return $ns.'_'.md5(json_encode($parts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function cacheFileGet(string $key, int $ttl=60): ?string {
  $file=cacheBaseDir().'/eskul_cache_'.$key.'.json';
  $fh=@fopen($file,'r');
  if(!$fh) return null;
  if(!@flock($fh, LOCK_SH)){ @fclose($fh); return null; }
  $raw=@stream_get_contents($fh);
  @flock($fh, LOCK_UN); @fclose($fh);
  if($raw===false || $raw==='') return null;
  $j=@json_decode($raw,true);
  if(!is_array($j) || !isset($j['data'])) return null;
  $mtime=(int)($j['_cached_at']??0);
  if(!$mtime) $mtime=@filemtime($file) ?: 0;
  if((time()-$mtime)>=$ttl) return null;
  return $j['data'];
}
function cacheGet(string $key, int $ttl=60): ?string {
  $dr=cacheDriver();
  if($dr==='redis'){
    $r=redisCacheConn();
    if($r){
      try{ $v=$r->get('appcache:'.$key); if($v!==false && $v!==null) return (string)$v; }catch(Exception $e){}
    }
    return cacheFileGet($key,$ttl);
  }
  if($dr==='file' || !function_exists('apcu_enabled') || !apcu_enabled()) return cacheFileGet($key,$ttl);
  $ok=false; $v=apcu_fetch($key,$ok);
  if($ok && is_array($v) && isset($v['data']) && (time()-(int)($v['_cached_at']??0))<$ttl) return $v['data'];
  return null;
}
function cacheFileSet(string $key, string $value, int $ttl=60): void {
  $dir=cacheBaseDir();
  if(!is_dir($dir)) @mkdir($dir, 0775, true);
  if(!is_dir($dir)) return;
  $file=$dir.'/eskul_cache_'.$key.'.json';
  $tmp=$file.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';
  $payload=json_encode(['data'=>$value,'_cached_at'=>time()], JSON_UNESCAPED_UNICODE);
  if(@file_put_contents($tmp,$payload,LOCK_EX)===false) return;
  @rename($tmp,$file); // atomic swap
}
function cacheSet(string $key, string $value, int $ttl=60): void {
  $dr=cacheDriver();
  if($dr==='redis'){
    $r=redisCacheConn();
    if($r){
      try{ $r->setex('appcache:'.$key, max(1,$ttl), (string)$value); return; }catch(Exception $e){}
    }
  }
  if($dr==='redis' || $dr==='file' || !function_exists('apcu_enabled') || !apcu_enabled()){ cacheFileSet($key,$value,$ttl); return; }
  apcu_store($key, ['data'=>$value,'_cached_at'=>time()], max(1,$ttl));
}
function cacheDel(string $key): void {
  $dr=cacheDriver();
  if($dr==='redis'){ $r=redisCacheConn(); if($r){ try{ $r->del('appcache:'.$key); }catch(Exception $e){} } }
  if($dr!=='file' && function_exists('apcu_enabled') && apcu_enabled() && function_exists('apcu_delete')) @apcu_delete($key);
  $file=cacheBaseDir().'/eskul_cache_'.$key.'.json';
  if(is_file($file)) @unlink($file);
}
function cacheDelPrefix(string $prefix): void {
  $dr=cacheDriver();
  if($dr==='redis'){
    $r=redisCacheConn();
    if($r){ try{ $ks=$r->keys('appcache:'.$prefix.'*'); if($ks) $r->del($ks); }catch(Exception $e){} }
  }
  if($dr!=='file' && function_exists('apcu_enabled') && apcu_enabled() && function_exists('apcu_delete')){
    try{ $info=@apcu_cache_info(false); $list=$info['cache_list']??[]; foreach($list as $e){ $k=$e['info']??''; if($k!=='' && strpos($k,$prefix)===0) @apcu_delete($k); } }catch(Exception $e){}
  }
  $dir=cacheBaseDir();
  foreach(glob($dir.'/eskul_cache_'.$prefix.'_*.json')?:[] as $f){ if(is_file($f)) @unlink($f); }
  // legacy dashboard_rekap_{role}_{uid}.json (pola existing dashboard) — jangan sentuh file rate-limit
  if($prefix==='dashboard_rekap'){ foreach(glob($dir.'/dashboard_rekap_*.json')?:[] as $f){ if(is_file($f)) @unlink($f); } }
}
