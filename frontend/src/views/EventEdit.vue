<template>
<div class="kat-page">
<div class="kat-inner">
<div class="kat-head">
<h1 class="kat-title">Edit Event</h1>
<router-link :to="'/events/'+route.params.id" class="kat-back">← Detail</router-link>
</div>
<div v-if="loadErr" class="kat-alert err" style="margin-top:12px">{{ loadErr }}</div>
<div v-else class="kat-card" style="margin-top:12px;max-width:720px">
<div class="kat-card-body">
<div v-if="formErr.server" class="kat-alert err" style="margin-bottom:12px">{{ formErr.server }}</div>

<!-- Cover -->
<div class="block" style="margin-bottom:14px">
<div class="mono" style="font-size:10px;letter-spacing:.1em;font-weight:700;color:#6B7C85">COVER  -  JPG/PNG/WEBP maks 2MB (same as Ekskul)</div>
<div style="display:flex;gap:12px;align-items:flex-start;margin-top:8px">
<div style="width:84px;height:84px;border-radius:14px;overflow:hidden;border:1px solid #E0E5E3;background:#f8fafc;display:grid;place-items:center;flex-shrink:0">
<img v-if="coverPreview" :src="coverPreview" alt="cover preview" style="width:100%;height:100%;object-fit:cover" />
<span v-else style="font-size:26px;color:#cbd5e1"> - </span>
</div>
<div style="flex:1;min-width:0">
<label style="display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid #E0E5E3;background:#fff;font-size:12.5px;font-weight:600;cursor:pointer">Pilih cover<input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onCoverChange" /></label>
<button v-if="coverPreview" type="button" @click="hapusCover" class="kat-btn-ghost sm" style="margin-left:8px">Hapus cover</button>
<p v-if="coverMsg" style="font-size:11.5px;margin:8px 0 0" :style="{color: coverOk?'#065f46':'#b91c1c'}">{{ coverMsg }}</p>
</div>
</div>
</div>

<!-- Nama + tanggal -->
<div class="fgrid2">
<label class="flabel"><span class="fk">NAMA <b style="color:#dc2626">*</b></span><input v-model="form.nama" class="finput" placeholder="Nama event" /><span v-if="formErr.nama" class="ferr">{{ formErr.nama }}</span></label>
<label class="flabel"><span class="fk">TANGGAL <b style="color:#dc2626">*</b></span><input type="date" v-model="form.tanggal" class="finput" /><span v-if="formErr.tanggal" class="ferr">{{ formErr.tanggal }}</span></label>
</div>
<div class="fgrid3" style="margin-top:10px">
<label class="flabel"><span class="fk">JAM MULAI <b style="color:#dc2626">*</b></span><input type="time" v-model="form.waktu" class="finput" /><span v-if="formErr.waktu" class="ferr">{{ formErr.waktu }}</span></label>
<label class="flabel"><span class="fk">JAM SELESAI</span><input type="time" v-model="form.waktu_selesai" class="finput" /><span v-if="formErr.waktu_selesai" class="ferr">{{ formErr.waktu_selesai }}</span></label>
<label class="flabel"><span class="fk">KUOTA <b style="color:#dc2626">*</b></span><input type="number" min="1" v-model.number="form.kuota" class="finput" /><span v-if="formErr.kuota" class="ferr">{{ formErr.kuota }}</span></label>
</div>
<label class="flabel" style="margin-top:10px"><span class="fk">LOKASI <b style="color:#dc2626">*</b></span><input v-model="form.lokasi" class="finput" placeholder="Aula, Lapangan..." /><span v-if="formErr.lokasi" class="ferr">{{ formErr.lokasi }}</span></label>

<!-- Scope multi-ekskul (same as AdminEkskul) -->
<div class="block" style="margin-top:10px">
<span class="fk">RUANG LINGKUP <b style="color:#dc2626">*</b></span>
<div style="display:flex;gap:8px;margin-top:6px">
<button type="button" :class="['scope-btn', form.scope==='umum'?'on':'']" @click="form.scope='umum'; form.ekskul_ids=[]">Umum</button>
<button type="button" :class="['scope-btn', form.scope==='ekskul'?'on':'']" @click="openScopeEkskul">Ekskul</button>
</div>
<div v-if="form.scope==='ekskul'" style="margin-top:8px">
<div v-if="form.ekskul_ids.length" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
<span v-for="eid in form.ekskul_ids" :key="eid" class="chip-ekskul">{{ labelForEkskulId(eid) }} <button type="button" @click="removeScopeEkskul(eid)" style="margin-left:6px;background:none;border:0;cursor:pointer">x</button></span>
</div>
<div style="position:relative">
<input v-model="scopeSearch" @input="onScopeInput" @focus="onScopeFocus" placeholder="Cari & tambah Ekskul..." class="finput" style="padding-right:32px" @keydown.escape="clearScopeSearch" />
<span v-if="scopeLoading" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;border:2px solid #e5e7eb;border-top-color:#111827;border-radius:999px;display:inline-block;animation:sp .7s linear infinite"></span>
<ul v-if="showScopeList" style="position:absolute;z-index:10;left:0;right:0;top:calc(100% + 6px);max-height:200px;overflow:auto;background:#fff;border:1px solid #E0E5E3;border-radius:12px;box-shadow:0 12px 24px rgba(0,0,0,.12);list-style:none;margin:0;padding:4px">
<li v-for="o in scopeOptions" :key="o.id" @click="selectScopeEkskul(o)" style="padding:9px 10px;border-radius:8px;cursor:pointer;display:flex;justify-content:space-between;gap:8px;font-size:13px" :style="{opacity: (form.ekskul_ids||[]).some(x=>String(x)===String(o.id))?'.45':'1'}" @mouseenter="$event.currentTarget.style.background='#fafaf9'" @mouseleave="$event.currentTarget.style.background=''"><span style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ o.nama }}</span><span class="mono" style="font-size:11px;color:#a8a29e;flex-shrink:0">{{ (form.ekskul_ids||[]).some(x=>String(x)===String(o.id))?'terpilih':o.status }}</span></li>
<li v-if="!scopeOptions.length && !scopeLoading" class="mono" style="padding:10px;font-size:11px;color:#a8a29e">Tidak ada ekskul</li>
</ul>
</div>
<p v-if="form.ekskul_ids.length" class="mono" style="font-size:11px;color:#6B7C85;margin:6px 0 0">{{ form.ekskul_ids.length }} ekskul terpilih</p>
<p v-else class="mono" style="font-size:11px;color:#b45309;margin:6px 0 0">Pilih minimal 1 ekskul (bisa banyak)</p>
</div>
<p v-else class="mono" style="font-size:11px;color:#a8a29e;margin:6px 0 0">Event umum  -  terlihat semua siswa, tidak terikat ekskul tertentu</p>
<span v-if="formErr.ekskul_ids" class="ferr">{{ formErr.ekskul_ids }}</span>
</div>

<!-- Deskripsi + inline image -->
<label class="flabel" style="margin-top:10px">
<span class="fk" style="display:flex;justify-content:space-between;align-items:center">DESKRIPSI <button type="button" class="kat-btn-ghost sm" @click="triggerDescImage" :disabled="descUploading">{{ descUploading?'Mengunggah...':'Sisipkan Gambar' }}</button></span>
<textarea ref="descTa" v-model="form.deskripsi" rows="5" class="finput" style="min-height:96px;resize:vertical;padding-top:10px" placeholder="Deskripsi event  -  gunakan Sisipkan Gambar untuk inline di posisi kursor"></textarea>
<input ref="descFileInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" @change="onDescImagePick" />
<span class="mono" style="font-size:11px;color:#8a8580;margin-top:4px;display:block">Gambar disisipkan sebagai <code>[[img:ID]]</code> di posisi kursor  -  maks 2MB JPG/PNG/WEBP/GIF.</span>
<span v-if="formErr.deskripsi" class="ferr">{{ formErr.deskripsi }}</span>
</label>

<!-- Rundown -->
<div class="block" style="margin-top:10px">
<div style="display:flex;justify-content:space-between;align-items:center"><span class="fk">RUNDOWN  -  jam + kegiatan</span><button type="button" class="kat-btn-ghost sm" @click="addRundown">+ Tambah baris</button></div>
<div v-for="(row,i) in rundownList" :key="i" style="display:grid;grid-template-columns:110px 1fr auto;gap:8px;margin-top:8px">
<input type="time" v-model="row.jam" class="finput" :aria-label="'Jam rundown '+(i+1)" />
<input v-model="row.kegiatan" class="finput" placeholder="Kegiatan, mis. Registrasi" :aria-label="'Kegiatan rundown '+(i+1)" />
<button type="button" style="width:40px;height:40px;display:grid;place-items:center;border-radius:12px;border:1px solid #E0E5E3;background:#fff;color:#dc2626;cursor:pointer" @click="removeRundown(i)">x</button>
</div>
<span v-if="formErr.rundown" class="ferr">{{ formErr.rundown }}</span>
</div>

<div class="fgrid2" style="margin-top:10px">
<label class="flabel"><span class="fk">PENDAFTARAN MULAI (opsional)</span><input type="datetime-local" v-model="form.registration_start" class="finput" /></label>
<label class="flabel"><span class="fk">PENDAFTARAN SELESAI (opsional)</span><input type="datetime-local" v-model="form.registration_end" class="finput" /></label>
</div>

<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px">
<router-link :to="'/events/'+route.params.id" class="kat-btn-ghost neutral">Batal</router-link>
<button class="kat-btn" style="min-width:120px" :disabled="saving" @click="submitEdit">{{ saving?'Menyimpan...':'Simpan' }}</button>
</div>
<p v-if="formErr.server" class="mono" style="font-size:12px;color:#b91c1c;margin-top:8px">{{ formErr.server }}</p>
</div>
</div>
</div>
</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, apiUpload, apiCover, apiDeleteCover } from '../lib/api.js'
import { useAuth } from '../stores/auth.js'

const route=useRoute(), router=useRouter(), auth=useAuth()
const loadErr=ref(''), saving=ref(false)
const form=ref({ nama:'', deskripsi:'', tanggal:'', waktu:'', waktu_selesai:'', lokasi:'', kuota:50, scope:'umum', ekskul_ids:[], registration_start:'', registration_end:'' })
const formErr=ref({})
const rundownList=ref([{ jam:'', kegiatan:'' }])

// cover
const coverPreview=ref(''), coverMsg=ref(''), coverOk=ref(false)
let pendingCoverFile=null
function onCoverChange(e){
  const f=e.target?.files?.[0]; if(!f) return
  if(f.size>2*1024*1024){ coverMsg.value='Maks 2MB'; coverOk.value=false; e.target.value=''; return }
  pendingCoverFile=f; coverPreview.value=URL.createObjectURL(f); coverMsg.value='Siap diunggah saat Simpan'; coverOk.value=true; e.target.value=''
}
async function hapusCover(){
  pendingCoverFile=null
  // if event has cover_url, delete via api; preview fallback to existing cover_url handled in load
  try{
    await apiDeleteCover('event', route.params.id)
    coverPreview.value=''; coverMsg.value='Cover dihapus'; coverOk.value=true
  }catch(ex){ coverMsg.value=ex?.error?.message||'Gagal hapus cover'; coverOk.value=false }
}

// scope multi-ekskul
const scopeSearch=ref(''), scopeOptions=ref([]), scopeLoading=ref(false), showScopeList=ref(false)
const selectedEkskulMap=computed(()=>{ const m=new Map(); for(const o of scopeOptions.value) m.set(String(o.id), o); return m })
function labelForEkskulId(id){ const h=selectedEkskulMap.value.get(String(id)); return h? h.nama : `Ekskul #${id}` }
let scopeDebounce=null
async function fetchScopeEkskul(q){
  scopeLoading.value=true
  try{ const p=new URLSearchParams({limit:'20'}); if(q) p.set('search',q); const j=await api('/ekskul?'+p.toString()); scopeOptions.value=j.data||[] }catch{ scopeOptions.value=[] }finally{ scopeLoading.value=false }
}
function openScopeEkskul(){ form.value.scope='ekskul'; showScopeList.value=true; fetchScopeEkskul(scopeSearch.value.trim()) }
function onScopeInput(){ showScopeList.value=true; clearTimeout(scopeDebounce); scopeDebounce=setTimeout(()=> fetchScopeEkskul(scopeSearch.value.trim()), 300) }
function onScopeFocus(){ showScopeList.value=true; fetchScopeEkskul(scopeSearch.value.trim()) }
function selectScopeEkskul(o){
  const arr=form.value.ekskul_ids||[]; if(!arr.includes(o.id) && !arr.includes(String(o.id))) arr.push(o.id)
  form.value.ekskul_ids=arr
  if(!scopeOptions.value.find(x=> String(x.id)===String(o.id))) scopeOptions.value=[o, ...scopeOptions.value]
  scopeSearch.value=''; showScopeList.value=false
}
function removeScopeEkskul(id){ form.value.ekskul_ids=(form.value.ekskul_ids||[]).filter(x=> String(x)!==String(id)) }
function clearScopeSearch(){ scopeSearch.value=''; showScopeList.value=false }
function onDocClick(ev){
  if(!showScopeList.value) return
  const el=document.activeElement
  // close if click outside input+list: rely on escape/clear; keep simple: if target not inside scope area, close
  const t=ev.target
  if(t && t.closest && !t.closest('input')){ /* keep if inside dropdown */ }
}
let docClick=null

// deskripsi inline image bridge (reuse /event-images upload via apiUpload)
const descTa=ref(null), descFileInput=ref(null), descUploading=ref(false)
function triggerDescImage(){ descFileInput.value?.click() }
function insertAtCursor(text){
  const el=descTa.value; const v=form.value.deskripsi||''
  if(!el || typeof el.selectionStart!=='number'){ form.value.deskripsi = v + (v && !v.endsWith('\n') ? '\n' : '') + text; return }
  const s=el.selectionStart, e2=el.selectionEnd
  form.value.deskripsi = v.slice(0,s) + text + v.slice(e2)
  nextTick(()=>{ el.focus(); const p=s+text.length; el.setSelectionRange(p,p) })
}
async function onDescImagePick(e){
  const f=e.target?.files?.[0]; if(!f) return
  if(f.size>2*1024*1024){ formErr.value={...formErr.value, deskripsi:'Gambar maks 2MB'}; e.target.value=''; return }
  descUploading.value=true
  try{
    const j=await apiUpload('/event-images', [f])
    const id=j?.data?.ids?.[0] || j?.data?.id || j?.data?.images?.[0]?.id
    if(!id) throw new Error('Upload gagal')
    insertAtCursor('[[img:'+id+']]')
  }catch(ex){ formErr.value={...formErr.value, deskripsi: ex?.error?.message||ex?.message||'Gagal upload gambar'} }finally{ descUploading.value=false; e.target.value='' }
}

// rundown helpers (same pattern as Events.vue)
function parseRundown(str){
  const raw=String(str||'').trim(); if(!raw) return [{jam:'',kegiatan:''}]
  return raw.split(/\r?\n/).filter(Boolean).map(line=>{
    const m=line.match(/^(\d{1,2}:\d{2}(?::\d{2})?)\s*[- -  - ]+\s*(.*)$/)
    if(m) return {jam:m[1].slice(0,5),kegiatan:m[2]||''}
    return {jam:'',kegiatan:line}
  })
}
function serializeRundown(){
  const items=rundownList.value.map(r=>({jam:(r.jam||'').trim(),kegiatan:(r.kegiatan||'').trim()})).filter(r=>r.jam||r.kegiatan)
  if(!items.length) return null
  return items.filter(r=>r.jam&&r.kegiatan).map(r=>`${r.jam} - ${r.kegiatan}`).join('\n')
}
function addRundown(){ rundownList.value.push({jam:'',kegiatan:''}) }
function removeRundown(i){ rundownList.value.splice(i,1); if(!rundownList.value.length) rundownList.value.push({jam:'',kegiatan:''}) }

function validateManage(){
  const err={}, f=form.value
  const n=(f.nama||'').trim(); if(!n) err.nama='Nama wajib (min 3)'; else if(n.length<3) err.nama='Minimal 3 karakter'
  if(!f.tanggal) err.tanggal='Tanggal wajib'
  if(!f.waktu) err.waktu='Jam mulai wajib'
  if(!(f.lokasi||'').trim()) err.lokasi='Lokasi wajib'; else if(f.lokasi.trim().length<3) err.lokasi='Minimal 3 karakter'
  if(!f.kuota||Number(f.kuota)<1) err.kuota='Kuota harus >0'
  if(f.waktu&&f.waktu_selesai&&f.waktu>=f.waktu_selesai) err.waktu_selesai='Jam selesai harus > jam mulai'
  if(f.scope==='ekskul' && !(f.ekskul_ids && f.ekskul_ids.length)) err.ekskul_ids='Pilih minimal 1 ekskul (bisa banyak)'
  const hasIncomplete=rundownList.value.some(r=>((r.jam||'').trim()&&!(r.kegiatan||'').trim())||(!(r.jam||'').trim()&&(r.kegiatan||'').trim()))
  if(hasIncomplete) err.rundown='Lengkapi jam + kegiatan tiap baris'
  formErr.value=err; return Object.keys(err).length===0
}

async function load(){
  try{
    const j=await api('/events/'+route.params.id)
    const e=j.data
    const ids = e.ekskul_ids && e.ekskul_ids.length ? e.ekskul_ids : (e.ekskul_id ? [e.ekskul_id] : [])
    form.value={ nama:e.nama, deskripsi:e.deskripsi||'', tanggal:e.tanggal||'', waktu:(e.waktu||'').slice(0,5), waktu_selesai:(e.waktu_selesai||'').slice(0,5), lokasi:e.lokasi||'', kuota:e.kuota, scope: ids.length ? 'ekskul' : 'umum', ekskul_ids: ids, registration_start:e.registration_start?String(e.registration_start).slice(0,16):'', registration_end:e.registration_end?String(e.registration_end).slice(0,16):'' }
    if(ids.length) fetchScopeEkskul('')
    rundownList.value=parseRundown(e.rundown||'')
    // cover preview from existing event
    if(e.cover_url) coverPreview.value=e.cover_url
    else coverPreview.value=''
  }catch(ex){
    const c=ex?.error?.code||ex?.code
    loadErr.value=(c==='NOT_FOUND'||c==='FORBIDDEN')?'Event tidak ditemukan atau di luar akses Anda':'Gagal memuat event'
  }
}

async function submitEdit(){
  if(!validateManage()) return
  saving.value=true; formErr.value={}
  const payload={...form.value}
  payload.kuota=Number(payload.kuota)
  if(!payload.waktu_selesai) payload.waktu_selesai=null
  if(!payload.deskripsi) payload.deskripsi=null
  payload.rundown=serializeRundown()
  if(payload.scope==='ekskul') payload.ekskul_ids=payload.ekskul_ids||[]; else payload.ekskul_ids=[]
  payload.ekskul_id = (payload.ekskul_ids && payload.ekskul_ids.length) ? payload.ekskul_ids[0] : null
  delete payload.scope
  if(!payload.registration_start) payload.registration_start=null
  if(!payload.registration_end) payload.registration_end=null
  try{
    await api('/events/'+route.params.id,{method:'PATCH',body:payload})
    if(pendingCoverFile){
      await apiCover('event', route.params.id, pendingCoverFile)
      pendingCoverFile=null
    }
    router.push('/events/'+route.params.id)
  }catch(ex){
    const m=ex?.error?.message||ex?.message||'Gagal simpan'
    formErr.value={...formErr.value, server:m}
  }finally{ saving.value=false }
}

onMounted(()=>{ load(); docClick=(e)=>{ const a=document.activeElement; if(showScopeList.value && e.target && !e.target.closest('ul')){ /* keep open until blur */ } }; document.addEventListener('click', onDocClick) })
onBeforeUnmount(()=>{ if(docClick) document.removeEventListener('click', onDocClick) })
</script>

<style scoped>
.kat-page{ --m-green:#5EB87E; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-line:#E0E5E3; --m-muted:#6B7C85; --m-cta:#4A7875; background:var(--m-bg); color:var(--m-ink); margin:-24px calc(50% - 50vw) 0; padding:20px max(16px,calc(50vw - 680px)) 24px }
.kat-inner{max-width:1120px;margin:0 auto}
.kat-head{display:flex;justify-content:space-between;align-items:center;gap:12px}
.kat-title{margin:0;font-size:20px;font-weight:800;letter-spacing:-.01em}
.kat-back{padding:8px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--m-ink)}
.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:20px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.kat-card-body{padding:18px}
.kat-alert{padding:10px 12px;border-radius:12px;font-size:13px;border:1px solid}
.kat-alert.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.fgrid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fgrid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
@media(max-width:640px){ .fgrid2,.fgrid3{grid-template-columns:1fr} .kat-page{padding:16px 14px 20px} }
.flabel{display:block}
.fk{font-size:10px;letter-spacing:.08em;font-weight:700;color:#334155}
.finput{margin-top:6px;width:100%;height:44px;padding:0 12px;border:1px solid #E0E5E3;border-radius:12px;background:#fff;font-size:13px;outline:none}
textarea.finput{height:auto}
.ferr{font-size:11px;color:#dc2626;margin-top:4px;display:block}
.scope-btn{flex:1;height:44px;border-radius:12px;border:1px solid #E0E5E3;background:#fff;font-size:13px;font-weight:600;cursor:pointer}
.scope-btn.on{background:#2F3E46;color:#fff;border-color:#2F3E46}
.chip-ekskul{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;font-size:12px;font-weight:600}
.kat-btn{padding:10px 18px;border-radius:999px;border:1px solid var(--m-cta);background:var(--m-cta);color:#fff;font-size:13px;font-weight:700;cursor:pointer}
.kat-btn:disabled{opacity:.5;cursor:not-allowed}
.kat-btn-ghost{padding:8px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--m-ink);text-decoration:none;display:inline-flex;align-items:center}
.kat-btn-ghost.neutral:hover{background:var(--m-bg)}
.kat-btn-ghost.sm{padding:6px 10px;font-size:11.5px}
.hidden{display:none}
.mono{font-family:'Satoshi',system-ui,sans-serif}
@keyframes sp{to{transform:translateY(-50%) rotate(360deg)}}
</style>

