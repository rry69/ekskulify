<template>
<div class="kat-page">
  <!-- header -->
  <div class="kat-header">
    <div>
      <h1 class="kat-title">Katalog Ekskul</h1>
      <p class="kat-sub">{{ total }} ekskul</p>
    </div>
    <router-link v-if="auth.user?.role==='admin'" to="/admin/ekskul" class="kat-link">Kelola (Admin View) →</router-link>
  </div>

  <!-- toolbar -->
  <div class="kat-toolbar">
    <div class="kat-toolbar-row">
      <div class="kat-search">
        <span class="kat-search-icon">⌕</span>
        <input v-model="search" placeholder="Cari PMR, Pramuka, Futsal..." class="kat-input" aria-label="Cari ekskul" />
      </div>
      <select v-model="statusFilter" class="kat-select" aria-label="Filter status">
        <option value="all">Status: Semua</option>
        <option value="approved">Approved</option>
        <option value="pending">Pending</option>
      </select>
      <select v-model="kuotaFilter" class="kat-select" aria-label="Filter kuota">
        <option value="all">Kuota: Semua</option>
        <option value="tersedia">Tersedia</option>
        <option value="hampir">Hampir penuh</option>
        <option value="penuh">Penuh</option>
      </select>
      <select v-model="sortFilter" class="kat-select" aria-label="Sort">
        <option value="baru">Terbaru</option>
        <option value="nama">Nama A–Z</option>
        <option value="sisa">Sisa kuota</option>
        <option value="isi">Terisi terbanyak</option>
      </select>
      <button v-if="auth.user?.role==='siswa'" :class="['kat-chip-btn', myFilter?'active':'']" @click="myFilter=!myFilter" :aria-pressed="myFilter?'true':'false'" aria-label="Filter Ekskul Saya">
        ♥ Ekskul Saya<span v-if="myCount>0" class="kat-chip-count">{{ myCount }}</span>
      </button>
      <div class="kat-toggle">
        <button :class="['kat-toggle-btn', mode==='grid'?'active':'']" @click="mode='grid'" aria-label="Grid view">▦ Grid</button>
        <button :class="['kat-toggle-btn', mode==='list'?'active':'']" @click="mode='list'" aria-label="List view">☰ List</button>
      </div>
    </div>
    <div class="kat-legend">
      <span class="legend-pill">Legend:</span>
      <span class="badge-legend emerald">Tersedia</span>
      <span class="badge-legend amber">Hampir penuh ≤3 sisa</span>
      <span class="badge-legend red">Penuh</span>
      <span class="badge-legend sky">Butuh approval</span>
      <span class="badge-legend zinc">Auto terima</span>
      <span v-if="auth.user?.role==='siswa'" class="badge-legend" style="background:#ecfdf5;color:#047857;border-color:#a7f3d0">● Diterima saya</span>
      <span v-if="auth.user?.role==='siswa'" class="badge-legend" style="background:#fffbeb;color:#b45309;border-color:#fde68a">● Menunggu saya</span>
    </div>
  </div>

  <!-- skeleton -->
  <div v-if="loading" :class="['kat-grid', mode]" aria-busy="true" aria-label="Memuat katalog">
    <div v-for="i in 6" :key="i" class="kat-card skeleton">
      <div class="skel-top"></div>
      <div class="kat-card-body">
        <div class="skel-line w40"></div>
        <div class="skel-line w80"></div>
        <div class="skel-line w90"></div>
        <div class="skel-chips"><span class="skel-chip"></span><span class="skel-chip"></span></div>
      </div>
    </div>
  </div>

  <!-- empty -->
  <div v-else-if="!filtered.length" class="kat-empty">
    <span v-if="myFilter">Belum ikut ekskul apapun · matikan filter Ekskul Saya</span>
    <span v-else>Tidak ada ekskul · ubah filter</span>
  </div>

  <!-- grid -->
  <div v-else :class="['kat-grid', mode]" v-memo="[filteredKey, mode, page]">
    <article v-for="e in paged" :key="e.id" class="kat-card" :class="e.my_status? 'mine-'+e.my_status : ''" role="article" :aria-label="`Ekskul ${e.nama} kuota ${e.terisi}/${e.kuota} ${kuotaMeta(e).label} ${e.my_status||''}`">
      <div class="kat-topbar" :class="kuotaMeta(e).bar"></div>
      <div class="kat-card-body">
        <div class="kat-badges">
          <span class="badge-status">{{ e.status }}</span>
          <span class="badge-kuota" :class="kuotaMeta(e).cls">{{ kuotaMeta(e).label }}</span>
          <span class="badge-need" :class="e.requires_approval ? 'need-sky':'need-zinc'">{{ e.requires_approval ? 'Butuh approval' : 'Auto terima' }}</span>
          <span v-if="e.my_status==='diterima'" class="badge-mine diterima">● Diterima</span>
          <span v-else-if="e.my_status==='menunggu'" class="badge-mine menunggu">● Menunggu</span>
          <span v-else-if="e.my_status==='ditolak'" class="badge-mine ditolak">● Ditolak</span>
        </div>
        <div class="kat-title-row">
          <span class="kat-card-title">{{ e.nama }}</span>
          <span class="kat-card-kuota">({{ e.terisi }}/{{ e.kuota }})</span>
        </div>
        <div class="kat-desc" :class="isDeskEmpty(e) ? 'muted italic' : ''">{{ displayDeskripsi(e) }}</div>
        <div class="kat-chips">
          <span v-if="e.hari" class="chip-hari has">{{ e.hari }}</span>
          <span v-else class="chip-hari empty">Belum dijadwalkan</span>
          <span v-if="hasJam(e)" class="chip-jam has mono">{{ formatJam(e) }}</span>
          <span v-else class="chip-jam empty mono">Jam —</span>
          <span v-if="e.lokasi" class="chip-lok has">📍 {{ e.lokasi }}</span>
          <span v-else class="chip-lok empty">Lokasi —</span>
        </div>
        <div class="kat-pembina">
          <span class="kat-avatar">{{ initials(e.pembina_nama) }}</span>
          <span>Pembina: <b>{{ e.pembina_nama || '—' }}</b></span>
        </div>
        <div class="kat-kuota">
          <div class="kat-kuota-row"><span>Kuota</span><span class="mono">{{ e.terisi }}/{{ e.kuota }} · {{ sisa(e) }} sisa</span></div>
          <div class="kat-progress"><div class="kat-progress-bar" :class="kuotaMeta(e).bar" :style="{width: kuotaMeta(e).pct + '%'}"></div></div>
        </div>
      </div>
      <div class="kat-cta">
        <router-link :to="`/ekskul/${e.id}`" class="kat-btn" :aria-describedby="`k-desc-${e.id}`">Detail →</router-link>
        <button v-if="e.my_status==='menunggu' || e.my_status==='diterima'" class="kat-btn-ghost" @click="batal(e)" :aria-label="`${e.my_status==='diterima'?'Lepas':'Batal'} pendaftaran ${e.nama}`">{{ e.my_status==='diterima' ? 'Lepas Ekskul' : 'Batal Daftar' }}</button>
      </div>
      <span :id="`k-desc-${e.id}`" class="visually-hidden">Kuota {{ e.terisi }} dari {{ e.kuota }}</span>
    </article>
  </div>

  <!-- pagination -->
  <div v-if="total>0 || filtered.length>0" class="kat-paging">
    <span class="kat-count">Menampilkan {{ pagingStart }}–{{ pagingEnd }} dari {{ filteredTotal }}<span v-if="filteredTotal!==total" class="text-muted"> (total {{ total }})</span></span>
    <div class="kat-paging-btns">
      <button class="kat-page-btn" :disabled="page<=1" @click="goPage(page-1)">Prev</button>
      <span class="kat-page-num mono">{{ page }} / {{ pages }}</span>
      <button class="kat-page-btn" :disabled="page>=pages" @click="goPage(page+1)">Next</button>
    </div>
  </div>
  <div v-if="msg" class="kat-alert" :class="ok?'ok':'err'">{{ msg }}</div>
</div>
</template>
<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useAuth } from '../stores/auth.js'
import { api } from '../lib/api.js'
const auth=useAuth()
const list=ref([])
const loading=ref(false)
const total=ref(0)
const page=ref(1)
const search=ref('')
const statusFilter=ref('all')
const kuotaFilter=ref('all')
const sortFilter=ref('baru')
const mode=ref('grid')
const msg=ref('')
const ok=ref(false)
let debounceTimer=null

function sisa(e){ const s=(e.kuota||0)-(e.terisi||0); return s<0?0:s }
function kuotaMeta(e){
  const s=sisa(e); const t=e.kuota||1; const terisi=e.terisi||0
  const pct = t? Math.round(terisi/t*100):0
  if(s===0) return {label:'Penuh',cls:'red',bar:'bg-red',pct:100}
  if(s<=3) return {label:'Hampir penuh',cls:'amber',bar:'bg-amber',pct}
  return {label:'Tersedia',cls:'emerald',bar:'bg-emerald',pct}
}
function isDeskEmpty(e){
  const d=(e.deskripsi||'').trim()
  if(!d) return true
  if(d==='Deskripsi belum diisi') return true
  if(d.length<10) return true
  if(/belum diisi/i.test(d)) return true
  return false
}
function displayDeskripsi(e){
  const d=(e.deskripsi||'').trim()
  if(!d) return 'Deskripsi belum diisi'
  if(d.length<10) return d
  return d
}
function hasJam(e){ return !!(e.jam_mulai && e.jam_selesai) || !!(e.jam_mulai||'').trim() || !!(e.jam_selesai||'').trim() }
function formatJam(e){
  const a=(e.jam_mulai||'').slice(0,5), b=(e.jam_selesai||'').slice(0,5)
  if(!a && !b) return ''
  return `${a||'—'}–${b||'—'}`
}
function initials(n){
  if(!n) return '?'
  return n.split(' ').map(s=>s[0]).join('').slice(0,2).toUpperCase()
}

const myFilter=ref(false)
const myCount=computed(()=> list.value.filter(x=> x.my_status).length)
const filtered=computed(()=>{
  let arr=[...list.value]
  if(myFilter.value) arr=arr.filter(x=> !!x.my_status)
  const q=search.value.trim().toLowerCase()
  if(q) arr=arr.filter(x=> (`${x.nama} ${x.deskripsi||''} ${x.pembina_nama||''}`).toLowerCase().includes(q))
  if(statusFilter.value!=='all') arr=arr.filter(x=> x.status===statusFilter.value)
  if(kuotaFilter.value==='penuh') arr=arr.filter(x=> sisa(x)===0)
  else if(kuotaFilter.value==='tersedia') arr=arr.filter(x=> sisa(x)>3)
  else if(kuotaFilter.value==='hampir') arr=arr.filter(x=> {const s=sisa(x); return s>0 && s<=3})
  // sort: my_status first when myFilter off, else keep
  if(!myFilter.value) arr.sort((a,b)=> (b.my_status?1:0)-(a.my_status?1:0))
  if(sortFilter.value==='nama') arr.sort((a,b)=> a.nama.localeCompare(b.nama,'id'))
  else if(sortFilter.value==='sisa') arr.sort((a,b)=> sisa(a)-sisa(b))
  else if(sortFilter.value==='isi') arr.sort((a,b)=> (b.terisi||0)-(a.terisi||0))
  return arr
})
const filteredTotal=computed(()=> filtered.value.length)
const filteredKey=computed(()=> filtered.value.map(x=>x.id).join(',') + `|${search.value}|${statusFilter.value}|${kuotaFilter.value}|${sortFilter.value}|${myFilter.value}`)
// Full Client pagination — 12 per page, no server paging
const pageSize=12
const pages=computed(()=> Math.max(1, Math.ceil(filteredTotal.value / pageSize)))
const paged=computed(()=> filtered.value.slice((page.value-1)*pageSize, page.value*pageSize))
const pagingStart=computed(()=> filteredTotal.value ? (page.value-1)*pageSize+1 : 0)
const pagingEnd=computed(()=> Math.min(page.value*pageSize, filteredTotal.value))

async function load(){
  loading.value=true
  try{
    // Full Client: fetch all (limit 100) tanpa paging server, filter/sort di client
    const j=await api('/ekskul?limit=100&page=1')
    list.value=j.data||[]
    total.value=j.meta?.total||0
  }catch(e){
    list.value=[]
  }finally{ loading.value=false }
}
async function batal(e){
  const isDiterima = e.my_status==='diterima'
  const q = isDiterima ? `Lepas ${e.nama}? Sisa kuota kembali` : `Batalkan ${e.nama}?`
  if(!confirm(q)) return
  try{
    await api(`/ekskul/${e.id}/batal`,{method:'POST'})
    msg.value = isDiterima ? 'Berhasil lepas' : 'Pendaftaran dibatalkan'; ok.value=true
    e.my_status=null
    if(typeof e.terisi==='number') e.terisi = Math.max(0, e.terisi - 1)
  }catch(err){
    const code=err?.error?.code||err?.code
    const m=err?.error?.message||err?.message||'Gagal batal'
    if(code==='NEED_ADMIN'){
      msg.value='Sudah berjalan, hubungi pembina'; ok.value=false
    } else {
      msg.value=m; ok.value=false
    }
  }
}
function goPage(p){
  const np=Math.max(1, Math.min(p, pages.value))
  page.value=np
}

watch([search, statusFilter, kuotaFilter, sortFilter], ()=>{
  clearTimeout(debounceTimer)
  debounceTimer=setTimeout(()=>{ page.value=1 },300)
})
watch(myFilter, ()=>{ page.value=1 })
onMounted(load)
</script>
<style scoped>
.kat-page{max-width:1120px;margin:0 auto;padding:0 16px 24px}
.kat-page-btn{min-width:32px;min-height:32px}
.kat-header{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;margin-bottom:12px;padding-top:8px}
.kat-title{font-size:22px;font-weight:700;letter-spacing:-0.02em;margin:0}
.kat-sub{font-size:13px;color:#71717a;margin-top:4px}
.kat-link{padding:8px 14px;border-radius:12px;border:1px solid #e4e4e7;background:#fff;font-size:13px;font-weight:500;text-decoration:none;color:#18181b}
.kat-link:hover{background:#fafafa}
.kat-toolbar{background:#fff;border:1px solid #e4e4e7;border-radius:16px;padding:12px}
.kat-toolbar-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.kat-search{position:relative;flex:1;min-width:220px}
.kat-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#a1a1aa}
.kat-input{width:100%;padding:10px 12px 10px 36px;border:1px solid #e4e4e7;background:#fafafa;border-radius:12px;font-size:13px;outline:none}
.kat-input:focus{background:#fff;border-color:#d4d4d8;box-shadow:0 0 0 2px rgba(24,24,27,.08)}
.kat-select{padding:10px 12px;border:1px solid #e4e4e7;background:#fff;border-radius:12px;font-size:13px}
.kat-toggle{display:flex;border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;margin-left:auto}
.kat-toggle-btn{padding:8px 12px;background:#fff;border:none;font-size:13px;cursor:pointer;color:#3f3f46}
.kat-toggle-btn.active{background:#18181b;color:#fff}
.kat-legend{margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;font-size:11px}
.legend-pill{padding:4px 10px;border-radius:999px;background:#fff;border:1px solid #e4e4e7}
.badge-legend{padding:4px 10px;border-radius:999px;border:1px solid}
.badge-legend.emerald{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.badge-legend.amber{background:#fffbeb;color:#b45309;border-color:#fde68a}
.badge-legend.red{background:#fef2f2;color:#dc2626;border-color:#fecaca}
.badge-legend.sky{background:#f0f9ff;color:#0369a1;border-color:#bae6fd}
.badge-legend.zinc{background:#fafafa;color:#52525b;border-color:#e4e4e7}
.kat-grid{margin-top:16px;display:grid;gap:16px}
.kat-grid.grid{grid-template-columns:1fr}
@media(min-width:640px){ .kat-grid.grid{grid-template-columns:repeat(2,1fr)} }
@media(min-width:1024px){ .kat-grid.grid{grid-template-columns:repeat(3,1fr)} }
.kat-grid.list{grid-template-columns:1fr}
.kat-card{background:#fff;border:1px solid #e4e4e7;border-radius:16px;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .15s,border-color .15s}
.kat-card:hover{box-shadow:0 6px 18px rgba(0,0,0,.06);border-color:#d4d4d8}
.kat-card.skeleton{opacity:.7}
.kat-topbar{height:4px}
.kat-topbar.bg-emerald{background:#10b981}
.kat-topbar.bg-amber{background:#f59e0b}
.kat-topbar.bg-red{background:#ef4444}
.kat-card-body{padding:16px;flex:1}
.kat-badges{display:flex;flex-wrap:wrap;gap:6px}
.badge-status{font-size:11px;padding:4px 8px;border-radius:999px;background:#059669;color:#fff;font-weight:500}
.badge-kuota{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid;font-weight:500}
.badge-kuota.emerald{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.badge-kuota.amber{background:#fffbeb;color:#b45309;border-color:#fde68a}
.badge-kuota.red{background:#fef2f2;color:#dc2626;border-color:#fecaca}
.badge-need{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid}
.badge-need.need-sky{background:#f0f9ff;color:#0369a1;border-color:#bae6fd}
.badge-need.need-zinc{background:#fafafa;color:#52525b;border-color:#e4e4e7}
.kat-title-row{margin-top:12px;display:flex;align-items:baseline;gap:6px}
.kat-card-title{font-size:15px;font-weight:700;line-height:1.2}
.kat-card-kuota{font-size:13px;color:#71717a;font-weight:400}
.kat-desc{font-size:12px;margin-top:6px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:36px}
.kat-desc.muted{color:#a1a1aa;font-style:italic}
.kat-desc:not(.muted){color:#52525b}
.kat-chips{margin-top:12px;display:flex;flex-wrap:wrap;gap:6px}
.chip-hari.has{font-size:11px;padding:4px 8px;border-radius:999px;background:#18181b;color:#fff}
.chip-hari.empty{font-size:11px;padding:4px 8px;border-radius:999px;background:#f4f4f5;color:#a1a1aa;border:1px dashed #d4d4d8}
.chip-jam{font-size:11px;padding:4px 8px;border-radius:999px;background:#fff;border:1px solid #e4e4e7}
.chip-jam.empty{color:#a1a1aa;border-style:dashed}
.chip-lok{font-size:11px;padding:4px 8px;border-radius:999px;background:#fff;border:1px solid #e4e4e7}
.chip-lok.empty{color:#a1a1aa;border-style:dashed}
.mono{font-family:JetBrains Mono,ui-monospace,monospace}
.kat-pembina{margin-top:12px;display:flex;align-items:center;gap:8px;font-size:12px;color:#52525b}
.kat-avatar{width:28px;height:28px;border-radius:999px;background:#18181b;color:#fff;display:grid;place-items:center;font-size:11px;font-weight:700;flex-shrink:0}
.kat-kuota-row{display:flex;justify-content:space-between;font-size:11px;color:#71717a}
.kat-progress{margin-top:6px;height:6px;border-radius:999px;background:#f4f4f5;overflow:hidden}
.kat-progress-bar{height:100%;border-radius:999px}
.kat-progress-bar.bg-emerald{background:#10b981}
.kat-progress-bar.bg-amber{background:#f59e0b}
.kat-progress-bar.bg-red{background:#ef4444}
.kat-empty{margin-top:16px;background:#fff;border:1px dashed #d4d4d8;border-radius:16px;padding:40px;text-align:center;color:#71717a;font-size:13px}
.kat-chip-btn{padding:8px 12px;border-radius:999px;border:1px solid #e4e4e7;background:#fff;font-size:13px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.kat-chip-btn.active{background:#18181b;color:#fff;border-color:#18181b}
.kat-chip-count{background:rgba(0,0,0,.08);padding:2px 6px;border-radius:999px;font-size:11px;min-width:18px;text-align:center}
.kat-chip-btn.active .kat-chip-count{background:rgba(255,255,255,.2)}
.badge-mine{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid;font-weight:500}
.badge-mine.diterima{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.badge-mine.menunggu{background:#fffbeb;color:#b45309;border-color:#fde68a}
.badge-mine.ditolak{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.kat-card.mine-diterima{border-color:#a7f3d0}
.kat-card.mine-menunggu{border-color:#fde68a}
.kat-cta{padding:0 16px 16px;display:flex;gap:8px}
.kat-cta .kat-btn{flex:1;display:block;width:100%;text-align:center;padding:10px 16px;border-radius:12px;background:#18181b;color:#fff;font-size:13px;font-weight:600;text-decoration:none}
.kat-cta .kat-btn:hover{background:#000}
.kat-btn-ghost{padding:10px 12px;border-radius:12px;border:1px solid #fecaca;background:#fff;color:#991b1b;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}
.kat-btn-ghost:hover{background:#fef2f2}
.kat-paging{margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:13px}
.kat-count{color:#71717a}
.kat-paging-btns{display:flex;gap:8px;align-items:center}
.kat-page-btn{padding:6px 12px;border-radius:8px;border:1px solid #e4e4e7;background:#fff;font-size:13px}
.kat-page-btn:disabled{opacity:.4;cursor:not-allowed}
.kat-page-num{padding:6px 10px;border:1px solid #e4e4e7;background:#fff;border-radius:8px;font-size:12px}
.skel-line{height:10px;background:#f4f4f5;border-radius:6px;margin-bottom:8px}
.skel-line.w40{width:40%}.skel-line.w80{width:80%}.skel-line.w90{width:90%}
.skel-chips{display:flex;gap:6px;margin-top:10px}
.skel-chip{width:60px;height:18px;background:#f4f4f5;border-radius:999px}
.skel-top{height:4px;background:#f4f4f5}
.visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
.kat-alert{margin-top:12px;padding:10px 12px;border-radius:10px;font-size:13px}
.kat-alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.kat-alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
</style>
