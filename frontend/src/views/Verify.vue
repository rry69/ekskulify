<template>

<div class="kat-page">

<div class="kat-inner">



 <!-- head flat ( /ekskul /events /kalender /saya) -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title">Verifikasi Sertifikat</h1>

 </div>

 </div>



 <div class="verify-layout">



 <!-- main form card -->

 <div class="kat-card verify-main">

 <div class="verify-main-head">

 <div class="verify-head-title">Tempel hash sertifikat</div>

 </div>



 <div class="verify-box">

 <div class="verify-input-wrap" :class="{ invalid: hashTrim && !isValid, valid: isValid }">

 <span class="mono inp-prefix">#</span>

 <input v-model="hash" placeholder="Tempel hash di sini" class="inp mono" maxlength="64" spellcheck="false" autocomplete="off" @keydown.enter="doVerify" aria-label="Hash sertifikat" />

 <button v-if="hash" class="inp-clear" @click="hash=''; data=null; notFound=false; err=''" aria-label="Hapus">x</button>

 </div>

 <button class="kat-cta verify-cta" :disabled="!hashTrim || loading" @click="doVerify">

 <span v-if="loading" class="spin spin-white"></span>

 {{ loading ? 'Memverifikasi...' : 'Verifikasi' }}

 </button>

 </div>

 <!-- scan actions -->

 <div class="verify-scan-row">

 <button class="scan-btn mono" :class="{ active: scanning }" @click="toggleScan">

 <span class="scan-ico">{{ scanning ? '-' : 'o' }}</span> {{ scanning ? 'Tutup Kamera' : 'Scan QR Kamera' }}

 </button>

 <button class="scan-btn mono scan-btn-ghost" @click="triggerUpload">

 Upload QR

 </button>

 <input ref="fileEl" type="file" accept="image/*" class="hidden-file" @change="onFile" />

 </div>

 <div v-if="scanErr" class="mono scan-err">{{ scanErr }}</div>

 <div v-show="scanning" class="scan-box">

 <div id="qr-reader" class="qr-reader"></div>

 </div>



 <div v-if="err" class="alert alert-err mono">{{ err }}</div>

 <div v-if="notFound" class="alert alert-err">x Tidak ditemukan - hash tidak terdaftar</div>



 <!-- result -->

 <div v-if="data" class="result">

 <div class="result-head">

 <span class="badge-ok">v VALID</span>

 <span class="mono result-nomor">{{ data.nomor }}</span>

 </div>

 <div class="res-grid">

 <div class="res-item">

 <span class="lbl mono">NOMOR</span>

 <div class="mono res-val">{{ data.nomor }}</div>

 </div>

 <div class="res-item">

 <span class="lbl mono">PENERIMA</span>

 <div class="res-val">{{ data.user_nama }}</div>

 <div class="mono muted" style="font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ data.email }}</div>

 </div>

 <div class="res-item">

 <span class="lbl mono">TIPE</span>

 <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">

 <span class="pill mono" :class="data.tipe==='ekskul'?'pill-ekskul':'pill-event'">{{ data.tipe }}</span>

 <span class="mono" style="font-size:12px">#{{ data.target_id }} - {{ data.target_nama }}</span>

 </div>

 </div>

 <div class="res-item">

 <span class="lbl mono">TANGGAL TERBIT</span>

 <div class="mono res-val">{{ data.issued_at }}</div>

 </div>

 <div class="res-item">

 <span class="lbl mono">SEKOLAH</span>

 <div class="res-val">{{ data.sekolah_nama }}</div>

 <div class="muted" style="font-size:11px">Kepala: {{ data.kepsek_nama }}</div>

 </div>

 <div class="res-item res-hash">

 <span class="lbl mono">HASH</span>

 <div class="mono hash-box">{{ data.hash }}</div>

 </div>

 </div>

 <div class="result-share">

 <button class="share-btn mono" @click="copyLink"> Salin Link</button>

 <a :href="waLink" target="_blank" class="share-btn mono share-wa"> Share WA</a>

 <button class="share-btn mono share-cta" @click="downloadPdf">v Bukti PDF</button>

 <a :href="`/api/verify/${data.hash}`" target="_blank" class="foot-link mono">Buka JSON -></a>

 </div>

 <div class="result-foot mono">

 <span class="mono" style="font-size:10.5px">{{ verifyUrl }}</span>

 </div>

 </div>



 <div v-if="toastMsg" class="mono toast-ok">{{ toastMsg }}</div>

 <!-- idle empty -->

 <div v-if="!data && !notFound && !err && !loading" class="verify-idle">

 <div class="idle-icon">-</div>

 <div class="mono idle-title">Belum ada verifikasi</div>

 <div class="mono idle-sub">Tempel hash di atas lalu tekan Verifikasi. Hasil valid akan tampil di sini.</div>

 </div>

 </div>



 <!-- rail -->

 <div class="verify-rail">

 <div class="kat-card rail-card muted-card">

 <div class="mono rail-sec">Verifikasi publik tanpa login.</div>

 </div>

 </div>



 </div>



</div>

</div>

</template>

<script setup>

import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'

import { useRoute } from 'vue-router'

const route=useRoute()

const hash=ref(route.params.hash||'')

watch(()=> route.params.hash, (newHash)=>{
  if(newHash && newHash !== hash.value) {
    hash.value = newHash
    doVerify()
  }
})

const hashTrim=computed(()=> hash.value.trim())

const isValid = computed(()=> /^[a-f0-9]{64}$/i.test(hashTrim.value))

const loading=ref(false), err=ref(''), data=ref(null), notFound=ref(false)

const scanning=ref(false), scanErr=ref(''), fileEl=ref(null)
const toastMsg=ref('')
let qr=null
let _scannerInit = false

const verifyUrl=computed(()=> data.value ? `${location.origin}/verify/${data.value.hash}` : '')

const waLink=computed(()=> data.value ? `https://wa.me/?text=${encodeURIComponent('Sertifikat VALID '+data.value.nomor+' - '+verifyUrl.value)}` : '#')

function extractHash(t){

 if(!t) return ''

 const m=String(t).match(/[a-f0-9]{64}/i)

 return m ? m[0].toLowerCase() : ''

}

function onScanText(txt){

 const h=extractHash(txt)

 if(!h){ scanErr.value='QR terbaca tapi bukan hash 64hex'; return }

 scanErr.value=''; hash.value=h; stopScan(); doVerify()

}

async function toggleScan(){
 if(scanning.value){ stopScan(); return }
 if(_scannerInit) return
 _scannerInit = true
 scanErr.value=''; scanning.value=true; await nextTick()
 try{
 const { Html5Qrcode } = await import('html5-qrcode')
 qr=new Html5Qrcode('qr-reader')
 await qr.start({ facingMode:'environment' }, { fps:10, qrbox: { width:220, height:220 } },
 (txt)=> onScanText(txt),
 ()=>{}
 )
 }catch(e){
 scanErr.value=e?.message?.includes('Permission') ? 'Izin kamera ditolak - pakai Upload QR' : (e.message||'Gagal buka kamera - pakai Upload QR')
 scanning.value=false; qr=null
 }finally{ _scannerInit = false }
}

function stopScan(){

 if(qr){ try{ qr.stop(); qr.clear() }catch{}; qr=null }

 scanning.value=false

}

function triggerUpload(){ fileEl.value?.click() }

async function onFile(e){
 const f=e.target.files?.[0]; if(!f) return
 scanErr.value=''
 if(scanning.value) stopScan()
 try{
 const { Html5Qrcode } = await import('html5-qrcode')
 const tmp=new Html5Qrcode('qr-reader')
 const res=await tmp.scanFile(f, true)
 onScanText(res)
 }catch(ex){
 scanErr.value='QR tidak terbaca dari gambar - coba foto lebih jelas'
 }finally{ e.target.value='' }
}

function copyLink(){

 if(!verifyUrl.value) return

 navigator.clipboard.writeText(verifyUrl.value).then(()=>{ err.value=''; toast('Link disalin v') }, ()=>{ window.prompt('Salin link:', verifyUrl.value) })

}

function toast(m){ toastMsg.value=m; setTimeout(()=>toastMsg.value='',2200) }

function escH(s){ return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) }

function downloadPdf(){

 if(!data.value) return

 const d=data.value

 const w=window.open('', '_blank')

 const html=`<!doctype html><title>Bukti Verifikasi - ${escH(d.nomor)}</title><style>body{font-family:'Satoshi',system-ui,sans-serif;padding:32px;color:#2F3E46}h1{font-size:18px;margin:0}.mono{font-family:'Satoshi',system-ui,sans-serif} .badge{display:inline-block;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;padding:6px 12px;border-radius:999px;font-weight:700;font-size:12px} table{margin-top:16px;border-collapse:collapse;width:100%} td{padding:8px 10px;border:1px solid #E0E5E3;font-size:13px} td:first-child{font-weight:700;background:#F1F5F4;width:160px} .foot{margin-top:18px;font-size:11px;color:#6B7C85}</style><h1>Bukti Verifikasi Sertifikat - VALID</h1><div class="mono" style="margin:8px 0"><span class="badge">v VALID - HMAC verified</span></div><table><tr><td>Nomor</td><td class="mono">${escH(d.nomor)}</td></tr><tr><td>Penerima</td><td>${escH(d.user_nama)} - ${escH(d.email)}</td></tr><tr><td>Tipe</td><td>${escH(d.tipe)} #${escH(d.target_id)} - ${escH(d.target_nama)}</td></tr><tr><td>Tanggal Terbit</td><td class="mono">${escH(d.issued_at)}</td></tr><tr><td>Sekolah</td><td>${escH(d.sekolah_nama)} - Kepsek: ${escH(d.kepsek_nama)}</td></tr><tr><td>Hash</td><td class="mono" style="word-break:break-all">${escH(d.hash)}</td></tr><tr><td>Link Validasi</td><td class="mono">${location.origin}/verify/${escH(d.hash)}</td></tr></table><div class="foot">Dicetak dari /verify - HMAC sha256 - ${new Date().toLocaleString('id-ID')}</div><script>window.print()<\/script>`

 w.document.write(html); w.document.close()

}

async function doVerify(){
 const h=hashTrim.value
 if(!/^[a-f0-9]{64}$/i.test(h)){ err.value='Hash harus 64 hex [a-f0-9]'; data.value=null; notFound.value=false; return }
 loading.value=true; err.value=''; data.value=null; notFound.value=false
 try{
 const ctrl = new AbortController()
 const timer = setTimeout(()=> ctrl.abort(), 15000)
 let res
 try {
 res = await fetch('/api/verify/'+h, { signal: ctrl.signal })
 } catch(e) {
 clearTimeout(timer)
 if(e?.name==='AbortError') throw {error:{code:'TIMEOUT',message:'Jaringan lambat — coba lagi.'}}
 throw e
 }
 clearTimeout(timer)
 const j=await res.json()
 if(!res.ok || !j.success) throw j
 data.value=j.data
 }catch(e){
 const code=e.error?.code||''
 if(code==='NOT_FOUND' || code==='INVALID') notFound.value=true
 else err.value=e.error?.message||'Gagal verifikasi'
 }finally{loading.value=false}
}

onMounted(()=>{ if(hashTrim.value) doVerify() })

onBeforeUnmount(()=> stopScan())

</script>

<style scoped>

/* Mindora tokens - identical to /ekskul /events /kalender /saya /admin/users */

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

.kat-sub{margin:2px 0 0;font-size:11.5px;color:var(--m-muted);font-family:'Satoshi',system-ui,sans-serif}

.kat-head-actions{display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}

.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px}

.kat-link:hover{border-color:#d4d4d8}

.kat-link.small{padding:7px 12px;font-size:12.5px}

.kat-strip{margin:0 0 14px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}



/* layout - intentional 2-col, stacks <900, no excessive whitespace */

.verify-layout{display:grid;grid-template-columns:1.45fr .85fr;gap:16px;align-items:start}

@media(max-width:900px){ .verify-layout{grid-template-columns:1fr} }



.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}



/* main */

.verify-main{padding:16px}

.verify-main-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px}

.verify-kicker{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.verify-head-title{font-size:15px;font-weight:800;letter-spacing:-.01em;margin-top:2px}

.verify-head-sub{font-size:11px;color:var(--m-muted);margin-top:2px}

.hash-sample{background:#F1F5F4;border:1px solid var(--m-line);padding:1px 6px;border-radius:999px;font-size:11px}

.verify-badge-count{font-size:11px;font-weight:700;padding:6px 10px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-muted);flex-shrink:0}

.verify-badge-count.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.verify-badge-count.warn{background:#fffbeb;color:#92400e;border-color:#fde68a}



.verify-box{display:flex;gap:10px;align-items:stretch}

.verify-input-wrap{flex:1;display:flex;align-items:center;gap:8px;padding:0 10px;border:1px solid var(--m-line);border-radius:12px;background:#fff;min-width:0;transition:.15s}

.verify-input-wrap:focus-within{border-color:var(--m-ink);box-shadow:0 0 0 3px rgba(47,62,70,.08)}

.verify-input-wrap.valid{border-color:#a7f3d0;background:#f0fdf6}

.verify-input-wrap.invalid{border-color:#fecaca;background:#fff1f2}

.inp-prefix{font-size:13px;color:var(--m-muted);flex-shrink:0}

.inp{flex:1;min-width:0;border:none;outline:none;padding:12px 0;font-size:13px;background:transparent;color:var(--m-ink)}

.inp::placeholder{color:#a1a1aa}

.inp-clear{width:28px;height:28px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-muted);cursor:pointer;display:grid;place-items:center;flex-shrink:0}

.inp-clear:hover{background:var(--m-bg)}

.kat-cta{padding:11px 22px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:13px;font-weight:700;border:1px solid var(--m-ink);cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;flex-shrink:0}

.kat-cta:hover{background:#1a2a33}

.kat-cta:disabled{opacity:.45;cursor:not-allowed}

.verify-cta{min-height:46px}

.verify-scan-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}

.scan-btn{padding:8px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;color:var(--m-ink)}

.scan-btn:hover{border-color:var(--m-cta);color:var(--m-cta)}

.scan-btn.active{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.scan-btn-ghost{background:#fff}

.scan-hint{font-size:11px;color:var(--m-muted)}

.scan-err{margin-top:8px;font-size:11.5px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:8px 10px;border-radius:10px}

.scan-box{margin-top:10px;padding:10px;background:#fff;border:1px solid var(--m-line);border-radius:12px}

.qr-reader{border-radius:10px;overflow:hidden}

.qr-reader video{border-radius:10px}

.scan-note{margin-top:8px;font-size:11px;color:var(--m-muted);text-align:center}

.hidden-file{display:none}

.result-share{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--m-line)}

.share-btn{padding:8px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center}

.share-btn:hover{border-color:var(--m-ink)}

.share-cta{background:var(--m-cta);color:#fff;border-color:var(--m-cta)}

.share-cta:hover{background:var(--m-cta-h)}

.share-wa{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}

.toast-ok{margin-top:10px;padding:8px 12px;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:12.5px}

.verify-hint{margin-top:8px;font-size:11px;color:var(--m-muted);display:flex;gap:6px;flex-wrap:wrap;align-items:center}

.verify-hint-dot{opacity:.5}



.alert{margin-top:12px;padding:10px 12px;border-radius:12px;font-size:12.5px;border:1px solid var(--m-line)}

.alert-err{background:#fef2f2;border-color:#fecaca;color:#991b1b}



.result{margin-top:14px;padding:14px;background:#fafafa;border:1px solid var(--m-line);border-radius:12px}

.result-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}

.badge-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;display:inline-flex;align-items:center}

.result-nomor{font-size:11px;color:var(--m-muted);background:#fff;border:1px solid var(--m-line);padding:4px 10px;border-radius:999px}

.res-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}

@media(max-width:640px){.res-grid{grid-template-columns:1fr}}

.res-item{min-width:0}

.lbl{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);display:block;margin-bottom:4px}

.res-val{font-size:13px;font-weight:600;letter-spacing:-.01em;overflow:hidden;text-overflow:ellipsis}

.muted{color:var(--m-muted)}

.pill{font-size:11px;padding:4px 10px;border-radius:999px;font-weight:700;border:1px solid var(--m-line)}

.pill-ekskul{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.pill-event{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}

.hash-box{font-size:10.5px;word-break:break-all;background:#fff;border:1px solid var(--m-line);padding:8px 10px;border-radius:10px}

.res-hash{grid-column:1/-1}

.result-foot{margin-top:12px;padding-top:10px;border-top:1px solid var(--m-line);display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--m-muted)}

.foot-link{color:var(--m-cta);font-weight:700;text-decoration:none}

.foot-link:hover{text-decoration:underline}



/* idle */

.verify-idle{margin-top:12px;padding:20px;text-align:center;border:1px dashed var(--m-line);border-radius:12px;background:#fff}

.idle-icon{width:36px;height:36px;border-radius:10px;border:1px solid var(--m-line);display:grid;place-items:center;margin:0 auto;color:var(--m-cta);background:var(--m-bg);font-size:14px}

.idle-title{margin-top:8px;font-size:12.5px;font-weight:700}

.idle-sub{margin-top:4px;font-size:11px;color:var(--m-muted)}



/* rail */

.verify-rail{display:flex;flex-direction:column;gap:12px}

.rail-card{padding:14px 16px}

.rail-head{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.rail-steps{margin:8px 0 0;padding-left:18px;display:flex;flex-direction:column;gap:6px;font-size:12.5px;color:var(--m-ink);line-height:1.5}

.rail-steps b{color:var(--m-ink)}

.rail-divider{height:1px;background:var(--m-line);margin:12px 0}

.rail-note{font-size:11.5px;color:var(--m-muted);line-height:1.5}

.rail-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}

.muted-card{background:#fafaf9}

.rail-sec{margin-top:6px;font-size:11.5px;color:var(--m-muted);line-height:1.6}

.rail-foot{font-size:11px;color:var(--m-muted);text-align:center}

.rail-foot .foot-link{color:var(--m-muted);text-decoration:underline;text-underline-offset:2px}

.rail-foot .foot-link:hover{color:var(--m-ink)}



.spin{width:14px;height:14px;border:2px solid #e4e4e7;border-top-color:#18181b;border-radius:999px;display:inline-block;animation:sp .6s linear infinite}

.spin-white{border-color:rgba(255,255,255,.3);border-top-color:#fff}

@keyframes sp{to{transform:rotate(360deg)}}



@media(max-width:640px){

 .kat-page{padding:16px 16px 24px}

 .verify-box{flex-direction:column}

 .verify-cta{width:100%;justify-content:center}

}

</style>

