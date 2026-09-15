<template>

<div class="ap-page">

 <div class="ap-shell">

 <!-- Header -->

 <div class="ap-header">

 <div>

 <h1 class="display ap-title" :title="`${pendingTotal} pending - ${approvedTotal} approved - ${rejectedTotal} rejected - ${total} total`">Approval Kepsek</h1>

 </div>

 <div class="ap-header-cta">

 <button class="ap-btn-ghost" @click="doExport" :disabled="exporting" aria-label="Export CSV">

 <span v-if="exporting" class="ap-spinner-sm"></span>

 <span v-else></span> Export CSV

 </button>

 <button class="ap-btn-ink" @click="reload" aria-label="Muat ulang"> Reload</button>

 </div>

 </div>



 <!-- Filter + Tabs Bar sticky -->

 <div class="ap-filter-wrap">

 <div class="ap-tabs" role="tablist" aria-label="Tipe approval">

 <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="activeTab===t.key" class="ap-tab" :class="{active: activeTab===t.key}" @click="activeTab=t.key" :title="t.count+' item'">

 {{ t.label }}

 </button>

 </div>

 <div class="ap-filter">

 <label class="ap-field ap-field-search">

 <span class="mono ap-label">SEARCH</span>

 <div class="ap-input-wrap">

 <span class="ap-search-icon" aria-hidden="true"></span>

 <input v-model="search" placeholder="Cari nama, pembina, lokasi..." class="ap-input ap-input-search" aria-label="Cari approval" />

 </div>

 </label>

 <label class="ap-field">

 <span class="mono ap-label">STATUS</span>

 <select v-model="statusFilter" class="ap-input ap-select" aria-label="Filter status">

 <option value="pending">Pending</option>

 <option value="approved">Approved</option>

 <option value="rejected">Rejected</option>

 <option value="all">Semua</option>

 </select>

 </label>

 <label class="ap-field">

 <span class="mono ap-label">SORT</span>

 <select v-model="sortFilter" class="ap-input ap-select" aria-label="Sort">

 <option value="updated_desc">Terbaru update</option>

 <option value="created_desc">Terbaru dibuat</option>

 <option value="nama_asc">Nama A-Z</option>

 </select>

 </label>

 <button class="ap-reset" @click="clearFilters">Reset</button>

 </div>

 <div class="ap-filter-meta">

 <span v-if="loading" class="ap-spinner" aria-hidden="true"></span>

 <label v-if="filtered.length" class="ap-select-all mono"><input type="checkbox" :checked="allSelected" @change="toggleAll" /> Pilih semua</label>

 </div>

 </div>



 <!-- Toast -->

 <div v-if="msg" class="ap-toast" :class="ok?'ap-toast-ok':'ap-toast-err'" role="alert" aria-live="polite">{{ msg }}</div>



 <!-- Batch bar -->

 <div v-if="selectedIds.size" class="ap-batch">

 <span class="mono ap-batch-count">{{ selectedIds.size }} terpilih</span>

 <div class="ap-batch-actions">

 <button class="ap-btn-ghost ap-btn-sm" @click="clearSelection">Batal</button>

 <button class="ap-btn-ink ap-btn-sm" @click="batchAct('approve')" :disabled="batching">Approve {{ selectedIds.size }}</button>

 <button class="ap-btn-rose ap-btn-sm" @click="openBatchReject" :disabled="batching">Reject</button>

 </div>

 </div>



 <!-- Card Table -->

 <div class="ap-card">

 <div v-if="loading" class="ap-skeleton" aria-busy="true">

 <div v-for="i in 5" :key="i" class="ap-skeleton-row">

 <div class="ap-skeleton-line w-6"></div>

 <div class="ap-skeleton-line w-32"></div>

 <div class="ap-skeleton-line w-20"></div>

 <div class="ap-skeleton-line w-16"></div>

 <div class="ap-skeleton-line w-24"></div>

 <div class="ap-skeleton-line w-20"></div>

 </div>

 </div>



 <div v-else-if="!filtered.length" class="ap-empty">

 <div class="ap-empty-illus" aria-hidden="true"><div class="ap-empty-icon"></div></div>

 <h2 class="ap-empty-title">{{ emptyTitle }}</h2>

 <p class="ap-empty-desc">{{ emptyDesc }}</p>

 </div>



 <div v-else class="ap-table-wrap">

 <table class="ap-table">

 <thead>

 <tr class="mono">

 <th style="width:36px"><input type="checkbox" :checked="allSelected" @change="toggleAll" aria-label="Pilih semua" /></th>

 <th>TIPE</th>

 <th>NAMA</th>

 <th>PEMBINA / CREATOR</th>

 <th>INFO</th>

 <th>STATUS</th>

 <th class="text-right">AKSI</th>

 </tr>

 </thead>

 <tbody>

 <tr v-for="row in paged" :key="row._key" :class="{'row-pending': row.status==='pending'}">

 <td><input type="checkbox" :checked="selectedIds.has(row._key)" @change="toggleOne(row._key)" :aria-label="`Pilih ${row.nama}`" :disabled="row.status!=='pending'" /></td>

 <td><span class="ap-chip mono"  :class="row._type=='ekskul'?'ap-chip-sky':'ap-chip-emerald'">{{ row._type==='ekskul'?'Ekskul':'Event' }}</span></td>

 <td>

 <div class="ap-name-cell">

 <button class="ap-name-btn" @click="openDetail(row)">{{ row.nama }}</button>

 <div class="mono ap-sub">{{ row._type==='event' ? formatTanggal(row.tanggal) : (row.hari||'-') + ' - ' + (row.jam_mulai?row.jam_mulai.slice(0,5):'-') }}</div>

 </div>

 </td>

 <td><span class="ap-creator">{{ row.pembina_nama || row.creator || '-' }}</span></td>

 <td class="mono ap-info">

 <span v-if="row._type==='event'"> {{ row.lokasi||'-' }} - {{ row.kuota }} kuota</span>

 <span v-else> {{ row.lokasi||'-' }} - {{ row.kuota }} kuota</span>

 </td>

 <td><span class="ap-status" :class="statusClass(row.status)"><span class="ap-dot" :class="dotClass(row.status)"></span>{{ row.status }}</span></td>

 <td class="text-right">

 <div class="ap-actions">

 <button class="ap-icon-btn ap-icon-detail" @click="openDetail(row)" :aria-label="`Detail ${row.nama}`" title="Detail"></button>

 <button v-if="row.status==='pending'" class="ap-icon-btn ap-icon-approve" @click="askApprove(row)" :aria-label="`Approve ${row.nama}`" title="Approve">v</button>

 <button v-if="row.status==='pending'" class="ap-icon-btn ap-icon-reject" @click="askReject(row)" :aria-label="`Reject ${row.nama}`" title="Reject">x</button>

 <span v-else class="mono ap-done">-</span>

 </div>

 </td>

 </tr>

 </tbody>

 </table>

 </div>



 <!-- Pagination -->

 <div v-if="!loading && filtered.length" class="ap-pagination">

 <div class="ap-pagination-ctrls">

 <button class="ap-page-btn" :disabled="page<=1" @click="page--" aria-label="Prev"><</button>

 <span class="mono ap-page-pill">{{ page }} / {{ pages }}</span>

 <button class="ap-page-btn" :disabled="page>=pages" @click="page++" aria-label="Next">></button>

 </div>

 </div>

 </div>



 <div class="ap-footnote mono" style="display:none">Hanya Kepsek</div>

 </div>



 <!-- Detail Drawer -->

 <Teleport to="body">

 <div v-if="showDetail" class="ap-overlay" role="dialog" aria-modal="true" aria-label="Detail approval" @click.self="showDetail=false">

 <div class="ap-overlay-bg" @click="showDetail=false"></div>

 <div class="ap-drawer">

 <div class="ap-drawer-head">

 <div>

 <div class="mono ap-drawer-kicker">{{ detailRow?._type==='ekskul'?'Ekskul':'Event' }} - {{ detailRow?.status }}</div>

 <h2 class="display ap-drawer-title">{{ detailRow?.nama }}</h2>

 <div class="mono ap-drawer-sub">{{ detailRow?.pembina_nama || detailRow?.creator || '-' }} - {{ detailRow?._type==='event' ? formatTanggal(detailRow?.tanggal) : detailRow?.hari }}</div>

 </div>

 <button class="ap-close" @click="showDetail=false" aria-label="Tutup">x</button>

 </div>

 <div class="ap-drawer-body">

 <div class="ap-detail-grid">

 <div><span class="mono ap-label">Deskripsi</span><p class="ap-detail-text">{{ detailRow?.deskripsi || '-' }}</p></div>

 <div><span class="mono ap-label">Lokasi</span><p class="ap-detail-text">{{ detailRow?.lokasi || '-' }}</p></div>

 <div v-if="detailRow?._type==='ekskul'"><span class="mono ap-label">Jadwal</span><p class="ap-detail-text">{{ detailRow?.hari }} {{ detailRow?.jam_mulai }}- {{ detailRow?.jam_selesai }}</p></div>

 <div v-if="detailRow?._type==='event'"><span class="mono ap-label">Waktu</span><p class="ap-detail-text">{{ detailRow?.waktu }} {{ detailRow?.waktu_selesai ? '-> '+detailRow.waktu_selesai : '' }} - {{ detailRow?.kuota }} kuota</p></div>

 <div><span class="mono ap-label">Dibuat</span><p class="mono ap-detail-text">{{ detailRow?.created_at || '-' }}</p></div>

 <div v-if="detailRow?.rejected_reason"><span class="mono ap-label">Alasan reject</span><p class="ap-detail-text ap-reject-text">{{ detailRow.rejected_reason }}</p></div>

 </div>

 </div>

 <div class="ap-drawer-foot" v-if="detailRow?.status==='pending'">

 <button class="ap-btn-ghost" @click="showDetail=false">Tutup</button>

 <button class="ap-btn-rose" @click="askReject(detailRow)">Reject</button>

 <button class="ap-btn-ink" @click="askApprove(detailRow)">Approve</button>

 </div>

 <div v-else class="ap-drawer-foot">

 <button class="ap-btn-ghost" @click="showDetail=false">Tutup</button>

 </div>

 </div>

 </div>

 </Teleport>



 <!-- Confirm Approve -->

 <Teleport to="body">

 <div v-if="showApprove" class="ap-overlay ap-overlay-confirm" role="dialog" aria-modal="true" aria-label="Konfirmasi approve" @click.self="showApprove=false">

 <div class="ap-overlay-bg" @click="showApprove=false"></div>

 <div class="ap-confirm-card">

 <div class="ap-confirm-icon ap-confirm-icon-ok" aria-hidden="true">v</div>

 <h2 class="ap-confirm-title">Approve {{ targetRow?._type==='ekskul'?'Ekskul':'Event' }}?</h2>

 <p class="ap-confirm-body">Yakin approve <b>{{ targetRow?.nama }}</b>?</p>

 <div class="ap-confirm-foot">

 <button class="ap-btn-ghost" @click="showApprove=false" :disabled="acting">Batal</button>

 <button class="ap-btn-ink" @click="doApprove" :disabled="acting"><span v-if="acting" class="ap-btn-spinner"></span>{{ acting?'Memproses...':'Approve' }}</button>

 </div>

 </div>

 </div>

 </Teleport>



 <!-- Reject with reason -->

 <Teleport to="body">

 <div v-if="showReject" class="ap-overlay ap-overlay-confirm" role="dialog" aria-modal="true" aria-label="Konfirmasi reject" @click.self="showReject=false">

 <div class="ap-overlay-bg" @click="showReject=false"></div>

 <div class="ap-confirm-card ap-confirm-card-lg">

 <div class="ap-confirm-icon ap-confirm-icon-rose" aria-hidden="true">x</div>

 <h2 class="ap-confirm-title">Reject {{ targetRow?._type==='ekskul'?'Ekskul':'Event' }}?</h2>

 <p class="ap-confirm-body">Tolak <b>{{ targetRow?.nama }}</b> - wajib isi alasan (min 10 karakter).</p>

 <label class="ap-field mt-12">

 <span class="mono ap-label">ALASAN REJECT <span class="ap-req">*</span></span>

 <textarea v-model="rejectReason" rows="3" placeholder="Alasan penolakan (min 10 karakter)..." class="ap-input ap-textarea" :class="{'is-invalid': rejectErr}" aria-label="Alasan reject"></textarea>

 <span v-if="rejectErr" class="ap-helper ap-helper-err">{{ rejectErr }}</span>

 <span v-else class="ap-helper mono">{{ rejectReason.length }}/10 - {{ rejectReason.length>=10 ? 'siap' : 'kurang '+(10-rejectReason.length) }}</span>

 </label>

 <div class="ap-confirm-foot">

 <button class="ap-btn-ghost" @click="showReject=false" :disabled="acting">Batal</button>

 <button class="ap-btn-rose" @click="doReject" :disabled="acting || rejectReason.trim().length<10"><span v-if="acting" class="ap-btn-spinner ap-btn-spinner--light"></span>{{ acting?'Menolak...':'Reject' }}</button>

 </div>

 </div>

 </div>

 </Teleport>



 <!-- Batch Reject -->

 <Teleport to="body">

 <div v-if="showBatchReject" class="ap-overlay ap-overlay-confirm" role="dialog" aria-modal="true" aria-label="Batch reject" @click.self="showBatchReject=false">

 <div class="ap-overlay-bg" @click="showBatchReject=false"></div>

 <div class="ap-confirm-card ap-confirm-card-lg">

 <div class="ap-confirm-icon ap-confirm-icon-rose" aria-hidden="true">x</div>

 <h2 class="ap-confirm-title">Reject {{ selectedIds.size }} item?</h2>

 <p class="ap-confirm-body">Semua item pending terpilih akan di-reject dengan alasan yang sama.</p>

 <label class="ap-field mt-12">

 <span class="mono ap-label">ALASAN REJECT BATCH <span class="ap-req">*</span></span>

 <textarea v-model="batchReason" rows="3" placeholder="Alasan batch (min 10 karakter)..." class="ap-input ap-textarea" :class="{'is-invalid': batchErr}" aria-label="Alasan batch reject"></textarea>

 <span v-if="batchErr" class="ap-helper ap-helper-err">{{ batchErr }}</span>

 <span v-else class="ap-helper mono">{{ batchReason.length }}/10</span>

 </label>

 <div class="ap-confirm-foot">

 <button class="ap-btn-ghost" @click="showBatchReject=false" :disabled="batching">Batal</button>

 <button class="ap-btn-rose" @click="doBatchReject" :disabled="batching || batchReason.trim().length<10"><span v-if="batching" class="ap-btn-spinner ap-btn-spinner--light"></span>{{ batching?'Memproses...':'Reject '+selectedIds.size }}</button>

 </div>

 </div>

 </div>

 </Teleport>

</div>

</template>

<script setup>

import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

import { useRouter, useRoute } from 'vue-router'

import { api } from '../lib/api.js'

const router=useRouter(), route=useRoute()

const search=ref(''), statusFilter=ref('pending'), sortFilter=ref('updated_desc'), activeTab=ref('all')

const loading=ref(false), msg=ref(''), ok=ref(false), acting=ref(false), batching=ref(false), exporting=ref(false)

const ekskulList=ref([]), eventList=ref([])

const page=ref(1), pageSize=20

const showDetail=ref(false), detailRow=ref(null)

const showApprove=ref(false), showReject=ref(false), showBatchReject=ref(false)

const targetRow=ref(null), rejectReason=ref(''), rejectErr=ref(''), batchReason=ref(''), batchErr=ref('')

const selectedIds=ref(new Set())

let debounce=null

const tabs=computed(()=>[

 {key:'all', label:'Semua', count: allRows.value.length},

 {key:'ekskul', label:'Ekskul', count: ekskulList.value.length},

 {key:'event', label:'Event', count: eventList.value.length},

])

const allRows=computed(()=>[

 ...ekskulList.value.map(e=>({...e,_type:'ekskul',_key:'ekskul-'+e.id})),

 ...eventList.value.map(e=>({...e,_type:'event',_key:'event-'+e.id}))

])

const total=computed(()=> allRows.value.length)

const pendingTotal=computed(()=> allRows.value.filter(r=>r.status==='pending').length)

const approvedTotal=computed(()=> allRows.value.filter(r=>r.status==='approved').length)

const rejectedTotal=computed(()=> allRows.value.filter(r=>r.status==='rejected').length)

const filtered=computed(()=>{

 let rows=[...allRows.value]

 if(activeTab.value==='ekskul') rows=rows.filter(r=>r._type==='ekskul')

 else if(activeTab.value==='event') rows=rows.filter(r=>r._type==='event')

 if(statusFilter.value!=='all') rows=rows.filter(r=>r.status===statusFilter.value)

 if(search.value.trim()){

 const q=search.value.trim().toLowerCase()

 rows=rows.filter(r=> (r.nama||'').toLowerCase().includes(q) || (r.pembina_nama||r.creator||'').toLowerCase().includes(q) || (r.lokasi||'').toLowerCase().includes(q))

 }

 if(sortFilter.value==='nama_asc') rows.sort((a,b)=> String(a.nama).localeCompare(String(b.nama)))

 else if(sortFilter.value==='created_desc') rows.sort((a,b)=> String(b.created_at||'').localeCompare(String(a.created_at||'')))

 else rows.sort((a,b)=> String(b.updated_at||b.created_at||'').localeCompare(String(a.updated_at||a.created_at||'')))

 return rows

})

const pages=computed(()=> Math.max(1, Math.ceil(filtered.value.length / pageSize)))

const paged=computed(()=> filtered.value.slice((page.value-1)*pageSize, page.value*pageSize))

const pageInfo=computed(()=>{

 const s=(page.value-1)*pageSize+1, e=Math.min(page.value*pageSize, filtered.value.length)

 return filtered.value.length? `${s}-${e}` : '0'

})

const allSelected=computed(()=> paged.value.length>0 && paged.value.filter(r=>r.status==='pending').every(r=> selectedIds.value.has(r._key)))

const emptyTitle=computed(()=>{

 if(statusFilter.value==='pending') return 'Tidak ada pending'

 if(!allRows.value.length) return 'Belum ada pengajuan'

 return 'Tidak ada hasil'

})

const emptyDesc=computed(()=>{

 if(statusFilter.value==='pending') return 'Semua sudah di-ACC.'

 return 'Ubah filter / pencarian.'

})

function statusClass(s){ if(s==='approved') return 'ap-status-approved'; if(s==='rejected') return 'ap-status-rejected'; return 'ap-status-pending' }

function dotClass(s){ if(s==='approved') return 'dot-emerald'; if(s==='rejected') return 'dot-rose'; return 'dot-amber' }

function formatTanggal(t){ if(!t) return '-'; const p=String(t).split('-'); if(p.length!==3) return t; const m=['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']; return `${p[2]} ${m[parseInt(p[1],10)]||p[1]}` }

function clearFilters(){ search.value=''; statusFilter.value='pending'; sortFilter.value='updated_desc'; activeTab.value='all'; page.value=1 }

function clearSelection(){ selectedIds.value=new Set() }

function toggleOne(k){ const s=new Set(selectedIds.value); if(s.has(k)) s.delete(k); else s.add(k); selectedIds.value=s }

function toggleAll(){

 const pendingKeys=paged.value.filter(r=>r.status==='pending').map(r=>r._key)

 const allSel=pendingKeys.every(k=> selectedIds.value.has(k))

 const s=new Set(selectedIds.value)

 if(allSel) pendingKeys.forEach(k=> s.delete(k))

 else pendingKeys.forEach(k=> s.add(k))

 selectedIds.value=s

}

function openDetail(row){ detailRow.value=row; showDetail.value=true }

function askApprove(row){ targetRow.value=row; showApprove.value=true }

function askReject(row){ targetRow.value=row; rejectReason.value=''; rejectErr.value=''; showReject.value=true; if(showDetail.value) showDetail.value=false }

function openBatchReject(){ batchReason.value=''; batchErr.value=''; showBatchReject.value=true }

async function load(){

 loading.value=true

 try{

 const st=statusFilter.value==='all' ? '' : statusFilter.value

 const ekQ=new URLSearchParams(); if(st) ekQ.set('status',st); ekQ.set('limit','100'); if(search.value) ekQ.set('search',search.value)

 const evQ=new URLSearchParams(); if(st) evQ.set('status',st); evQ.set('limit','100'); if(search.value) evQ.set('search',search.value)

 const [ek,ev]=await Promise.all([

 api('/ekskul?'+ekQ.toString()).catch(()=>({data:[]})),

 api('/events?'+evQ.toString()).catch(()=>({data:[]})),

 ])

 ekskulList.value=Array.isArray(ek.data)? ek.data : []

 eventList.value=Array.isArray(ev.data)? ev.data : []

 }catch(e){ /* keep */ }

 finally{ loading.value=false }

}

async function reload(){ await load(); ok.value=true; msg.value='Data dimuat ulang'; setTimeout(()=>msg.value='',1800) }

function toast(m, isOk=true){ ok.value=isOk; msg.value=m; setTimeout(()=>msg.value='',2200) }

async function doApprove(){

 if(!targetRow.value) return

 acting.value=true

 try{

 const r=targetRow.value

 const path=r._type==='ekskul' ? `/ekskul/${r.id}/approve` : `/events/${r.id}/approve`

 await api(path,{method:'POST', body:{action:'approve'}})

 toast('Approved: '+r.nama, true); showApprove.value=false; showDetail.value=false; await load()

 }catch(e){ toast(e.error?.message||'Gagal approve', false); if(e.error?.code==='CONFLICT') await load() }

 finally{ acting.value=false }

}

async function doReject(){

 const reason=rejectReason.value.trim()

 if(reason.length<10){ rejectErr.value='Minimal 10 karakter'; return }

 if(!targetRow.value) return

 acting.value=true; try{ const r=targetRow.value; const path=r._type==='ekskul' ? `/ekskul/${r.id}/approve` : `/events/${r.id}/approve`

 await api(path,{method:'POST', body:{action:'reject', rejected_reason: reason}})

 toast('Rejected: '+r.nama, true); showReject.value=false; showDetail.value=false; await load() }catch(e){ const m=e.error?.message||'Gagal reject'; if(m.includes('10 karakter')) rejectErr.value=m; else toast(m,false) } finally{ acting.value=false } } async function batchAct(action){ if(!selectedIds.value.size) return; const ids=[...selectedIds.value]; const ekskulIds=ids.filter(k=>k.startsWith('ekskul-')).map(k=>parseInt(k.replace('ekskul-','')))

 const eventIds=ids.filter(k=>k.startsWith('event-')).map(k=>parseInt(k.replace('event-','')))

 batching.value=true

 try{

 let okCount=0

 if(ekskulIds.length){

 const j=await api('/approvals/batch',{method:'POST', body:{type:'ekskul', action, ids: ekskulIds, rejected_reason: ''}})

 okCount+= j.data?.ok||0

 }

 if(eventIds.length){

 const j=await api('/approvals/batch',{method:'POST', body:{type:'event', action, ids: eventIds, rejected_reason: ''}})

 okCount+= j.data?.ok||0

 }

 if(!ekskulIds.length && !eventIds.length){

 // mixed fallback

 const allIds=ids.map(k=> parseInt(k.split('-')[1]))

 const j=await api('/approvals/batch',{method:'POST', body:{type:'mixed', action, ids: allIds}})

 okCount=j.data?.ok||0

 }

 toast(`Batch ${action} ${okCount} item`, true); clearSelection(); await load()

 }catch(e){ toast(e.error?.message||'Batch gagal', false) }

 finally{ batching.value=false }

}

async function doBatchReject(){

 const reason=batchReason.value.trim()

 if(reason.length<10){ batchErr.value='Minimal 10 karakter'; return }

 const ids=[...selectedIds.value]; const ekskulIds=ids.filter(k=>k.startsWith('ekskul-')).map(k=>parseInt(k.replace('ekskul-','')))

 const eventIds=ids.filter(k=>k.startsWith('event-')).map(k=>parseInt(k.replace('event-','')))

 batching.value=true

 try{

 let okCount=0

 if(ekskulIds.length){ const j=await api('/approvals/batch',{method:'POST', body:{type:'ekskul', action:'reject', ids: ekskulIds, rejected_reason: reason}}); okCount+= j.data?.ok||0 }

 if(eventIds.length){ const j=await api('/approvals/batch',{method:'POST', body:{type:'event', action:'reject', ids: eventIds, rejected_reason: reason}}); okCount+= j.data?.ok||0 }

 toast(`Batch reject ${okCount} item`, true); showBatchReject.value=false; clearSelection(); await load()

 }catch(e){ const m=e.error?.message||'Batch reject gagal'; if(m.includes('10 karakter')) batchErr.value=m; else toast(m,false) }

 finally{ batching.value=false }

}

async function doExport(){

 exporting.value=true

 try{

 const st=statusFilter.value==='all' ? 'all' : (statusFilter.value||'pending')

 const res=await api('/approvals/export?status='+encodeURIComponent(st), {method:'GET'})

 // api helper returns csv text for text/csv

 if(res.csv){

 const blob=new Blob([res.csv],{type:'text/csv;charset=utf-8;'})

 const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download=`approvals-${st}-${new Date().toISOString().slice(0,10)}.csv`; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url)

 toast('CSV terunduh', true)

 } else {

 // fallback fetch raw

 const r=await fetch('/api/approvals/export?status='+encodeURIComponent(st),{credentials:'include'})

 const t=await r.text(); const blob=new Blob([t],{type:'text/csv'}); const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download=`approvals-${st}.csv`; a.click(); URL.revokeObjectURL(url)

 }

 }catch(e){ toast(e.error?.message||'Export gagal', false) }

 finally{ exporting.value=false }

}

watch([search, statusFilter, sortFilter], ()=>{ clearTimeout(debounce); debounce=setTimeout(()=>{ page.value=1; load() },300) })

watch(activeTab, ()=>{ page.value=1 })

watch(filtered, ()=>{ if(page.value>pages.value) page.value=pages.value })

function onKey(e){

 if(e.key==='Escape'){ if(showBatchReject.value) showBatchReject.value=false; else if(showReject.value) showReject.value=false; else if(showApprove.value) showApprove.value=false; else if(showDetail.value) showDetail.value=false }

}

onMounted(()=>{ load(); window.addEventListener('keydown', onKey) })

onBeforeUnmount(()=> window.removeEventListener('keydown', onKey))

</script>

<style scoped>



.ap-page{ --bg:#fdfcfa; --surface:#ffffff; --line:#e7e2dc; --ink:#1a1a18; --muted:#8a8580; --accent:#d97706; --success:#0f7a4a; --success-soft:#ecfdf5; --warn:#a16207; --warn-soft:#fef9c3; --danger:#be123c; --danger-soft:#fff1f2; background:var(--bg); color:var(--ink); min-height:60vh; font-family:'Satoshi',system-ui,sans-serif; }

.display{ font-family:'Satoshi',system-ui,sans-serif } .mono{ font-family:'Satoshi',system-ui,sans-serif }

.ap-shell{ max-width:1280px; margin:0 auto; padding:20px 16px 24px; }

@media(min-width:640px){ .ap-shell{ padding:24px 24px 32px; } }

.ap-header{ display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px; }

.ap-title{ font-size:22px; line-height:1; font-weight:700; letter-spacing:-0.02em; margin:0; }

@media(min-width:640px){ .ap-title{ font-size:26px; } }

.ap-subtitle{ font-size:13px; color:var(--muted); margin:6px 0 0; }

.ap-badges{ display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }

.ap-badge{ font-size:11px; padding:4px 10px; border-radius:999px; border:1px solid var(--line); font-weight:500; display:inline-flex; align-items:center; gap:6px; }

.ap-badge-warn{ background:var(--warn-soft); color:var(--warn); border-color:#fde68a; }

.ap-badge-success{ background:var(--success-soft); color:var(--success); border-color:#a7f3d0; }

.ap-badge-ink{ background:var(--ink); color:#fff; border-color:var(--ink); }

.ap-badge-muted{ background:#fff; color:var(--muted); }

.ap-dot{ width:6px; height:6px; border-radius:999px; display:inline-block; }

.dot-emerald{ background:#10b981; } .dot-amber{ background:#f59e0b; } .dot-rose{ background:var(--danger); }

.ap-header-cta{ display:flex; gap:8px; align-items:center; }

.ap-btn-ink{ background:var(--ink); color:#fff; border:1px solid var(--ink); border-radius:999px; padding:10px 18px; font-size:13px; font-weight:600; min-height:44px; cursor:pointer; }

.ap-btn-ink:hover{ background:#000; } .ap-btn-ink:disabled{ opacity:.5; cursor:not-allowed; }

.ap-btn-ghost{ background:#fff; color:var(--ink); border:1px solid var(--line); border-radius:999px; padding:10px 18px; font-size:13px; font-weight:500; min-height:44px; cursor:pointer; }

.ap-btn-ghost:hover{ background:#f5f5f4; }

.ap-btn-rose{ background:var(--danger); color:#fff; border:1px solid var(--danger); border-radius:999px; padding:10px 18px; font-size:13px; font-weight:600; min-height:44px; cursor:pointer; }

.ap-btn-rose:hover{ background:#9f1239; }

.ap-btn-sm{ padding:8px 14px; min-height:36px; font-size:12px; }

.ap-filter-wrap{ position:sticky; top:56px; z-index:10; margin-top:18px; }

.ap-tabs{ display:flex; gap:6px; margin-bottom:8px; flex-wrap:wrap; }

.ap-tab{ padding:8px 14px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:13px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }

.ap-tab.active{ background:var(--ink); color:#fff; border-color:var(--ink); }

.ap-tab-count{ font-size:11px; padding:2px 7px; border-radius:999px; background:rgba(0,0,0,.06); }

.ap-tab.active .ap-tab-count{ background:rgba(255,255,255,.18); color:#fff; }

.ap-filter{ background:#fafaf9; border:1px solid var(--line); border-radius:16px; padding:12px; display:grid; grid-template-columns:1fr; gap:10px; align-items:end; }

@media(min-width:640px){ .ap-filter{ grid-template-columns:1.6fr .85fr .95fr auto; padding:14px 16px; } }

.ap-field{ display:block; } .ap-label{ font-size:10px; letter-spacing:.12em; font-weight:600; color:#78716c; display:block; }

.ap-input-wrap{ position:relative; margin-top:4px; }

.ap-search-icon{ position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#a8a29e; font-size:14px; }

.ap-input{ width:100%; height:44px; padding:10px 12px; border:1px solid var(--line); border-radius:12px; background:#fff; font-size:13px; outline:none; }

.ap-input-search{ padding-left:30px; }

.ap-input:focus{ border-color:var(--ink); box-shadow:0 0 0 2px rgba(26,26,24,.06); }

.ap-textarea{ height:auto; min-height:84px; resize:vertical; padding-top:10px; }

.ap-select{ cursor:pointer; }

.ap-reset{ height:44px; padding:0 18px; border-radius:12px; border:1px solid var(--line); background:#fff; font-size:13px; cursor:pointer; }

.ap-filter-meta{ display:flex; align-items:center; gap:8px; margin-top:8px; font-size:11px; color:#78716c; flex-wrap:wrap; }

.ap-count-strong{ color:var(--ink); font-weight:600; }

.ap-spinner{ display:inline-block; width:12px; height:12px; border:1.5px solid #d6d3d1; border-top-color:var(--ink); border-radius:999px; animation:spin .7s linear infinite; margin-left:6px; }

.ap-spinner-sm{ display:inline-block; width:12px; height:12px; border:1.5px solid #d6d3d1; border-top-color:var(--ink); border-radius:999px; animation:spin .7s linear infinite; margin-right:6px; vertical-align:middle; }

@keyframes spin{ to{ transform:rotate(360deg) } }

.ap-select-all{ margin-left:auto; display:inline-flex; align-items:center; gap:6px; cursor:pointer; }

.ap-toast{ margin-top:12px; padding:10px 14px; border-radius:12px; font-size:13px; }

.ap-toast-ok{ background:var(--success-soft); color:var(--success); border:1px solid #a7f3d0; }

.ap-toast-err{ background:var(--danger-soft); color:var(--danger); border:1px solid #fecdd3; }

.ap-batch{ margin-top:12px; padding:10px 14px; border-radius:12px; border:1px solid var(--ink); background:var(--ink); color:#fff; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }

.ap-batch-count{ font-size:13px; font-weight:600; }

.ap-batch-actions{ display:flex; gap:8px; }

.ap-batch .ap-btn-ghost{ background:#fff; color:var(--ink); }

.ap-card{ margin-top:14px; background:var(--surface); border:1px solid var(--line); border-radius:16px; overflow:hidden; box-shadow:0 8px 32px rgba(26,26,24,.06); }

.ap-skeleton{ padding:12px; display:flex; flex-direction:column; gap:10px; }

.ap-skeleton-row{ display:grid; grid-template-columns:32px 70px 1.2fr .9fr .9fr .7fr .9fr; gap:10px; align-items:center; padding:10px 8px; }

.ap-skeleton-line{ height:12px; border-radius:999px; background:linear-gradient(90deg,#f5f5f4 25%,#e7e5e4 50%,#f5f5f4 75%); background-size:200% 100%; animation:shimmer 1.2s infinite; }

.w-6{ width:24px; } .w-32{ width:128px; } .w-20{ width:80px; } .w-16{ width:64px; } .w-24{ width:96px; }

@keyframes shimmer{ 0%{background-position:200% 0} 100%{background-position:-200% 0} }

.ap-empty{ text-align:center; padding:40px 20px; }

.ap-empty-illus{ width:64px; height:64px; margin:0 auto 14px; border:1px dashed var(--line); border-radius:16px; display:grid; place-items:center; background:#fafaf9; }

.ap-empty-icon{ font-size:22px; color:#a8a29e; }

.ap-empty-title{ font-size:15px; font-weight:600; margin:0; }

.ap-empty-desc{ font-size:13px; color:var(--muted); margin:6px auto 0; max-width:520px; }

.ap-table-wrap{ overflow:auto; }

.ap-table{ width:100%; min-width:860px; border-collapse:collapse; }

.ap-table thead{ background:#fafaf9; border-bottom:1px solid var(--line); }

.ap-table thead th{ text-align:left; padding:10px 14px; font-size:10px; letter-spacing:.12em; font-weight:600; color:#78716c; white-space:nowrap; }

.ap-table tbody tr{ border-bottom:1px solid #f5f5f4; }

.ap-table tbody tr:hover{ background:#fafaf9; }

.ap-table tbody tr.row-pending{ background:#fffbeb; }

.ap-table tbody tr.row-pending:hover{ background:#fef9c3; }

.ap-table td{ padding:12px 14px; vertical-align:middle; }

.ap-chip{ font-size:10px; padding:3px 8px; border-radius:999px; border:1px solid var(--line); font-weight:600; }

.ap-chip-sky{ background:#f0f9ff; color:#0369a1; border-color:#bae6fd; }

.ap-chip-emerald{ background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }

.ap-name-cell{ min-width:160px; }

.ap-name-btn{ background:none; border:none; padding:0; font-size:13px; font-weight:600; color:var(--ink); cursor:pointer; text-align:left; }

.ap-name-btn:hover{ text-decoration:underline; }

.ap-sub{ font-size:11px; color:var(--muted); margin-top:2px; }

.ap-creator{ font-size:12px; }

.ap-info{ font-size:11px; color:var(--muted); white-space:nowrap; }

.ap-status{ font-size:11px; padding:4px 10px; border-radius:999px; border:1px solid var(--line); display:inline-flex; align-items:center; gap:6px; font-weight:500; white-space:nowrap; }

.ap-status-approved{ background:var(--success-soft); color:var(--success); border-color:#a7f3d0; }

.ap-status-pending{ background:var(--warn-soft); color:var(--warn); border-color:#fde68a; }

.ap-status-rejected{ background:var(--danger-soft); color:var(--danger); border-color:#fecdd3; }

.ap-actions{ display:flex; justify-content:flex-end; gap:6px; }

.ap-icon-btn{ width:32px; height:32px; border-radius:999px; border:1px solid var(--line); background:#fff; display:grid; place-items:center; cursor:pointer; font-size:13px; }

.ap-icon-detail:hover{ background:#f5f5f4; }

.ap-icon-approve{ border-color:#a7f3d0; color:var(--success); }

.ap-icon-approve:hover{ background:var(--success-soft); }

.ap-icon-reject{ border-color:#fecdd3; color:var(--danger); }

.ap-icon-reject:hover{ background:var(--danger-soft); }

.ap-done{ color:var(--muted); }

.ap-pagination{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; border-top:1px solid var(--line); background:#fafaf9; }

.ap-pagination-hint{ font-size:11px; color:#78716c; }

.ap-pagination-ctrls{ display:flex; align-items:center; gap:8px; }

.ap-page-btn{ width:32px; height:32px; border-radius:999px; border:1px solid var(--line); background:#fff; display:grid; place-items:center; cursor:pointer; }

.ap-page-btn:disabled{ opacity:.4; cursor:not-allowed; }

.ap-page-pill{ font-size:11px; padding:4px 12px; border-radius:999px; background:#fff; border:1px solid var(--line); }

.ap-footnote{ margin-top:10px; font-size:10px; color:#78716c; display:flex; gap:8px; flex-wrap:wrap; }

.ap-footnote span{ padding:4px 8px; border-radius:999px; border:1px solid var(--line); background:#fff; }



/* overlay */

.ap-overlay{ position:fixed; inset:0; z-index:40; display:grid; place-items:center; padding:16px; }

.ap-overlay-bg{ position:absolute; inset:0; background:rgba(26,26,24,.4); backdrop-filter:blur(8px); }

.ap-drawer{ position:relative; width:100%; max-width:560px; max-height:90vh; overflow:auto; background:#fff; border:1px solid var(--line); border-radius:20px; box-shadow:0 24px 64px rgba(0,0,0,.18); animation:modalIn .18s ease; }

@keyframes modalIn{ from{ opacity:0; transform:scale(.98) translateY(4px)} to{opacity:1; transform:scale(1) translateY(0)} }

.ap-drawer-head{ padding:16px 20px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; gap:12px; background:#fdfcfa; }

.ap-drawer-kicker{ font-size:10px; letter-spacing:.12em; color:#78716c; font-weight:600; }

.ap-drawer-title{ font-size:18px; font-weight:700; margin:4px 0 0; }

.ap-drawer-sub{ font-size:11px; color:var(--muted); margin-top:4px; }

.ap-close{ width:32px; height:32px; border-radius:999px; border:1px solid var(--line); background:#fff; display:grid; place-items:center; cursor:pointer; flex-shrink:0; }

.ap-drawer-body{ padding:20px; }

.ap-detail-grid{ display:grid; gap:12px; }

.ap-detail-text{ font-size:13px; color:#44403c; margin:4px 0 0; line-height:1.5; }

.ap-reject-text{ background:var(--danger-soft); border:1px solid #fecdd3; padding:8px 10px; border-radius:10px; }

.ap-drawer-foot{ padding:14px 20px; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:8px; background:#fff; }

.ap-confirm-card{ position:relative; width:100%; max-width:440px; background:#fff; border:1px solid var(--line); border-radius:20px; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,.18); padding:22px 20px 0; animation:modalIn .18s ease; }

.ap-confirm-card-lg{ max-width:520px; }

.ap-confirm-icon{ width:36px; height:36px; border-radius:999px; display:grid; place-items:center; font-weight:700; }

.ap-confirm-icon-ok{ background:var(--success-soft); color:var(--success); border:1px solid #a7f3d0; }

.ap-confirm-icon-rose{ background:var(--danger-soft); color:var(--danger); border:1px solid #fecdd3; }

.ap-confirm-title{ font-size:16px; font-weight:600; margin:14px 0 8px; }

.ap-confirm-body{ font-size:13px; color:#57534e; line-height:1.55; margin:0; }

.ap-confirm-foot{ display:flex; justify-content:flex-end; gap:8px; margin-top:16px; padding:14px 20px; border-top:1px solid var(--line); background:#fafaf9; margin-left:-20px; margin-right:-20px; }

.ap-req{ color:var(--danger); }

.ap-helper{ font-size:11px; color:var(--muted); margin-top:4px; display:block; }

.ap-helper-err{ color:var(--danger); }

.ap-input.is-invalid{ border-color:var(--danger); background:#fff1f2; }

.mt-12{ margin-top:12px; }

.ap-btn-spinner{ display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,.35); border-top-color:#fff; border-radius:999px; animation:spin .7s linear infinite; margin-right:6px; vertical-align:middle; }

.ap-btn-spinner--light{ border-color:rgba(255,255,255,.4); border-top-color:#fff; }

.text-right{ text-align:right; }

</style>

