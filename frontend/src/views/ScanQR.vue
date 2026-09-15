<template>

<div class="kat-page">

<div class="kat-inner">



 <!-- head: flat tool header -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title">Scan QR Absen</h1>

 </div>

 </div>



 <!-- layout: scanner + side rail -->

 <div class="scan-layout">



 <!-- LEFT: scanner card -->

 <div class="kat-card scan-main">

 <div class="scan-head">

 <div class="scan-head-l">

 <span class="scan-title">Kamera</span>

 <span class="mono scan-sub">{{ camState }}</span>

 </div>

 <div class="scan-head-actions">

 <span class="scan-live" :class="scanning ? 'on' : ''"><span class="dot"></span>{{ scanning ? 'Live' : 'Idle' }}</span>

 <button v-if="scanning" @click="flipCam" class="icon-btn" :disabled="cameras.length<=1 && !scanning" title="Ganti kamera">{{ camLabel }}</button>

 <button v-if="torchSupported" @click="toggleTorch" class="icon-btn" :class="{active:torchOn}" title="Torch">{{ torchOn ? 'On' : 'Off' }}</button>

 </div>

 </div>



 <!-- reader viewport -->

 <div class="scan-viewport">

 <div id="reader" class="scan-reader"></div>

 <div v-if="!scanning && !camError" class="scan-placeholder mono">

 <span class="ph-icon">-</span>

 <span class="ph-text">Menunggu kamera...</span>

 <span class="ph-hint">Izinkan kamera / pakai manual</span>

 </div>

 <div v-if="camError" class="scan-error mono">

 <span class="text-[12px] font-semibold text-red-700">Kamera tidak tersedia</span>

 <span class="text-[11px] leading-snug" style="color:#78716c">{{ camError }}</span>

 <span class="text-[11px]" style="color:#78716c">Gunakan tempel token manual -></span>

 </div>

 <!-- cooldown overlay -->

 <div v-if="cooldown>0" class="cooldown-badge mono">{{ cooldown }}s - tahan...</div>

 </div>



 <div class="scan-foot mono">

 <label class="scan-upload">

 <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="handleFile" />

 <span class="scan-foot-btn"> - Upload galeri</span>

 </label>

 <button v-if="camError" @click="retryCam" class="scan-retry">Coba lagi</button>

 </div>



 <!-- mobile manual (visible only on small) -->

 <div class="lg:hidden scan-manual-mobile">

 <div class="mono scan-label">TOKEN MANUAL</div>

 <div class="scan-input-row">

 <div class="scan-input-wrap">

 <input ref="mobileTokenInput" v-model="token" @keydown.enter="scan" placeholder="Tempel token QR di sini..." class="scan-input" aria-label="Token QR" />

 <button v-if="token" @click="token=''" class="scan-clear" aria-label="Hapus token">x</button>

 </div>

 <button class="scan-cta" :disabled="!token.trim() || scanningSend || cooldown" @click="scan">

 <span v-if="scanningSend" class="scan-spin"></span>

 <span>{{ cooldown>0 ? cooldown+'s' : scanningSend ? 'Mengirim...' : 'Kirim' }}</span>

 </button>

 </div>

 </div>

 </div>



 <!-- RIGHT: rail -->

 <div class="scan-rail">



 <!-- manual card (desktop) -->

 <div class="kat-card rail-card hidden lg:block">

 <div class="rail-head">

 <span class="rail-title">Tempel token</span>

 <span class="mono rail-badge">Manual</span>

 </div>

 <div class="scan-input-row mt-3">

 <div class="scan-input-wrap flex-1">

 <input ref="desktopTokenInput" v-model="token" @keydown.enter="scan" placeholder="Tempel token QR (atau isi scan)" class="scan-input" aria-label="Token QR desktop" />

 <button v-if="token" @click="token=''" class="scan-clear" aria-label="Hapus token">x</button>

 </div>

 </div>

 <button class="scan-cta w-full mt-2 justify-center" :disabled="!token.trim() || scanningSend || cooldown" @click="scan">

 <span v-if="scanningSend" class="scan-spin"></span>

 <span>{{ cooldown>0 ? 'Jeda '+cooldown+'s...' : scanningSend ? 'Mengirim...' : 'Scan / Kirim' }}</span>

 </button>



 <!-- feedback inline (desktop) -->

 <div v-if="msg" class="kat-alert mt-3" :class="ok?'ok':'err'" role="status">

 <span class="alert-dot" :class="ok?'ok':'err'"></span>

 <span class="flex-1 min-w-0 break-words">{{ msg }}</span>

 <button @click="msg=''" class="alert-close">x</button>

 </div>

 <!-- duplicate banner -->

 <div v-if="isDuplicate" class="dup-banner mono">

 <span class="dup-icon">!</span>

 <div>

 <div class="font-semibold text-[12px]">Sudah hadir / token terpakai</div>

 <div class="text-[11px]" style="color:#92400e">Minta QR baru dari pembina.</div>

 </div>

 </div>

 </div>



 <!-- mobile feedback (also shows on mobile when desktop card hidden) -->

 <div v-if="msg" class="kat-alert lg:hidden" :class="ok?'ok':'err'" role="status">

 <span class="alert-dot" :class="ok?'ok':'err'"></span>

 <span class="flex-1 min-w-0 break-words">{{ msg }}</span>

 <button @click="msg=''" class="alert-close">x</button>

 </div>

 <div v-if="isDuplicate" class="dup-banner mono lg:hidden">

 <span class="dup-icon">!</span>

 <div>

 <div class="font-semibold text-[12px]">Sudah hadir / token terpakai</div>

 <div class="text-[11px]" style="color:#92400e">QR ini sudah dipakai - minta QR baru.</div>

 </div>

 </div>



 <!-- status card -->

 <div class="kat-card rail-card">

 <div class="rail-head">

 <span class="rail-title">Status terakhir</span>

 <span v-if="msg" class="mono rail-pill" :class="ok?'pill-ok':'pill-err'">{{ ok ? 'Berhasil' : 'Gagal' }}</span>

 <span v-else class="mono rail-pill idle">Menunggu</span>

 </div>

 <div v-if="msg" class="rail-status" :class="ok?'ok':'err'">

 <div class="rail-status-icon" :class="ok?'ok':'err'">{{ ok ? 'v' : '!' }}</div>

 <div class="min-w-0">

 <div class="text-[13px] font-semibold leading-tight" :class="ok?'text-emerald-700':'text-red-700'">{{ ok ? 'Hadir tercatat!' : 'Gagal absen' }}</div>

 <div class="text-[12px] leading-snug break-words" style="color:#57534e">{{ msg }}</div>

 <div v-if="ok" class="mono text-[11px] mt-1" style="color:#78716c">Terima kasih - kehadiranmu sudah masuk rekap.</div>

 </div>

 </div>

 <div v-else class="rail-empty mono">

 <span class="empty-icon">-</span>

 <span>Belum ada scan.</span>

 </div>

 <div class="rail-actions">

 <button @click="retryCam" class="rail-link">Coba Lagi</button>

 <button @click="focusManual" class="rail-link">Manual </button>

 <router-link to="/saya" class="rail-link muted">Lihat Saya -></router-link>

 </div>

 <div class="rail-actions" style="margin-top:8px">

 <button @click="token=''; msg=''; isDuplicate=false" class="rail-link muted">Reset</button>

 <button @click="clearHistory" class="rail-link muted">Hapus riwayat</button>

 </div>

 </div>



 <!-- history today (Opsi B) -->

 <div class="kat-card rail-card">

 <div class="rail-head">

 <span class="rail-title">Riwayat hari ini</span>

 <span class="mono text-[11px]" style="color:#a8a29e">{{ history.length }} scan</span>

 </div>

 <div v-if="history.length===0" class="mono text-[12px] leading-snug" style="color:#78716c">Belum ada.</div>

 <ul v-else class="history-list mono">

 <li v-for="(h,i) in history" :key="i" class="history-item" :class="h.ok?'ok':'err'">

 <span class="history-time">{{ h.time }}</span>

 <span class="history-msg">{{ h.msg }}</span>

 <span class="history-badge" :class="h.ok?'ok':'err'">{{ clsHt(h) }}</span>

 </li>

 </ul>

 </div>







 </div>

 </div>



</div>

</div>

</template>

<script setup>

import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

import { api } from '../lib/api.js'

const token=ref(''), msg=ref(''), ok=ref(false)

const isDuplicate=ref(false)

const scanning=ref(false)

const scanningSend=ref(false)

const camState=ref('Mengaktifkan kamera...')

const camError=ref('')

const cameras=ref([])

const camIdx=ref(0)

const torchOn=ref(false)

const torchSupported=ref(false)

const cooldown=ref(0)

const history=ref([])

const fileInput=ref(null)

const desktopTokenInput=ref(null)

const mobileTokenInput=ref(null)

let qr=null

let unmounted=false

let cooldownTimer=null

let lastToken=''

let lastTokenTime=0



const camLabel = computed(()=>{

 if(cameras.value.length<=1) return ' Flip'

 const cur=cameras.value[camIdx.value]; const label=String(cur?.label||'').toLowerCase()

 return label.includes('front') ? ' Depan' : label.includes('back') ? ' - Belakang' : ' Flip'

})



function loadHistory(){

 try{

 const raw=localStorage.getItem('mindora_scan_history')

 if(raw) history.value=JSON.parse(raw)

 // keep only today

 const today="new Date().toISOString().slice(0,10)"

 history.value=history.value.filter(h=>h.date===today)

 }catch{ history.value=[] }

}

function saveHistory(){

 try{ localStorage.setItem('mindora_scan_history', JSON.stringify(history.value.slice(0,20))) }catch{}

}

function addHistory(entry){

 const d=new Date()

 const t=d.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})

 const today=d.toISOString().slice(0,10)

 history.value.unshift({ time:t, date:today, ...entry })

 if(history.value.length>10) history.value=history.value.slice(0,10)

 saveHistory()

}

function clearHistory(){ history.value=[]; saveHistory() }

function beep(success){

 try{

 const ctx=new (window.AudioContext||window.webkitAudioContext)()

 const o=ctx.createOscillator(), g=ctx.createGain()

 o.type='sine'; o.frequency.value= success? 880 : 280

 g.gain.value=0.12

 o.connect(g); g.connect(ctx.destination); o.start()

 setTimeout(()=>{ o.stop(); ctx.close() }, success? 120: 220)

 }catch{}

 try{ navigator.vibrate && navigator.vibrate(success? 80 : [60,40,60]) }catch{}

}

function startCooldown(sec=5){

 cooldown.value=sec

 if(cooldownTimer) clearInterval(cooldownTimer)

 cooldownTimer=setInterval(()=>{

 cooldown.value--

 if(cooldown.value<=0){ clearInterval(cooldownTimer); cooldownTimer=null }
 },1000)
}

function focusManual(){

 // scroll to manual and focus

 if(window.innerWidth<1024) mobileTokenInput.value?.focus()

 else desktopTokenInput.value?.focus()

 document.querySelector('.scan-rail')?.scrollIntoView({behavior:'smooth'})

}



async function scan(){

 const t = token.value.trim()

 if(!t){

 ok.value=false

 msg.value='Token kosong - tempel isi QR dulu.'

 isDuplicate.value=false
 beep(false)
 return }

  // cooldown guard

  if(cooldown.value>0){

  msg.value='Tahan dulu '+cooldown.value+'s - anti double scan.'

  return

  }

  // in-flight guard: jaringan buruk → cegah request tumpuk (BE tetap idempoten via UNIQUE+ALREADY)

  if(scanningSend.value){

  msg.value='Masih memproses scan sebelumnya — tunggu sebentar, jangan scan ulang.'

  return

  }

 // duplicate token guard in session (anti double tap)

 if(t===lastToken && Date.now()-lastTokenTime < 5000){

 msg.value='Token sama baru saja dikirim - tunggu 5s.'

 isDuplicate.value=true
 return }

 lastToken=t
 lastTokenTime=Date.now()

 msg.value=''

 isDuplicate.value=false

  scanningSend.value=true
  const isEventQR = /^EVENT:\d+:[a-fA-F0-9]{16,}$/.test(t) || /^[a-f0-9]{32,}$/.test(t)
  // event QR contains 'EVENT:' prefix  -  fallback 32hex ambiguous with ekskul but backend will route: EVENT scan endpoint. Try EVENT first when looks like EVENT:*; otherwise try EVENT then ekskul.
  async function tryEventScan(){
    try{ const j=await api('/event-attendance/scan',{method:'POST',body:{token:t}}); ok.value=true; const sid=j?.data?.session_id||j?.data?.session?.id||''; msg.value=sid?`Hadir event tercatat! Sesi #${sid}`:'Hadir event tercatat!'; token.value=''; beep(true); addHistory({ok:true,msg:msg.value}); startCooldown(5); return true }catch(e){
      const code=e.error?.code||e.code||''; const raw=e.error?.message||e.message||''
      if(code==='ALREADY' || /sudah melakukan absensi/i.test(raw)){ ok.value=false; msg.value='Anda sudah melakukan absensi'; isDuplicate.value=true; beep(false); addHistory({ok:false,msg:msg.value}); return true }
      if(code==='STOPPED'){ ok.value=false; msg.value='QR sudah dihentikan admin  -  tidak bisa scan lagi.'; beep(false); addHistory({ok:false,msg:msg.value}); return true }
      if(code==='EXPIRED'){ ok.value=false; msg.value='QR expired  -  minta QR baru dari admin.'; beep(false); addHistory({ok:false,msg:msg.value}); return true }
      if(code==='NOT_REGISTERED'){ ok.value=false; msg.value='Belum terdaftar di event ini  -  daftar dulu ya.'; beep(false); addHistory({ok:false,msg:msg.value}); return true }
      if(code==='RATE_LIMIT'){ ok.value=false; msg.value='Terlalu sering  -  tunggu 1 menit.'; beep(false); addHistory({ok:false,msg:msg.value}); return true }
      if(code==='TIMEOUT'){ ok.value=false; msg.value='Jaringan lambat — request dibatalkan. Cek riwayat/status sebelum scan ulang (absen mungkin sudah tercatat).'; beep(false); addHistory({ok:false,msg:msg.value}); return true }
      // NOT_FOUND or other: if token looks like EVENT, surface; else fall through to ekskul scan
      if(/^EVENT:/.test(t) || code==='NOT_FOUND'){ ok.value=false; msg.value=raw||'QR event tidak ditemukan'; beep(false); addHistory({ok:false,msg:msg.value}); if(!isDuplicate.value) startCooldown(2); return true }
      return false
    }
  }
  // If token is EVENT:*, try event first. Else try event opportunistically then fall back.
  if(/^EVENT:/.test(t)){
    const handled=await tryEventScan(); if(handled) return
  } else {
    const handled=await tryEventScan(); if(handled) return
  }
  try{ const j=await api('/attendance/scan',{method:'POST',body:{token:t}})

 ok.value=true
 const id = j?.data?.ekskul_id ?? j?.data?.ekskulId ?? j?.ekskul_id ?? ''

 msg.value= id ? `Hadir tercatat! Ekskul #${id}` : 'Hadir tercatat!'

 token.value=''

 beep(true)

 addHistory({ ok:true, msg: msg.value })

 startCooldown(5)

 }catch(e){

 ok.value=false
 const code=e.error?.code || e.code || ''

 const raw=e.error?.message || e.message || 'Gagal'

   if(code==='ALREADY' || /sudah melakukan absensi/i.test(raw)){

  msg.value='Anda sudah melakukan absensi — tidak perlu scan lagi.'

  isDuplicate.value=true } else if(code==='TIMEOUT' || /jaringan lambat/i.test(raw)){

  msg.value='Jaringan lambat — request dibatalkan. Cek riwayat/status sebelum scan ulang (absen mungkin sudah tercatat).'

  } else if(code==='USED' || /sudah dipakai|already used/i.test(raw)){

  msg.value='Token sudah dipakai - minta QR baru dari pembina.'

 isDuplicate.value=true } else if(code==='EXPIRED' || /expired/i.test(raw)){

 msg.value='QR expired 5 menit - minta QR baru.'

  } else if(code==='STOPPED'){

  msg.value='QR sudah dihentikan admin - tidak bisa scan lagi.'

  } else if(code==='NOT_REGISTERED'){

  msg.value='Belum terdaftar di event ini - daftar dulu ya.'

  } else if(code==='RATE_LIMIT'){

  msg.value='Terlalu sering - tunggu 1 menit.'

 } else {

 msg.value=raw


 }
 beep(false)
 addHistory({ ok:false, msg: msg.value })

 // if not duplicate, allow retry but still cooldown 2s to avoid spam

 if(!isDuplicate.value) startCooldown(2)

 } finally {

 scanningSend.value=false
 }
 }

 async function initCam(deviceId=null){

 camError.value=''

 camState.value='Mengaktifkan kamera...'

 scanning.value=false

 torchSupported.value=false; torchOn.value=false
 try{ const { Html5Qrcode } = await import('html5-qrcode')

 if(unmounted) return

 // enumerate cameras for flip

 try{

 const cams=await Html5Qrcode.getCameras()
 if(cams && cams.length){
 cameras.value=cams
 if(deviceId){ const idx=cams.findIndex(c=>c.id===deviceId); if(idx>=0) camIdx.value=idx } }

 }catch{}

 qr=new Html5Qrcode('reader')

 camState.value='Meminta izin kamera...'

 const config={fps:10,qrbox:{width:250,height:250}}

 const camConfig = deviceId ? { deviceId:{ exact: deviceId } } : { facingMode:'environment' }

  await qr.start(camConfig, config,(decoded)=>{

  if(cooldown.value>0 || scanningSend.value) return

 token.value=decoded

 scan()

 }, ()=>{})

 if(unmounted) return

 scanning.value=true

 camState.value='Kamera aktif - arahkan ke QR'

 // torch check

 try{

 const track = qr.getRunningTrackCapabilities && qr.getRunningTrackCapabilities()

 if(track && 'torch' in track) torchSupported.value=true

 else {

 // fallback via video track

 const videoEl=document.querySelector('#reader video')

 const s=videoEl && videoEl.srcObject

 const tr=s && s.getVideoTracks && s.getVideoTracks()[0]

 if(tr && tr.getCapabilities){

 const cap=tr.getCapabilities()

 if('torch' in cap) torchSupported.value=true

 }

 }

 }catch{}

 }catch(e){

 const m = e?.message || String(e) || 'Izin kamera ditolak atau tidak ada kamera.'

 if(/permission/i.test(m)) camError.value='Izin kamera ditolak. Aktifkan di pengaturan browser.'

 else if(/not found|no camera/i.test(m)) camError.value='Tidak ada kamera terdeteksi.'

 else if(/https/i.test(m)) camError.value='Kamera butuh HTTPS / localhost.'

 else camError.value=m.slice(0,140)

 camState.value='Kamera nonaktif'

 scanning.value=false

 }

}



async function flipCam(){

 if(!cameras.value.length){

 // try re-enumerate then flip facingMode fallback: restart with opposite

 try{ const { Html5Qrcode } = await import('html5-qrcode'); const cs=await Html5Qrcode.getCameras(); if(cs?.length) cameras.value=cs }catch{}

 if(!cameras.value.length) return

 }

 const nextIdx=(camIdx.value+1)%cameras.value.length

 camIdx.value=nextIdx

 const nextId=cameras.value[nextIdx]?.id

 if(qr){

 try{ await qr.stop(); qr.clear() }catch{}

 qr=null

 }

 scanning.value=false

 await initCam(nextId)

}



async function toggleTorch(){

 try{

 const videoEl=document.querySelector('#reader video')

 const stream=videoEl?.srcObject

 const track=stream?.getVideoTracks?.()[0]

 if(!track) return

 const next=!torchOn.value

 await track.applyConstraints({ advanced:[{ torch: next }] })

 torchOn.value=next

 }catch{

 // fallback via Html5Qrcode applyVideoConstraints if exists

 try{

 if(qr && qr.applyVideoConstraints) await qr.applyVideoConstraints({ advanced:[{ torch: !torchOn.value }] })

 torchOn.value=!torchOn.value

 }catch{}

 }

}



async function handleFile(e){

 const f=e.target?.files?.[0]

 if(!f) return

 camState.value='Membaca gambar...'

 try{

 const { Html5Qrcode } = await import('html5-qrcode')

 // use temporary instance to scan file

 const tmp=new Html5Qrcode('reader')

 // Html5Qrcode.scanFile requires file and showImage flag

 const decoded=await tmp.scanFile(f, false)

 token.value=decoded

 msg.value='QR dari galeri terbaca - mengirim...'

 await scan()

 try{ tmp.clear() }catch{}

 }catch(err){

 msg.value='Gagal baca QR dari gambar - coba foto lebih jelas.'

 ok.value=false

 beep(false)

 addHistory({ ok:false, msg: 'Galeri gagal: '+ (err?.message||'tidak terbaca') })

 } finally {

 camState.value = scanning.value ? 'Kamera aktif - arahkan ke QR' : 'Kamera nonaktif'

 if(fileInput.value) fileInput.value.value=''

 }

}



function retryCam(){

 if(qr){

 try{ qr.stop(); qr.clear() }catch{}

 qr=null

 }

 initCam(cameras.value[camIdx.value]?.id || null)

}



onMounted(()=>{ loadHistory(); initCam() })

onBeforeUnmount(()=>{

 unmounted=true

 if(cooldownTimer) clearInterval(cooldownTimer)

 if(qr){

 try{ qr.stop(); qr.clear() }catch{}

 }

})

</script>

<style scoped>

/* Mindora tokens - must match /ekskul /events /kalender */

.kat-page{

 --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3;

 --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85;

 background:var(--m-bg);color:var(--m-ink);

 margin:-24px calc(50% - 50vw) 0;padding:20px max(16px,calc(50vw - 680px)) 24px;

}

.kat-inner{max-width:1360px;margin:0 auto}

.mono{font-family:'Satoshi',system-ui,sans-serif}

.kat-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:2px 0 10px;flex-wrap:wrap}

.kat-title{margin:0;font-family:'Satoshi',system-ui,sans-serif;font-size:20px;font-weight:800;letter-spacing:-.01em}

.kat-sub{margin:2px 0 0;font-family:'Satoshi',system-ui,sans-serif;font-size:11.5px;color:var(--m-muted)}

.kat-head-r{display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}

.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px}

.kat-link:hover{border-color:#d4d4d8}

.kat-strip{margin:0 0 10px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}

.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}



/* layout */

.scan-layout{display:grid;grid-template-columns:1.45fr .85fr;gap:16px;margin-top:12px}

@media(max-width:900px){ .scan-layout{grid-template-columns:1fr} }



/* left scanner */

.scan-main{display:flex;flex-direction:column}

.scan-head{padding:14px 16px;border-bottom:1px solid var(--m-line);display:flex;justify-content:space-between;align-items:center;background:#fff;gap:12px}

.scan-title{font-size:13px;font-weight:700;letter-spacing:-.01em}

.scan-sub{font-size:11px;color:var(--m-muted);display:block;margin-top:1px}

.scan-head-l{min-width:0}

.scan-head-actions{display:flex;align-items:center;gap:8px}

.scan-live{font-size:11px;font-weight:700;letter-spacing:.06em;padding:5px 10px;border-radius:999px;border:1px solid var(--m-line);background:#fff;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}

.scan-live.on{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}

.scan-live .dot{width:7px;height:7px;border-radius:50%;background:#d4d4d8;display:inline-block}

.scan-live.on .dot{background:#10b981;box-shadow:0 0 0 4px rgba(16,185,129,.18)}

.icon-btn{padding:6px 10px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:11px;font-weight:700;cursor:pointer}

.icon-btn:hover{background:var(--m-bg)}

.icon-btn.active{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.icon-btn:disabled{opacity:.4;cursor:not-allowed}



.scan-viewport{position:relative;background:#F8FAFA;min-height:340px;display:grid;place-items:center;padding:12px}

.scan-reader{width:100%;max-width:420px;min-height:300px;background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden;position:relative}

.scan-reader video{width:100% !important;height:auto !important;border-radius:16px}

.scan-placeholder{position:absolute;inset:12px;display:grid;place-items:center;text-align:center;pointer-events:none}

.ph-icon{width:44px;height:44px;display:grid;place-items:center;border-radius:12px;background:#fff;border:1px solid var(--m-line);font-size:16px;margin:0 auto 8px}

.ph-text{font-size:13px;font-weight:600;color:var(--m-ink)}

.ph-hint{font-size:11px;color:var(--m-muted);max-width:260px;margin-top:4px;line-height:1.4}

.scan-error{position:absolute;inset:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;background:#fffbeb;border:1px dashed #fde68a;border-radius:16px;padding:16px;max-width:420px;margin:0 auto}

.cooldown-badge{position:absolute;bottom:16px;left:50%;transform:translateX(-50%);background:var(--m-ink);color:#fff;padding:6px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.02em}

.scan-foot{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-top:1px solid var(--m-line);font-size:11px;color:var(--m-muted);background:#fff;gap:12px;flex-wrap:wrap}

.scan-foot-r{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.scan-foot-btn{padding:6px 12px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center}

.scan-upload{cursor:pointer}

.scan-upload .hidden{display:none}

.scan-retry{padding:6px 12px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12px;font-weight:600;cursor:pointer}

.scan-retry:hover{background:var(--m-bg)}

.scan-hint{font-size:11px;color:var(--m-muted)}

.scan-manual-mobile{padding:14px 16px;border-top:1px solid var(--m-line);background:#fff}



/* shared input */

.scan-label{font-size:10px;letter-spacing:.08em;font-weight:700;color:var(--m-muted);margin-bottom:8px}

.scan-input-row{display:flex;gap:8px;align-items:center}

.scan-input-wrap{position:relative;flex:1;display:flex;align-items:center}

.scan-input{width:100%;height:44px;padding:0 36px 0 14px;border:1px solid var(--m-line);background:#fff;border-radius:12px;font-size:13px;outline:none;color:var(--m-ink)}

.scan-input:focus{border-color:var(--m-cta);box-shadow:0 0 0 2px rgba(74,120,117,.15)}

.scan-input::placeholder{color:#a8a29e}

.scan-clear{position:absolute;right:8px;width:24px;height:24px;border-radius:999px;border:1px solid var(--m-line);background:#fff;display:grid;place-items:center;cursor:pointer;color:var(--m-muted);font-size:12px;line-height:1}

.scan-clear:hover{background:var(--m-bg)}

.scan-cta{height:44px;padding:0 18px;border-radius:999px;border:1px solid var(--m-cta);background:var(--m-cta);color:#fff;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;flex-shrink:0}

.scan-cta:hover{background:var(--m-cta-h);border-color:var(--m-cta-h)}

.scan-cta:disabled{opacity:.45;cursor:not-allowed}

.scan-spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:inline-block}

@keyframes spin{to{transform:rotate(360deg)}}

.scan-help{margin:8px 0 0;font-size:11px;color:var(--m-muted);line-height:1.4}



/* alerts */

.kat-alert{padding:10px 12px;border-radius:12px;font-size:13px;display:flex;align-items:center;gap:10px;border:1px solid}

.kat-alert.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.kat-alert.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}

.alert-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

.alert-dot.ok{background:#10b981}

.alert-dot.err{background:#ef4444}

.alert-close{margin-left:auto;width:24px;height:24px;border-radius:999px;border:1px solid var(--m-line);background:#fff;display:grid;place-items:center;cursor:pointer;font-size:11px;flex-shrink:0}

.rail-hint{margin-top:8px;font-size:11px;color:var(--m-muted)}

.dup-banner{margin-top:8px;display:flex;gap:10px;padding:10px 12px;border-radius:12px;background:#fffbeb;border:1px solid #fde68a;align-items:flex-start}

.dup-icon{width:28px;height:28px;border-radius:8px;background:#fff;display:grid;place-items:center;border:1px solid #fde68a;flex-shrink:0}



/* right rail */

.scan-rail{display:flex;flex-direction:column;gap:12px}

.rail-card{padding:16px}

.rail-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}

.rail-title{font-size:13px;font-weight:700;letter-spacing:-.01em}

.rail-badge{font-size:10px;letter-spacing:.08em;font-weight:700;padding:3px 8px;border-radius:999px;background:var(--m-bg);border:1px solid var(--m-line);color:var(--m-muted)}

.rail-desc{font-size:12.5px;line-height:1.5;color:var(--m-muted);margin:0}

.rail-desc b{color:var(--m-ink)}

.rail-status{display:flex;gap:12px;padding:12px;border-radius:12px;border:1px solid;align-items:flex-start}

.rail-status.ok{background:#ecfdf5;border-color:#a7f3d0}

.rail-status.err{background:#fef2f2;border-color:#fecaca}

.rail-status-icon{width:32px;height:32px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:14px;flex-shrink:0}

.rail-status-icon.ok{background:#fff;color:#065f46;border:1px solid #a7f3d0}

.rail-status-icon.err{background:#fff;color:#991b1b;border:1px solid #fecaca}

.rail-empty{display:flex;gap:10px;align-items:center;padding:12px;border:1px dashed var(--m-line);border-radius:12px;background:#fafaf9;font-size:12.5px;color:var(--m-muted);line-height:1.4}

.empty-icon{width:28px;height:28px;border-radius:10px;background:#fff;border:1px solid var(--m-line);display:grid;place-items:center;font-weight:700;flex-shrink:0}

.rail-pill{font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid}

.rail-pill.idle{background:var(--m-bg);border-color:var(--m-line);color:var(--m-muted)}

.rail-pill.pill-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}

.rail-pill.pill-err{background:#fef2f2;border-color:#fecaca;color:#991b1b}

.rail-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}

.rail-link{font-size:12px;font-weight:600;text-decoration:none;padding:7px 12px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-ink);display:inline-flex;align-items:center;cursor:pointer}

.rail-link:hover{background:var(--m-bg)}

.rail-link.muted{color:var(--m-muted)}

.history-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}

.history-item{display:flex;gap:8px;align-items:center;padding:8px 10px;border-radius:10px;border:1px solid var(--m-line);background:#fafaf9;font-size:11.5px}

.history-item.ok{background:#ecfdf5;border-color:#a7f3d0}

.history-item.err{background:#fef2f2;border-color:#fecaca}

.history-time{font-weight:700;white-space:nowrap}

.history-msg{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.history-badge{font-size:10px;font-weight:800;padding:2px 6px;border-radius:999px;border:1px solid;flex-shrink:0}

.history-badge.ok{background:#fff;color:#065f46;border-color:#a7f3d0}

.history-badge.err{background:#fff;color:#991b1b;border-color:#fecaca}



.how .rail-head{margin-bottom:8px}

.how-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px;font-size:12.5px;line-height:1.5;color:#57534e}

.how-list b{color:var(--m-ink)}

.how-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}

.tag{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:999px;border:1px solid var(--m-line);background:var(--m-bg);color:var(--m-muted)}



.quick{padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}

.quick-k{font-size:11px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.quick-actions{display:flex;gap:8px;flex-wrap:wrap}

.kat-chip{padding:7px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12.5px;font-weight:600;color:var(--m-ink);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}

.kat-chip:hover{background:var(--m-bg)}



.scan-legal{margin:14px 0 0;text-align:center;font-size:11px;color:#a8a29e}



@media(max-width:639px){

 .kat-page{padding:20px 16px 24px}

 .kat-head{flex-direction:column;align-items:flex-start;gap:6px}

 .kat-title{font-size:18px}

 .scan-viewport{min-height:300px}

 .scan-reader{min-height:260px}

 .scan-input,.scan-cta{font-size:16px}

}

</style>


