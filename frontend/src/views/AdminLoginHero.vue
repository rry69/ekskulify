<template>

<div class="kat-page">

<div class="kat-inner">



 <!-- head flat -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title">Hero Login</h1>

 <p class="kat-sub" title="Carousel otomatis di halaman login. Format: PNG/JPG/WEBP, max 5 MB per foto. Max 5 foto. Setiap foto bisa diatur alt teks & link.">Kelola tampilan halaman login</p>

 </div>



 </div>

 <p class="kat-strip" title="Foto hero tampil sebagai carousel auto-slide di halaman login. Interval 2-10 detik. Drag untuk urutkan. Klik angka untuk reorder. Overlay mengatur keterbacaan teks.">Carousel auto-slide &middot; drag untuk urutkan &middot; overlay teks</p>



 <!-- stats -->

 <div class="kat-stats" aria-label="Statistik login hero">

 <div class="kat-stat">

 <div class="kat-stat-label mono">FOTO</div>

 <div class="kat-stat-value">{{ photos.length }}/5</div>

 <div class="kat-stat-desc mono" title="Jumlah foto yang terpasang. Carousel aktif jika ada foto.">{{ photos.length ? 'carousel aktif' : 'gradasi bawaan' }}</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">INTERVAL</div>

 <div class="kat-stat-value">{{ interval }}<span style="font-size:14px;font-weight:600;color:var(--m-muted)"> detik</span></div>

 <div class="kat-stat-desc mono" title="Waktu jeda antar slide dalam detik (2-10).">auto-slide</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">OVERLAY</div>

 <div class="kat-stat-value" style="font-size:18px">{{ overlay }}</div>

 <div class="kat-stat-desc mono" title="Tingkat kegelapan overlay. 0 = transparan, 0.8 = sangat gelap.">gelap</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">LENCANA</div>

 <div class="kat-stat-value" style="font-size:13px">{{ badgeCount }}</div>

 <div class="kat-stat-desc mono" title="Jumlah lencana informasi yang ditampilkan.">lencana</div>

 </div>

 </div>



 <!-- FOTO + PREVIEW SPLIT -->

 <div class="kat-card hero-card">

 <div class="hero-card-head">

 <div>

 <div class="mono hero-kicker">FOTO HERO</div>

 <div class="hero-card-title">Foto ({{ photos.length }}/5)</div>

 <div class="mono hero-card-sub" title="Auto-compress ke WEBP 1920px. Format: PNG, JPG, atau WEBP.">Auto-slide {{ interval }} detik</div>

 </div>

 <span class="mono hero-badge" :class="{ok: photos.length > 0}" title="Status carousel hero">{{ photos.length ? photos.length + ' foto' : 'gradient' }}</span>

 </div>

 <div class="hero-grid">

 <div class="hero-left">

 <label class="lbl mono" title="Format: PNG, JPG, WEBP. Max 5 MB per foto. Akan di-compress otomatis ke WEBP 1920px.">UNGGAH FOTO</label>

 <input ref="heroInput" type="file" accept="image/png,image/jpeg,image/webp" style="display:none" @change="onHeroFile" />

 <div style="display:flex;gap:8px;flex-wrap:wrap">

 <button class="kat-cta" :disabled="mediaLoading||photos.length>=5" @click="openPicker">

 <span v-if="mediaLoading" class="spin spin-white"></span>

 {{ mediaLoading?'Memproses...':'Unggah Foto' }}

 </button>

 <button v-if="photos.length>0" class="kat-link" :disabled="mediaLoading" @click="deleteAll">Hapus semua</button>

 </div>

 <div v-if="mediaMsg" class="alert mono" :class="mediaOk?'alert-ok':'alert-err'">{{ mediaMsg }}</div>

 <div class="mono muted" style="font-size:11px;margin-top:6px" title="Drag angka 1-5 untuk mengurutkan foto. Klik 'Edit' untuk atur alt teks & link.">{{ photos.length>0?('Terpasang '+photos.length+' foto'):'Belum ada foto — hero menggunakan gradasi bawaan.' }}</div>



 <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--m-line)">

 <label class="lbl mono" title="Waktu jeda antar slide dalam detik. Default: 4 detik.">INTERVAL (2-10 DETIK)</label>

 <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">

 <input v-model.number="interval" type="number" min="2" max="10" class="kat-input" style="max-width:100px" />

 <button class="kat-link strong" :disabled="savingIv" @click="saveInterval">{{ savingIv?'Menyimpan...':'Simpan Interval' }}</button>

 </div>

 </div>

 <div style="margin-top:12px">

 <label class="lbl mono" title="0 = transparan, 0.8 = sangat gelap. Atur agar teks di atas foto tetap terbaca.">OVERLAY (0-0.8)</label>

 <div style="display:flex;gap:10px;align-items:center">

 <input v-model.number="overlay" type="range" min="0" max="0.8" step="0.02" style="flex:1" />

 <span class="mono" style="font-size:12px;min-width:36px">{{ overlay }}</span>

 <button class="kat-link strong" :disabled="savingOv" @click="saveOverlay">{{ savingOv?'Menyimpan...':'Simpan' }}</button>

 </div>

 <div class="mono muted" style="font-size:11px;margin-top:4px" title="Saran: 0.3 untuk foto terang, 0.5 untuk foto campuran, 0.7 untuk foto gelap.">Keterangan overlay</div>

 </div>

 </div>

 <div class="hero-preview">

 <div v-if="photos.length>0" class="hero-grid-photos">

 <div v-for="(p,i) in photos" :key="p.basename||p.url" class="hero-thumb-wrap" draggable="true"

 @dragstart="onDragStart(i)" @dragover.prevent="onDragOver(i)" @dragend="onDragEnd" @drop="onDrop(i)"

 :class="{dragging: dragIdx===i, 'drag-over': dragOver===i}">

 <img :src="p.url" :alt="p.alt||'Hero login'" class="hero-thumb" />

 <span class="hero-num mono">{{ i+1 }}</span>

 <button class="hero-del" :disabled="mediaLoading" @click="deleteOne(p)" aria-label="Hapus foto">x</button>

 <span class="mono hero-thumb-name" :title="p.alt||p.name">{{ p.alt ? p.alt.slice(0,18) : (p.basename||'foto') }}</span>

 <div class="thumb-actions">

 <button class="mini-btn mono" @click="openMeta(p)">{{ p.alt||p.link?'Edit':'Alt/Link' }}</button>

 </div>

 </div>

 </div>

 <span v-else class="mono muted" style="font-size:11px;text-align:center;line-height:1.4" title="Belum ada foto yang diunggah. Hero akan menampilkan gradasi warna default.">Preview hero<br>(kosong = gradasi)</span>

 </div>

 </div>



 <!-- live preview split beneran -->

 <div v-if="photos.length>0" class="live-preview-wrap">

 <div class="live-label mono" title="Ini adalah pratinjau langsung tampilan login yang dilihat pengguna.">LIVE PREVIEW</div>

 <div class="live-hero" :style="{'--ov': overlay}">

 <img v-if="photos[0]" :src="photos[0].url" class="live-img" alt="" />

 <div class="live-shade"></div>

 <div class="live-body">

 <div class="live-title" v-text="form.login_hero_title||'Belajar, Berkarya, dan Bertumbuh Bersama.'"></div>

 <div class="live-desc" v-text="form.login_hero_desc||'Sistem informasi ekstrakurikuler terpadu'"></div>

 <div class="live-badges"><span v-for="(b,i) in badgePreview" :key="i" class="badge-pill mono">{{ b }}</span></div>

 </div>

 </div>

 </div>



 <!-- meta editor -->

 <div v-if="metaEdit" class="meta-box">

 <div class="mono" style="font-size:11px;font-weight:700;margin-bottom:6px">EDIT FOTO — {{ metaEdit.basename }}</div>

 <label class="lbl mono" title="Deskripsi singkat foto untuk screen reader & SEO. Max 120 karakter.">ALT TEXT</label>

 <input v-model="metaEdit.alt" class="kat-input" maxlength="120" placeholder="Deskripsi singkat foto, cth: Siswa pramuka baris-berbaris" />

 <label class="lbl mono" style="margin-top:8px" title="Opsional. Jika diisi, foto akan menjadi tautan yang bisa diklik di halaman login.">LINK CTA</label>

 <input v-model="metaEdit.link" class="kat-input" placeholder="https://sekolah.test/ekskul/..." />

 <div style="display:flex;gap:8px;margin-top:10px">

 <button class="kat-cta" :disabled="savingMeta" @click="saveMeta">{{ savingMeta?'Menyimpan...':'Simpan' }}</button>

 <button class="kat-link" @click="metaEdit=null">Batal</button>

 </div>

 <div v-if="metaMsg" class="alert mono" :class="metaOk?'alert-ok':'alert-err'" style="margin-top:8px">{{ metaMsg }}</div>

 </div>

 </div>



 <!-- TEKS -->

 <div class="kat-card hero-card">

 <div class="hero-card-head">

 <div>

 <div class="mono hero-kicker">TEKS HALAMAN MASUK</div>

 <div class="hero-card-title">Konten Teks</div>

 <div class="mono hero-card-sub" title="Semua field hanya teks biasa (v-text), tanpa HTML. Panjang teks dibatasi di client & server.">5 field teks</div>

 </div>

 <span class="mono hero-badge" title="Jumlah field teks yang bisa diedit">5 field</span>

 </div>

 <div class="form-grid">

 <div class="field">

 <label class="lbl mono" title="Teks sapaan di atas formulir login. Max 200 karakter.">SAPAAN</label>

 <input v-model="form.login_greet" class="kat-input" maxlength="200" placeholder="Selamat datang kembali..." />

 <div class="mono field-hint">{{ (form.login_greet||'').length }}/200</div>

 </div>

 <div class="field">

 <label class="lbl mono" title="Teks di bawah sapaan. Max 500 karakter.">SUB-SAPAAN</label>

 <textarea v-model="form.login_sub" class="kat-input" rows="2" maxlength="500" placeholder="Masuk untuk melanjutkan..."></textarea>

 <div class="mono field-hint">{{ (form.login_sub||'').length }}/500</div>

 </div>

 <div class="field">

 <label class="lbl mono" title="Judul besar di area hero. Enter = baris baru. Max 200 karakter.">JUDUL HERO</label>

 <textarea v-model="form.login_hero_title" class="kat-input" rows="2" maxlength="200" placeholder="Belajar. Berkarya.&#10;Berprestasi."></textarea>

 <div class="mono field-hint" title="Sisa karakter yang tersedia">{{ (form.login_hero_title||'').length }}/200</div>

 </div>

 <div class="field">

 <label class="lbl mono" title="Teks deskripsi di bawah judul hero. Max 500 karakter.">DESKRIPSI HERO</label>

 <textarea v-model="form.login_hero_desc" class="kat-input" rows="2" maxlength="500" placeholder="Kelola ekskul, event, dan kehadiran..."></textarea>

 <div class="mono field-hint">{{ (form.login_hero_desc||'').length }}/500</div>

 </div>

 <div class="field" style="grid-column:1/-1">

 <label class="lbl mono" title="Pisahkan dengan tanda | (pipe). Max 6 lencana, max 300 karakter total. Contoh: Kuota Tersedia | Kalender Terpusat | Presensi QR">LENCANA</label>

 <input v-model="form.login_hero_badges" class="kat-input" maxlength="300" placeholder="Kuota Tersedia - 18/20 | Kalender Terpusat | Presensi QR" />

 <div class="mono field-hint" title="Pisahkan dengan tanda | (pipe). Max 6 lencana.">{{ (form.login_hero_badges||'').length }}/300</div>

 <div v-if="badgePreview.length" class="badge-preview">

 <span v-for="(b,i) in badgePreview" :key="i" class="badge-pill mono">{{ b }}</span>

 </div>

 </div>

 </div>

 <div class="gen-actions">

 <button class="kat-cta" :disabled="saving" @click="saveTexts">

 <span v-if="saving" class="spin spin-white"></span>

 {{ saving?'Menyimpan...':'Simpan Teks' }}

 </button>

 <button class="kat-link" :disabled="saving" @click="fetchAll">Reset</button>

 <span class="mono muted" style="font-size:11px" title="Data disimpan ke tabel app_settings sebagai teks biasa (tanpa HTML).">Tersimpan di app_settings</span>

 </div>

 <div v-if="msg" class="alert mono" :class="ok?'alert-ok':'alert-err'">{{ msg }}</div>

 </div>



</div>

</div>

</template>

<script setup>

import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

import { api, getCsrf } from '../lib/api.js'

const _timers = new Set()
function setDelay(fn, ms) {
  const id = setTimeout(() => { _timers.delete(id); fn() }, ms)
  _timers.add(id)
  return id
}
let _fetchSeq = 0
let _msgTimer = null, _mediaTimer = null, _metaTimer = null

const form=ref({login_hero_title:'',login_hero_desc:'',login_hero_badges:'',login_greet:'',login_sub:''})

const saving=ref(false), msg=ref(''), ok=ref(true)

const heroInput=ref(null), photos=ref([]), mediaLoading=ref(false), mediaMsg=ref(''), mediaOk=ref(true)

const interval=ref(4), savingIv=ref(false)

const overlay=ref(0.42), savingOv=ref(false)

const metaEdit=ref(null), savingMeta=ref(false), metaMsg=ref(''), metaOk=ref(true)

const dragIdx=ref(null), dragOver=ref(null)

const badgeCount = computed(()=>{

 const s=(form.value.login_hero_badges||'').trim()

 if(!s) return 0

 return s.split('|').map(x=>x.trim()).filter(Boolean).length

})

const badgePreview = computed(()=>{

 const s=(form.value.login_hero_badges||'').trim()

 if(!s) return []

 return s.split('|').map(x=>x.trim()).filter(Boolean).slice(0,6)

})

async function fetchAll(){

 const seq = ++_fetchSeq

 try{

 const j=await api('/settings')

 if(seq !== _fetchSeq) return

 const d=j.data||{}

 form.value={login_hero_title:d.login_hero_title||'',login_hero_desc:d.login_hero_desc||'',login_hero_badges:d.login_hero_badges||'',login_greet:d.login_greet||'',login_sub:d.login_sub||''}

 const meta=d.login_hero_meta||{}

 const urls=Array.isArray(d.login_hero_urls)?d.login_hero_urls:[]

 if(urls.length===0 && d.login_hero_media && typeof d.login_hero_media==='string' && !d.login_hero_media.startsWith('[')){

 photos.value=[{url:'/api/login-hero',name:'login_hero',basename:'login_hero',alt:'',link:''}]

 } else {

 photos.value=urls.map(u=>{

 const base=(u.split('f=')[1]||'').split('&')[0]

 const dec=decodeURIComponent(base)

 const m=meta[dec]||meta[base]||{}

 return {url:u, name:base, basename:dec, alt:m.alt||'', link:m.link||''}

 })

 }

 const iv=parseInt(d.login_hero_interval,10)

 if(iv>=2&&iv<=10) interval.value=iv; const ov=parseFloat(d.login_hero_overlay); if(!isNaN(ov) && ov>=0 && ov<=0.8) overlay.value=Math.round(ov*100)/100 }catch(e){ msg.value='Gagal muat settings'; ok.value=false } } async function saveTexts(){

 saving.value=true; clearTimeout(_msgTimer); msg.value=''

 try{

 const body={}
 for(const k of Object.keys(form.value)){
  let v=String(form.value[k]??'').trim()
  const max=(k==='login_hero_desc'||k==='login_sub')?500:(k==='login_hero_badges'?300:200)
  if(v.length>max) throw {error:{message:k+' maksimal '+max+' karakter'}}
  body[k]=v
 }
 await api('/settings',{method:'PUT',body})

 ok.value=true; clearTimeout(_msgTimer); msg.value='Teks halaman masuk berhasil disimpan'

 _msgTimer=setDelay(()=>msg.value='',2000)

 }catch(e){ ok.value=false; clearTimeout(_msgTimer); msg.value=e?.error?.message||'Gagal simpan'; _msgTimer=setDelay(()=>msg.value='',3000) }

 finally{ saving.value=false }

}

async function saveInterval(){

 const iv=parseInt(interval.value,10)

 if(!(iv>=2&&iv<=10)){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value='Interval 2-10 detik'; _mediaTimer=setDelay(()=>mediaMsg.value='',2500); return }

 savingIv.value=true

 try{

 await api('/settings',{method:'PUT',body:{login_hero_interval:String(iv)}})

 mediaOk.value=true; clearTimeout(_mediaTimer); mediaMsg.value='Interval '+iv+' detik tersimpan'

 _mediaTimer=setDelay(()=>mediaMsg.value='',2000)

 }catch(e){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value=e?.error?.message||'Gagal simpan interval'; _mediaTimer=setDelay(()=>mediaMsg.value='',3000) }

 finally{ savingIv.value=false }

}

async function saveOverlay(){

 const ov=parseFloat(overlay.value)

 if(isNaN(ov)||ov<0||ov>0.8){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value='Overlay 0-0.8'; _mediaTimer=setDelay(()=>mediaMsg.value='',2500); return }

 savingOv.value=true

 try{

 await api('/settings',{method:'PUT',body:{login_hero_overlay:String(Math.round(ov*100)/100)}})

 mediaOk.value=true; clearTimeout(_mediaTimer); mediaMsg.value='Overlay '+ov+' tersimpan - cek preview & halaman login'

 _mediaTimer=setDelay(()=>mediaMsg.value='',2000)

 }catch(e){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value=e?.error?.message||'Gagal simpan overlay'; _mediaTimer=setDelay(()=>mediaMsg.value='',3000) }

 finally{ savingOv.value=false }

}

function openPicker(){ try{ heroInput.value && heroInput.value.click() }catch{} }

async function onHeroFile(e){

 const f=e.target?.files?.[0]; if(!f) return

 if(photos.value.length>=5){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value='Maksimal 5 foto - hapus dulu sebelum tambah'; _mediaTimer=setDelay(()=>mediaMsg.value='',2500); e.target.value=''; return }

 if(f.size>5*1024*1024){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value='Ukuran berkas maksimal 5 MB - silakan kompres terlebih dahulu'; _mediaTimer=setDelay(()=>mediaMsg.value='',2500); e.target.value=''; return }

 const allowed=['image/png','image/jpeg','image/jpg','image/webp']

 if(!allowed.includes(f.type)){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value='Format berkas hanya foto PNG/JPG/WEBP (video dihapus)'; _mediaTimer=setDelay(()=>mediaMsg.value='',2500); e.target.value=''; return }

 mediaLoading.value=true; clearTimeout(_mediaTimer); mediaMsg.value=''

 try{

 const fd=new FormData(); fd.append('hero',f)

 const csrf=await getCsrf()

 const res=await fetch('/api/settings/login-hero',{method:'POST',credentials:'include',headers:{'X-CSRF-Token':csrf||''},body:fd})

 const j=await res.json().catch(()=>({success:false,error:{message:res.statusText}}))

 if(!res.ok||!j.success) throw j

 await fetchAll()

 mediaOk.value=true; clearTimeout(_mediaTimer); mediaMsg.value='Foto hero berhasil dipasang ('+photos.value.length+'/5) - auto-compress WEBP'

 _mediaTimer=setDelay(()=>mediaMsg.value='',2000)

 }catch(err){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value=err?.error?.message||'Gagal upload'; _mediaTimer=setDelay(()=>mediaMsg.value='',3000) }

 finally{ mediaLoading.value=false; if(e.target) e.target.value='' }

}

async function deleteOne(p){

 if(!confirm('Hapus foto '+ (p.basename||'') +' ?')) return

 mediaLoading.value=true; clearTimeout(_mediaTimer); mediaMsg.value=''

 try{

 const qp=p.basename?'?f='+encodeURIComponent(p.basename):(p.name?'?f='+encodeURIComponent(decodeURIComponent(p.name)):'')

 await api('/settings/login-hero'+qp,{method:'DELETE'})

 await fetchAll()

 mediaOk.value=true; clearTimeout(_mediaTimer); mediaMsg.value='Foto dihapus'

 _mediaTimer=setDelay(()=>mediaMsg.value='',2000)

 }catch(err){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value=err?.error?.message||'Gagal hapus'; _mediaTimer=setDelay(()=>mediaMsg.value='',3000) }

 finally{ mediaLoading.value=false }

}

async function deleteAll(){

 if(!confirm('Hapus SEMUA foto hero? Akan kembali ke gradasi bawaan.')) return

 mediaLoading.value=true; clearTimeout(_mediaTimer); mediaMsg.value=''

 try{

 await api('/settings/login-hero',{method:'DELETE'})

 await fetchAll()

 mediaOk.value=true; clearTimeout(_mediaTimer); mediaMsg.value='Semua foto dihapus - kembali ke gradasi bawaan'

 _mediaTimer=setDelay(()=>mediaMsg.value='',2000)

 }catch(err){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value=err?.error?.message||'Gagal hapus'; _mediaTimer=setDelay(()=>mediaMsg.value='',3000) }

 finally{ mediaLoading.value=false }

}

function onDragStart(i){ dragIdx.value=i }

function onDragOver(i){ dragOver.value=i }

function onDragEnd(){ dragIdx.value=null; dragOver.value=null }

async function onDrop(targetIdx){

 const src=dragIdx.value

 if(src===null || src===targetIdx) { onDragEnd(); return }

 const arr=[...photos.value]

 const [moved]=arr.splice(src,1)

 arr.splice(targetIdx,0,moved)

 photos.value=arr

 onDragEnd()

 // persist order

 mediaLoading.value=true

 try{

 const order=arr.map(p=>p.basename||decodeURIComponent(p.name||''))

 await api('/settings/login-hero/order',{method:'PUT',body:{order}})

 mediaOk.value=true; clearTimeout(_mediaTimer); mediaMsg.value='Urutan disimpan v'

 _mediaTimer=setDelay(()=>mediaMsg.value='',1800)

 }catch(e){ mediaOk.value=false; clearTimeout(_mediaTimer); mediaMsg.value=e?.error?.message||'Gagal simpan urutan'; await fetchAll(); _mediaTimer=setDelay(()=>mediaMsg.value='',2500) }

 finally{ mediaLoading.value=false }

}

function openMeta(p){ metaEdit.value={basename:p.basename||decodeURIComponent(p.name||''), alt:p.alt||'', link:p.link||''}; clearTimeout(_metaTimer); metaMsg.value='' }

async function saveMeta(){

 if(!metaEdit.value) return

 const bn=metaEdit.value.basename

 const alt=String(metaEdit.value.alt||'').trim().slice(0,120)

 const link=String(metaEdit.value.link||'').trim()

 if(link && !/^https?:\/\//.test(link)){ metaOk.value=false; clearTimeout(_metaTimer); metaMsg.value='Link harus https://...'; return }

 savingMeta.value=true

 try{

 await api('/settings/login-hero/meta',{method:'PUT',body:{basename:bn, alt, link}})

 metaOk.value=true; clearTimeout(_metaTimer); metaMsg.value='Meta tersimpan v'

 const savedBn = metaEdit.value?.basename
 await fetchAll()
 setDelay(()=>{ if(metaEdit.value?.basename === savedBn) metaEdit.value=null }, 800)

 }catch(e){ metaOk.value=false; clearTimeout(_metaTimer); metaMsg.value=e?.error?.message||'Gagal simpan meta' }

 finally{ savingMeta.value=false }

}

onMounted(fetchAll)
onBeforeUnmount(() => { _timers.forEach(clearTimeout); _timers.clear() })

</script>

<style scoped>

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

.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px;cursor:pointer}

.kat-link:hover{border-color:#d4d4d8}

.kat-link.strong{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.mindora-cta-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:12px;background:var(--m-cta);color:#fff;font-family:'Satoshi',system-ui,sans-serif;font-size:13px;font-weight:700;text-decoration:none;border:none;cursor:pointer;box-shadow:0 4px 20px rgba(74,120,117,.2);transition:all .2s ease;white-space:nowrap}

.mindora-cta-btn:hover{background:var(--m-cta-h);box-shadow:0 6px 24px rgba(74,120,117,.3);transform:translateY(-1px)}

.mindora-cta-btn:active{transform:translateY(0);box-shadow:0 2px 12px rgba(74,120,117,.15)}

.kat-strip{margin:0 0 12px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}

.kat-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}

@media(max-width:900px){ .kat-stats{grid-template-columns:repeat(2,1fr)} }

@media(max-width:640px){ .kat-stats{grid-template-columns:1fr 1fr} .kat-head{flex-direction:column;align-items:flex-start} }

.kat-stat{background:#fff;border:1px solid var(--m-line);border-radius:14px;padding:12px 14px}

.kat-stat-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.kat-stat-value{font-size:22px;font-weight:800;letter-spacing:-.02em;margin-top:2px}

.kat-stat-desc{font-size:11px;color:var(--m-muted);margin-top:2px}

.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}

.hero-card{padding:16px;margin-bottom:12px}

.hero-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}

.hero-kicker{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.hero-card-title{font-size:15px;font-weight:800;letter-spacing:-.01em;margin-top:2px}

.hero-card-sub{font-size:11px;color:var(--m-muted);margin-top:2px;line-height:1.5}

.hero-badge{font-size:11px;font-weight:700;padding:6px 10px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-muted);flex-shrink:0}

.hero-badge.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.hero-grid{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start}

.hero-left{flex:1;min-width:280px}

.hero-preview{width:320px;min-height:140px;border:1px dashed var(--m-line);border-radius:12px;display:grid;place-items:center;background:#fafafa;overflow:hidden;padding:10px}

.hero-grid-photos{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%}

.hero-thumb-wrap{position:relative;cursor:grab;border:1px solid var(--m-line);border-radius:10px;overflow:hidden;background:#fff}

.hero-thumb-wrap.dragging{opacity:.45}

.hero-thumb-wrap.drag-over{outline:2px solid var(--m-cta)}

.hero-thumb{width:100%;height:72px;object-fit:cover;display:block}

.hero-num{position:absolute;top:4px;left:4px;width:20px;height:20px;border-radius:999px;background:var(--m-ink);color:#fff;display:grid;place-items:center;font-size:10px;font-weight:800}

.hero-del{position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-muted);cursor:pointer;display:grid;place-items:center;font-size:10px;line-height:1}

.hero-del:hover{background:var(--m-bg);color:var(--m-ink)}

.hero-thumb-name{font-size:9px;color:var(--m-muted);padding:2px 6px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.thumb-actions{padding:0 6px 6px;display:flex}

.mini-btn{font-size:10px;padding:4px 8px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-muted);cursor:pointer}

.mini-btn:hover{border-color:var(--m-cta);color:var(--m-ink)}

.lbl{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);display:block;margin-bottom:6px}

.kat-input{width:100%;padding:10px 12px;border:1px solid var(--m-line);border-radius:12px;background:#fff;font-size:13px;box-sizing:border-box;color:var(--m-ink);outline:none;font-family:inherit}

.kat-input:focus{border-color:var(--m-ink);box-shadow:0 0 0 3px rgba(47,62,70,.08)}

.kat-cta{padding:10px 18px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:13px;font-weight:700;border:1px solid var(--m-ink);cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}

.kat-cta:hover{background:#1a2a33}

.kat-cta:disabled{opacity:.45;cursor:not-allowed}

.alert{margin-top:10px;padding:10px 12px;border-radius:12px;font-size:12px;border:1px solid var(--m-line)}

.alert-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}

.alert-err{background:#fef2f2;border-color:#fecaca;color:#991b1b}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}

@media(max-width:900px){.form-grid{grid-template-columns:1fr}}

.field{min-width:0}

.field-hint{font-size:10.5px;color:var(--m-muted);margin-top:4px}

.badge-preview{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}

.badge-pill{font-size:11px;padding:4px 10px;border-radius:999px;background:var(--m-bg);border:1px solid var(--m-line);color:var(--m-ink);font-weight:600}

.gen-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}

.muted{color:var(--m-muted)}

.spin{width:14px;height:14px;border:2px solid #e4e4e7;border-top-color:var(--m-ink);border-radius:999px;display:inline-block;animation:sp .6s linear infinite}

.spin-white{border-color:rgba(255,255,255,.3);border-top-color:#fff}

@keyframes sp{to{transform:rotate(360deg)}}

.live-preview-wrap{margin-top:14px;border-top:1px solid var(--m-line);padding-top:12px}

.live-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);margin-bottom:8px}

.live-hero{position:relative;height:180px;border-radius:12px;overflow:hidden;background:linear-gradient(180deg,#CBD8CF,#9DB5A3)}

.live-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}

.live-shade{position:absolute;inset:0;background:rgba(47,62,70,var(--ov,0.42))}

.live-body{position:relative;padding:16px;display:flex;flex-direction:column;gap:6px;justify-content:flex-end;height:100%;color:#fff}

.live-title{font-family:'Satoshi';font-weight:800;font-size:15px;line-height:1.1;text-shadow:0 1px 8px rgba(0,0,0,.35);white-space:pre-line}

.live-desc{font-size:11px;opacity:.92;line-height:1.5;max-width:42ch}

.live-badges{display:flex;gap:6px;flex-wrap:wrap}

.live-badges .badge-pill{background:rgba(255,255,255,.92);color:var(--m-ink)}

.meta-box{margin-top:12px;padding:12px;border:1px solid var(--m-line);border-radius:12px;background:#fafafa}

@media(max-width:640px){

 .kat-page{padding:16px 16px 24px}

 .hero-preview{width:100%}

}

</style>

