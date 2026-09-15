<?php
// Ekskul Social: posts, comments, likes, uploads, notifications, profile, admin file manager
// Polls: create/get/vote/close (attached to posts), like/comment grouped notifications, member-only guard, local upload

function isMemberOfEkskul($ekskulId, $uid=null){
  if($uid===null){ $u=currentUser(); if(!$u) return false; $uid=$u['id']; }
  $st=pdo()->prepare("SELECT 1 FROM registrations WHERE ekskul_id=? AND user_id=? AND deleted_at IS NULL AND status IN ('diterima','menunggu')");
  $st->execute([$ekskulId,$uid]);
  return (bool)$st->fetch();
}
function canAccessEkskulSocial($ekskulId){
  $u=currentUser(); if(!$u) return false;
  if($u['role']==='admin') return true;
  if(isPembinaOf($ekskulId)) return true;
  return isMemberOfEkskul($ekskulId, $u['id']);
}
function socialRateLimit($key, $limit=20){
  $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';
  $uid=currentUser()['id']??0;
  $f=sys_get_temp_dir()."/social_rl_".$key."_".$uid."_".md5($ip).".json";
  $now=time(); $cnt=0; $win=$now;
  $fp=@fopen($f,'c+');
  if($fp){
    @flock($fp, LOCK_EX);
    $raw=@stream_get_contents($fp); $j=$raw?@json_decode($raw,true):null;
    if($j && ($now - (int)($j['start']??0)<60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); }
    else if($j && ($now - (int)($j['start']??0)>=60)){ $cnt=0; $win=$now; }
    if($cnt>=$limit){ @flock($fp, LOCK_UN); @fclose($fp); header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering, coba 1 menit']],429); }
    @ftruncate($fp,0); @rewind($fp); @fwrite($fp, json_encode(['count'=>$cnt+1,'start'=>$win]));
    @flock($fp, LOCK_UN); @fclose($fp);
  } else {
    if(file_exists($f)){ $j=@json_decode(@file_get_contents($f),true); if($j && ($now - (int)($j['start']??0)<60)){ $cnt=(int)($j['count']??0); $win=(int)($j['start']); } }
    if($cnt>=$limit){ header('Retry-After: 60'); jsonOut(['success'=>false,'error'=>['code'=>'RATE_LIMIT','message'=>'Terlalu sering, coba 1 menit']],429); }
    @file_put_contents($f, json_encode(['count'=>$cnt+1,'start'=>$win]));
  }
}
function createGroupedNotif($recipientId, $actorId, $ekskulId, $postId, $commentId, $tipe, $groupKey){
  if((int)$recipientId === (int)$actorId) return;
  $pdo=pdo();
  // find existing unread with same group_key
  $st=$pdo->prepare("SELECT id, actor_count, actor_sample FROM ekskul_notifications WHERE recipient_id=? AND group_key=? AND is_read=0 LIMIT 1");
  $st->execute([$recipientId,$groupKey]);
  $row=$st->fetch();
  $actor=pdo()->prepare("SELECT nama FROM users WHERE id=?"); $actor->execute([$actorId]); $actorName=$actor->fetch()['nama']??'Seseorang';
  if($row){
    $cnt=(int)$row['actor_count']+1;
    $sample=$row['actor_sample'] ?? '';
    // keep sample max 3 names
    $parts=array_filter(explode(',', $sample));
    if(!in_array($actorName,$parts,true)) $parts[]=$actorName;
    if(count($parts)>3) $parts=array_slice($parts,-3);
    $sample=implode(',', $parts);
    $msg='';
    if($tipe==='like_post') $msg = $cnt.' orang menyukai postingan kamu';
    elseif($tipe==='like_comment') $msg = $cnt.' orang menyukai komentar kamu';
    elseif($tipe==='reply') $msg = $cnt.' orang membalas '.($commentId?'komentar':'postingan').' kamu';
    elseif($tipe==='mention') $msg = $actorName.' menyebut kamu';
    elseif($tipe==='pengumuman') $msg = 'Pengumuman baru di ekskul';
    else $msg=$cnt.' notifikasi baru';
    $pdo->prepare("UPDATE ekskul_notifications SET actor_count=?, actor_sample=?, message=?, updated_at=NOW() WHERE id=?")->execute([$cnt,$sample,$msg,$row['id']]);
  } else {
    $msg='';
    if($tipe==='like_post') $msg='menyukai postingan kamu';
    elseif($tipe==='like_comment') $msg='menyukai komentar kamu';
    elseif($tipe==='reply') $msg='membalas '.($commentId?'komentar':'postingan').' kamu';
    elseif($tipe==='mention') $msg='menyebut kamu';
    else $msg='notifikasi baru';
    // for single, message is actorName + msg
    $full=$actorName.' '.$msg;
    $pdo->prepare("INSERT INTO ekskul_notifications(recipient_id,actor_id,ekskul_id,post_id,comment_id,tipe,group_key,actor_count,actor_sample,message) VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([$recipientId,$actorId,$ekskulId,$postId,$commentId,$tipe,$groupKey,1,$actorName,$full]);
  }
}
function parseMentions($text){
  // extract @username or @Nama patterns — for simplicity @ followed by word, lookup users by nama like
  preg_match_all('/@([A-Za-z0-9_ ]{2,30})/', $text, $m);
  return array_unique(array_map('trim',$m[1]??[]));
}

// === PROFILE GET /u/:id ===
if(routeMatch('/u/:id',$uri,$pm) && $method==='GET'){
  requireLogin();
  $uid=(int)$pm['id'];
  $st=pdo()->prepare("SELECT id,nama,email,role,nip,kelas,foto,created_at FROM users WHERE id=? AND deleted_at IS NULL");
  $st->execute([$uid]); $u=$st->fetch();
  if(!$u) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'User tidak ada']],404);
  $u['nama']=e($u['nama']); $u['email']=e($u['email']);
  $u['foto_url']=!empty($u['foto'])?'/api/avatar/'.(int)$u['id']:null;
  // hide email for non-self non-admin
  $cu=currentUser();
  if((int)$cu['id']!==(int)$uid && $cu['role']!=='admin') unset($u['email']);
  // counts
  $c1=pdo()->prepare("SELECT COUNT(*) c FROM registrations WHERE user_id=? AND deleted_at IS NULL AND status IN ('diterima','menunggu')"); $c1->execute([$uid]); $u['ekskul_count']=(int)($c1->fetch()['c']??0);
  $c2=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_posts WHERE user_id=? AND deleted_at IS NULL"); $c2->execute([$uid]); $u['posts_count']=(int)($c2->fetch()['c']??0);
  jsonOut(['success'=>true,'data'=>$u]);
}

// === POSTS LIST GET /ekskul/:id/posts ===
if(routeMatch('/ekskul/:id/posts',$uri,$pm) && $method==='GET'){
  requireLogin();
  $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota ekskul ini']],403);
  $tipe=trim($_GET['tipe']??''); // diskusi/tanya/postingan
  $page=max(1,(int)($_GET['page']??1)); $limit=min(50,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $search=trim($_GET['search']??'');
  $where=['p.ekskul_id=?','p.deleted_at IS NULL']; $par=[$eid];
  if($tipe && in_array($tipe,['diskusi','tanya','postingan'],true)){ $where[]='p.tipe=?'; $par[]=$tipe; }
  if($search!==''){ $where[]='(p.isi LIKE ? OR p.judul LIKE ?)'; $par[]='%'.$search.'%'; $par[]='%'.$search.'%'; }
  $whereSql=implode(' AND ',$where);
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_posts p WHERE $whereSql"); $ct->execute($par); $total=(int)($ct->fetch()['c']??0);
  $q="SELECT p.*, u.nama author_nama, u.role author_role FROM ekskul_posts p LEFT JOIN users u ON u.id=p.user_id WHERE $whereSql ORDER BY p.created_at DESC LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($q); $st->execute($par); $rows=$st->fetchAll();
  $cu=currentUser();
  $postIds=array_values(array_unique(array_map('intval',array_column($rows,'id'))));
  $likeAgg=[]; $ccntMap=[]; $upsByPost=[]; $pollByPost=[]; $optsByPoll=[]; $myVoteMap=[];
  if($postIds){
    $ph=implode(',',array_fill(0,count($postIds),'?'));
    $la=pdo()->prepare("SELECT target_id, COUNT(*) c, SUM(user_id=?) liked FROM ekskul_likes WHERE target_type='post' AND target_id IN ($ph) GROUP BY target_id");
    $la->execute(array_merge([$cu['id']],$postIds));
    foreach($la->fetchAll() as $a){ $likeAgg[(int)$a['target_id']]=[(int)$a['c'],(int)$a['liked']>0]; }
    $cc=pdo()->prepare("SELECT post_id, COUNT(*) c FROM ekskul_comments WHERE post_id IN ($ph) AND deleted_at IS NULL GROUP BY post_id");
    $cc->execute($postIds);
    foreach($cc->fetchAll() as $a){ $ccntMap[(int)$a['post_id']]=(int)$a['c']; }
    $up=pdo()->prepare("SELECT id,post_id,original_name,stored_path,mime,size FROM ekskul_uploads WHERE post_id IN ($ph) AND deleted_at IS NULL");
    $up->execute($postIds);
    foreach($up->fetchAll() as $u){ $u['url']='/api/uploads/'.$u['id']; $upPid=(int)$u['post_id']; unset($u['post_id']); $upsByPost[$upPid][]=$u; }
    try{
      $ps=pdo()->prepare("SELECT id,post_id,question,closed_at,created_at FROM ekskul_polls WHERE post_id IN ($ph)");
      $ps->execute($postIds);
      $pollRows=$ps->fetchAll();
      foreach($pollRows as $pr){ $pollByPost[(int)$pr['post_id']]=$pr; }
      $pollIds=array_values(array_unique(array_map('intval',array_column($pollRows,'id'))));
      if($pollIds){
        $ph2=implode(',',array_fill(0,count($pollIds),'?'));
        $os=pdo()->prepare("SELECT po.id,po.poll_id,po.label,po.sort_order,COUNT(pv.option_id) votes,MAX(CASE WHEN pv.user_id=? THEN 1 ELSE 0 END) mine FROM poll_options po LEFT JOIN poll_votes pv ON pv.option_id=po.id WHERE po.poll_id IN ($ph2) GROUP BY po.id ORDER BY po.sort_order ASC, po.id ASC");
        $os->execute(array_merge([$cu['id']],$pollIds));
        foreach($os->fetchAll() as $o){ $opid=(int)$o['poll_id']; if((int)$o['mine']===1) $myVoteMap[$opid]=(int)$o['id']; unset($o['mine']); $optsByPoll[$opid][]=$o; }
      }
    }catch(Exception $e){ $pollByPost=[]; $optsByPoll=[]; $myVoteMap=[]; }
  }
  foreach($rows as &$r){
     $pid=(int)$r['id'];
     $r['isi']=e($r['isi']); $r['judul']=$r['judul']?e($r['judul']):null; $r['author_nama']=e($r['author_nama']);
     $r['likes']=$likeAgg[$pid][0]??0;
     $r['is_liked']=$likeAgg[$pid][1]??false;
     $r['comments_count']=$ccntMap[$pid]??0;
     $r['uploads']=$upsByPost[$pid]??[];
     $poll=$pollByPost[$pid]??null;
     if($poll){
       unset($poll['post_id']);
       $opts=$optsByPoll[(int)$poll['id']]??[];
       $total=0; foreach($opts as $o){ $total+=(int)$o['votes']; }
       foreach($opts as &$o){ $o['votes']=(int)$o['votes']; $o['percent']=$total? round($o['votes']/$total*100,1):0; $o['label']=e($o['label']); unset($o['poll_id']); } unset($o);
       $poll['options']=array_values($opts);
       $poll['total_votes']=$total;
       $poll['my_vote']=$myVoteMap[(int)$poll['id']]??null;
       $poll['question']=e($poll['question']); $poll['is_closed']=!empty($poll['closed_at']);
       $r['poll']=$poll;
     } else $r['poll']=null;
   }
  unset($r);
  $etag='"'.md5(json_encode($rows).$total.$eid.$tipe).'"';
  header('ETag: '.$etag); header('Cache-Control: private, max-age=10');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit,'pages'=>(int)ceil($total/$limit)]]);
}

// === POST CREATE ===
if(routeMatch('/ekskul/:id/members',$uri,$pm) && $method==='GET'){
  requireLogin(); $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $q=trim($_GET['q']??''); $limit=min(20,max(1,(int)($_GET['limit']??20)));
  $sql="SELECT r.user_id, u.nama, u.role FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.ekskul_id=? AND r.deleted_at IS NULL AND r.status IN ('diterima','menunggu') AND u.deleted_at IS NULL";
  $par=[$eid];
  if($q!==''){ $sql.=" AND u.nama LIKE ?"; $par[]='%'.$q.'%'; }
  $sql.=" ORDER BY u.nama ASC LIMIT $limit";
  $st=pdo()->prepare($sql); $st->execute($par); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['nama']=e($r['nama']); } unset($r);
  $etag='"'.md5(json_encode($rows).$eid.$q).'"'; header('ETag: '.$etag); header('Cache-Control: private, max-age=10, stale-while-revalidate=30');
  if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag){ http_response_code(304); exit; }
  jsonOut(['success'=>true,'data'=>$rows]);
}
if(routeMatch('/ekskul/:id/posts',$uri,$pm) && $method==='POST'){
  requireLogin(); socialRateLimit('post_create',10);
  $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $b=getBody();
  $tipe=trim($b['tipe']??'diskusi');
  if(!in_array($tipe,['diskusi','tanya','postingan'],true)) $tipe='diskusi';
  $judul=trim($b['judul']??'');
  $isi=trim($b['isi']??$b['content']??'');
  // mentions real: if mentions ids provided, validate members + build @ string for notif
  $mentionIds=$b['mention_ids']??$b['mentions']??[];
  if(is_array($mentionIds) && $mentionIds){
    $mentionIds=array_values(array_unique(array_map('intval',$mentionIds)));
    if(count($mentionIds)>10) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maks 10 mention']],422);
    // validate each is member of this ekskul
    $ph=implode(',',array_fill(0,count($mentionIds),'?'));
    $chk=pdo()->prepare("SELECT user_id FROM registrations WHERE ekskul_id=? AND user_id IN ($ph) AND deleted_at IS NULL AND status IN ('diterima','menunggu')");
    $chk->execute(array_merge([$eid], $mentionIds));
    $valid=array_column($chk->fetchAll(),'user_id');
    $mentionIds=array_values(array_intersect($mentionIds, array_map('intval',$valid)));
    if(!$mentionIds && !empty($b['mention_ids'])){ /* silently drop invalid */ }
  } else $mentionIds=[];
  $badWords=['anjing','bangsat','babi','tolol','goblok','kampret','kontol','memek','jancok','asu','bajingan','brengsek','kampang','ngentot','jembut'];
  $lowIsi=mb_strtolower($isi); $lowJudul=mb_strtolower($judul);
  foreach($badWords as $bw){ if(mb_strpos($lowIsi,$bw)!==false || mb_strpos($lowJudul,$bw)!==false) jsonOut(['success'=>false,'error'=>['code'=>'BAD_WORD','message'=>'Mengandung kata tidak pantas']],422); }
  if(mb_strlen($isi)<3) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Isi minimal 3 karakter']],422);
  if(mb_strlen($isi)>5000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Isi maksimal 5000']],422);
  if($tipe==='tanya' && $judul==='' && mb_strlen($isi)<10) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tanya minimal 10 karakter']],422);
  $uid=currentUser()['id'];
  pdo()->prepare("INSERT INTO ekskul_posts(ekskul_id,user_id,tipe,judul,isi) VALUES (?,?,?,?,?)")->execute([$eid,$uid,$tipe,$judul?:null,$isi]);
  $pid=pdo()->lastInsertId();
  // attach uploads if ids provided
  $uploadIds=$b['upload_ids']??$b['uploads']??[];
  if(is_array($uploadIds) && $uploadIds){
    $ph=implode(',',array_fill(0,count($uploadIds),'?'));
    // only attach own unattached uploads for this ekskul
    $st=pdo()->prepare("UPDATE ekskul_uploads SET post_id=? WHERE id IN ($ph) AND user_id=? AND ekskul_id=? AND post_id IS NULL");
    $st->execute(array_merge([$pid], $uploadIds, [$uid,$eid]));
  }
  // mentions -> notify (prefer mentionIds, fallback parse @name for compat)
  if(!empty($mentionIds)){
    foreach($mentionIds as $mid) createGroupedNotif((int)$mid,$uid,$eid,$pid,null,'mention','mention:post:'.$pid);
  } else {
    $mentions=parseMentions($isi.' '.$judul);
    foreach($mentions as $name){
      $st=pdo()->prepare("SELECT id FROM users WHERE nama LIKE ? AND deleted_at IS NULL LIMIT 1"); $st->execute(['%'.$name.'%']); $to=$st->fetch();
      if($to) createGroupedNotif((int)$to['id'],$uid,$eid,$pid,null,'mention','mention:post:'.$pid);
    }
  }
  $row=pdo()->prepare("SELECT p.*, u.nama author_nama FROM ekskul_posts p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=?"); $row->execute([$pid]); $r=$row->fetch();
  $r['isi']=e($r['isi']); $r['judul']=$r['judul']?e($r['judul']):null;
  jsonOut(['success'=>true,'data'=>$r],201);
}

// === POST DETAIL GET /ekskul/:id/posts/:pid ===
if(routeMatch('/ekskul/:id/posts/:pid',$uri,$pm) && $method==='GET'){
  requireLogin();
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $st=pdo()->prepare("SELECT p.*, u.nama author_nama, u.role author_role FROM ekskul_posts p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.ekskul_id=? AND p.deleted_at IS NULL");
  $st->execute([$pid,$eid]); $r=$st->fetch();
  if(!$r) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Post tidak ada']],404);
  $r['isi']=e($r['isi']); $r['judul']=$r['judul']?e($r['judul']):null; $r['author_nama']=e($r['author_nama']);
  $lc=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_likes WHERE target_type='post' AND target_id=?"); $lc->execute([$pid]); $r['likes']=(int)($lc->fetch()['c']??0);
  $cu=currentUser(); $lk=pdo()->prepare("SELECT 1 FROM ekskul_likes WHERE target_type='post' AND target_id=? AND user_id=?"); $lk->execute([$pid,$cu['id']]); $r['is_liked']=(bool)$lk->fetch();
  $up=pdo()->prepare("SELECT id,original_name,stored_path,mime,size FROM ekskul_uploads WHERE post_id=? AND deleted_at IS NULL"); $up->execute([$pid]); $r['uploads']=$up->fetchAll();
  foreach($r['uploads'] as &$u){ $u['url']='/api/uploads/'.$u['id']; } unset($u);
  // poll attached to this post (if any) — same shape as list
  try{
    $ps=pdo()->prepare("SELECT id, user_id, question, closed_at, created_at FROM ekskul_polls WHERE post_id=? LIMIT 1"); $ps->execute([$pid]); $poll=$ps->fetch();
    if($poll){
      $opts=pdo()->prepare("SELECT po.id, po.label, po.sort_order, (SELECT COUNT(*) FROM poll_votes pv WHERE pv.option_id=po.id) AS votes FROM poll_options po WHERE po.poll_id=? ORDER BY po.sort_order ASC, po.id ASC"); $opts->execute([$poll['id']]); $poll['options']=$opts->fetchAll();
      $ptotal=array_sum(array_column($poll['options'],'votes'));
      foreach($poll['options'] as &$o){ $o['votes']=(int)$o['votes']; $o['percent']=$ptotal? round($o['votes']/$ptotal*100,1):0; $o['label']=e($o['label']); } unset($o);
      $poll['total_votes']=$ptotal;
      $my=pdo()->prepare("SELECT option_id FROM poll_votes WHERE poll_id=? AND user_id=?"); $my->execute([$poll['id'],$cu['id']]); $m=$my->fetch(); $poll['my_vote']=$m? (int)$m['option_id']:null;
      $poll['question']=e($poll['question']); $poll['is_closed']=!empty($poll['closed_at']);
      $r['poll']=$poll;
    } else $r['poll']=null;
  }catch(Exception $e){ $r['poll']=null; }
  $cs=pdo()->prepare("SELECT c.*, u.nama author_nama, u.role author_role FROM ekskul_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.post_id=? AND c.deleted_at IS NULL ORDER BY c.created_at ASC");
  $cs->execute([$pid]); $comments=$cs->fetchAll();
  $cIds=array_values(array_unique(array_map('intval',array_column($comments,'id'))));
  $cLike=[]; $cUps=[];
  if($cIds){
    $cph=implode(',',array_fill(0,count($cIds),'?'));
    $cla=pdo()->prepare("SELECT target_id, COUNT(*) c, SUM(user_id=?) liked FROM ekskul_likes WHERE target_type='comment' AND target_id IN ($cph) GROUP BY target_id");
    $cla->execute(array_merge([$cu['id']],$cIds));
    foreach($cla->fetchAll() as $a){ $cLike[(int)$a['target_id']]=[(int)$a['c'],(int)$a['liked']>0]; }
    $cup=pdo()->prepare("SELECT id,comment_id,original_name,mime,size FROM ekskul_uploads WHERE comment_id IN ($cph) AND deleted_at IS NULL");
    $cup->execute($cIds);
    foreach($cup->fetchAll() as $u){ $u['url']='/api/uploads/'.$u['id']; $cp=(int)$u['comment_id']; unset($u['comment_id']); $cUps[$cp][]=$u; }
  }
  foreach($comments as &$c){ $cid=(int)$c['id']; $c['isi']=e($c['isi']); $c['author_nama']=e($c['author_nama']); $c['likes']=$cLike[$cid][0]??0; $c['is_liked']=$cLike[$cid][1]??false; $c['uploads']=$cUps[$cid]??[]; }
  unset($c);
  // build tree
  $byId=[]; foreach($comments as $c) $byId[$c['id']]=$c;
  $tree=[];
  foreach($comments as $c){
    if($c['parent_id'] && isset($byId[$c['parent_id']])){
      $byId[$c['parent_id']]['replies'][]=$c;
    } else {
      $tree[]=$c;
    }
  }
  // fix nested replies reference
  foreach($byId as $id=>$val){ if(isset($val['replies'])) $byId[$id]=$val; }
  // rebuild tree with replies from byId
  $final=[];
  foreach($tree as $t){
    $item=$byId[$t['id']]??$t;
    if(isset($byId[$t['id']]['replies'])) $item['replies']=$byId[$t['id']]['replies'];
    else $item['replies']=[];
    $final[]=$item;
  }
  $r['comments']=$final;
  $r['comments_count']=count($comments);
  jsonOut(['success'=>true,'data'=>$r]);
}

// === POST REPORT === (Opsi B: category + status + handled) ===
if(routeMatch('/ekskul/:id/posts/:pid/report',$uri,$pm) && $method==='POST'){
  requireLogin(); socialRateLimit('report',10);
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota ekskul ini']],403);
  $st=pdo()->prepare("SELECT id, user_id FROM ekskul_posts WHERE id=? AND ekskul_id=? AND deleted_at IS NULL"); $st->execute([$pid,$eid]); $postRow=$st->fetch(); if(!$postRow) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Post tidak ada']],404);
  $uidTmp=currentUser()['id']; if((int)$postRow['user_id']===(int)$uidTmp) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Tidak bisa melaporkan postingan sendiri']],403);
  $b=getBody();
  $allowedCat=['spam','kasar','hoax','sara','lainnya'];
  $category=trim(strtolower($b['category']??$b['kategori']??'lainnya'));
  if(!in_array($category,$allowedCat,true)) $category='lainnya';
  $reason=trim($b['reason']??$b['alasan']??'');
  if($reason==='') $reason=$category;
  if(mb_strlen($reason)>300) $reason=mb_substr($reason,0,300);
  if(mb_strlen($reason)<3) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Alasan minimal 3 karakter']],422);
  $uid=currentUser()['id'];
  try{ pdo()->exec("CREATE TABLE IF NOT EXISTS post_reports (id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, ekskul_id INT NOT NULL, reporter_id INT NOT NULL, category VARCHAR(20) NOT NULL DEFAULT 'lainnya', reason VARCHAR(300) NOT NULL, status ENUM('pending','dismissed','resolved') NOT NULL DEFAULT 'pending', handled_by INT NULL, handled_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_reports_post (post_id), INDEX idx_reports_ekskul (ekskul_id), INDEX idx_reports_status (status), UNIQUE KEY uq_report_once (post_id, reporter_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  // migrate existing table if needed
  try{
    $cols=pdo()->query("SHOW COLUMNS FROM post_reports")->fetchAll(PDO::FETCH_COLUMN);
    // PDO fetch column 0 is Field
    $fields=array_column(pdo()->query("SHOW COLUMNS FROM post_reports")->fetchAll(),'Field');
    if(!in_array('category',$fields,true)) pdo()->exec("ALTER TABLE post_reports ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'lainnya' AFTER reporter_id");
    if(!in_array('status',$fields,true)) pdo()->exec("ALTER TABLE post_reports ADD COLUMN status ENUM('pending','dismissed','resolved') NOT NULL DEFAULT 'pending' AFTER reason");
    if(!in_array('handled_by',$fields,true)) pdo()->exec("ALTER TABLE post_reports ADD COLUMN handled_by INT NULL AFTER status");
    if(!in_array('handled_at',$fields,true)) pdo()->exec("ALTER TABLE post_reports ADD COLUMN handled_at DATETIME NULL AFTER handled_by");
    // reason length
    pdo()->exec("ALTER TABLE post_reports MODIFY reason VARCHAR(300) NOT NULL");
  }catch(Exception $e){}
  $chk=pdo()->prepare("SELECT 1 FROM post_reports WHERE post_id=? AND reporter_id=?"); $chk->execute([$pid,$uid]); if($chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'EXISTS','message'=>'Sudah dilaporkan']],409);
  pdo()->prepare("INSERT INTO post_reports(post_id,ekskul_id,reporter_id,category,reason,status) VALUES (?,?,?,?,?,'pending')")->execute([$pid,$eid,$uid,$category,$reason]);
  $rid=pdo()->lastInsertId();
  try{ pdo()->prepare("INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)")->execute([$uid,'report','post',$pid, json_encode(['ekskul_id'=>$eid,'category'=>$category,'reason'=>$reason,'report_id'=>$rid],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
  // notify pembina
  try{
    $pemb=pdo()->prepare("SELECT pembina_id FROM ekskul WHERE id=?"); $pemb->execute([$eid]); $pr=$pemb->fetch();
    if($pr && (int)$pr['pembina_id']!==(int)$uid) createGroupedNotif((int)$pr['pembina_id'],$uid,$eid,$pid,null,'report','report:post:'.$pid);
  }catch(Exception $e){}
  jsonOut(['success'=>true,'data'=>['id'=>(int)$rid,'category'=>$category]],201);
}

// === REPORTS LIST GET /ekskul/:id/reports (pembina only) ===
if(routeMatch('/ekskul/:id/reports',$uri,$pm) && $method==='GET'){
  requireLogin();
  $eid=(int)$pm['id'];
  // only pembina/admin
  $cu=currentUser();
  if(!isPembinaOf($eid) && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pembina']],403);
  try{ pdo()->exec("CREATE TABLE IF NOT EXISTS post_reports (id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, ekskul_id INT NOT NULL, reporter_id INT NOT NULL, category VARCHAR(20) NOT NULL DEFAULT 'lainnya', reason VARCHAR(300) NOT NULL, status ENUM('pending','dismissed','resolved') NOT NULL DEFAULT 'pending', handled_by INT NULL, handled_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_reports_post (post_id), INDEX idx_reports_ekskul (ekskul_id), INDEX idx_reports_status (status), UNIQUE KEY uq_report_once (post_id, reporter_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $e){}
  $status=trim($_GET['status']??'pending');
  if(!in_array($status,['pending','dismissed','resolved','all'],true)) $status='pending';
  $where=['r.ekskul_id=?']; $par=[$eid];
  if($status!=='all'){ $where[]='r.status=?'; $par[]=$status; }
  $whereSql=implode(' AND ',$where);
  // pending first, grouped by post
  $q="SELECT r.id report_id, r.post_id, r.category, r.reason, r.status, r.created_at report_at, r.handled_by, r.handled_at, u.nama reporter_nama, u.role reporter_role, p.isi post_isi, p.judul post_judul, p.tipe post_tipe, p.user_id post_author_id, pu.nama post_author_nama, p.created_at post_created_at, p.deleted_at post_deleted_at FROM post_reports r JOIN ekskul_posts p ON p.id=r.post_id LEFT JOIN users u ON u.id=r.reporter_id LEFT JOIN users pu ON pu.id=p.user_id WHERE $whereSql ORDER BY r.created_at DESC LIMIT 100";
  $st=pdo()->prepare($q); $st->execute($par); $rows=$st->fetchAll();
  // aggregate count per post
  $counts=[];
  foreach($rows as $r){ $pid=$r['post_id']; if(!isset($counts[$pid])) $counts[$pid]=0; $counts[$pid]++; }
  foreach($rows as &$r){ $r['report_count']=$counts[$r['post_id']]??1; $r['reporter_nama']=e($r['reporter_nama']); $r['post_isi']=e($r['post_isi']); $r['post_judul']=$r['post_judul']?e($r['post_judul']):null; $r['post_author_nama']=e($r['post_author_nama']); }
  unset($r);
  // summary
  $pendingCt=pdo()->prepare("SELECT COUNT(*) c FROM post_reports WHERE ekskul_id=? AND status='pending'"); $pendingCt->execute([$eid]); $pending=(int)($pendingCt->fetch()['c']??0);
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['pending'=>$pending]]);
}

// === REPORT HANDLE POST /ekskul/:id/reports/:rid/handle ===
if(routeMatch('/ekskul/:id/reports/:rid/handle',$uri,$pm) && $method==='POST'){
  requireLogin();
  $eid=(int)$pm['id']; $rid=(int)$pm['rid'];
  $cu=currentUser();
  if(!isPembinaOf($eid) && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pembina']],403);
  $b=getBody(); $action=trim($b['action']??'');
  if(!in_array($action,['dismiss','dismiss_post','resolve_delete'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'action: dismiss | dismiss_post | resolve_delete']],422);
  $st=pdo()->prepare("SELECT * FROM post_reports WHERE id=? AND ekskul_id=?"); $st->execute([$rid,$eid]); $rep=$st->fetch();
  if(!$rep) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Laporan tidak ada']],404);
  $uid=$cu['id'];
  if($action==='dismiss'){
    pdo()->prepare("UPDATE post_reports SET status='dismissed', handled_by=?, handled_at=NOW() WHERE id=?")->execute([$uid,$rid]);
    try{ pdo()->prepare("INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)")->execute([$uid,'report_dismiss','post',$rep['post_id'], json_encode(['report_id'=>$rid,'ekskul_id'=>$eid],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
    jsonOut(['success'=>true,'data'=>['status'=>'dismissed']]);
  } elseif($action==='dismiss_post'){
    // dismiss all pending reports for this post
    pdo()->prepare("UPDATE post_reports SET status='dismissed', handled_by=?, handled_at=NOW() WHERE post_id=? AND ekskul_id=? AND status='pending'")->execute([$uid,$rep['post_id'],$eid]);
    try{ pdo()->prepare("INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)")->execute([$uid,'report_dismiss_post','post',$rep['post_id'], json_encode(['ekskul_id'=>$eid],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
    jsonOut(['success'=>true,'data'=>['status'=>'dismissed']]);
  } else { // resolve_delete: hapus post + mark resolved
    pdo()->prepare("UPDATE ekskul_posts SET deleted_at=NOW() WHERE id=? AND ekskul_id=?")->execute([$rep['post_id'],$eid]);
    pdo()->prepare("UPDATE post_reports SET status='resolved', handled_by=?, handled_at=NOW() WHERE post_id=? AND ekskul_id=? AND status='pending'")->execute([$uid,$rep['post_id'],$eid]);
    try{ pdo()->prepare("INSERT INTO audit_log(user_id,action,target_type,target_id,detail) VALUES (?,?,?,?,?)")->execute([$uid,'report_resolve_delete','post',$rep['post_id'], json_encode(['report_id'=>$rid,'ekskul_id'=>$eid],JSON_UNESCAPED_UNICODE)]); }catch(Exception $e){}
    jsonOut(['success'=>true,'data'=>['status'=>'resolved','post_deleted'=>true]]);
  }
}

// === POST DELETE ===
if(routeMatch('/ekskul/:id/posts/:pid',$uri,$pm) && $method==='DELETE'){
  requireLogin();
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  $st=pdo()->prepare("SELECT user_id FROM ekskul_posts WHERE id=? AND ekskul_id=? AND deleted_at IS NULL"); $st->execute([$pid,$eid]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Post tidak ada']],404);
  $cu=currentUser();
  $isOwner=(int)$row['user_id']===(int)$cu['id'];
  if(!$isOwner && !isPembinaOf($eid) && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pemilik/pembina']],403);
  pdo()->prepare("UPDATE ekskul_posts SET deleted_at=NOW() WHERE id=?")->execute([$pid]);
  jsonOut(['success'=>true,'data'=>null]);
}

// === POST LIKE TOGGLE ===
if(routeMatch('/ekskul/:id/posts/:pid/like',$uri,$pm) && $method==='POST'){
  requireLogin(); socialRateLimit('like',30);
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $st=pdo()->prepare("SELECT user_id FROM ekskul_posts WHERE id=? AND ekskul_id=? AND deleted_at IS NULL"); $st->execute([$pid,$eid]); $post=$st->fetch();
  if(!$post) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Post tidak ada']],404);
  $uid=currentUser()['id'];
  $chk=pdo()->prepare("SELECT id FROM ekskul_likes WHERE target_type='post' AND target_id=? AND user_id=?"); $chk->execute([$pid,$uid]); $ex=$chk->fetch();
  if($ex){
    pdo()->prepare("DELETE FROM ekskul_likes WHERE id=?")->execute([$ex['id']]);
    $liked=false;
  } else {
    pdo()->prepare("INSERT INTO ekskul_likes(target_type,target_id,user_id) VALUES ('post',?,?)")->execute([$pid,$uid]);
    $liked=true;
    createGroupedNotif((int)$post['user_id'],$uid,$eid,$pid,null,'like_post','like_post:post:'.$pid);
  }
  $cnt=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_likes WHERE target_type='post' AND target_id=?"); $cnt->execute([$pid]); $total=(int)($cnt->fetch()['c']??0);
  jsonOut(['success'=>true,'data'=>['liked'=>$liked,'likes'=>$total]]);
}

// === COMMENT CREATE POST /ekskul/:id/posts/:pid/comments ===
if(routeMatch('/ekskul/:id/posts/:pid/comments',$uri,$pm) && $method==='POST'){
  requireLogin(); socialRateLimit('comment',20);
  $eid=(int)$pm['id']; $pid=(int)$pm['pid'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $st=pdo()->prepare("SELECT id,user_id FROM ekskul_posts WHERE id=? AND ekskul_id=? AND deleted_at IS NULL"); $st->execute([$pid,$eid]); $post=$st->fetch();
  if(!$post) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Post tidak ada']],404);
  $b=getBody(); $isi=trim($b['isi']??''); $parentId=isset($b['parent_id']) ? (int)$b['parent_id'] : null;
  if(mb_strlen($isi)<1) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Komentar wajib']],422);
  if(mb_strlen($isi)>2000) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maks 2000']],422);
  // nested depth max 2: parent must be top-level (parent_id IS NULL)
  if($parentId){
    $pc=pdo()->prepare("SELECT id,parent_id,user_id FROM ekskul_comments WHERE id=? AND post_id=? AND deleted_at IS NULL"); $pc->execute([$parentId,$pid]); $par=$pc->fetch();
    if(!$par) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Parent tidak ada']],422);
    if($par['parent_id']!==null) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maksimal 2 level']],422);
  } else $parentId=null;
  $uid=currentUser()['id'];
  pdo()->prepare("INSERT INTO ekskul_comments(post_id,user_id,parent_id,isi) VALUES (?,?,?,?)")->execute([$pid,$uid,$parentId,$isi]);
  $cid=pdo()->lastInsertId();
  // attach uploads for comment if provided
  $uploadIds=$b['upload_ids']??[];
  if(is_array($uploadIds) && $uploadIds){
    $ph=implode(',',array_fill(0,count($uploadIds),'?'));
    $up=pdo()->prepare("UPDATE ekskul_uploads SET comment_id=? WHERE id IN ($ph) AND user_id=? AND ekskul_id=? AND comment_id IS NULL AND post_id IS NULL");
    $up->execute(array_merge([$cid], $uploadIds, [$uid,$eid]));
  }
  // notify post owner + parent owner
  if($parentId){
    $cc=pdo()->prepare("SELECT user_id FROM ekskul_comments WHERE id=?"); $cc->execute([$parentId]); $parOwner=$cc->fetch()['user_id']??null;
    if($parOwner) createGroupedNotif((int)$parOwner,$uid,$eid,$pid,$parentId,'reply','reply:comment:'.$parentId);
    // also notify post owner if different
    if((int)$post['user_id']!==(int)$parOwner) createGroupedNotif((int)$post['user_id'],$uid,$eid,$pid,$cid,'reply','reply:post:'.$pid);
  } else {
    createGroupedNotif((int)$post['user_id'],$uid,$eid,$pid,$cid,'reply','reply:post:'.$pid);
  }
  // mentions in comment (prefer mention_ids)
  $mentionIds=$b['mention_ids']??$b['mentions']??[];
  if(is_array($mentionIds) && $mentionIds){
    $mentionIds=array_values(array_unique(array_map('intval',$mentionIds)));
    if(count($mentionIds)>10) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Maks 10 mention']],422);
    $ph=implode(',',array_fill(0,count($mentionIds),'?'));
    $chk=pdo()->prepare("SELECT user_id FROM registrations WHERE ekskul_id=? AND user_id IN ($ph) AND deleted_at IS NULL AND status IN ('diterima','menunggu')");
    $chk->execute(array_merge([$eid], $mentionIds)); $valid=array_column($chk->fetchAll(),'user_id');
    $mentionIds=array_values(array_intersect($mentionIds, array_map('intval',$valid)));
    foreach($mentionIds as $mid) createGroupedNotif((int)$mid,$uid,$eid,$pid,$cid,'mention','mention:comment:'.$cid);
  } else {
    $mentions=parseMentions($isi);
    foreach($mentions as $name){
      $s=pdo()->prepare("SELECT id FROM users WHERE nama LIKE ? AND deleted_at IS NULL LIMIT 1"); $s->execute(['%'.$name.'%']); $to=$s->fetch();
      if($to) createGroupedNotif((int)$to['id'],$uid,$eid,$pid,$cid,'mention','mention:comment:'.$cid);
    }
  }
  $row=pdo()->prepare("SELECT c.*, u.nama author_nama, u.role author_role FROM ekskul_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.id=?"); $row->execute([$cid]); $r=$row->fetch();
  $r['isi']=e($r['isi']); $r['author_nama']=e($r['author_nama']);
  jsonOut(['success'=>true,'data'=>$r],201);
}

// === COMMENT DELETE ===
if(routeMatch('/comments/:id',$uri,$pm) && $method==='DELETE'){
  requireLogin();
  $cid=(int)$pm['id'];
  $st=pdo()->prepare("SELECT c.*, p.ekskul_id FROM ekskul_comments c JOIN ekskul_posts p ON p.id=c.post_id WHERE c.id=? AND c.deleted_at IS NULL"); $st->execute([$cid]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Komentar tidak ada']],404);
  $cu=currentUser();
  $isOwner=(int)$row['user_id']===(int)$cu['id'];
  if(!$isOwner && !isPembinaOf($row['ekskul_id']) && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pemilik/pembina']],403);
  pdo()->prepare("UPDATE ekskul_comments SET deleted_at=NOW() WHERE id=?")->execute([$cid]);
  jsonOut(['success'=>true,'data'=>null]);
}

// === COMMENT LIKE ===
if(routeMatch('/comments/:id/like',$uri,$pm) && $method==='POST'){
  requireLogin(); socialRateLimit('like_comment',30);
  $cid=(int)$pm['id'];
  $st=pdo()->prepare("SELECT c.user_id, p.ekskul_id FROM ekskul_comments c JOIN ekskul_posts p ON p.id=c.post_id WHERE c.id=? AND c.deleted_at IS NULL"); $st->execute([$cid]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Komentar tidak ada']],404);
  if(!canAccessEkskulSocial((int)$row['ekskul_id'])) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $uid=currentUser()['id'];
  $chk=pdo()->prepare("SELECT id FROM ekskul_likes WHERE target_type='comment' AND target_id=? AND user_id=?"); $chk->execute([$cid,$uid]); $ex=$chk->fetch();
  if($ex){ pdo()->prepare("DELETE FROM ekskul_likes WHERE id=?")->execute([$ex['id']]); $liked=false; }
  else { pdo()->prepare("INSERT INTO ekskul_likes(target_type,target_id,user_id) VALUES ('comment',?,?)")->execute([$cid,$uid]); $liked=true; createGroupedNotif((int)$row['user_id'],$uid,(int)$row['ekskul_id'],null,$cid,'like_comment','like_comment:comment:'.$cid); }
  $cnt=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_likes WHERE target_type='comment' AND target_id=?"); $cnt->execute([$cid]); $total=(int)($cnt->fetch()['c']??0);
  jsonOut(['success'=>true,'data'=>['liked'=>$liked,'likes'=>$total]]);
}

// === UPLOAD POST /ekskul/:id/upload ===
if(routeMatch('/ekskul/:id/upload',$uri,$pm) && $method==='POST'){
  requireLogin();
  $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  socialRateLimit('upload',20);
  // limits: max 200 files, 500MB total per ekskul
  $cnt=pdo()->prepare("SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM ekskul_uploads WHERE ekskul_id=? AND deleted_at IS NULL"); $cnt->execute([$eid]); $agg=$cnt->fetch();
  $fileCount=(int)($agg['c']??0); $totalSize=(int)($agg['s']??0);
  if($fileCount>=200) jsonOut(['success'=>false,'error'=>['code'=>'LIMIT','message'=>'Batas 200 file tercapai, hubungi admin untuk hapus']],413);
  if($totalSize>=500*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'LIMIT','message'=>'Batas 500MB tercapai, hubungi admin']],413);
  if(empty($_FILES['file']) && empty($_FILES['files'])) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File wajib']],422);
  $files=[];
  if(!empty($_FILES['file']['name'])) $files[]=$_FILES['file'];
  if(!empty($_FILES['files'])){
    $arr=$_FILES['files'];
    if(is_array($arr['name'])){
      for($i=0;$i<count($arr['name']);$i++) $files[]=['name'=>$arr['name'][$i],'type'=>$arr['type'][$i],'tmp_name'=>$arr['tmp_name'][$i],'error'=>$arr['error'][$i],'size'=>$arr['size'][$i]];
    } else $files[]=$arr;
  }
  // also handle file[] multiple
  if(empty($files) && !empty($_FILES['file']['name']) && is_array($_FILES['file']['name'])){
    $files=[];
    for($i=0;$i<count($_FILES['file']['name']);$i++) $files[]=['name'=>$_FILES['file']['name'][$i],'type'=>$_FILES['file']['type'][$i],'tmp_name'=>$_FILES['file']['tmp_name'][$i],'error'=>$_FILES['file']['error'][$i],'size'=>$_FILES['file']['size'][$i]];
  }
  $saved=[];
  $uid=currentUser()['id'];
  $baseDir=uploadPath('ekskul_'.$eid);
  $blockedExt=['php','phtml','phar','sh','exe','js','html','htm'];
  $allowedExt=['jpg','jpeg','png','webp','gif','pdf','txt','zip','doc','docx'];
  foreach($files as $f){
    if($f['error']!==0) continue;
    if($f['size']>5*1024*1024) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'File '.$f['name'].' >5MB']],422);
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    if(in_array($ext,$blockedExt,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Ekstensi .'.$ext.' tidak diperbolehkan']],422);
    if($ext && !in_array($ext,$allowedExt,true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Ekstensi .'.$ext.' tidak diizinkan']],422);
    $mime=mime_content_type($f['tmp_name']) ?: $f['type'];
    $allowedMime=['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain','application/zip','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/octet-stream'];
    if(strpos($mime,'image/')!==0 && !in_array($mime,$allowedMime,true)){
      // still allow but extension already validated
    }
    $safe=preg_replace('/[^A-Za-z0-9_\-]/','_', pathinfo($f['name'],PATHINFO_FILENAME));
    $stored=$baseDir.'/'.uniqid().'_'.$safe.'.'.$ext;
    if(!@move_uploaded_file($f['tmp_name'],$stored)){
      @copy($f['tmp_name'],$stored);
    }
    $rel='uploads/ekskul_'.$eid.'/'.basename($stored);
    pdo()->prepare("INSERT INTO ekskul_uploads(ekskul_id,user_id,original_name,stored_path,mime,size) VALUES (?,?,?,?,?,?)")->execute([$eid,$uid,$f['name'],$rel,$mime,$f['size']]);
    $id=pdo()->lastInsertId();
    $saved[]=['id'=>(int)$id,'original_name'=>$f['name'],'mime'=>$mime,'size'=>$f['size'],'url'=>'/api/uploads/'.$id];
  }
  if(!$saved) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Tidak ada file tersimpan']],422);
  jsonOut(['success'=>true,'data'=>$saved],201);
}

// === UPLOADS LIST GET /ekskul/:id/uploads ===
if(routeMatch('/ekskul/:id/uploads',$uri,$pm) && $method==='GET'){
  requireLogin();
  $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $st=pdo()->prepare("SELECT id,original_name,stored_path,mime,size,created_at,user_id FROM ekskul_uploads WHERE ekskul_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100");
  $st->execute([$eid]); $rows=$st->fetchAll();
  foreach($rows as &$r){ $r['url']='/api/uploads/'.$r['id']; } unset($r);
  jsonOut(['success'=>true,'data'=>$rows]);
}

// === SERVE UPLOAD GET /uploads/:id ===
if(routeMatch('/uploads/:id',$uri,$pm) && $method==='GET'){
  requireLogin();
  $id=(int)$pm['id'];
  $st=pdo()->prepare("SELECT * FROM ekskul_uploads WHERE id=? AND deleted_at IS NULL"); $st->execute([$id]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'File tidak ada']],404);
  if(!canAccessEkskulSocial((int)$row['ekskul_id'])) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota ekskul']],403);
  $path=uploadPath($row['stored_path']);
  if(!file_exists($path)) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'File hilang di disk']],404);
  $mime=$row['mime']?: mime_content_type($path) ?: 'application/octet-stream';
  header('Content-Type: '.$mime);
  // inline for image, attachment for others? use inline for image
  if(strpos($mime,'image/')===0) header('Content-Disposition: inline; filename="'.addslashes($row['original_name']).'"');
  else header('Content-Disposition: attachment; filename="'.addslashes($row['original_name']).'"');
  header('Content-Length: '.filesize($path));
  header('Cache-Control: private, max-age=600');
  readfile($path); exit;
}

// === NOTIFICATIONS ===
if($uri==='/notifications' && $method==='GET'){
  requireLogin();
  $uid=currentUser()['id'];
  $page=max(1,(int)($_GET['page']??1)); $limit=min(50,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $st=pdo()->prepare("SELECT * FROM ekskul_notifications WHERE recipient_id=? ORDER BY is_read ASC, updated_at DESC LIMIT $limit OFFSET $off");
  $st->execute([$uid]); $rows=$st->fetchAll();
  $ct=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_notifications WHERE recipient_id=? AND is_read=0"); $ct->execute([$uid]); $unread=(int)($ct->fetch()['c']??0);
  $ct2=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_notifications WHERE recipient_id=?"); $ct2->execute([$uid]); $total=(int)($ct2->fetch()['c']??0);
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'unread'=>$unread,'page'=>$page,'limit'=>$limit]]);
}
if(routeMatch('/notifications/:id/read',$uri,$pm) && $method==='POST'){
  requireLogin();
  $nid=(int)$pm['id']; $uid=currentUser()['id'];
  pdo()->prepare("UPDATE ekskul_notifications SET is_read=1 WHERE id=? AND recipient_id=?")->execute([$nid,$uid]);
  jsonOut(['success'=>true,'data'=>null]);
}
if($uri==='/notifications/read-all' && $method==='POST'){
  requireLogin(); $uid=currentUser()['id'];
  pdo()->prepare("UPDATE ekskul_notifications SET is_read=1 WHERE recipient_id=? AND is_read=0")->execute([$uid]);
  jsonOut(['success'=>true,'data'=>null]);
}

// === ADMIN FILE MANAGER ===
if($uri==='/admin/uploads' && $method==='GET'){
  requireRole('admin');
  $ekskulId=isset($_GET['ekskul_id'])? (int)$_GET['ekskul_id'] : 0;
  $minSize=(int)($_GET['min_size']??0);
  $maxSize=isset($_GET['max_size']) ? (int)$_GET['max_size'] : 0;
  $olderThan=(int)($_GET['older_than_days']??0);
  $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20))); $off=($page-1)*$limit;
  $where=['deleted_at IS NULL']; $par=[];
  if($ekskulId){ $where[]='ekskul_id=?'; $par[]=$ekskulId; }
  if($minSize){ $where[]='size>=?'; $par[]=$minSize; }
  if($maxSize){ $where[]='size<=?'; $par[]=$maxSize; }
  if($olderThan){ $where[]='created_at <= DATE_SUB(NOW(), INTERVAL '.((int)$olderThan).' DAY)'; }
  $whereSql=implode(' AND ',$where);
  $ct=pdo()->prepare("SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM ekskul_uploads WHERE $whereSql"); $ct->execute($par); $agg=$ct->fetch();
  $total=(int)($agg['c']??0); $totalSize=(int)($agg['s']??0);
  $q="SELECT id,ekskul_id,user_id,original_name,stored_path,mime,size,created_at FROM ekskul_uploads WHERE $whereSql ORDER BY created_at DESC LIMIT $limit OFFSET $off";
  $st=pdo()->prepare($q); $st->execute($par); $rows=$st->fetchAll();
  jsonOut(['success'=>true,'data'=>$rows,'meta'=>['total'=>$total,'total_size'=>$totalSize,'page'=>$page,'limit'=>$limit]]);
}
if($uri==='/admin/uploads/bulk-delete' && $method==='POST'){
  requireRole('admin');
  $b=getBody(); $ids=$b['ids']??[];
  if(!is_array($ids) || !$ids) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'ids wajib']],422);
  $ids=array_map('intval',$ids);
  $ph=implode(',',array_fill(0,count($ids),'?'));
  $st=pdo()->prepare("SELECT id,stored_path FROM ekskul_uploads WHERE id IN ($ph) AND deleted_at IS NULL"); $st->execute($ids); $rows=$st->fetchAll();
  foreach($rows as $r){
    $p=uploadPath($r['stored_path']);
    if(file_exists($p)) @unlink($p);
  }
  $st2=pdo()->prepare("UPDATE ekskul_uploads SET deleted_at=NOW() WHERE id IN ($ph)"); $st2->execute($ids);
  jsonOut(['success'=>true,'data'=>['deleted'=>count($rows)]]);
}

// handle DELETE /uploads/:id for owner/pembina
if(routeMatch('/uploads/:id',$uri,$pm) && $method==='DELETE'){
  requireLogin();
  $id=(int)$pm['id'];
  $st=pdo()->prepare("SELECT * FROM ekskul_uploads WHERE id=? AND deleted_at IS NULL"); $st->execute([$id]); $row=$st->fetch();
  if(!$row) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'File tidak ada']],404);
  $cu=currentUser();
  $isOwner=(int)$row['user_id']===(int)$cu['id'];
  if(!$isOwner && !isPembinaOf((int)$row['ekskul_id']) && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pemilik/pembina']],403);
  $p=uploadPath($row['stored_path']); if(file_exists($p)) @unlink($p);
  pdo()->prepare("UPDATE ekskul_uploads SET deleted_at=NOW() WHERE id=?")->execute([$id]);
  jsonOut(['success'=>true,'data'=>null]);
}

// === UNREAD COUNTS GET /ekskul/:id/unread-counts ===
if(routeMatch('/ekskul/:id/unread-counts',$uri,$pm) && $method==='GET'){
  requireLogin();
  $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $uid=(int)currentUser()['id'];
  $tabs=['diskusi','tanya','postingan'];
  $result=[];
  foreach($tabs as $t){
    $st=pdo()->prepare("SELECT last_read_at FROM ekskul_tab_reads WHERE user_id=? AND ekskul_id=? AND tipe=?");
    $st->execute([$uid,$eid,$t]);
    $row=$st->fetch();
    if($row){
      $ct=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_posts WHERE ekskul_id=? AND tipe=? AND deleted_at IS NULL AND created_at > ?");
      $ct->execute([$eid,$t,$row['last_read_at']]);
      $result[$t]=(int)($ct->fetch()['c']??0);
    } else {
      $ct=pdo()->prepare("SELECT COUNT(*) c FROM ekskul_posts WHERE ekskul_id=? AND tipe=? AND deleted_at IS NULL");
      $ct->execute([$eid,$t]);
      $result[$t]=(int)($ct->fetch()['c']??0);
    }
  }
  $cu=currentUser();
  $result['is_pembina']=(isPembinaOf($eid) && $cu['role']!=='admin');
  jsonOut(['success'=>true,'data'=>$result]);
}

// === MARK TAB READ POST /ekskul/:id/mark-read ===
if(routeMatch('/ekskul/:id/mark-read',$uri,$pm) && $method==='POST'){
  requireLogin();
  $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $b=getBody();
  $tipe=trim($b['tipe']??'');
  if(!in_array($tipe,['diskusi','tanya','postingan'],true)) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'tipe wajib']],422);
  $uid=(int)currentUser()['id'];
  $st=pdo()->prepare("INSERT INTO ekskul_tab_reads(user_id,ekskul_id,tipe,last_read_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE last_read_at=NOW()");
  $st->execute([$uid,$eid,$tipe]);
  jsonOut(['success'=>true,'data'=>null]);
}

if(routeMatch('/ekskul/:id/polls',$uri,$pm) && $method==='GET'){
  requireLogin(); $eid=(int)$pm['id'];
  if(!canAccessEkskulSocial($eid)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya anggota']],403);
  $rows=pdo()->prepare("SELECT ep.*, u.nama author_nama FROM ekskul_polls ep LEFT JOIN users u ON u.id=ep.user_id WHERE ep.ekskul_id=? ORDER BY ep.created_at DESC LIMIT 50"); $rows->execute([$eid]); $polls=$rows->fetchAll();
  $uidPoll=(int)currentUser()['id'];
  $pollIds=array_values(array_unique(array_map('intval',array_column($polls,'id'))));
  $optsByPoll=[]; $myVoteMap=[];
  if($pollIds){
    $ph=implode(',',array_fill(0,count($pollIds),'?'));
    $os=pdo()->prepare("SELECT po.id, po.poll_id, po.label, po.sort_order, COUNT(pv.option_id) AS votes FROM poll_options po LEFT JOIN poll_votes pv ON pv.option_id=po.id WHERE po.poll_id IN ($ph) GROUP BY po.id ORDER BY po.sort_order ASC, po.id ASC"); $os->execute($pollIds);
    foreach($os->fetchAll() as $o){ $optsByPoll[(int)$o['poll_id']][]=$o; }
    $my=pdo()->prepare("SELECT poll_id, option_id FROM poll_votes WHERE poll_id IN ($ph) AND user_id=?"); $my->execute(array_merge($pollIds,[$uidPoll]));
    foreach($my->fetchAll() as $m){ $myVoteMap[(int)$m['poll_id']]=(int)$m['option_id']; }
  }
  foreach($polls as &$pl){
    $opts=$optsByPoll[(int)$pl['id']]??[];
    foreach($opts as &$o){ unset($o['poll_id']); }
    unset($o);
    $pl['options']=array_values($opts);
    $total=array_sum(array_column($pl['options'],'votes'));
    foreach($pl['options'] as &$o){ $o['votes']=(int)$o['votes']; $o['percent']=$total? round($o['votes']/$total*100,1):0; } unset($o);
    $pl['total_votes']=$total;
    $pl['my_vote']=$myVoteMap[(int)$pl['id']]??null;
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
  $cuVote=currentUser(); if(in_array($cuVote['role'], ['pembina','kepsek','admin'], true)) jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya siswa yang boleh memberikan suara']],403);
  $b=getBody(); $optId=(int)($b['option_id']??$b['optionId']??0);
  if(!$optId) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'option_id wajib']],422);
  $chk=pdo()->prepare("SELECT 1 FROM poll_options WHERE id=? AND poll_id=?"); $chk->execute([$optId,$pollId]); if(!$chk->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'VALIDATION','message'=>'Opsi tidak valid untuk poll ini']],422);
  // one vote per user, LOCKED — no change/revoke (spec: vote tidak bisa dicabut)
  $cu2=currentUser();
  $dup=pdo()->prepare("SELECT 1 FROM poll_votes WHERE poll_id=? AND user_id=?"); $dup->execute([$pollId,$cu2['id']]); if($dup->fetch()) jsonOut(['success'=>false,'error'=>['code'=>'ALREADY_VOTED','message'=>'Sudah vote, tidak bisa diubah']],409);
  try{
    pdo()->prepare("INSERT INTO poll_votes(poll_id,option_id,user_id) VALUES (?,?,?)")->execute([$pollId,$optId,$cu2['id']]);
  } catch(Exception $e){
    // race: unique constraint hit → treat as already voted
    jsonOut(['success'=>false,'error'=>['code'=>'ALREADY_VOTED','message'=>'Sudah vote, tidak bisa diubah']],409);
  }
  // agregat fresh
  $opts=pdo()->prepare("SELECT po.id, po.label, (SELECT COUNT(*) FROM poll_votes pv WHERE pv.option_id=po.id) AS votes FROM poll_options po WHERE po.poll_id=? ORDER BY po.sort_order ASC"); $opts->execute([$pollId]); $rows=$opts->fetchAll();
  $total=array_sum(array_column($rows,'votes')); foreach($rows as &$r){ $r['votes']=(int)$r['votes']; $r['percent']=$total? round($r['votes']/$total*100,1):0; } unset($r);
  jsonOut(['success'=>true,'data'=>['options'=>$rows,'total'=>$total,'my_vote'=>$optId]]);
}
if(routeMatch('/polls/:id/close',$uri,$pm) && $method==='POST'){
  requireLogin(); $pollId=(int)$pm['id'];
  $st=pdo()->prepare("SELECT ekskul_id, user_id, closed_at FROM ekskul_polls WHERE id=?"); $st->execute([$pollId]); $poll=$st->fetch();
  if(!$poll) jsonOut(['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Poll tidak ada']],404);
  $cu=currentUser();
  if((int)$poll['user_id']!==(int)$cu['id'] && !isPembinaOf((int)$poll['ekskul_id']) && $cu['role']!=='admin') jsonOut(['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Hanya pemilik/pembina']],403);
  pdo()->prepare("UPDATE ekskul_polls SET closed_at=NOW() WHERE id=?")->execute([$pollId]);
  jsonOut(['success'=>true,'data'=>['id'=>$pollId]]);
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

