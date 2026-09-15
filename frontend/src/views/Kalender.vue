<template>



<div class="kat-page">



<div class="kat-inner">



 <!-- head: flat tool header ( Katalog/Events) -->



 <div class="kat-head">



 <div class="kat-head-l">



    <h1 class="kat-title" :title="bulanLabel + (conflictsCount ? ' - ' + conflictsCount + ' bentrok' : '')">Kalender Terpusat</h1>



 </div>



 <div class="kat-head-r">



 <button class="kat-link" style="cursor:pointer" @click="goToday" aria-label="Ke hari ini">Hari ini</button>



 <div class="kat-nav">



 <button class="kat-nav-btn" @click="navDebounced(-1)" aria-label="Bulan sebelumnya"><</button>



    <span class="kat-nav-label mono" :title="(data?.all?.length||0)+' item - '+filteredGrouped.length+' tanggal'+(conflictsCount?' - '+conflictsCount+' bentrok':'')">{{ bulanLabel }}</span>



 <button class="kat-nav-btn" @click="navDebounced(1)" aria-label="Bulan berikutnya">></button>



 </div>







 </div>



 </div>















 <!-- view switcher + toolbar: sticky filters ( Katalog kat-toolbar) -->



 <div class="kat-toolbar">



 <div class="kat-view mono" role="tablist" aria-label="Mode tampilan">



  <button :class="['kat-chip','kat-chip-view', view==='month'?'active':'']" @click="setView('month')" role="tab" :aria-selected="view==='month'">Bulan</button>



  <button :class="['kat-chip','kat-chip-view', view==='week'?'active':'']" @click="setView('week')" role="tab" :aria-selected="view==='week'">Minggu</button>



  <button :class="['kat-chip','kat-chip-view', view==='list'?'active':'']" @click="setView('list')" role="tab" :aria-selected="view==='list'">Daftar</button>



 </div>



 <div class="kat-filters" role="tablist" aria-label="Filter tipe jadwal">



  <button :class="['kat-chip', filter==='all'?'active':'']" @click="filter='all'" role="tab" :aria-selected="filter==='all'">Semua</button>



  <button :class="['kat-chip', filter==='rutin'?'active':'']" @click="filter='rutin'" role="tab" :aria-selected="filter==='rutin'">Rutin</button>



  <button :class="['kat-chip', filter==='tambahan'?'active':'']" @click="filter='tambahan'" role="tab" :aria-selected="filter==='tambahan'">Tambahan</button>



  <button :class="['kat-chip', filter==='event'?'active':'']" @click="filter='event'" role="tab" :aria-selected="filter==='event'">Event</button>



  <button v-if="!isAdminKepsek" :class="['kat-chip', filter==='ekskul'?'active':'']" @click="filter='ekskul'" role="tab" :aria-selected="filter==='ekskul'">Ekskul Saya</button>



 <select v-if="isAdminKepsek" v-model="adminEkskulFilter" class="kat-select" aria-label="Filter ekskul (admin)" style="min-width:160px">



  <option value="">Ekskul: Semua</option>



 <option v-for="e in ekskulOptions" :key="e.id" :value="String(e.id)">{{ e.nama }}</option>



 </select>



  <label class="kat-chip kat-chip-check" :class="onlyBentrok?'active':''" :title="onlyBentrok?'Hanya tanggal bentrok':'Filter hanya bentrok'">



 <input type="checkbox" v-model="onlyBentrok" style="accent-color:var(--m-ink)" /> Hanya bentrok



 </label>



 </div>



 </div>



 <!-- list search - only Daftar -->



  <div v-if="view==='list'" class="kat-searchbar">



  <input v-model="listSearch" class="kat-search" placeholder="Cari nama ekskul / lokasi / pembina..." aria-label="Cari daftar" />



 </div>















 <!-- MONTH layout -->



  <div v-if="view==='month'" class="kal-grid-layout">



 <!-- calendar card -->



 <div id="kalCard" class="kat-card kal-card">



 <div class="kal-weekhead">



 <div v-for="d in weekDays" :key="d" class="kal-wh mono">{{ d }}</div>



 </div>



 <!-- skeleton -->



 <div v-if="loading" class="kal-grid skeleton">



 <div v-for="i in 42" :key="i" class="kal-cell kal-skeleton-cell"><div class="skel-line"></div><div class="skel-dots"><span></span><span></span></div></div>



 </div>



 <!-- grid -->



 <div v-else class="kal-grid" v-memo="[filter, adminEkskulFilter, onlyBentrok, dataKey, selectedDate, conflictsKey, pulseDate]">



 <div v-for="(cell, idx) in cells" :key="idx"



  :class="['kal-cell', cell.isEmpty?'kal-cell-empty':'', cell.isToday?'kal-cell-today':'', selectedDate===cell.dateStr?'kal-cell-selected':'', pulseDate===cell.dateStr?'kal-cell-pulse':'', cell.isBentrok?'kal-cell-bentrok':'']"



  :data-date="cell.dateStr||''"



 :aria-label="cell.ariaLabel"



  :title="cell.isBentrok ? ('BENTROK - '+ (conflictTooltip(cell.dateStr)||'tap lihat detail')) : cell.ariaLabel"



  :aria-current="cell.isToday?'date':undefined"



 :tabindex="cell.isEmpty? -1 : 0"



 @click="!cell.isEmpty && selectDate(cell.dateStr)"



 @keydown.enter="!cell.isEmpty && selectDate(cell.dateStr)"



 @keydown.space.prevent="!cell.isEmpty && selectDate(cell.dateStr)"



 role="gridcell">



 <template v-if="!cell.isEmpty">



 <div class="kal-cell-top">



  <span :class="['kal-date-num', cell.isToday?'kal-date-today':'']">{{ cell.day }}</span>



 <span v-if="cell.isBentrok" class="badge-bentrok">BENTROK</span>



 </div>



 <div class="kal-dots">



  <span v-for="(ev,i) in cell.filtered" :key="i" :class="['dot', dotClass(ev.tipe)]" :title="ev.ekskul_nama"></span>



 </div>



 <div class="kal-cell-labels">



  <div v-for="(ev,i) in cell.filtered.slice(0,2)" :key="i" :class="['kal-ev-label', ev.tipe==='event'?'ev-event':'ev-rutin']">{{ ev.ekskul_nama }}</div>



  <div v-if="cell.filtered.length>2" class="kal-more mono">+{{ cell.filtered.length-2 }} lagi</div>



 </div>



 <div v-if="cell.isToday" class="kal-today-tag mono">HARI INI</div>



 </template>



 </div>



 </div>



 </div>







 <!-- agenda -->



 <div class="kat-card kal-agenda">



 <div class="kal-agenda-head">



 <div>



 <div class="kal-agenda-title">Agenda</div>



 </div>



 </div>



  <div v-if="loading" class="kal-agenda-list">



  <div v-for="i in 3" :key="i" class="agenda-skel"><div class="skel-line w60"></div><div class="skel-line w90"></div></div>



  </div>



  <div v-else-if="agendaList.length===0" class="kal-empty">Tidak ada jadwal</div>



 <div v-else class="kal-agenda-list">



 <div v-for="item in agendaList" :key="itemKey(item)" class="agenda-row" @click="goDetail(item)" role="button" tabindex="0" @keydown.enter="goDetail(item)">



  <div :class="['agenda-bar', barColor(item.tipe)]"></div>



 <div class="agenda-main">



 <div class="agenda-topline">



 <span class="mono agenda-time">{{ item.jam_mulai }}- {{ item.jam_selesai }}</span>



 <span :class="['agenda-badge', item.isBentrok?'badge-bentrok-light':'badge-idle']">{{ badgeLabel(item) }}</span>



 </div>



 <div class="agenda-title">{{ item.ekskul_nama }}</div>



 <div class="agenda-meta mono">{{ item.lokasi || 'Lokasi belum diisi' }} - {{ item.pembina_nama || 'Pembina -' }} - {{ item.terisi }}/{{ item.kuota }} - sisa {{ item.sisa }}</div>



 </div>



 <span class="agenda-arrow">></span>



 </div>



 </div>



 <div v-if="conflictsForSelected.length" id="conflictBox" class="kal-conflict-box">



 <div class="conflict-icon">!</div>



 <div class="conflict-text">



 <div class="conflict-title">Bentrok terdeteksi - {{ selectedDate }} ({{ conflictsForSelected.length }})</div>



 <div class="conflict-desc mono" v-for="(c,i) in conflictsForSelected.slice(0,3)" :key="i">{{ c.a.nama }} {{ c.a.jam }} bentrok dengan {{ c.b.nama }} {{ c.b.jam }}</div>



  <div v-if="conflictsForSelected.length>3" class="conflict-desc mono">+{{ conflictsForSelected.length-3 }} bentrok lagi di hari ini</div>



 </div>



 </div>



 <div v-else-if="data?.conflicts?.length" id="conflictBox" class="kal-conflict-box kal-conflict-muted">



 <div class="conflict-icon">!</div>



 <div class="conflict-text">



 <div class="conflict-title">Bentrok bulan ini: {{ data.conflicts.length }}</div>



 <div class="conflict-desc mono" v-for="(c,i) in data.conflicts.slice(0,3)" :key="i">{{ c.a.nama }} {{ c.a.jam }} bentrok {{ c.b.nama }} {{ c.b.jam }} - {{ c.tanggal }}</div>



  <div v-if="data.conflicts.length>3" class="conflict-desc mono">+{{ data.conflicts.length-3 }} bentrok lagi</div>



 </div>



 </div>



 </div>



 </div>







 <!-- WEEK layout (read-only 7 kolom, reuse scoped data) -->



  <div v-else-if="view==='week'" class="kal-grid-layout">



 <div id="kalCard" class="kat-card kal-card">



 <div class="kal-weekbar mono">



 <button class="kat-nav-btn" @click="shiftWeek(-1)" aria-label="Minggu sebelumnya"><</button>



 <span class="kal-weeklabel">{{ weekLabel }}</span>



 <button class="kat-nav-btn" @click="shiftWeek(1)" aria-label="Minggu berikutnya">></button>



 </div>



 <div v-if="loading" class="kal-week skeleton">



 <div v-for="i in 7" :key="i" class="kal-week-col"><div class="skel-line" style="margin:12px"></div></div>



 </div>



 <div v-else class="kal-week-wrap">



 <div class="kal-week" v-memo="[filter, adminEkskulFilter, onlyBentrok, selectedDate, weekKey, pulseDate]">



 <div v-for="col in weekCols" :key="col.dateStr"



   :class="['kal-week-col', col.isToday?'kal-week-col-today':'', selectedDate===col.dateStr?'kal-week-col-selected':'', pulseDate===col.dateStr?'kal-cell-pulse':'']"



 :data-date="col.dateStr"



 @click="selectDate(col.dateStr)">



  <div class="kal-week-col-head" :class="col.isToday?'isToday':''">

 

  <div class="mono kal-wdow">{{ col.dow }}</div>

 

  <div :class="['kal-date-num', col.isToday?'kal-date-today':'']" style="margin:4px auto 0">{{ col.day }}</div>



 <div class="mono kal-wdate">{{ col.dateStr.slice(5) }}</div>



 <div v-if="col.isBentrok" class="badge-bentrok" style="margin-top:4px;display:inline-block">BENTROK</div>



 </div>



 <div class="kal-week-col-body">



  <div v-if="col.items.length===0" class="mono kal-week-empty">-</div>

 

  <div v-for="it in col.items" :key="itemKey(it)" :class="['kal-week-item', it.isBentrok?'kal-week-item-bentrok':'']" @click.stop="goDetail(it)">



 <div class="mono kal-week-time">{{ it.jam_mulai }}- {{ it.jam_selesai }}</div>



 <div class="kal-week-title">{{ it.ekskul_nama }}</div>



 <div class="mono kal-week-meta">{{ it.lokasi || '-' }} - {{ badgeLabel(it) }}</div>



 </div>



 </div>



 </div>



 </div>



 </div>



 </div>



 <!-- agenda reuse same -->



 <div class="kat-card kal-agenda">



 <div class="kal-agenda-head">



 <div>



 <div class="kal-agenda-title">Agenda</div>



 </div>



 </div>



  <div v-if="loading" class="kal-agenda-list"><div v-for="i in 3" :key="i" class="agenda-skel"><div class="skel-line w60"></div><div class="skel-line w90"></div></div></div>



  <div v-else-if="agendaList.length===0" class="kal-empty">Tidak ada jadwal</div>



 <div v-else class="kal-agenda-list">



 <div v-for="item in agendaList" :key="itemKey(item)" class="agenda-row" @click="goDetail(item)" role="button" tabindex="0" @keydown.enter="goDetail(item)">



  <div :class="['agenda-bar', barColor(item.tipe)]"></div>



 <div class="agenda-main">



 <div class="agenda-topline"><span class="mono agenda-time">{{ item.jam_mulai }}- {{ item.jam_selesai }}</span><span :class="['agenda-badge', item.isBentrok?'badge-bentrok-light':'badge-idle']">{{ badgeLabel(item) }}</span></div>



 <div class="agenda-title">{{ item.ekskul_nama }}</div>



 <div class="agenda-meta mono">{{ item.lokasi || 'Lokasi belum diisi' }} - {{ item.pembina_nama || 'Pembina -' }} - {{ item.terisi }}/{{ item.kuota }} - sisa {{ item.sisa }}</div>



 </div><span class="agenda-arrow">></span>



 </div>



 </div>



 <div v-if="conflictsForSelected.length" class="kal-conflict-box"><div class="conflict-icon">!</div><div class="conflict-text"><div class="conflict-title">Bentrok - {{ selectedDate }} ({{ conflictsForSelected.length }})</div><div class="conflict-desc mono" v-for="(c,i) in conflictsForSelected.slice(0,3)" :key="i">{{ c.a.nama }} {{ c.a.jam }} bentrok {{ c.b.nama }} {{ c.b.jam }}</div></div></div>



 </div>



 </div>







 <!-- LIST layout (grouped by tanggal, search + bentrok filter, no extra fetch) -->



 <div v-else class="kat-card kal-list-card">



 <div class="kal-list-head mono">



 <span>Daftar bulan ini</span>



 </div>



 <div v-if="loading" class="kal-list">



 <div v-for="i in 4" :key="i" class="agenda-skel"><div class="skel-line w60"></div><div class="skel-line w90"></div></div>



 </div>



  <div v-else-if="filteredGrouped.length===0" class="kal-empty">Tidak ada jadwal - ubah filter / kata kunci</div>



 <div v-else class="kal-list">



 <div v-for="g in filteredGrouped" :key="g.tanggal" class="kal-list-group">



  <div class="kal-list-date" :class="g.isBentrok?'kal-list-date-bentrok':''">



 <div class="kal-list-date-l">



 <span class="kal-list-dow mono">{{ g.dow }}</span>



 <span class="kal-list-d mono">{{ g.tanggal }}</span>



 <span v-if="g.isToday" class="badge-bentrok" style="background:var(--m-ink)">HARI INI</span>



 <span v-if="g.isBentrok" class="badge-bentrok">BENTROK</span>



 </div>



 <span class="mono kal-list-count">{{ g.items.length }} jadwal</span>



 </div>



 <div v-for="item in g.items" :key="itemKey(item)" class="agenda-row" @click="goDetail(item)" role="button" tabindex="0" @keydown.enter="goDetail(item)">



  <div :class="['agenda-bar', barColor(item.tipe)]"></div>



 <div class="agenda-main">



 <div class="agenda-topline"><span class="mono agenda-time">{{ item.jam_mulai }}- {{ item.jam_selesai }}</span><span :class="['agenda-badge', item.isBentrok?'badge-bentrok-light':'badge-idle']">{{ badgeLabel(item) }}</span></div>



 <div class="agenda-title">{{ item.ekskul_nama }}</div>



 <div class="agenda-meta mono">{{ item.lokasi || 'Lokasi belum diisi' }} - {{ item.pembina_nama || 'Pembina -' }} - {{ item.terisi }}/{{ item.kuota }} - sisa {{ item.sisa }}</div>



 </div><span class="agenda-arrow">></span>



 </div>



 </div>



 </div>



 </div>







 <!-- aksi cepat -->



 <div class="kat-card kal-quick">



 <div class="strip-k mono">AKSI CEPAT</div>



 <div class="strip-actions">



 <button class="kat-chip" @click="exportCSV">Export CSV</button>



  <button class="kat-chip" :class="!conflictsCount?'kat-chip-disabled':''" :disabled="!conflictsCount" :title="conflictsCount?conflictsCount+' bentrok - klik scroll ke kalender':'Bulan ini aman, gak ada bentrok'" @click="scrollToConflicts">Lihat bentrok<span v-if="conflictsCount" class="kat-chip-count">{{ conflictsCount }}</span></button>



 <button v-if="canCreate" class="kat-chip kat-chip-cta" @click="goBuatJadwal">+ Jadwal</button>



 <button v-if="canCreateEvent" class="kat-chip kat-chip-cta strong" @click="goBuatEvent">+ Event</button>



 </div>



 </div>



 <p v-if="toast" class="kat-toast mono" role="status">{{ toast }}</p>



</div>



</div>



</template>







<script setup>



import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'



import { useRoute, useRouter } from 'vue-router'



import { useAuth } from '../stores/auth.js'



import { api } from '../lib/api.js'







const route = useRoute()



const router = useRouter()



const auth = useAuth()







function localISODate(d=new Date()){ const y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), day=String(d.getDate()).padStart(2,'0'); return `${y}-${m}-${day}` }



const realToday = localISODate()



const realBulan = realToday.slice(0,7)



function isValidBulan(v){ return /^\d{4}-(0[1-9]|1[0-2])$/.test(v) }



function isValidISODate(v){ return /^\d{4}-\d{2}-\d{2}$/.test(v) && !isNaN(new Date(v+'T00:00:00').getTime()) }



function isValidView(v){ return ['month','week','list'].includes(v) }



const initBulan = isValidBulan(route.query.bulan) ? route.query.bulan : realBulan



const initView = isValidView(route.query.view) ? route.query.view : (isValidView(localStorage.getItem('kalView')) ? localStorage.getItem('kalView') : 'month')



const initHighlightRaw = route.query.highlight || route.query.tanggal || ''



const initHighlight = isValidISODate(initHighlightRaw) ? initHighlightRaw : ''







const bulan = ref(initHighlight ? initHighlight.slice(0,7) : initBulan)



const loading = ref(false)



const data = ref(null)



const fetchMeta = ref(null)



const etagShort = ref('-')



const filter = ref('all')



const adminEkskulFilter = ref('')



const selectedDate = ref(initHighlight || realToday)



const isCached = ref(false)



const pulseDate = ref('')



const toast = ref('')



let toastTimer=null, pulseTimer=null







const view = ref(initView)



  const listSearch = ref('')



const onlyBentrok = ref(false)







const cacheMap = new Map()



const etagMap = new Map()



let abortCtrl = null



let navTimer = null







const weekDays = ['Sen','Sel','Rab','Kam','Jum','Sab','Min']



const role = computed(()=> auth.user?.role || '')



const isAdminKepsek = computed(()=> ['admin','kepsek'].includes(role.value))



const canCreate = computed(()=> auth.user && ['admin','pembina'].includes(auth.user.role))



const canCreateEvent = computed(()=> canCreate.value)



function isInputFocused(){ const a=document.activeElement; return a && (a.tagName==='INPUT' || a.tagName==='TEXTAREA' || a.tagName==='SELECT' || a.isContentEditable) }







const ekskulOptions = ref([])



async function loadEkskulOptions(){



 try{ const j=await api('/ekskul?limit=100'); ekskulOptions.value=j.data||[] }catch{}



}







const bulanLabel = computed(()=>{



 try{ const d=new Date(bulan.value+'-01'); return d.toLocaleDateString('id-ID',{month:'long', year:'numeric'}) }catch{ return bulan.value }



})



const conflictsCount = computed(()=> data.value?.conflicts?.length ?? 0)



const agendaSub = computed(()=>{



 if(!selectedDate.value) return '-'



 const d=new Date(selectedDate.value)



 const s = d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})



 const isToday = selectedDate.value===realToday



  return `${s}${isToday?' - Hari ini':''}`



})



const dataKey = computed(()=> data.value? (data.value.all?.length||0)+'-'+(data.value.conflicts?.length||0) : '0')



const conflictsKey = computed(()=> (data.value?.conflicts||[]).map(c=>c.tanggal+c.a.jam+c.b.jam).join('|'))







function dotClass(t){ return t==='rutin'?'dot-rutin':t==='tambahan'?'dot-tambahan':'dot-event' }



function barColor(t){ return t==='rutin'?'bar-rutin':t==='tambahan'?'bar-tambahan':t==='event'?'bar-event':'bar-bentrok' }



function badgeLabel(item){ if(item.isBentrok) return 'BENTROK'; if(item.tipe==='rutin') return 'Rutin'; if(item.tipe==='tambahan') return 'Tambahan'; return 'Event - approved' }



function itemKey(it){ return (it.tipe||'')+'-'+(it.id||it.ekskul_id||'')+'-'+it.tanggal+'-'+it.jam_mulai }







function applyViewFilter(list){



 let out = list



 if(filter.value !== 'all'){



 if(filter.value === 'ekskul'){



 out = out.filter(x=> x.tipe==='rutin' || x.tipe==='tambahan')



 } else {



 out = out.filter(x=> x.tipe === filter.value)



 }



 }



 if(isAdminKepsek.value && adminEkskulFilter.value){



 if(filter.value === 'event'){



 } else {



 const want = String(adminEkskulFilter.value)



 out = out.filter(x=> x.tipe==='event' ? true : String(x.ekskul_id ?? '') === want)



 }



 }



 if(onlyBentrok.value){



 const bentrokDates=new Set((data.value?.conflicts||[]).map(c=>c.tanggal))



 out = out.filter(x=> bentrokDates.has(x.tanggal))



 }



 return out



}







const cells = computed(()=>{



 const [y,m]=bulan.value.split('-').map(Number)



 const daysInMonth=new Date(y,m,0).getDate()



 const first=new Date(y,m-1,1)



 const jsDow=first.getDay()



 const offset = jsDow===0?6:jsDow-1



 const allRows = data.value?.all || []



 const bentrokDates = new Set((data.value?.conflicts||[]).map(c=>c.tanggal))



 const byDateMap=new Map()



 for(const r of allRows){ if(!byDateMap.has(r.tanggal)) byDateMap.set(r.tanggal,[]); byDateMap.get(r.tanggal).push(r) }



 const arr=[]



 for(let i=0;i<42;i++){



  const dayNum=i - offset +1



 if(dayNum<1 || dayNum>daysInMonth){ arr.push({isEmpty:true}); continue }



 const dd=String(dayNum).padStart(2,'0'); const mm=String(m).padStart(2,'0'); const dateStr=`${y}-${mm}-${dd}`



 const raw = byDateMap.get(dateStr)||[]



 let filtered = applyViewFilter(raw)



 const isBentrok=bentrokDates.has(dateStr)



 const isToday=dateStr===realToday



 const cnt=filtered.length



   const aria=`${dayNum} September ${y}, ${cnt} jadwal${isBentrok?', bentrok':''}${isToday?', hari ini':''}`



 arr.push({ isEmpty:false, day:dayNum, dateStr, raw, filtered, isBentrok, isToday, ariaLabel:aria })



 }



 return arr



})







const agendaList = computed(()=>{



 const all=data.value?.all||[]



 const bentrokDates=new Set((data.value?.conflicts||[]).map(c=>c.tanggal))



 let list=applyViewFilter(all.filter(x=> x.tanggal===selectedDate.value))



 list=[...list].sort((a,b)=> String(a.jam_mulai).localeCompare(String(b.jam_mulai)))



 return list.map(x=> ({...x, isBentrok:bentrokDates.has(x.tanggal)}))



})



const conflictsForSelected = computed(()=> (data.value?.conflicts||[]).filter(c=> c.tanggal===selectedDate.value))



function conflictTooltip(dateStr){



 const list=(data.value?.conflicts||[]).filter(c=>c.tanggal===dateStr).slice(0,2)



 if(!list.length) return ''



 return list.map(c=> `${c.a.nama} ${c.a.jam} bentrok ${c.b.nama} ${c.b.jam}`).join(' - ')



}







function selectDate(d){ selectedDate.value=d }



function goDetail(item){



 if(item.tipe==='event') router.push(`/events/${item.id}`)



 else if(item.ekskul_id) router.push(`/ekskul/${item.ekskul_id}`)



 else router.push(`/ekskul/${item.id}`)



}







// --- WEEK (reuse same bulan data, derive 7 days around selectedDate) ---



function weekStartOf(dateStr){



 const d=new Date(dateStr+'T00:00:00'); const js=d.getDay(); const off=js===0?6:js-1; d.setDate(d.getDate()-off); return d



}



const weekStart = computed(()=> weekStartOf(isValidISODate(selectedDate.value)?selectedDate.value:realToday))



const weekCols = computed(()=>{



 const bentrokDates=new Set((data.value?.conflicts||[]).map(c=>c.tanggal))



 const all=data.value?.all||[]



 const byDate=new Map(); for(const r of all){ if(!byDate.has(r.tanggal)) byDate.set(r.tanggal,[]); byDate.get(r.tanggal).push(r) }



 const dowNames=['Sen','Sel','Rab','Kam','Jum','Sab','Min']



 const cols=[]



 for(let i=0;i<7;i++){



  const d=new Date(weekStart.value); d.setDate(d.getDate()+i); const dateStr=localISODate(d); const raw=byDate.get(dateStr)||[]; let items=applyViewFilter(raw).slice().sort((a,b)=> String(a.jam_mulai).localeCompare(String(b.jam_mulai)))



 const isBentrok=bentrokDates.has(dateStr)



 items=items.map(x=> ({...x, isBentrok}))



 cols.push({ dateStr, dow:dowNames[i], day:d.getDate(), isToday:dateStr===realToday, isBentrok, items })



 }



 return cols



})



const weekKey = computed(()=> weekCols.value.map(c=> c.dateStr+':'+c.items.length).join('|'))



const weekLabel = computed(()=>{



 const s=weekStart.value; const e=new Date(s); e.setDate(e.getDate()+6)



 const a=s.toLocaleDateString('id-ID',{day:'2-digit',month:'short'}); const b=e.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})



 return `${a} - ${b}`



})



const weekBulanNote = computed(()=>{



 const ws=localISODate(weekStart.value)



 const we=localISODate(new Date(weekStart.value.getTime()+6*86400000))



 const same = ws.slice(0,7)===bulan.value && we.slice(0,7)===bulan.value



   return same ? '' : 'lintas bulan - tetap dari data '+bulan.value



})



function shiftWeek(dir){



 const d=new Date(selectedDate.value+'T00:00:00'); d.setDate(d.getDate()+dir*7)



 const ns=localISODate(d); selectedDate.value=ns



 const nb=ns.slice(0,7)



 if(nb!==bulan.value){ bulan.value=nb; syncRoute(nb); fetchBulan(nb) }



}







// --- LIST grouped ---



const listGroupedRaw = computed(()=>{



 const all=data.value?.all||[]



 const bentrokDates=new Set((data.value?.conflicts||[]).map(c=>c.tanggal))



 let rows=applyViewFilter(all.slice())



 // search deferred to filteredGrouped



 rows=[...rows].sort((a,b)=> a.tanggal===b.tanggal ? String(a.jam_mulai).localeCompare(String(b.jam_mulai)) : a.tanggal.localeCompare(b.tanggal))



 const map=new Map()



 for(const r of rows){



 if(!map.has(r.tanggal)) map.set(r.tanggal,[])



 map.get(r.tanggal).push({...r, isBentrok:bentrokDates.has(r.tanggal)})



 }



 const dowFull=['Min','Sen','Sel','Rab','Kam','Jum','Sab']



 const out=[]



 for(const [tanggal, items] of map){



 const d=new Date(tanggal+'T00:00:00'); const dow=dowFull[d.getDay()]



 out.push({ tanggal, dow, isToday:tanggal===realToday, isBentrok:bentrokDates.has(tanggal), items })



 }



 return out



})



const filteredGrouped = computed(()=>{



 const q=listSearch.value.trim().toLowerCase()



 if(!q) return listGroupedRaw.value



 return listGroupedRaw.value.map(g=>{



 const items=g.items.filter(it=> `${it.ekskul_nama} ${it.lokasi||''} ${it.pembina_nama||''}`.toLowerCase().includes(q))



 return {...g, items}



 }).filter(g=> g.items.length>0)



})



const listTotal = computed(()=> filteredGrouped.value.reduce((a,g)=> a+g.items.length,0))







let t0=0



async function fetchBulan(b){



 if(abortCtrl) try{ abortCtrl.abort() }catch{}



 abortCtrl=new AbortController()



 loading.value=true; isCached.value=false



 const start=performance.now()



 try{



 const headers={}



 if(etagMap.has(b)) headers['If-None-Match']=etagMap.get(b)



 const res=await fetch(`/api/kalender?bulan=${encodeURIComponent(b)}`,{ credentials:'include', headers, signal:abortCtrl.signal })



 const ms=Math.round(performance.now()-start)



 if(res.status===304){



 data.value=cacheMap.get(b) || data.value



 isCached.value=true



 fetchMeta.value={ count:(data.value?.all?.length||0), ms }



  const et=etagMap.get(b)||''



 etagShort.value=et? et.slice(1,7) : '304'



 return



 }



 if(!res.ok){



 const txt=await res.text()



 throw new Error(txt || res.statusText)



 }



 const j=await res.json()



 const d=j.data



 cacheMap.set(b,d)



  const et=res.headers.get('ETag')||''



 if(et){ etagMap.set(b,et); etagShort.value=et.slice(1,7) }



 else etagShort.value='fresh'



 data.value=d



 fetchMeta.value={ count:(d.all?.length||0), ms }



 if(selectedDate.value.slice(0,7)!==b){



 const want = b===realBulan ? realToday : b+'-01'



 selectedDate.value = d.all?.some(x=>x.tanggal===want) ? want : b+'-01'



 }



 }catch(e){



 if(e.name==='AbortError') return



 console.error(e)



 }finally{



 loading.value=false



 }



}







function syncRoute(b){



 const q={ ...route.query, bulan:b }



 if(view.value!=='month') q.view=view.value; else delete q.view



 if(route.query.bulan!==b || route.query.view!==q.view) router.replace({ query:q })



}



function setView(v){



 if(!isValidView(v)) return



 view.value=v



 try{ localStorage.setItem('kalView',v) }catch{}



 syncRoute(bulan.value)



}



function validateBulanOrRedirect(v){



 if(!isValidBulan(v)){ router.replace({ query:{ ...route.query, bulan:realBulan } }); return false }



 return true



}







function navDebounced(dir){



 if(navTimer) clearTimeout(navTimer)



 navTimer=setTimeout(()=>{



 const [y,m]=bulan.value.split('-').map(Number)



 const d=new Date(y,m-1,1); d.setMonth(d.getMonth()+dir)



 const nb=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`



 bulan.value=nb; syncRoute(nb); fetchBulan(nb)



 },150)



}



function goToday(){



 const t=realToday



 bulan.value=t.slice(0,7); selectedDate.value=t; syncRoute(bulan.value); fetchBulan(bulan.value)



}



function onKey(e){



 if(isInputFocused()) return



 if(e.key==='ArrowLeft') navDebounced(-1)



 if(e.key==='ArrowRight') navDebounced(1)



}



function exportCSV(){



 const rows=agendaList.value



 let csv='tanggal,jam_mulai,jam_selesai,nama,tipe,lokasi,kuota,terisi,sisa\n'



 for(const r of rows) csv+=`${r.tanggal},${r.jam_mulai},${r.jam_selesai},${r.ekskul_nama},${r.tipe},${r.lokasi||''},${r.kuota},${r.terisi},${r.sisa}\n`



 const blob=new Blob([csv],{type:'text/csv'}); const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download=`kalender-${selectedDate.value}.csv`; a.click(); URL.revokeObjectURL(url)



}



function showToast(msg){



 toast.value=msg



 clearTimeout(toastTimer)



  toastTimer=setTimeout(()=> toast.value='', 2600)



}



function flashPulse(dateStr){



 pulseDate.value=dateStr



 clearTimeout(pulseTimer)



  pulseTimer=setTimeout(()=> pulseDate.value='', 3600)



}



function handleHighlightParam(h){



 if(!h || !isValidISODate(h)) return



 const nb=h.slice(0,7)



 selectedDate.value=h



 if(nb!==bulan.value){ bulan.value=nb; syncRoute(nb); fetchBulan(nb) }



 // tunggu render lalu kedip 3-4 detik + scroll ke tanggal



 setTimeout(()=>{



 flashPulse(h)



  const cell=document.querySelector(`.kal-cell[data-date="${h}"]`) || document.querySelector(`.kal-week-col[data-date="${h}"]`) || document.querySelector(`.kal-list-group [data-date="${h}"]`)



 if(cell) cell.scrollIntoView({behavior:'smooth', block:'center', inline:'center'})



 showToast(`Highlight ${h} - kedip 3 detik`)



 }, 180)



}



function scrollToConflicts(){



 const list=data.value?.conflicts||[]



 if(!list.length){ showToast('Bulan ini aman - tidak ada bentrok'); return }



 const uniq=[...new Set(list.map(c=>c.tanggal))].sort()



 // next >= selectedDate, kalau gak ada -> wrap ke awal. Kalau selectedDate sudah bentrok -> next setelahnya (cicil)



  let target=''



 const cur=selectedDate.value



 const curIdx=uniq.indexOf(cur)



 if(curIdx!==-1){



 target = uniq[(curIdx+1) % uniq.length]



 } else {



 target = uniq.find(d=> d >= cur) || uniq[0]



 }



 selectedDate.value = target



 flashPulse(target)



 // if lintas bulan, fetch dulu



 const nb=target.slice(0,7)



 if(nb!==bulan.value){ bulan.value=nb; syncRoute(nb); fetchBulan(nb) }



 setTimeout(()=>{



 const card=document.getElementById('kalCard')



 if(card) card.scrollIntoView({behavior:'smooth', block:'start'})



  const cell=document.querySelector(`.kal-cell[data-date="${target}"]`) || document.querySelector(`.kal-week-col[data-date="${target}"]`)



 if(cell) cell.scrollIntoView({behavior:'smooth', block:'center', inline:'center'})



 const idx=uniq.indexOf(target)+1



 showToast(`Bentrok ${idx}/${uniq.length}: ${target} - ${conflictTooltip(target)}`)



 }, 60)



}



function goBuatJadwal(){



 const d=isValidISODate(selectedDate.value) ? selectedDate.value : realToday



 // list ekskul adalah tempat pilih ekskul -> jadwal create ada di detail per-ekskul



 router.push({ path:'/ekskul', query:{ tanggal:d, from:'kalender' }})



}



function goBuatEvent(){



 const d=isValidISODate(selectedDate.value) ? selectedDate.value : realToday



 router.push({ path:'/events', query:{ tanggal:d, create:'1' }})



}







watch(()=> route.query.bulan, (nb)=>{



 if(nb && nb!==bulan.value){



 if(!isValidBulan(nb)){ router.replace({ query:{ ...route.query, bulan:realBulan } }); return }



 bulan.value=nb; fetchBulan(nb)



 }



})



watch(()=> route.query.view, (nv)=>{



 if(nv && isValidView(nv) && nv!==view.value) view.value=nv



 else if(!nv && view.value!=='month') view.value='month'



})



watch(()=> route.query.highlight, (nv)=>{



 if(nv && isValidISODate(nv)) handleHighlightParam(nv)



})



watch(()=> route.query.tanggal, (nv)=>{



 if(nv && isValidISODate(nv) && !route.query.highlight) handleHighlightParam(nv)



})







onMounted(()=>{



 if(!route.query.bulan) syncRoute(bulan.value)



 else if(!isValidBulan(route.query.bulan)) router.replace({ query:{ ...route.query, bulan:realBulan } })



 fetchBulan(bulan.value)



 loadEkskulOptions()



 window.addEventListener('keydown', onKey)



 if(initHighlight) setTimeout(()=> handleHighlightParam(initHighlight), 400)



})



onBeforeUnmount(()=> window.removeEventListener('keydown', onKey))



</script>







<style scoped>



/* Mindora tokens - identical to /ekskul & /events ( Katalog) */



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



.kat-sub-warn{color:#dc2626;font-weight:700}



.kat-head-r{display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}



.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px}



.kat-link:hover{border-color:#d4d4d8}



.kat-link.strong{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}



.kat-nav{display:flex;align-items:center;border:1px solid var(--m-line);border-radius:10px;overflow:hidden;background:#fff}



.kat-nav-btn{padding:8px 12px;border:none;background:#fff;cursor:pointer;color:#3f3f46;font-size:16px;line-height:1}



.kat-nav-btn:hover{background:var(--m-bg)}



.kat-nav-label{padding:8px 12px;font-size:12.5px;font-weight:700;border-left:1px solid var(--m-line);border-right:1px solid var(--m-line);min-width:132px;text-align:center}



.kat-strip{margin:0 0 10px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}



.kat-strip.warn{border-left-color:#f59e0b;background:#fffbeb}







/* toolbar sticky ( Katalog) */



.kat-toolbar{position:sticky;top:56px;z-index:10;background:var(--m-bg);border-bottom:1px solid var(--m-line);padding:10px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}



.kat-view{display:flex;gap:6px;align-items:center}



.kat-chip-view{padding:6px 12px;font-size:11.5px}



.kat-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1 1 auto;min-width:0}



.kat-chip{padding:7px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12.5px;font-weight:600;color:var(--m-ink);cursor:pointer}



.kat-chip:hover{background:var(--m-bg)}



.kat-chip.active{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}



.kat-chip-check{display:inline-flex;align-items:center;gap:6px;padding:7px 12px}



.kat-chip-check input{width:14px;height:14px}



.kat-select{padding:9px 10px;border:1px solid var(--m-line);background:#fff;border-radius:10px;font-size:12.5px;color:var(--m-ink);min-height:38px}



.kat-count-top{margin-left:auto;font-size:11px;color:var(--m-muted);white-space:nowrap}



.kat-count-pill{font-size:11px;background:var(--m-ink);color:#fff;padding:4px 10px;border-radius:999px;font-weight:700}



.kat-legend-bar{display:flex;flex-wrap:wrap;gap:12px;margin:8px 0 12px;font-size:11px;color:var(--m-muted)}



.legend-item{display:inline-flex;align-items:center;gap:6px}



.dot{width:8px;height:8px;border-radius:50%;display:inline-block}



.dot-rutin{background:#0ea5e9}



.dot-tambahan{background:#f59e0b}



.dot-event{background:#10b981}



.dot-bentrok{background:#ef4444}



.kat-searchbar{display:flex;gap:8px;align-items:center;margin:8px 0;background:#fff;border:1px solid var(--m-line);border-radius:12px;padding:8px 12px}



.kat-search{flex:1;border:none;outline:none;font-size:13px;color:var(--m-ink)}



.kat-search-hint{font-size:11px;color:var(--m-muted);white-space:nowrap}







/* layout */



.kal-grid-layout{display:grid;grid-template-columns:1.45fr 0.85fr;gap:16px}



@media(max-width:900px){ .kal-grid-layout{grid-template-columns:1fr} }







/* kat-card base (reuse) */



.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}



.kal-card{border-radius:16px}



.kal-weekhead{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;background:var(--m-line);border-bottom:1px solid var(--m-line);font-size:11px;font-weight:600;color:var(--m-muted)}



.kal-wh{padding:10px 0;text-align:center;background:#fafafa}



.kal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;background:var(--m-line);grid-auto-rows:minmax(108px,1fr)}



.kal-cell{background:#fff;padding:8px;cursor:pointer;display:flex;flex-direction:column;overflow:hidden;box-sizing:border-box;height:100%}



.kal-cell:hover{background:#fafafa}



.kal-cell-empty{background:#fafafa;cursor:default}



.kal-cell-today{box-shadow:inset 0 0 0 2px var(--m-ink);background:#fffbeb66}



.kal-cell-selected{outline:2px solid #f59e0b;outline-offset:-2px;background:#fffbeb}



.kal-cell-today.kal-cell-selected{box-shadow:inset 0 0 0 2px var(--m-ink), 0 0 0 2px #f59e0b;background:#fffbeb}



.kal-cell-top{display:flex;justify-content:space-between;align-items:flex-start;gap:4px;flex-shrink:0}



.kal-date-num{font-size:13px;font-weight:500;width:28px;height:28px;display:grid;place-items:center;border-radius:999px;flex-shrink:0}



.kal-date-today{background:var(--m-ink);color:#fff;font-weight:700}



.badge-bentrok{font-size:9px;font-weight:700;letter-spacing:0.08em;background:#ef4444;color:#fff;padding:2px 6px;border-radius:6px;flex-shrink:0;line-height:1}



.kal-dots{display:flex;gap:4px;flex-wrap:wrap;margin-top:8px;min-height:8px;flex-shrink:0}



.kal-dots:empty::before{content:'';display:block;width:8px;height:8px;opacity:0}



.kal-cell-labels{margin-top:6px;display:flex;flex-direction:column;gap:2px;min-height:28px;flex-shrink:0;overflow:hidden}



.kal-cell-labels:empty::before{content:'-';font-size:11px;opacity:0}



.kal-ev-label{font-size:11px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}



.ev-event{color:#047857}



.ev-rutin{color:#52525b}



.kal-more{font-size:10px;color:#a1a1aa;line-height:1}



.kal-today-tag{margin-top:auto;padding-top:4px;font-size:10px;font-weight:600;color:var(--m-ink);letter-spacing:0.04em;min-height:14px;flex-shrink:0}



.kal-foot{display:flex;justify-content:space-between;padding:10px 12px;font-size:11px;color:var(--m-muted);background:#fff;border-top:1px solid var(--m-line)}



.kal-cached{color:var(--m-muted);font-size:10px}







@media(max-width:640px){ .kal-grid{grid-auto-rows:minmax(78px,1fr)} .kal-cell{padding:6px} .kal-cell-labels{min-height:22px} }







.kal-agenda{display:flex;flex-direction:column}



.kal-agenda-head{padding:14px 16px;border-bottom:1px solid var(--m-line);display:flex;justify-content:space-between;align-items:center;background:#fff}



.kal-agenda-title{font-size:13px;font-weight:700;letter-spacing:-.01em}



.kal-agenda-sub{font-size:11px;color:var(--m-muted);margin-top:2px}



.kal-agenda-list{overflow:auto;max-height:520px}



.agenda-row{display:flex;gap:12px;padding:12px 16px;cursor:pointer;border-bottom:1px solid #f4f4f5}



.agenda-row:hover{background:#fafafa}



.agenda-bar{width:4px;border-radius:999px;flex-shrink:0}



.bar-rutin{background:#0ea5e9}



.bar-tambahan{background:#f59e0b}



.bar-event{background:#10b981}



.bar-bentrok{background:#ef4444}



.agenda-main{flex:1;min-width:0}



.agenda-topline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}



.agenda-time{font-size:11px;background:var(--m-ink);color:#fff;padding:2px 6px;border-radius:6px}



.agenda-badge{font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid var(--m-line);background:var(--m-bg);color:var(--m-muted)}



.badge-bentrok-light{background:#fef2f2;border-color:#fecaca;color:#dc2626}



.agenda-title{font-size:13px;font-weight:600;margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}



.agenda-meta{font-size:11px;color:var(--m-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}



.agenda-arrow{color:#d4d4d8;align-self:center}



.kal-empty{padding:24px;text-align:center;color:var(--m-muted);font-size:13px}



.kal-conflict-box{margin:12px;padding:12px;background:#fffbeb;border-top:1px solid #fde68a;display:flex;gap:8px}



.kal-conflict-muted{background:#fafafa;border-color:var(--m-line)}



.conflict-icon{width:24px;height:24px;border-radius:50%;background:#ef4444;color:#fff;display:grid;place-items:center;font-size:11px;font-weight:700;flex-shrink:0}



.conflict-title{font-size:12px;font-weight:600;color:#92400e}



.conflict-desc{font-size:12px;color:#92400e;line-height:1.4}







.kal-quick{margin-top:16px;padding:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}



.strip-k{font-size:11px;font-weight:700;letter-spacing:0.08em;color:var(--m-muted)}



.strip-actions{display:flex;gap:8px;flex-wrap:wrap}



.kat-chip-cta{background:#fff;border-color:var(--m-ink);color:var(--m-ink)}



.kat-chip-cta:hover{background:var(--m-bg)}



.kat-chip-cta.strong{background:var(--m-ink);color:#fff}



.kat-chip-count{background:rgba(0,0,0,.08);padding:1px 6px;border-radius:999px;font-size:10px;font-weight:700;margin-left:6px}



.kat-chip-disabled{opacity:.45;cursor:not-allowed}



.kat-toast{margin:10px 0 0;background:#2F3E46;color:#fff;padding:8px 12px;border-radius:10px;font-size:12px;border:1px solid #26343c}



.kal-cell-bentrok{box-shadow:inset 0 0 0 2px #fecaca}



.kal-cell-pulse{outline:2px solid #ef4444;outline-offset:-2px;animation:kalPulse 0.85s ease-in-out 4}



@keyframes kalPulse{ 0%,100%{box-shadow:inset 0 0 0 2px #fecaca, 0 0 0 0 rgba(239,68,68,0); outline-color:#ef4444} 50%{box-shadow:inset 0 0 0 2px #fecaca, 0 0 0 7px rgba(239,68,68,.22); outline-color:#f59e0b} }







/* skeleton */



.kal-skeleton-cell{background:#fff;padding:8px}



.skel-line{height:12px;background:#f4f4f5;border-radius:6px;margin-bottom:8px}



.skel-dots{display:flex;gap:4px}



.skel-dots span{width:8px;height:8px;border-radius:50%;background:#f4f4f5;display:block}



.agenda-skel{padding:12px 16px;border-bottom:1px solid #f4f4f5}



.agenda-skel .skel-line{height:10px}



.w60{width:60%}.w90{width:90%}







/* week */



.kal-weekbar{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--m-line);background:#fafafa}



.kal-weeklabel{font-size:12px;font-weight:700;flex:1}



.kal-weekhint{font-size:10px;color:var(--m-muted)}



.kal-week-wrap{overflow-x:auto}



.kal-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;background:var(--m-line);min-width:640px}



.kal-week-col{background:#fff;display:flex;flex-direction:column;min-height:260px;cursor:pointer}



.kal-week-col:hover{background:#fafafa}



.kal-week-col-today{box-shadow:inset 0 0 0 2px var(--m-ink)}



.kal-week-col-selected{outline:2px solid #f59e0b;outline-offset:-2px;background:#fffbeb}



.kal-week-col-head{padding:10px 8px;text-align:center;border-bottom:1px solid var(--m-line);background:#fff}



.kal-week-col-head.isToday{background:#fffbeb}



.kal-wdow{font-size:11px;font-weight:600;color:var(--m-muted)}



.kal-wdate{font-size:10px;color:var(--m-muted);margin-top:2px}



.kal-week-col-body{padding:8px;display:flex;flex-direction:column;gap:6px;flex:1;overflow:auto;max-height:420px}



.kal-week-empty{font-size:11px;color:#d4d4d8;text-align:center;padding:12px}



.kal-week-item{padding:7px 8px;border-radius:10px;border:1px solid var(--m-line);background:#fff}



.kal-week-item:hover{border-color:#d4d4d8}



.kal-week-item-bentrok{border-color:#fecaca;background:#fff1f1}



.kal-week-time{font-size:10px;font-weight:700;background:var(--m-ink);color:#fff;display:inline-block;padding:1px 6px;border-radius:6px}



.kal-week-title{font-size:11.5px;font-weight:600;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}



.kal-week-meta{font-size:10.5px;color:var(--m-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}







/* list */



.kal-list-card{border-radius:16px}



.kal-list-head{display:flex;justify-content:space-between;padding:10px 16px;border-bottom:1px solid var(--m-line);background:#fafafa;font-size:11px;color:var(--m-muted)}



.kal-list{display:flex;flex-direction:column}



.kal-list-group{border-bottom:1px solid #f4f4f5}



.kal-list-date{position:sticky;top:0;background:#fff;padding:10px 16px;border-bottom:1px solid var(--m-line);display:flex;justify-content:space-between;align-items:center;z-index:1}



.kal-list-date-bentrok{background:#fffbeb;border-color:#fde68a}



.kal-list-date-l{display:flex;gap:8px;align-items:center;flex-wrap:wrap}



.kal-list-dow{font-size:11px;font-weight:700;color:var(--m-muted)}



.kal-list-d{font-size:12px;font-weight:700}



.kal-list-count{font-size:11px;color:var(--m-muted)}







@media(max-width:639px){



 .kat-page{padding:16px 14px 20px}



 .kat-head{flex-direction:column;align-items:flex-start;gap:6px}



 .kat-toolbar{position:static;top:auto;z-index:auto;flex-direction:column;align-items:stretch;padding:8px 0 6px;gap:6px;border-bottom:none}



 .kat-filters{display:grid;grid-template-columns:1fr 1fr;width:100%;gap:6px}



 .kat-select{width:100%;padding:7px 10px;min-height:36px;border-radius:9px}



 .kat-chip{padding:6px 10px;font-size:12px;min-height:32px}



 .kat-chip-view{padding:5px 10px}



 .kat-view{gap:5px}



 .kat-count-top{font-size:10.5px}



 .kal-weekhead{font-size:10px}



 .kal-week{grid-template-columns:repeat(7,minmax(92px,1fr));overflow-x:auto}



 .kat-searchbar{flex-direction:column;align-items:stretch;padding:6px 10px;gap:6px}



}



.kat-card:focus-within{outline:2px solid var(--m-green);outline-offset:2px}



</style>



