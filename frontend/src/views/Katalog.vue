<template>

<div class="kat-page">

<div class="kat-inner">

 <!-- head: flat tool header, no hero card -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title" :title="headSub">{{ headTitle }}</h1>

 </div>

 <div class="kat-head-r">

 <button v-if="canManageAny" @click="openCreate" class="kat-link" style="cursor:pointer">+ Tambah</button>

 </div>

 </div>



 <!-- toolbar: sticky tool strip, not a card - disembunyikan saat siswa 2/2 (list miliknya, tak perlu discovery) -->

 <div v-if="!(isSiswa && limited)" class="kat-toolbar">

 <div class="kat-search">

 <span class="kat-search-icon" aria-hidden="true"></span>

 <input v-model="search" placeholder="Cari PMR, Pramuka, Futsal..." class="kat-input" aria-label="Cari ekskul" />

 <button v-if="search" class="kat-clear" @click="search=''" aria-label="Hapus pencarian">x</button>

 </div>

 <div class="kat-filters">

 <select v-if="canSeeStatus" v-model="statusFilter" class="kat-select" aria-label="Filter status" :title="attention.pending ? attention.pending + ' pending' : 'Tidak ada pending'">

 <option value="all">Status: Semua</option>

 <option value="approved">Approved</option>

 <option value="pending">Pending</option>

 </select>

 <select v-model="kuotaFilter" class="kat-select" aria-label="Filter kuota" :title="'Tersedia '+stats.tersedia+' - Hampir '+stats.hampir+' - Penuh '+stats.penuh">

 <option value="all">Kuota: Semua</option>

 <option value="tersedia">Tersedia</option>

 <option value="hampir">Hampir</option>

 <option value="penuh">Penuh</option>

 </select>

 <select v-model="sortFilter" class="kat-select" aria-label="Sort">

 <option value="baru">Terbaru</option>

 <option value="nama">Nama A-Z</option>

 <option value="sisa">Sisa kuota</option>

 <option value="isi">Terisi terbanyak</option>

 </select>

  <button v-if="isSiswa" :class="['kat-chip-btn', myFilter?'active':'']" @click="myFilter=!myFilter" :aria-pressed="myFilter?'true':'false'" aria-label="Filter Ekskul Saya" :title="myCount ? myCount + ' ekskul saya' : 'Belum ikut ekskul'">

 Saya

 </button>

 </div>

 </div>




 <!-- charts: ringkas analitik ekskul (ApexCharts, data = filtered agar selaras filter) -->
 <div v-if="canSeeCharts && !loading && list.length" class="kat-charts" aria-label="Analitik ekskul">
  <div class="kat-chart-card">
   <h3 class="kat-chart-title">Top 5 Paling Diminati <span class="kat-chart-sub">% terisi = terdaftar / kapasitas</span></h3>
   <ApexChart type="bar" :height="230" :options="top5Opts" :series="top5Series" />
  </div>
  <div class="kat-chart-card">
   <h3 class="kat-chart-title">Distribusi Status Approval</h3>
   <ApexChart type="donut" :height="230" :options="approvalOpts" :series="approvalSeries" />
  </div>
  <div class="kat-chart-card">
   <h3 class="kat-chart-title">Beban Pembimbing <span class="kat-chart-sub">ekskul per pembimbing</span></h3>
   <ApexChart type="bar" :height="230" :options="bebanOpts" :series="bebanSeries" />
  </div>
 </div>

 <!-- skeleton -->

 <div v-if="loading" class="kat-grid" aria-busy="true" aria-label="Memuat katalog">

 <div v-for="i in 6" :key="i" class="kat-card skeleton" aria-hidden="true">

 <div class="skel-line w40"></div>

 <div class="skel-line w80"></div>

 <div class="skel-line w90"></div>

 </div>

 </div>



 <!-- empty -->

 <div v-else-if="!filtered.length" class="kat-empty">

 <span v-if="myFilter">Belum ikut ekskul apapun</span>

 <span v-else>Tidak ada ekskul</span>

 </div>



 <!-- grid: dense work cards, title-first -->

 <div v-else class="kat-grid" v-memo="[filteredKey, page]">

  <article v-for="e in paged" :key="e.id" class="kat-card" :class="cardCls(e)" role="article" :aria-label="'Ekskul '+e.nama+' kuota '+e.terisi+'/'+e.kuota+' '+kuotaMeta(e).label+' '+(e.my_status||'')" >

 <div class="kat-top">

 <div class="kat-top-l">

 <h3 class="kat-name" :title="e.nama">{{ e.nama }}</h3>

 <p class="kat-sched mono">{{ schedLine(e) }}</p>

 </div>

 <div class="kat-top-r">

  <img v-if="safeCover(e)" :src="safeCover(e)" alt="" loading="lazy" decoding="async" class="kat-thumb" />

 <div v-else class="kat-thumb kat-thumb-empty" aria-hidden="true"><span>{{ (e.nama||'?').slice(0,1).toUpperCase() }}</span></div>

 </div>

 </div>

 <div class="kat-tags">

  <span v-if="canSeeStatus && e.status!=='approved'" class="tag status">{{ e.status }}</span>

  <span v-if="e.my_status==='diterima'" class="tag mine-ok">- Diterima</span>

  <span v-else-if="e.my_status==='menunggu'" class="tag mine-wait">- Menunggu</span>

  <span v-else-if="e.my_status==='ditolak'" class="tag mine-no">- Ditolak</span>

  <span class="tag kuota" :class="kuotaMeta(e).cls">{{ kuotaMeta(e).short }}</span>

  <span v-if="role==='kepsek' && e.status==='pending'" class="tag act">-> Approval</span>

 <span v-else-if="canSeeStatus" class="tag need">{{ e.requires_approval ? 'Butuh approval' : 'Auto' }}</span>

 </div>

 <p v-if="showDesc(e)" class="kat-desc">{{ displayDeskripsi(e) }}</p>

 <div class="kat-foot">

  <span class="kat-pembina" :title="e.pembina_nama||''">{{ e.pembina_nama || '-' }}</span>

  <span class="kat-quota mono"><b>{{ e.terisi }}/{{ e.kuota }}</b> - {{ kuotaMeta(e).pct }}%</span>

  </div>

  <div class="kat-bar"><div class="kat-bar-fill" :class="kuotaMeta(e).bar" :style="{width: kuotaMeta(e).pct + '%'}"></div></div>

  <div class="kat-cta">

   <router-link :to="`/ekskul/${e.id}`" class="kat-detail" :aria-describedby="`k-desc-${e.id}`"><span>Detail</span><span class="kat-detail-arrow" aria-hidden="true">→</span></router-link>

  <template v-if="canManage(e)">

   <button class="kat-mini neutral" @click="openEdit(e)" :aria-label="`Edit ${e.nama}`">Edit</button>

   <button v-if="isAdmin" class="kat-mini danger" @click="hapus(e)" :aria-label="`Hapus ${e.nama}`">Hapus</button>

   </template>

   <button v-else-if="e.my_status==='menunggu' || e.my_status==='diterima'" class="kat-mini danger" @click="batal(e)" :aria-label="`${e.my_status==='diterima'?'Lepas':'Batal'} pendaftaran ${e.nama}`">{{ e.my_status==='diterima' ? 'Lepas' : 'Batal' }}</button>

   </div>

  <span :id="`k-desc-${e.id}`" class="visually-hidden">Kuota {{ e.terisi }} dari {{ e.kuota }}</span>

 </article>

 </div>



 <!-- pagination -->

  <div v-if="total>0 || filtered.length>0" class="kat-paging">

  <div class="kat-paging-btns">

  <button class="kat-page-btn" :disabled="page<=1" @click="goPage(page-1)">Prev</button>

  <span class="kat-page-num mono" :title="'Menampilkan ' + pagingStart + '-' + pagingEnd + ' dari ' + filteredTotal + (filteredTotal!==total ? ' (total '+total+')' : '')">{{ page }} / {{ pages }}</span>

  <button class="kat-page-btn" :disabled="page>=pages" @click="goPage(page+1)">Next</button>

 </div>

 </div>

  <div v-if="msg" class="kat-alert" :class="ok?'ok':'err'" role="status">{{ msg }}</div>



 <!-- Modal Tambah/Edit (admin + pembina binaan) -->

 <Teleport to="body">

 <div v-if="showModal" class="fixed inset-0 z-40">

  <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="closeModal"></div>

 <div class="absolute inset-0 grid place-items-center p-4 overflow-auto">

 <div class="w-full max-w-[680px] max-h-[90vh] overflow-auto rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] flex flex-col" style="border-color:var(--m-line,#E0E5E3)">

 <div class="sticky top-0 bg-white px-6 py-4 border-b flex items-center justify-between" style="border-color:var(--m-line,#E0E5E3)">

 <h2 class="text-[18px] font-bold">{{ editing?'Edit Ekskul':'Tambah Ekskul' }}</h2>

 <button type="button" @click="closeModal" class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50 transition" aria-label="Close">x</button>

 </div>

 <div class="p-6">

 <div v-if="formErr.server" class="rounded-xl border px-3 py-2 text-[12px] bg-red-50 text-red-700 mb-3" style="border-color:#fecdd3">{{ formErr.server }}</div>

 <div class="grid sm:grid-cols-[1.4fr_0.6fr] gap-3">

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">NAMA <span class="text-red-600">*</span></span>

 <input v-model="form.nama" placeholder="Nama ekskul" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" />

 <span v-if="formErr.nama" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.nama }}</span>

 </label>

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">KUOTA <span class="text-red-600">*</span></span>

  <input type="number" min="1" v-model.number="form.kuota" placeholder="Kuota" :min="0" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" />

 <span v-if="formErr.kuota" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.kuota }}</span>

 </label>

 </div>

 <label class="block mt-3">

 <span class="mono text-[10px] tracking-widest font-semibold">DESKRIPSI <span class="text-red-600">*</span> <span class="font-normal normal-case tracking-normal" style="color:#a8a29e">(min 10 karakter)</span></span>

 <textarea v-model="form.deskripsi" placeholder="Deskripsi minimal 10 karakter" rows="3" class="mt-1 w-full px-3 py-3 rounded-xl border bg-white text-[13px] outline-none min-h-[80px] resize-none"></textarea>

 <span v-if="formErr.deskripsi" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.deskripsi }}</span>

 </label>

 <div class="grid sm:grid-cols-2 gap-3 mt-3">

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">HARI <span class="text-red-600">*</span></span>

 <select v-model="form.hari" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none">

   <option value="">-- Pilih Hari --</option><option>Senin</option><option>Selasa</option><option>Rabu</option><option>Kamis</option><option>Jumat</option><option>Sabtu</option><option>Minggu</option>

 </select>

 <span v-if="formErr.hari" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.hari }}</span>

 </label>

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">LOKASI <span class="text-red-600">*</span></span>

 <input v-model="form.lokasi" placeholder="Lokasi (mis. Aula)" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" />

 <span v-if="formErr.lokasi" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.lokasi }}</span>

 </label>

 </div>

 <div class="grid sm:grid-cols-2 gap-3 mt-3">

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">JAM MULAI <span class="text-red-600">*</span></span>

 <input type="time" v-model="form.jam_mulai" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" />

 <span v-if="formErr.jam_mulai" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.jam_mulai }}</span>

 </label>

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">JAM SELESAI <span class="text-red-600">*</span></span>

 <input type="time" v-model="form.jam_selesai" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" />

 <span v-if="formErr.jam_selesai" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.jam_selesai }}</span>

 </label>

 </div>

 <div class="grid sm:grid-cols-2 gap-3 mt-3">

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">MODE PENDAFTARAN</span>

 <select v-model="form.requires_approval" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none">

 <option :value="0">Auto terima (default)</option>

 <option :value="1">Butuh persetujuan pembina</option>

 </select>

 </label>

 <div class="block" v-if="isAdmin">

 <span class="mono text-[10px] tracking-widest font-semibold">PEMBINA <span class="text-red-600">*</span></span>

 <div class="relative mt-1">

  <input v-model="pembinaSearch" @input="onPembinaInput" @focus="onPembinaFocus" placeholder="Cari nama / NIP pembina..." autocomplete="off" class="w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none pr-8" aria-label="Cari pembina" />

  <span v-if="pembinaLoading" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 border-2 border-stone-300 border-t-zinc-900 rounded-full animate-spin"></span>

 <ul v-if="showPembinaList" class="absolute z-10 w-full mt-1 rounded-xl border bg-white shadow-lg overflow-hidden max-h-[180px] overflow-auto">

 <li v-for="u in pembinaOptions" :key="u.id" class="px-3 py-2 hover:bg-stone-50 cursor-pointer flex justify-between items-center text-[13px]" @click="selectPembina(u)">

 <span class="font-medium">{{ u.nama }}</span><span class="mono text-[11px]" style="color:#a8a29e">{{ u.nip || u.email }}</span>

 </li>

 <li v-if="!pembinaOptions.length && !pembinaLoading" class="px-3 py-2 mono text-[11px]" style="color:#a8a29e">Tidak ada pembina</li>

 </ul>

 </div>

 <span v-if="formErr.pembina_id" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.pembina_id }}</span>

 </div>

 <label v-else class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">PEMBINA</span>

  <input :value="auth.user?.nama + ' (otomatis)'" disabled class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-stone-50 text-[13px]" style="color:#78716c" />

 </label>

 </div>

 <div class="grid sm:grid-cols-2 gap-3 mt-3">

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">PENDAFTARAN MULAI (opsional)</span>

 <input type="datetime-local" v-model="form.registration_start" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" />

 </label>

 <label class="block">

 <span class="mono text-[10px] tracking-widest font-semibold">PENDAFTARAN SELESAI (opsional)</span>

 <input type="datetime-local" v-model="form.registration_end" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" />

 </label>

 </div>

 </div>

 <div class="sticky bottom-0 bg-white px-6 py-4 border-t flex justify-end gap-2">

 <button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50 transition" @click="closeModal">Batal</button>

 <button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold hover:bg-black transition disabled:opacity-40" style="background:var(--m-cta,#4A7875)" @click="submitManage">{{ editing?'Update':'Tambah' }}</button>

 </div>

 </div>

 </div>

 </div>

 </Teleport>

</div>

</div>

</template>

<script setup>

import { ref, computed, watch, onMounted } from 'vue'

import { useAuth } from '../stores/auth.js'

import { api } from '../lib/api.js'
import ApexChart from '../components/ApexChart.vue'

const auth=useAuth()

const list=ref([])

const loading=ref(false)

const total=ref(0)

const page=ref(1)

const search=ref('')

const statusFilter=ref('all')

const kuotaFilter=ref('all')

const sortFilter=ref('baru')

const msg=ref('')

const ok=ref(false)

// siswa 2/2: backend scope list ke miliknya; flag untuk strip + sembunyikan toolbar discovery

const limited=ref(false)

let debounceTimer=null



// role adaptif: satu layout, badge/CTA berubah per role

const role=computed(()=> auth.user?.role || 'guest')

const isSiswa=computed(()=> role.value==='siswa')

const isAdmin=computed(()=> role.value==='admin')

const canSeeStatus=computed(()=> ['admin','pembina','kepsek'].includes(role.value))

// chart analitik: admin + kepsek saja (siswa/pembina/guest disembunyikan)
const canSeeCharts=computed(()=> ['admin','kepsek'].includes(role.value))

// kelola inline: admin semua, pembina hanya binaan sendiri

const canManageAny=computed(()=> isAdmin.value || role.value==='pembina')

function canManage(e){

 if(isAdmin.value) return true

 if(role.value==='pembina' && auth.user?.id===e.pembina_id) return true

 return false

}



function sisa(e){ const s=(e.kuota||0)-(e.terisi||0); return s<0?0:s }

function kuotaMeta(e){

  const s=sisa(e); const t=e.kuota||1; const terisi=e.terisi||0; const pct = t? Math.round(terisi/t*100):0

  if(s===0) return {label:'Penuh',short:'Penuh',cls:'red',bar:'bg-red',pct:100}

  if(s<=3) return {label:'Hampir penuh',short:`Sisa ${s}`,cls:'amber',bar:'bg-amber',pct}

  return {label:'Tersedia',short:`Sisa ${s}`,cls:'emerald',bar:'bg-emerald',pct}

 }

 // role-adaptive card class: quota state + mine state + pending triage - no leak, FE display only

 function cardCls(e){

  const m=kuotaMeta(e); const c=['q-'+m.cls]

  if(e.my_status==='diterima') c.push('is-mine-ok')

 else if(e.my_status==='menunggu') c.push('is-mine-wait')

 if(canSeeStatus.value && e.status==='pending') c.push('is-pending')

 if(m.cls==='red') c.push('is-full')

 return c.join(' ')

}

function schedLine(e){

  const d=(e.hari||'-').toUpperCase()

  const j=hasJam(e)?formatJam(e):'-'

  const l=(e.lokasi||'-').toUpperCase()

  return `${d} ${j} - ${l}`

 }

 function showDesc(e){ return !isDeskEmpty(e) }

 // allowlist cover: backend hanya keluarkan /api/covers/ekskul/:id numerik

 function safeCover(e){

  const u=e.cover_url; return (typeof u==='string' && /^\/api\/covers\/ekskul\/\d+$/.test(u)) ? u : null

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

 return `${a||'-'}-${b||'-'}`

}

// role-adaptive header: 1 baris judul + sub berbeda per role, strip hanya bila actionable

const headTitle=computed(()=>{

 if(role.value==='kepsek') return 'Approval Ekskul'

 if(role.value==='pembina') return 'Binaan Saya'

 if(role.value==='admin') return 'Semua Ekskul'

 if(role.value==='siswa') return 'Katalog Ekskul'

 return 'Katalog Ekskul'

})

const headSub=computed(()=>{

 if(role.value==='kepsek') return attention.value.pending ? `${attention.value.pending} menunggu keputusan` : 'Katalog ekskul'

 if(role.value==='admin') return `${total.value} ekskul`

 if(role.value==='pembina') return `${total.value} binaan`

 if(role.value==='siswa') return `${total.value} ekskul`

 return `${total.value} ekskul`

})

const stripText=computed(()=>{

 if(role.value==='guest') return 'Login untuk mendaftar.'

 if(role.value==='kepsek' && attention.value.pending) return `${attention.value.pending} menunggu keputusan.`

 if(role.value==='admin' && attention.value.pending) return `${attention.value.pending} pending.`

 if(role.value==='siswa' && limited.value) return `Menampilkan ${myCount.value} ekskul kamu.`

 return ''

})

const stripCls=computed(()=> (role.value==='kepsek'||role.value==='admin')? 'warn' : '')

 const myFilter=ref(false)

const myCount=computed(()=> list.value.filter(x=> x.my_status).length)

const stats=computed(()=>{

 let tersedia=0, hampir=0, penuh=0

 for(const x of list.value){ const s=sisa(x); if(s===0) penuh++; else if(s<=3) hampir++; else tersedia++ }

 return {tersedia, hampir, penuh}

})

const attention=computed(()=>{

 let pending=0, penuh=0, nodesc=0

 for(const x of list.value){ if(x.status==='pending') pending++; if(sisa(x)===0) penuh++; if(isDeskEmpty(x)) nodesc++ }

 return {pending, penuh, nodesc}

})

const filtered=computed(()=>{

 let arr=[...list.value]

 if(myFilter.value) arr=arr.filter(x=> !!x.my_status)

 // defense-in-depth: guest/siswa hanya approved walau backend sudah enforce

 if(!canSeeStatus.value) arr=arr.filter(x=> x.status==='approved')

 const q=search.value.trim().toLowerCase()

 if(q) arr=arr.filter(x=> (`${x.nama} ${x.deskripsi||''} ${x.pembina_nama||''}`).toLowerCase().includes(q))

 if(canSeeStatus.value && statusFilter.value!=='all') arr=arr.filter(x=> x.status===statusFilter.value)

 if(kuotaFilter.value==='penuh') arr=arr.filter(x=> sisa(x)===0)

 else if(kuotaFilter.value==='tersedia') arr=arr.filter(x=> sisa(x)>3)

 else if(kuotaFilter.value==='hampir') arr=arr.filter(x=> {const s=sisa(x); return s>0 && s<=3})

 if(!myFilter.value) arr.sort((a,b)=> (b.my_status?1:0)-(a.my_status?1:0))

 if(sortFilter.value==='nama') arr.sort((a,b)=> a.nama.localeCompare(b.nama,'id'))

 else if(sortFilter.value==='sisa') arr.sort((a,b)=> sisa(a)-sisa(b))

 else if(sortFilter.value==='isi') arr.sort((a,b)=> (b.terisi||0)-(a.terisi||0))

 return arr

})

const filteredTotal=computed(()=> filtered.value.length)

const filteredKey=computed(()=> filtered.value.map(x=>x.id).join(',') + `|${search.value}|${statusFilter.value}|${kuotaFilter.value}|${sortFilter.value}|${myFilter.value}`)

// ponytail: full-client 12/page, 1 request ETag-cached; pindah paging server saat ekskul >100

const pageSize=12

const pages=computed(()=> Math.max(1, Math.ceil(filteredTotal.value / pageSize)))


const chartList=ref([])
const chartLoading=ref(false)
function pctFill(e){ const k=Number(e.kuota)||0; if(k<=0) return 0; return Math.min(100, Math.round((Number(e.terisi)||0)/k*100)) }
async function loadCharts(){
  if(!canSeeCharts.value){ chartList.value=[]; chartLoading.value=false; return }
  chartLoading.value=true
  try{
    const acc=[]; let page=1
    for(let i=0;i<10;i++){
      const j=await api('/ekskul?limit=100&page='+page)
      const rows=j.data||[]
      acc.push(...rows)
      const pages=j.meta?.pages||1
      if(page>=pages) break
      page++
    }
    chartList.value=acc
  }catch{ chartList.value=[] }finally{ chartLoading.value=false }
}
const top5Rows=computed(()=> [...chartList.value].sort((a,b)=> pctFill(b)-pctFill(a)).slice(0,5))
const top5Series=computed(()=> [{ name:'% Terisi', data: top5Rows.value.map(pctFill) }])
const top5Opts=computed(()=> ({ plotOptions:{ bar:{ horizontal:true, borderRadius:6, barHeight:'55%' } }, colors:['#4A7875'], xaxis:{ categories: top5Rows.value.map(e=> (e.nama||'?').slice(0,18)), max:100, labels:{ formatter:v=> v+'%' } }, dataLabels:{ enabled:true, formatter:v=> v+'%' }, tooltip:{ y:{ formatter:v=> v+'%' } }, grid:{ strokeDasharray:3 } }))
// donut: 4 dimensi eksplisit — approval status x mode pendaftaran x kuota penuh (tidak dicampur)
const approvalCounts=computed(()=>{
  const c={ approved:0, pending:0, penuh:0, auto:0, butuh:0 }
  for(const e of chartList.value){
    if(e.status==='approved') c.approved++
    else if(e.status==='pending') c.pending++
    if((e.kuota||0)-(e.terisi||0)<=0) c.penuh++
    if(Number(e.requires_approval)) c.butuh++
    else c.auto++
  }
  return c
})
const approvalSeries=computed(()=> [approvalCounts.value.approved, approvalCounts.value.pending, approvalCounts.value.penuh])
const approvalOpts=computed(()=> ({ labels:['Approved','Pending','Penuh'], colors:['#5EB87E','#f59e0b','#ef4444'], legend:{ position:'bottom' }, dataLabels:{ enabled:true }, tooltip:{ y:{ formatter:v=> v+' ekskul' } }, title:{ text:'Approval + Penuh ('+Number(approvalCounts.value.auto)+' auto / '+Number(approvalCounts.value.butuh)+' butuh approval)', align:'center', style:{ fontSize:'11px', fontWeight:'400', color:'#6B7C85' } } }))
const bebanRows=computed(()=>{
  const m=new Map()
  for(const e of chartList.value){ const k=e.pembina_nama||'-'; m.set(k,(m.get(k)||0)+1) }
  return [...m.entries()].sort((a,b)=> b[1]-a[1]).slice(0,8)
})
const bebanSeries=computed(()=> [{ name:'Ekskul', data: bebanRows.value.map(r=> r[1]) }])
const bebanOpts=computed(()=> ({ plotOptions:{ bar:{ borderRadius:6, columnWidth:'55%' } }, colors:['#A7C7E7'], xaxis:{ categories: bebanRows.value.map(r=> (r[0]||'?').slice(0,14)), tickAmount:5, tickPlacement:'on', labels:{ rotate:-45, rotateAlways:false, hideOverlappingLabels:true, trim:true, style:{ fontSize:'10px' } } }, dataLabels:{ enabled:false }, grid:{ strokeDasharray:3 } }))

const paged=computed(()=> filtered.value.slice((page.value-1)*pageSize, page.value*pageSize))

const pagingStart=computed(()=> filteredTotal.value ? (page.value-1)*pageSize+1 : 0)

const pagingEnd=computed(()=> Math.min(page.value*pageSize, filteredTotal.value))



async function load(){

 loading.value=true

 try{

 const j=await api('/ekskul?limit=100&page=1')

 list.value=j.data||[]

 total.value=j.meta?.total||0

 limited.value=!!j.meta?.limited

   if(limited.value){ myFilter.value=false; search.value=''; kuotaFilter.value='all' }

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

 // refetch: terisi hitung menunggu+diterima, decrement lokal salah untuk menunggu

 try{

 const j=await api('/ekskul?limit=100&page=1')

 list.value=j.data||[]

 total.value=j.meta?.total||0

 loadCharts()

 }catch(e2){

 e.my_status=null

 }

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

// ---- kelola inline (dipindah dari AdminEkskul, disederhanakan: tanpa cover/dummy/url-sync) ----

const showModal=ref(false), editing=ref(null)

  const form=ref({nama:'',deskripsi:'',kuota:30,requires_approval:0,pembina_id:'',hari:'',jam_mulai:'',jam_selesai:'',lokasi:'',registration_start:'',registration_end:''})

const formErr=ref({})

const pembinaSearch=ref(''), pembinaOptions=ref([]), pembinaLoading=ref(false), showPembinaList=ref(false)

let pembinaDebounce=null

function validateManage(){

 const err={}

 const n=(form.value.nama||'').trim(), d=(form.value.deskripsi||'').trim(), k=form.value.kuota

 if(!n) err.nama='Nama wajib'

 else if(n.length<3) err.nama='Minimal 3 huruf'

  if(k==null || k==='' || Number(k)<1) err.kuota='Kuota harus >0'

 if(!d) err.deskripsi='Deskripsi wajib (min 10 karakter)'

 else if(d.length<10) err.deskripsi='Minimal 10 karakter'

 if(!(form.value.hari||'').trim()) err.hari='Hari wajib dipilih'

 if(!(form.value.lokasi||'').trim()) err.lokasi='Lokasi wajib diisi'

 if(!form.value.jam_mulai) err.jam_mulai='Jam mulai wajib'

 if(!form.value.jam_selesai) err.jam_selesai='Jam selesai wajib'

 if(form.value.jam_mulai && form.value.jam_selesai && form.value.jam_mulai>=form.value.jam_selesai) err.jam_selesai='Jam selesai harus > jam mulai'

 if(isAdmin.value && !form.value.pembina_id) err.pembina_id='Pembina wajib dipilih'

 formErr.value=err

 return Object.keys(err).length===0

}

function buildManagePayload(){

 const p={}

 for(const k of ['nama','deskripsi','kuota','requires_approval','hari','jam_mulai','jam_selesai','lokasi','pembina_id','registration_start','registration_end']){

 let v=form.value[k]

 if(k==='pembina_id'){

 if(!isAdmin.value) continue

   if(v==='' || v==null) continue

 p[k]=parseInt(v); continue

 }

 if(['hari','lokasi','jam_mulai','jam_selesai','registration_start','registration_end'].includes(k)){

   p[k]=(v===''||v==null)?null:v; continue

 }

 p[k]=v

 }

 return p

}

async function submitManage(){

 if(!validateManage()) return

 try{

 if(editing.value) await api('/ekskul/'+editing.value,{method:'PATCH',body:buildManagePayload()})

 else await api('/ekskul',{method:'POST',body:buildManagePayload()})

 ok.value=true; msg.value=editing.value?'Update berhasil':'Tambah berhasil'; closeModal(); await load(); loadCharts()

 setTimeout(()=> msg.value='',1800)

 }catch(e){ ok.value=false; msg.value=e.error?.message||'Gagal'; formErr.value={...formErr.value,server:msg.value} }

}

async function hapus(e){

 if(!canManage(e)) return

 if(!confirm(`Hapus ${e.nama}? (soft delete)`)) return

 try{ await api('/ekskul/'+e.id,{method:'DELETE',body:{}}); msg.value='Hapus berhasil'; ok.value=true; await load(); loadCharts() }catch(err){ msg.value=err?.error?.message||'Gagal hapus'; ok.value=false }

}

function openCreate(){

 if(!canManageAny.value) return

 editing.value=null; resetManageForm(); showModal.value=true

 if(isAdmin.value) fetchPembina('')

}

function openEdit(e){

 if(!canManage(e)) return

 editing.value=e.id

   Object.assign(form.value,{nama:e.nama,deskripsi:e.deskripsi||'',kuota:e.kuota,requires_approval:e.requires_approval,hari:e.hari||'',jam_mulai:(e.jam_mulai||'').slice(0,5),jam_selesai:(e.jam_selesai||'').slice(0,5),lokasi:e.lokasi||'',pembina_id:e.pembina_id,registration_start:e.registration_start||'',registration_end:e.registration_end||''})

 pembinaSearch.value=e.pembina_nama||''

 if(isAdmin.value) fetchPembina(pembinaSearch.value)

 showModal.value=true

}

  function resetManageForm(){ form.value={nama:'',deskripsi:'',kuota:30,requires_approval:0,pembina_id:'',hari:'',jam_mulai:'',jam_selesai:'',lokasi:'',registration_start:'',registration_end:''}; pembinaSearch.value=''; formErr.value={} }

function closeModal(){ showModal.value=false; editing.value=null; resetManageForm(); showPembinaList.value=false }

async function fetchPembina(q){

 pembinaLoading.value=true

 try{

 const params=new URLSearchParams({role:'pembina',limit:'20'})

 if(q) params.set('search',q)

 const j=await api('/users?'+params.toString())

 pembinaOptions.value=j.data||[]

 }catch{ pembinaOptions.value=[] }finally{ pembinaLoading.value=false }

}

function onPembinaInput(){

 showPembinaList.value=true

 clearTimeout(pembinaDebounce)

 pembinaDebounce=setTimeout(()=> fetchPembina(pembinaSearch.value.trim()),300)

}

function onPembinaFocus(){ showPembinaList.value=true; fetchPembina(pembinaSearch.value.trim()) }

function selectPembina(u){ form.value.pembina_id=u.id; pembinaSearch.value=u.nama; showPembinaList.value=false }

// kalender deep-link: ?tanggal=YYYY-MM-DD&from=kalender -> hint saja (detail jadwal ada di EkskulDetail per-ekskul)

watch(()=> loading.value, (v)=>{

 if(v) return

 try{

 const q=new URLSearchParams(location.search)

 const t=q.get('tanggal'), from=q.get('from')

 if(from==='kalender' && t && /^\d{4}-\d{2}-\d{2}$/.test(t)){

 msg.value=`Dari Kalender ${t} - buka ekskul untuk tambah jadwal pada tanggal tersebut.`; ok.value=true

   setTimeout(()=>{ if(msg.value.startsWith('Dari Kalender')) msg.value='' }, 4000)

   history.replaceState(null,'', location.pathname)

 }

 }catch{}

})

// clamp: hasil menyusut (mis. batal saat myFilter on) - page tak boleh gantung

watch(pages, (n)=>{ if(page.value>n) page.value=n })



watch([search, statusFilter, kuotaFilter, sortFilter], ()=>{

 clearTimeout(debounceTimer)

 debounceTimer=setTimeout(()=>{ page.value=1 },300)

})

watch(myFilter, ()=>{ page.value=1 })

onMounted(()=>{ load(); loadCharts() })

</script>

<style scoped>

/* Mindora tokens, scoped - tanpa bocor ke chrome global */

.kat-page{

 --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3;

 --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85;

 background:var(--m-bg);color:var(--m-ink);

 margin:-24px calc(50% - 50vw) 0;padding:20px max(16px,calc(50vw - 680px)) 24px;

}

.kat-inner{max-width:1360px;margin:0 auto}

/* flat tool head: title + sub 1 baris, no card */

.kat-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:2px 0 10px}

.kat-title{margin:0;font-family:'Satoshi',system-ui,sans-serif;font-size:20px;font-weight:800;letter-spacing:-.01em}

.kat-sub{margin:2px 0 0;font-family:'Satoshi',system-ui,sans-serif;font-size:11.5px;color:var(--m-muted)}

.kat-head-r{display:flex;gap:8px;align-items:center;flex-shrink:0}

.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px;font-family:'Satoshi',system-ui,sans-serif}

.kat-link:hover{border-color:#d4d4d8}

.kat-link.strong{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.kat-link-n{background:rgba(255,255,255,.2);padding:1px 7px;border-radius:999px;font-size:11px}

.kat-strip{margin:0 0 10px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}

.kat-strip.warn{border-left-color:#f59e0b;background:#fffbeb}

.kat-strip a{color:var(--m-cta);font-weight:600}

.kat-toolbar{position:sticky;top:56px;z-index:10;background:var(--m-bg);border-bottom:1px solid var(--m-line);padding:10px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}

.kat-search{position:relative;flex:0 1 280px;min-width:200px}

.kat-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--m-muted)}

.kat-input{width:100%;padding:9px 36px 9px 34px;border:1px solid var(--m-line);background:#fff;border-radius:10px;font-size:13px;outline:none;color:var(--m-ink);font-family:'Satoshi',system-ui,sans-serif}

.kat-input:focus{border-color:var(--m-green);box-shadow:0 0 0 2px rgba(94,184,126,.2)}

.kat-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:24px;height:24px;border-radius:999px;border:1px solid var(--m-line);background:#fff;cursor:pointer;color:var(--m-muted);line-height:1}

.kat-clear:hover{background:var(--m-bg)}

.kat-select{padding:9px 10px;border:1px solid var(--m-line);background:#fff;border-radius:10px;font-size:12.5px;color:var(--m-ink);min-height:38px;min-width:0;font-family:'Satoshi',system-ui,sans-serif}

.kat-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1 1 auto;min-width:0}

.kat-count-top{margin-left:auto;font-size:11px;color:var(--m-muted);white-space:nowrap}

/* mobile: filter non-sticky + compact */

@media(max-width:639px){

 .kat-page{padding:16px 14px 20px}

 .kat-head{flex-direction:column;align-items:flex-start;gap:6px}

 .kat-title{font-size:18px}

 .kat-toolbar{position:static;top:auto;z-index:auto;flex-direction:column;align-items:stretch;padding:8px 0 6px;gap:6px;border-bottom:none}

 .kat-search{flex:none;width:100%;min-width:0}

 .kat-filters{display:grid;grid-template-columns:1fr 1fr;width:100%;flex:none;gap:6px}

 .kat-select{width:100%;padding:7px 10px;min-height:36px;border-radius:9px}

 .kat-input{padding:7px 30px 7px 30px;min-height:36px;border-radius:9px;font-size:13.5px}

 .kat-chip-btn{grid-column:1/-1;justify-content:center;min-height:36px;padding:6px 10px;font-size:12.5px;border-radius:999px}

 .kat-count-top{font-size:10.5px;padding-top:2px}

 .kat-clear{width:22px;height:22px}

 .kat-paging{flex-wrap:wrap}

}

.kat-grid{margin-top:12px;display:grid;gap:12px;grid-template-columns:1fr}

@media(min-width:640px){ .kat-grid{grid-template-columns:repeat(2,1fr)} }

@media(min-width:1024px){ .kat-grid{grid-template-columns:repeat(3,1fr)} }

@media(min-width:1280px){ .kat-grid{grid-template-columns:repeat(4,1fr)} }

/* dense work card: flat, radius 12, quota left-bar, no shadow */

.kat-card{background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-line);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;padding:12px 12px 10px;gap:8px;min-width:0}

.kat-card:hover{border-color:#c9cfcb}

.kat-card.skeleton{opacity:.7;padding:12px}

.kat-card.q-emerald{border-left-color:var(--m-green)}

.kat-card.q-amber{border-left-color:#f59e0b}

.kat-card.q-red{border-left-color:#ef4444}

.kat-card.is-mine-ok{background:#f0fdf6;border-color:#a7f3d0;border-left-color:#10b981}

.kat-card.is-mine-wait{border-style:solid;border-color:#f59e0b;border-left:3px dashed #f59e0b}

.kat-card.is-pending{border-color:#2F3E46}

.kat-card.is-full{opacity:.72}

.kat-top{display:flex;gap:10px;align-items:flex-start;min-width:0}

.kat-top-l{flex:1;min-width:0}

.kat-name{margin:0;font-size:15px;font-weight:700;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.kat-sched{margin:3px 0 0;font-size:11px;color:var(--m-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.kat-thumb{width:44px;height:44px;border-radius:8px;object-fit:cover;display:block;flex-shrink:0;border:1px solid var(--m-line)}

.kat-thumb-empty{width:44px;height:44px;border-radius:8px;display:grid;place-items:center;background:var(--m-bg);border:1px solid var(--m-line);flex-shrink:0}

.kat-thumb-empty span{font-size:18px;font-weight:800;color:var(--m-cta)}

.kat-tags{display:flex;flex-wrap:wrap;gap:5px}

.tag{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:999px;border:1px solid var(--m-line);background:var(--m-bg);color:var(--m-muted);line-height:1.6}

.tag.status{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.tag.kuota.emerald{background:#ecfdf5;color:#047857;border-color:#a7f3d0}

.tag.kuota.amber{background:#fffbeb;color:#b45309;border-color:#fde68a}

.tag.kuota.red{background:#fef2f2;color:#dc2626;border-color:#fecaca}

.tag.mine-ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}

.tag.mine-wait{background:#fffbeb;color:#b45309;border-color:#fde68a}

.tag.mine-no{background:#fef2f2;color:#991b1b;border-color:#fecaca}

.tag.act{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.tag.need{background:#fff;color:var(--m-muted)}

.kat-desc{margin:0;font-size:12.5px;line-height:1.5;color:#52525b;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}

.kat-foot{display:flex;justify-content:space-between;align-items:baseline;gap:8px;min-width:0}

.kat-pembina{font-size:11.5px;color:var(--m-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}

.kat-quota{font-size:11.5px;color:var(--m-ink);white-space:nowrap}

.kat-quota b{font-size:13px}

.kat-bar{height:4px;border-radius:999px;background:var(--m-bg);overflow:hidden}

.kat-bar-fill{height:100%;border-radius:999px}

.kat-bar-fill.bg-emerald{background:var(--m-green)}

.kat-bar-fill.bg-amber{background:#f59e0b}

.kat-bar-fill.bg-red{background:#ef4444}

.kat-cta{display:flex;gap:8px;align-items:center;margin-top:2px;flex-wrap:wrap}

.kat-detail{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:999px;background:var(--m-cta);color:#fff;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap;border:1px solid var(--m-cta);line-height:1;transition:background .15s,transform .15s,box-shadow .15s}

.kat-detail:hover{background:#3d6462;transform:translateY(-1px);box-shadow:0 4px 12px rgba(74,120,117,.25);text-decoration:none;color:#fff}

.kat-detail:active{transform:none;box-shadow:none}

.kat-detail-arrow{font-size:13px;line-height:1;transition:transform .15s}

.kat-detail:hover .kat-detail-arrow{transform:translateX(2px)}

.kat-mini{margin-left:auto;padding:5px 10px;border-radius:8px;border:1px solid var(--m-line);background:#fff;font-size:11.5px;font-weight:600;cursor:pointer;white-space:nowrap;color:var(--m-ink);font-family:'Satoshi',system-ui,sans-serif}

.kat-mini + .kat-mini{margin-left:0}

.kat-mini.neutral:hover{background:var(--m-bg)}

.kat-mini.danger{border-color:#fecaca;color:#991b1b}

.kat-mini.danger:hover{background:#fef2f2}

.kat-charts{margin-top:12px;display:grid;gap:10px;grid-template-columns:repeat(2,1fr)}
.kat-chart-card:first-child{grid-column:1/-1}
@media(min-width:900px){.kat-charts{grid-template-columns:repeat(3,1fr);gap:12px}.kat-chart-card:first-child{grid-column:auto}}
.kat-chart-card{background:#fff;border:1px solid var(--m-line);border-radius:12px;padding:12px;min-width:0}
.kat-chart-title{margin:0 0 4px;font-size:13px;font-weight:700;font-family:'Satoshi',system-ui,sans-serif}
.kat-chart-sub{font-size:11px;font-weight:400;color:var(--m-muted)}
.kat-empty{margin-top:16px;background:#fff;border:1px dashed var(--m-line);border-radius:16px;padding:40px;text-align:center;color:var(--m-muted);font-size:13px}

.kat-chip-btn{padding:8px 12px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;color:var(--m-ink);min-height:40px;font-family:'Satoshi',system-ui,sans-serif}

.kat-chip-btn.active{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.kat-chip-count{background:rgba(0,0,0,.08);padding:2px 6px;border-radius:999px;font-size:11px;min-width:18px;text-align:center}

.kat-chip-btn.active .kat-chip-count{background:rgba(255,255,255,.2)}

.badge-mine{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid;font-weight:500}

.badge-mine.diterima{background:#ecfdf5;color:#047857;border-color:#a7f3d0}

.badge-mine.menunggu{background:#fffbeb;color:#b45309;border-color:#fde68a}

.badge-mine.ditolak{background:#fef2f2;color:#991b1b;border-color:#fecaca}

/* legacy CTA classes retired: kat-cta now a slim action row (see .kat-detail/.kat-mini above) */

.kat-cta-row-legacy{display:none}

.kat-paging{margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:13px}

.kat-count{color:var(--m-muted)}

.kat-paging-btns{display:flex;gap:8px;align-items:center}

.kat-page-btn{padding:6px 12px;border-radius:8px;border:1px solid var(--m-line);background:#fff;font-size:13px;min-width:32px;min-height:32px;color:var(--m-ink);font-family:'Satoshi',system-ui,sans-serif}

.kat-page-btn:disabled{opacity:.4;cursor:not-allowed}

.kat-page-num{padding:6px 10px;border:1px solid var(--m-line);background:#fff;border-radius:8px;font-size:12px;color:var(--m-ink)}

.skel-line{height:10px;background:#e7eceb;border-radius:6px;margin-bottom:8px}

.skel-line.w40{width:40%}.skel-line.w80{width:80%}.skel-line.w90{width:90%}

.visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}

.kat-alert{margin-top:12px;padding:10px 12px;border-radius:10px;font-size:13px}

.kat-alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}

.kat-alert.err{background:#fef2f2;color:#991b1b;border-color:#fecaca;border:1px solid #fecaca}

.kat-detail:focus-visible,.kat-mini:focus-visible,.kat-page-btn:focus-visible,.kat-chip-btn:focus-visible,.kat-clear:focus-visible,.kat-input:focus-visible,.kat-select:focus-visible,.kat-link:focus-visible{outline:2px solid var(--m-green);outline-offset:2px}

</style>

