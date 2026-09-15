<template>

<div class="kat-page">

<div class="kat-inner">

 <!-- head: flat Mindora ( Katalog/Events/Kalender) -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title" :title="headSub">{{ headTitle }}</h1>

 </div>

 <div class="kat-head-r">

 <div class="export-wrap" ref="exportWrap">

 <button class="kat-link strong" @click="showExportMenu=!showExportMenu" :disabled="!selectedEkskul && !selectedEvent" aria-haspopup="menu" :aria-expanded="showExportMenu" aria-label="Export" :title="!selectedEkskul && !selectedEvent ? 'Pilih ekskul/event dulu' : 'Export'">

  <span v-if="exporting" class="spin spin-white"></span><svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export

 </button>

 <div v-if="showExportMenu" class="export-menu" role="menu">

 <button role="menuitem" class="exp-opt" :disabled="!selectedEkskul" @click="doExport('ekskul','csv')">Ekskul - CSV</button>

 <button role="menuitem" class="exp-opt" :disabled="!selectedEkskul" @click="doExport('ekskul','xlsx')">Ekskul - Excel (.xlsx)</button>

 <button role="menuitem" class="exp-opt" :disabled="!selectedEkskul" @click="doExport('ekskul','pdf')">Ekskul - PDF</button>

 <div class="exp-sep"></div>

 <template v-if="selectedEvent">
  <div class="exp-sesi-label">Pilih Sesi:</div>
  <select class="exp-sesi-select" v-model="selectedSession">
    <option value="">-- Pilih Sesi --</option>
    <option v-if="sessionList.length > 1" value="all">Semua Sesi ({{ sessionList.length }})</option>
    <option v-for="s in sessionList" :key="s.id" :value="String(s.id)">{{ s.nama }} ({{ s.hadir_count ?? 0 }} hadir)</option>
    <option v-if="sessionList.length === 0" value="" disabled>Belum ada sesi</option>
  </select>
  <button role="menuitem" class="exp-opt" :disabled="!selectedSession" @click="doExport('event','csv')">Event - CSV</button>
  <button role="menuitem" class="exp-opt" :disabled="!selectedSession" @click="doExport('event','xlsx')">Event - Excel (.xlsx)</button>
  <button role="menuitem" class="exp-opt" :disabled="!selectedSession" @click="doExport('event','pdf')">Event - PDF</button>
 </template>
 <template v-else>
  <button role="menuitem" class="exp-opt" disabled>Event - CSV</button>
  <button role="menuitem" class="exp-opt" disabled>Event - Excel (.xlsx)</button>
  <button role="menuitem" class="exp-opt" disabled>Event - PDF</button>
 </template>

 </div>

 </div>

 <button class="kat-link" @click="drawerAudit=true; fetchAudit()" aria-label="Audit log">Audit</button>

 </div>

 </div>

 <p v-if="false" class="kat-strip" :class="stripCls">{{ stripText }}</p>



 <!-- toolbar: sticky filters ( Katalog) -->

 <div class="kat-toolbar">

 <!-- ekskul / event combo (reuse Mindora field styling) -->

 <div class="kat-combo">

 <label class="kat-combo-label mono" for="f-ekskul">Ekskul</label>

 <div class="combo" :class="{open: showEkskul}">

 <input id="f-ekskul" v-model="ekskulQ" @focus="onEkskulFocus" @click="onEkskulFocus" @input="onEkskulInput" placeholder="Cari ekskul..." autocomplete="off" aria-label="Cari ekskul" :aria-expanded="showEkskul" aria-haspopup="listbox" class="kat-input combo-input" />

 <button v-if="ekskulQ" class="kat-clear combo-clear" @click="clearEkskul" aria-label="Hapus pilihan ekskul">x</button>

 <ul v-if="showEkskul" class="combo-list" role="listbox">

 <li v-if="!isPembina" role="option" class="combo-opt" :class="{active: !selectedEkskul}" @click="selectEkskul(null)">Semua Ekskul</li>

 <li v-else-if="!selectedEkskul" role="option" class="combo-opt muted">Pilih salah satu ekskul binaanmu (wajib)</li>

 <li v-for="e in filteredEkskul" :key="e.id" role="option" class="combo-opt" :class="{active: selectedEkskul===e.id}" @click="selectEkskul(e)">{{ e.nama }} <span class="mono" style="font-size:10px;color:var(--m-muted)">({{ e.terisi ?? '-' }}/{{ e.kuota }})</span></li>

 <li v-if="!filteredEkskul.length" class="combo-opt muted">{{ isPembina ? 'Tidak ada ekskul binaan - hubungi admin' : 'Tidak ada hasil' }}</li>

 </ul>

 </div>

 </div>

 <div class="kat-combo">

 <label class="kat-combo-label mono" for="f-event">Event</label>

 <div class="combo" :class="{open: showEvent}">

 <input id="f-event" v-model="eventQ" @focus="onEventFocus" @click="onEventFocus" @input="onEventInput" placeholder="Cari event..." autocomplete="off" aria-label="Cari event" :aria-expanded="showEvent" aria-haspopup="listbox" class="kat-input combo-input" />

 <button v-if="eventQ" class="kat-clear combo-clear" @click="clearEvent" aria-label="Hapus pilihan event">x</button>

 <ul v-if="showEvent" class="combo-list" role="listbox">

 <li role="option" class="combo-opt" :class="{active: !selectedEvent}" @click="selectEvent(null)">Semua Event</li>

 <li v-for="ev in filteredEvent" :key="ev.id" role="option" class="combo-opt" :class="{active: selectedEvent===ev.id}" @click="selectEvent(ev)">{{ ev.nama }}</li>

 <li v-if="!filteredEvent.length" class="combo-opt muted">Tidak ada hasil</li>

 </ul>

 </div>

 </div>

 <div class="kat-field">

 <label class="kat-field-label mono" for="f-from">Dari</label>

 <input id="f-from" type="date" v-model="fromDate" class="kat-select kat-date" aria-label="Tanggal dari" :class="{invalid: !!dateErr}" />

 </div>

 <div class="kat-field">

 <label class="kat-field-label mono" for="f-to">Sampai</label>

 <input id="f-to" type="date" v-model="toDate" class="kat-select kat-date" aria-label="Tanggal sampai" :class="{invalid: !!dateErr}" />

 </div>

 </div>



 <!-- toolbar row 2: search / kelas / sort / kehadiran -->

 <div class="kat-toolbar kat-toolbar--second">

 <div class="kat-search" style="flex:1.2;min-width:200px">

 <span class="kat-search-icon" aria-hidden="true"></span>

 <input id="f-q" v-model="qSearch" @input="debounceFetch" placeholder="Cari siswa (nama/email)" class="kat-input" aria-label="Cari siswa" />

 <button v-if="qSearch" class="kat-clear" @click="qSearch=''; debounceFetch()" aria-label="Hapus pencarian">x</button>

 </div>

 <div class="kat-filters" style="flex:1 1 auto">

 <select id="f-kelas" v-model="kelasFilter" @change="onFilterChange" @focus="fetchKelasList" class="kat-select" aria-label="Kelas">

 <option value="">Kelas: Semua</option>

 <option v-for="k in kelasList" :key="k" :value="k">{{ k }}</option>

 </select>

 <select id="f-sort" v-model="sortParam" @change="onFilterChange" class="kat-select" aria-label="Urut">

 <option value="nama_asc">Nama A -> Z</option>

 <option value="nama_desc">Nama Z -> A</option>

 <option value="persen_desc">% Hadir tertinggi</option>

 <option value="persen_asc">% Hadir terendah</option>

 <option value="kelas_asc">Kelas A -> Z</option>

 </select>

 <select id="f-hadir" v-model="kehadiranFilter" @change="onFilterChange" class="kat-select" aria-label="Kehadiran">

 <option value="">Hadir: Semua</option>

 <option value="hadir">Hadir 75%</option>

 <option value="izin">Izin 50-74%</option>

 <option value="alpa">Alpa &lt;50%</option>

 </select>

 <select id="f-limit" v-model.number="limit" @change="onLimitChange" class="kat-select" aria-label="Baris">

 <option :value="20">20 / hal</option>

 <option :value="50">50 / hal</option>

 <option :value="100">100 / hal</option>

 </select>

 </div>

 </div>

 <div v-if="dateErr" class="kat-alert err" role="alert">{{ dateErr }}</div>

 <div v-if="error" class="kat-alert err" role="alert">{{ error }}</div>



 <!-- KPI 4 cards - Mindora -->

 <div class="kpi-grid" aria-label="KPI">

 <div class="kpi-card">

 <div class="kpi-label mono">ANGGOTA AKTIF</div>

 <div class="kpi-value mono">{{ kpi.anggota_aktif }} <span class="kpi-muted">anggota</span></div>

 <div class="kpi-bar"><div class="kpi-fill" :style="{width: kpiPct+'%'}"></div></div>

 <div class="kpi-desc mono">{{ kpiDescEkskul }}</div>

 </div>

 <div class="kpi-card">

 <div class="kpi-label mono">% HADIR</div>

 <div class="kpi-value mono">{{ kpi.persen_hadir_rata }}%</div>

 <div class="kpi-desc mono">{{ kpi.total_sesi ?? totalSesiEff }} sesi, {{ kpi.total_hadir ?? totalHadirEff }} hadir</div>

 </div>

 <div class="kpi-card">

 <div class="kpi-label mono">EVENT PESERTA</div>

 <div class="kpi-value mono">{{ kpi.event_peserta }} <span class="kpi-muted">pendaftaran</span></div>

 <div class="kpi-desc mono">{{ kpiDescEvent }}</div>

 </div>

 <div class="kpi-card">

 <div class="kpi-label mono">SIAP EXPORT</div>

 <div class="kpi-value mono" style="font-size:14px;font-weight:700">{{ kpi.siap_export ? 'Siap unduh' : 'Pilih dulu' }}</div>

 <div class="kpi-desc mono">CSV / Excel / PDF</div>

 </div>

 </div>



 <!-- INSIGHT KEPSEK -->

 <div v-if="selectedEkskul && insightReady" class="kat-card insight-card">

 <div class="insight-head">

 <div class="insight-title" :title="(insight.chart?.labels?.length||0) + ' minggu'">Insight</div>

 </div>

 <div class="insight-grid">

 <div class="insight-chart">

 <div class="chart-title mono">Tren mingguan</div>

 <div class="chart-wrap">

 <canvas ref="chartCanvas" aria-label="Grafik tren mingguan"></canvas>

 </div>

 <div v-if="!chartRendered" class="bars">

 <div v-for="(v,i) in (insight.chart?.values||[])" :key="i" class="bar-col">

 <div class="bar" :style="{height: Math.max(4, v*1.4)+'px'}" :title="v + '%'"></div>

 <div class="bar-lab mono">{{ (insight.chart.labels[i]||'').slice(2) }}</div>

 <div class="bar-val mono">{{ v }}%</div>

 </div>

 <div v-if="!(insight.chart?.values?.length)" class="muted mono" style="font-size:11px;padding:12px">Belum ada sesi di rentang ini</div>

 </div>

 </div>

 <div class="insight-side">

 <div class="rank-card">

 <div class="rank-title mono">Ranking Top 3</div>

 <div v-if="insight.ranking?.length" class="rank-list">

 <div v-for="(r,i) in insight.ranking" :key="r.id" class="rank-row">

 <span class="rank-medal">{{ ['\U0001f947','\U0001f948','\U0001f949'][i] }}</span>

 <span class="rank-name">{{ r.nama }}</span>

 <span class="rank-pct mono" :class="{low: r.persen<75}">{{ r.persen }}%</span>

 </div>

 </div>

 <div v-else class="muted mono" style="font-size:11px">Belum ada ranking</div>

 </div>

 <div class="alert-card">

 <div class="rank-title mono">Perlu perhatian</div>

 <div v-if="insight.alert?.length" class="alert-list">

 <div v-for="a in insight.alert" :key="a.id" class="alert-row">

 <span>{{ a.nama }} <span class="mono muted" style="font-size:11px">{{ a.kelas || '-' }}</span></span>

 <span class="pill mono alert-pct">{{ a.persen }}%</span>

 </div>

 </div>

 <div v-else class="mono" style="font-size:11px;color:var(--m-green)">v Semua aman</div>

 </div>

 </div>

 </div>

 </div>



 <!-- 1 tabel rekap adaptif -->

 <div v-if="hasRekap" class="table-card kat-card rekap-single">

 <div class="table-head">

 <div class="table-title" :title="rekapHint">{{ isEkskulActive ? 'Rekap Ekskul - '+(selectedEkskulNama||'-') : 'Rekap Event - '+(selectedEventNama||'-') }}</div>

 <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">

 <div v-if="selectedEkskul && selectedEvent" class="tabs" role="tablist" aria-label="Pilih rekap">

 <button role="tab" class="tab" :class="{active: activeTab==='ekskul'}" @click="activeTab='ekskul'; page=1; fetchRekap()" :aria-selected="activeTab==='ekskul'" :title="totalEkskul + ' anggota'">Ekskul</button>

 <button role="tab" class="tab" :class="{active: activeTab==='event'}" @click="activeTab='event'; page=1; fetchRekap()" :aria-selected="activeTab==='event'" :title="totalEvent + ' peserta'">Event</button>

 </div>

 <span v-else class="pill mono" :style="isEkskulActive ? 'background:var(--m-ink);color:#fff' : 'background:#fff;border:1px solid var(--m-line)'" :title="isEkskulActive ? totalEkskul + ' anggota' : totalEvent + ' peserta'">{{ isEkskulActive ? 'Ekskul' : 'Event' }}</span>

 </div>

 </div>

 <div v-if="isEkskulActive">

 <div v-if="loadingEkskul" class="skeleton-wrap" aria-busy="true"><div v-for="i in 5" :key="i" class="skel-row"><span class="skel" style="width:32%"></span><span class="skel" style="width:18%"></span><span class="skel" style="width:14%"></span></div></div>

 <div v-else-if="!ekskulRows.length" class="empty mono">Tidak ada anggota</div>

 <div v-else class="table-wrap">

 <table>

 <thead><tr><th scope="col">Nama</th><th scope="col">Kelas</th><th scope="col" style="text-align:center">H/I/A</th><th scope="col" style="text-align:right">% Hadir</th></tr></thead>

 <tbody>

 <tr v-for="r in ekskulRows" :key="r.id">

 <td><div style="font-weight:600">{{ r.nama }}</div><div class="mono muted" style="font-size:11px">{{ r.email }}</div></td>

 <td class="mono" style="font-size:12px">{{ r.kelas || '-' }}</td>

 <td style="text-align:center;font-size:11px" class="mono">{{ r.hadir }}/{{ r.izin || 0 }}/{{ r.alpa || 0 }} <span class="muted">/ {{ r.total_sesi }}</span></td>

 <td style="text-align:right" class="mono"><span :class="{'pct-low': r.persen<50, 'pct-mid': r.persen>=50 && r.persen<75}">{{ r.persen }}%</span></td>

 </tr>

 </tbody>

 </table>

 </div>

 </div>

 <div v-else>

  <div v-if="selectedEvent && sessionList.length" class="rekap-sesi-filter">
    <label class="mono rekap-sesi-label">Filter Sesi:</label>
    <select v-model="rekapSession" @change="fetchRekap" class="rekap-sesi-select">
      <option value="">Semua peserta (tanpa filter sesi)</option>
      <option v-if="sessionList.length > 1" value="all">Semua Sesi ({{ sessionList.length }}) — agregat</option>
      <option v-for="s in sessionList" :key="s.id" :value="String(s.id)">{{ s.nama }} ({{ s.hadir_count ?? 0 }} hadir)</option>
    </select>
  </div>
  <div v-else-if="selectedEvent && !sessionList.length" class="mono muted" style="font-size:11px;padding:6px 2px 8px">Belum ada sesi untuk event ini</div>

  <div v-if="loadingEvent" class="skeleton-wrap" aria-busy="true"><div v-for="i in 5" :key="i" class="skel-row"><span class="skel" style="width:28%"></span><span class="skel" style="width:18%"></span><span class="skel" style="width:16%"></span></div></div>

 <div v-else-if="!eventRows.length" class="empty mono">Belum ada peserta</div>

 <div v-else class="table-wrap">

 <table>

  <thead><tr>
    <th scope="col">Peserta</th>
    <th scope="col">Kelas</th>
    <th v-if="rekapSession === ''" scope="col">Status</th>
    <th v-else-if="rekapSession === 'all'" scope="col" style="text-align:center">Kehadiran</th>
    <th v-else scope="col" style="text-align:center">Kehadiran</th>
    <th v-if="rekapSession === ''" scope="col" style="text-align:right">Daftar</th>
    <th v-else-if="rekapSession === 'all'" scope="col" style="text-align:right">Daftar</th>
    <th v-else scope="col" style="text-align:right">Scan</th>
  </tr></thead>

  <tbody>

  <tr v-for="r in eventRows" :key="r.id">

  <td><div style="font-weight:600">{{ r.nama }}</div><div class="mono muted" style="font-size:11px">{{ r.email }}</div></td>

  <td class="mono" style="font-size:12px">{{ r.kelas || '-' }}</td>

  <template v-if="rekapSession === ''">
    <td><span class="badge" :class="r.status==='diterima' ? 'badge-ok' : 'badge-muted'">{{ r.status }}</span></td>
    <td style="text-align:right;font-size:11px" class="mono">{{ formatDate(r.tgl_daftar || r.created_at) }}</td>
  </template>
  <template v-else-if="rekapSession === 'all'">
    <td style="text-align:center" class="mono"><span class="badge badge-muted">{{ (r.sesi_hadir ?? 0) + '/' + (r.total_sesi ?? sessionList.length ?? 0) }}</span></td>
    <td style="text-align:right;font-size:11px" class="mono">{{ formatDate(r.tgl_daftar || r.created_at) }}</td>
  </template>
  <template v-else>
    <td style="text-align:center"><span class="badge" :class="r.kehadiran_sesi ? 'badge-ok' : 'badge-muted'">{{ r.kehadiran_sesi ? 'hadir' : 'tidak hadir' }}</span></td>
    <td style="text-align:right;font-size:11px" class="mono">{{ r.scanned_at ? formatDate(r.scanned_at) : '-' }}</td>
  </template>

  </tr>

 </tbody>

 </table>

 </div>

 </div>

 <div class="table-foot">

 <div class="pagination mono" v-if="activeTotal">

 <button class="btn-page" :disabled="page<=1" @click="goPage(page-1)" aria-label="Prev"><</button>

 <span v-for="p in activeNumbers" :key="p"><button v-if="p!=='...'" class="btn-page" :class="{active: p===page}" @click="goPage(p)">{{ p }}</button><span v-else class="page-ell">...</span></span>

 <button class="btn-page" :disabled="page<=activePages" @click="goPage(page+1)" aria-label="Next">></button>

 <span class="jump">Ke <input v-model.number="jumpPage" type="number" min="1" :max="activePages" class="jump-in" @keydown.enter="goPage(jumpPage)" aria-label="Jump page" /> <button class="btn-page" @click="goPage(jumpPage)">Go</button></span>

 </div>

 <span class="mono muted" style="font-size:11px;margin-left:auto"></span>

 </div>

 </div>

 <div v-else class="kat-empty mono">Pilih ekskul atau event untuk melihat rekap</div>



 <!-- AUDIT DRAWER -->

 <div v-if="drawerAudit" class="drawer-overlay" @click.self="drawerAudit=false">

 <div class="drawer" role="dialog" aria-label="Audit log export" aria-modal="true">

 <div class="drawer-head">

 <div style="font-weight:700">Audit Export - 20 terbaru</div>

 <button class="kat-link" @click="drawerAudit=false" aria-label="Tutup">x</button>

 </div>

 <div class="drawer-body">

 <div v-if="auditLoading" class="skeleton-wrap"><div v-for="i in 4" :key="i" class="skel-row"><span class="skel" style="width:70%"></span></div></div>

 <div v-else-if="!auditRows.length" class="empty mono" style="font-size:12px">Belum ada log export</div>

 <div v-else class="audit-list">

 <div v-for="a in auditRows" :key="a.id" class="audit-row">

 <div class="audit-main"><span class="mono" style="font-weight:600">{{ a.nama || a.email || '-' }}</span> <span class="pill mono" style="background:var(--m-bg);border:1px solid var(--m-line)">{{ a.action }}</span> <span class="mono" style="font-size:11px">{{ a.target_type }} #{{ a.target_id }}</span></div>

 <div class="mono muted" style="font-size:11px">{{ a.detail }}</div>

 <div class="mono muted" style="font-size:11px">{{ a.created_at }} - {{ a.ip || '-' }}</div>

 </div>

 </div>

 <div class="pagination mono" style="margin-top:10px" v-if="auditTotal">auditLimit>

 <button class="btn-page" :disabled="auditPage<=1" @click="auditPage--; fetchAudit()"><</button>

 <span style="font-size:12px">{{ auditPage }} / {{ Math.ceil(auditTotal/auditLimit) }}</span>

 <button class="btn-page" :disabled="auditPage">=Math.ceil(auditTotal/auditLimit) @click="auditPage++; fetchAudit()">></button>

 </div>

 </div>

 </div>

 </div>



 <div v-if="toast" class="toast mono" :class="toastOk?'toast-ok':'toast-err'" role="status" aria-live="polite">{{ toast }}</div>

</div>

</div>

</template>



<script setup>

import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'

import { useAuth } from '../stores/auth.js'

import { api } from '../lib/api.js'



const auth=useAuth()

const ekskulList=ref([]); const eventList=ref([])

const ekskulQ=ref(''); const eventQ=ref('')

const showEkskul=ref(false); const showEvent=ref(false)

const selectedEkskul=ref(null); const selectedEkskulNama=ref('')

const selectedEvent=ref(null); const selectedEventNama=ref('')

const fromDate=ref(new Date().toISOString().slice(0,7)+'-01')

const toDate=ref(new Date(new Date().getFullYear(), new Date().getMonth()+1, 0).toISOString().slice(0,10))

const activeTab=ref('ekskul')

const hasRekap=computed(()=> !!(selectedEkskul.value || selectedEvent.value))

const isEkskulActive=computed(()=> selectedEkskul.value && (!selectedEvent.value || activeTab.value==='ekskul'))

const isEventActive=computed(()=> selectedEvent.value && (!selectedEkskul.value || activeTab.value==='event'))

const qSearch=ref(''); const kelasFilter=ref(''); const kelasList=ref([]); const kehadiranFilter=ref(''); const sortParam=ref('nama_asc')

const limit=ref(20); const page=ref(1); const jumpPage=ref(1)

const loading=ref(false); const loadingEkskul=ref(false); const loadingEvent=ref(false)

const ekskulRows=ref([]); const eventRows=ref([])

const totalEkskul=ref(0); const totalEvent=ref(0)

const kpi=ref({ anggota_aktif:0, persen_hadir_rata:0, event_peserta:0, siap_export:false })

const totalSesiEff=ref(0); const totalHadirEff=ref(0); const hasSesi=ref(false)

const sessionList = ref([])
const selectedSession = ref('')
const rekapSession = ref('')

watch(selectedEvent, async (newVal) => {
  sessionList.value = []
  selectedSession.value = ''
  rekapSession.value = ''
  if(!newVal) return
  try {
    const res = await fetch('/api/events/' + newVal + '/sessions', { credentials: 'include' })
    if(!res.ok) return
    const j = await res.json()
    sessionList.value = j.data || []
  } catch(e) { /* ignore */ }
})

const error=ref(''); const toast=ref(''); const toastOk=ref(true)

const exporting=ref(false); const showExportMenu=ref(false)

const exportWrap=ref(null)

const insight=ref({ chart:{labels:[],values:[]}, ranking:[], alert:[] })

const chartCanvas=ref(null); const chartRendered=ref(false); let chartInst=null

const drawerAudit=ref(false); const auditRows=ref([]); const auditTotal=ref(0); const auditPage=ref(1); const auditLimit=20; const auditLoading=ref(false)



// head per-role (Mindora Katalog)

const role=computed(()=> auth.user?.role || 'guest')

const headTitle=computed(()=>{

 if(role.value==='kepsek') return 'Laporan Kepsek'

 if(role.value==='pembina') return 'Laporan Binaan'

 if(role.value==='admin') return 'Laporan & Rekap'

 return 'Laporan'

})

const headSub=computed(()=>{

 if(role.value==='kepsek') return 'Monitoring kehadiran'

 if(role.value==='pembina') return 'Rekap binaan kamu'

 if(role.value==='admin') return `${ekskulList.value.length} ekskul, ${eventList.value.length} event`

 return 'Pilih ekskul/event'

})

const isPembina=computed(()=> role.value==='pembina')

const stripText=computed(()=>{

 return role.value==='pembina' ? 'Pembina - hanya ekskul binaanmu' : 'Kepala Sekolah'

})

const stripCls=computed(()=> role.value==='pembina' ? 'warn' : '')

const rekapHint=computed(()=>{

 if(!hasRekap.value) return 'Pilih ekskul/event'

 if(selectedEkskul.value && selectedEvent.value) return (activeTab.value==='ekskul' ? 'Ekskul - '+selectedEkskulNama.value : 'Event - '+selectedEventNama.value)

 if(selectedEkskul.value) return 'Ekskul - '+selectedEkskulNama.value

 return 'Event - '+selectedEventNama.value

})



const filteredEkskul=computed(()=>{

 const q=ekskulQ.value.trim().toLowerCase()

 if(showEkskul.value && selectedEkskul.value && q===selectedEkskulNama.value.toLowerCase()) return ekskulList.value.slice(0,20)

 if(!q) return ekskulList.value.slice(0,20)

 return ekskulList.value.filter(e=> e.nama.toLowerCase().includes(q)).slice(0,20)

})

const filteredEvent=computed(()=>{

 const q=eventQ.value.trim().toLowerCase()

 if(showEvent.value && selectedEvent.value && q===selectedEventNama.value.toLowerCase()) return eventList.value.slice(0,20)

 if(!q) return eventList.value.slice(0,20)

 return eventList.value.filter(e=> e.nama.toLowerCase().includes(q)).slice(0,20)

})

const kpiPct=computed(()=> Math.min(100, Math.round(kpi.value.persen_hadir_rata || 0)))

const kpiDescEkskul=computed(()=> !selectedEkskul.value ? 'Pilih ekskul' : `${totalEkskul.value} anggota`)

const kpiDescEvent=computed(()=> !selectedEvent.value ? 'Pilih event' : `${totalEvent.value} peserta`)

const dateErr=computed(()=>{ if(!fromDate.value || !toDate.value) return ''; return fromDate.value > toDate.value ? 'Dari tidak boleh > Sampai' : '' })

const activeTotal=computed(()=> isEkskulActive.value ? totalEkskul.value : totalEvent.value)

const activePages=computed(()=> Math.max(1, Math.ceil(activeTotal.value/limit.value)))

const activeNumbers=computed(()=> buildPages(page.value, activePages.value))

const insightReady=computed(()=> !!selectedEkskul.value && (insight.value.chart?.labels?.length || insight.value.ranking?.length || insight.value.alert?.length || ekskulRows.value.length))



function buildPages(cur, tot){

 if(tot<=7) return Array.from({length:tot},(_,i)=>i+1)

 const out=[]; out.push(1)

 if(cur>3) out.push('...')

 for(let i=Math.max(2,cur-1); i<=Math.min(tot-1,cur+1); i++) out.push(i)

 if(cur<tot-2) out.push('...')

 out.push(tot)

 return out

}




let debounceTimer=null
let abortCtrl=null
let etagCache=new Map()
function onEkskulFocus(){
 showEkskul.value=true; showEvent.value=false
}
function onEventFocus(){
 showEvent.value=true; showEkskul.value=false
}
function onEkskulInput(){
 showEkskul.value=true; debounceFetch()
}
function onEventInput(){
 showEvent.value=true; debounceFetch()
}
function clearEkskul(){
 ekskulQ.value=''; showEkskul.value=false; selectedEkskul.value=null; selectedEkskulNama.value=''; if(selectedEvent.value) activeTab.value='event'; fetchRekap()
}
function clearEvent(){
 eventQ.value=''; showEvent.value=false; selectedEvent.value=null; selectedEventNama.value=''; if(selectedEkskul.value) activeTab.value='ekskul'; fetchRekap()
}
function selectEkskul(e){
 showEkskul.value=false
 if(!e){ selectedEkskul.value=null; selectedEkskulNama.value=''; ekskulQ.value=''; activeTab.value='ekskul'; fetchRekap(); return }
 selectedEkskul.value=e.id; selectedEkskulNama.value=e.nama; ekskulQ.value=''; selectedEvent.value=null; selectedEventNama.value=''; eventQ.value=''
 if(isPembina.value && !ekskulList.value.some(x=> String(x.id)===String(e.id))){ toast.value='Ekskul bukan binaanmu (403)'; toastOk.value=false; setTimeout(()=>toast.value='',4000); return }
 activeTab.value='ekskul'; page.value=1; fetchRekap()
}
function selectEvent(ev){

 if(!ev){ selectedEvent.value=null; selectedEventNama.value=''; eventQ.value=''; if(selectedEkskul.value) activeTab.value='ekskul' }

 else {

 if(isPembina.value && !eventList.value.some(x=> String(x.id)===String(ev.id))){ toast.value='Event bukan milik/binaanmu (403)'; toastOk.value=false; setTimeout(()=>toast.value='',2000); return }

 selectedEvent.value=ev.id; selectedEventNama.value=ev.nama; eventQ.value=ev.nama; selectedEkskul.value=null; selectedEkskulNama.value=''; ekskulQ.value=''; activeTab.value='event'

 }

 showEvent.value=false; page.value=1; debounceFetch()

}

function debounceFetch(){ clearTimeout(debounceTimer); debounceTimer=setTimeout(()=>fetchRekap(),300) }

function onFilterChange(){ page.value=1; debounceFetch() }

function onLimitChange(){ page.value=1; fetchRekap() }

watch([fromDate,toDate], ()=>{ if(!dateErr.value) debounceFetch() })

function formatDate(s){ if(!s) return '-'; return String(s).slice(0,10) }

function goPage(p){ const n=Math.max(1, Math.min(p, activePages.value)); if(!n || isNaN(n)) return; page.value=n; jumpPage.value=n; fetchRekap() }



async function fetchKelasList(){

 try{

 const r=await api('/users/kelas-list')

 const list = Array.isArray(r.data) ? r.data : (Array.isArray(r) ? r : [])

 kelasList.value = list

 if(kelasFilter.value && !kelasList.value.includes(kelasFilter.value)){

 kelasFilter.value=''

 }

 }catch{ }

}

async function fetchInit(){

 try{ const a=await api('/ekskul?limit=100'); ekskulList.value=a.data||[] }catch{}

 try{ const b=await api('/events?limit=100'); eventList.value=b.data||[] }catch{}

 fetchKelasList()

}



async function fetchRekap(){

 clearTimeout(debounceTimer)

 if(dateErr.value){ error.value=dateErr.value; toast.value=dateErr.value; toastOk.value=false; setTimeout(()=>toast.value='',2000);; return }

 if(!selectedEkskul.value && !selectedEvent.value){

 kpi.value={ anggota_aktif:0, persen_hadir_rata:0, event_peserta:0, siap_export:false }

 totalSesiEff.value=0; totalHadirEff.value=0; hasSesi.value=false; ekskulRows.value=[]; eventRows.value=[]; totalEkskul.value=0; totalEvent.value=0

 insight.value={ chart:{labels:[],values:[]}, ranking:[], alert:[] }

 toast.value='Pilih ekskul/event dulu'; toastOk.value=false; setTimeout(()=>{ if(toast.value==='Pilih ekskul/event dulu') toast.value='' },2200)

 return

 }

 loading.value=true; if(selectedEkskul.value) loadingEkskul.value=true; if(selectedEvent.value) loadingEvent.value=true; error.value=''

 const params=new URLSearchParams()

 if(selectedEkskul.value) params.set('ekskul_id', String(selectedEkskul.value))

  if(selectedEvent.value) params.set('event_id', String(selectedEvent.value))
  if(selectedEvent.value && rekapSession.value) params.set('session_id', String(rekapSession.value))

  params.set('from', fromDate.value); params.set('to', toDate.value)

 params.set('page', String(page.value)); params.set('limit', String(limit.value))

 if(qSearch.value.trim()) params.set('q', qSearch.value.trim())

 if(kelasFilter.value) params.set('kelas', kelasFilter.value)

 if(kehadiranFilter.value) params.set('kehadiran', kehadiranFilter.value)

 if(sortParam.value) params.set('sort', sortParam.value)

 const url='/laporan/rekap?'+params.toString()

 try{

 const j=await api(url); const d=j.data||{}

 kpi.value=d.kpi||{ anggota_aktif:0, persen_hadir_rata:0, event_peserta:0, siap_export:false }

 totalEkskul.value=d.ekskul?.total ?? 0; totalEvent.value=d.event?.total ?? 0

 ekskulRows.value=d.ekskul?.rows||[]; eventRows.value=d.event?.rows||[]

 totalSesiEff.value = d.kpi?.total_sesi ?? 0

 totalHadirEff.value = d.kpi?.total_hadir ?? 0

 hasSesi.value = totalSesiEff.value > 0

 insight.value=d.insight || { chart:{labels:[],values:[]}, ranking:[], alert:[] }

 await nextTick(); renderChart()

 }catch(e){ const msg=e.error?.message || e.message || String(e) || 'Gagal memuat rekap'; error.value=msg; toast.value=msg; toastOk.value=false; setTimeout(()=>toast.value='',3200); console.error('[Laporan fetchRekap]', e) }finally{ loading.value=false; loadingEkskul.value=false; loadingEvent.value=false }

}



function ensureChartJs(){

 if(window.Chart) return Promise.resolve()

 return new Promise((res,rej)=>{

 const s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js'

 s.onload=()=>res(); s.onerror=()=>rej(new Error('chart load fail')); document.head.appendChild(s)

 })

}

async function renderChart(){

 const cv=chartCanvas.value; if(!cv) return

 const labels=insight.value.chart?.labels||[]; const vals=insight.value.chart?.values||[]

 if(!labels.length){ chartRendered.value=false; if(chartInst){ try{ chartInst.destroy()}catch{}; chartInst=null } return }

 try{

 await ensureChartJs()

 if(chartInst){ try{ chartInst.destroy()}catch{}; chartInst=null }

 const ctx=cv.getContext('2d')

 chartInst=new window.Chart(ctx, { type:'bar', data:{ labels, datasets:[{ label:'% Hadir', data: vals, backgroundColor:'#2F3E46', borderRadius:6, barPercentage:0.6 }] }, options:{ responsive:true, maintainAspectRatio:false, animation:false, plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:(c)=> c.parsed.y+'%' } } }, scales:{ y:{ beginAtZero:true, max:100, ticks:{ callback:v=>v+'%' } }, x:{ grid:{display:false} } } } })

 chartRendered.value=true

 }catch{ chartRendered.value=false }

}



async function doExport(tipe, format){

 const id = tipe==='ekskul' ? selectedEkskul.value : selectedEvent.value

 if(!id){ toast.value='Pilih '+tipe+' dulu'; toastOk.value=false; setTimeout(()=>toast.value='',2000);; return }

 // FE scoped guard anti-bypass: id harus ada di list server-scoped

 if(isPembina.value){

 const list = tipe==='ekskul' ? ekskulList.value : eventList.value

 if(!list.some(x=> String(x.id)===String(id))){ toast.value='Tidak berhak export '+tipe+' ini (scoped pembina - 403)'; toastOk.value=false; setTimeout(()=>toast.value='',2000);; return }

 }

 exporting.value=true; showExportMenu.value=false

 const params=new URLSearchParams({tipe, id:String(id), from: fromDate.value, to: toDate.value, format})
 if(kelasFilter.value) params.set('kelas', kelasFilter.value)
 if(tipe==='event' && selectedSession.value) params.set('session_id', selectedSession.value)

  try{

  const res=await fetch('/api/laporan/export?'+params.toString(), { credentials:'include' })

  // pseudo-queue: xlsx/pdf -> 202 {job_id} -> poll /jobs/:id 2 detik -> download
  if(res.status===202){
    const j=await res.json().catch(()=>null)
    const jobId=j?.data?.job_id
    if(!jobId) throw {error:{message:'Queue gagal: tanpa job_id'}}
    toast.value='Diproses di background (job #'+jobId+')...'; toastOk.value=true
    for(let i=0;i<30;i++){
      await new Promise(r=>setTimeout(r,2000))
      const st=await fetch('/api/jobs/'+jobId,{credentials:'include'}).then(r=>r.json()).catch(()=>null)
      const s=st?.data?.status
      if(s==='done'){
        window.location.href='/api/jobs/'+jobId+'/download'
        toast.value=(format.toUpperCase()+' siap — mengunduh...'); toastOk.value=true
        return
      }
      if(s==='failed') throw {error:{message:'Job gagal: '+(st?.data?.error||'worker error')}}
    }
    throw {error:{message:'Job timeout — cek lagi via download manual'}}
  }

  if(!res.ok){ const j=await res.json().catch(()=>({error:{message:res.statusText}})); throw j }

  const blob=await res.blob()

  const cd=res.headers.get('Content-Disposition')||''; let filename='rekap-'+tipe+'-'+format+'.'+(format==='xlsx'?'xlsx':format)

  // parse Content-Disposition properly (handle quoted + RFC5987)
  const fnStar=cd.match(/filename\*\s*=\s*UTF-8''([^\s;]+)/i)
  const fnQuoted=cd.match(/filename\s*=\s*"([^"]+)"/i)
  const fnPlain=cd.match(/filename\s*=\s*([^\s;]+)/i)
  if(fnStar) filename=decodeURIComponent(fnStar[1])
  else if(fnQuoted) filename=fnQuoted[1]
  else if(fnPlain) filename=fnPlain[1]

 const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download=filename; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),2000)

 toast.value=(format.toUpperCase()+' terunduh'); toastOk.value=true

 }catch(e){ toast.value=e.error?.message || 'Export gagal'; toastOk.value=false }finally{ exporting.value=false; setTimeout(()=>toast.value='',2000); }

}

async function fetchAudit(){

 auditLoading.value=true

 try{ const j=await api('/laporan/audit?page='+auditPage.value+'&limit='+auditLimit); auditRows.value=j.data||[]; auditTotal.value=j.meta?.total||auditRows.value.length }catch{ auditRows.value=[] }finally{ auditLoading.value=false }

}

function onDocClick(e){

 if(!e.target.closest('.combo')){ showEkskul.value=false; showEvent.value=false }

 if(!e.target.closest('.export-wrap')) showExportMenu.value=false

}

let kelasPoll=null

function onVis(){ if(document.visibilityState==='visible') fetchKelasList() }

onMounted(()=>{

 fetchInit();

 document.addEventListener('click', onDocClick)

 document.addEventListener('visibilitychange', onVis)

 window.addEventListener('focus', fetchKelasList)

 kelasPoll=setInterval(fetchKelasList, 30000)

})

onBeforeUnmount(()=>{

 document.removeEventListener('click', onDocClick)

 document.removeEventListener('visibilitychange', onVis)

 window.removeEventListener('focus', fetchKelasList)

 if(kelasPoll) clearInterval(kelasPoll)

 if(chartInst){ try{ chartInst.destroy()}catch{}; chartInst=null }

})

</script>



<style scoped>

/* Mindora tokens - identical to /ekskul & /events & /kalender */

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

.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px;cursor:pointer}

.kat-link:hover{border-color:#d4d4d8}

.kat-link.strong{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.kat-link:disabled{opacity:.45;cursor:not-allowed}

.kat-strip{margin:0 0 10px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}

.kat-strip.warn{border-left-color:#f59e0b;background:#fffbeb}

.kat-empty{margin-top:12px;background:#fff;border:1px dashed var(--m-line);border-radius:16px;padding:40px;text-align:center;color:var(--m-muted);font-size:13px}

/* toolbar sticky ( Katalog) */

.kat-toolbar{position:sticky;top:56px;z-index:10;background:var(--m-bg);border-bottom:1px solid var(--m-line);padding:10px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}

.kat-toolbar--second{top:auto;position:relative;z-index:1;border-bottom:none;padding-top:8px}

.kat-combo{flex:1 1 220px;min-width:200px;display:flex;flex-direction:column;gap:4px}

.kat-combo-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.kat-field{display:flex;flex-direction:column;gap:4px;min-width:140px;flex:0 0 auto}

.kat-field-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.kat-search{position:relative;flex:0 1 280px;min-width:200px}

.kat-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--m-muted)}

.kat-input{width:100%;padding:9px 36px 9px 34px;border:1px solid var(--m-line);background:#fff;border-radius:10px;font-size:13px;outline:none;color:var(--m-ink);box-sizing:border-box}

.kat-input:focus{border-color:var(--m-green);box-shadow:0 0 0 2px rgba(94,184,126,.2)}

.combo-input{padding-right:36px}

.kat-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:24px;height:24px;border-radius:999px;border:1px solid var(--m-line);background:#fff;cursor:pointer;color:var(--m-muted);line-height:1;display:grid;place-items:center}

.kat-clear:hover{background:var(--m-bg)}

.combo{position:relative}

.combo-list{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1px solid var(--m-line);border-radius:12px;box-shadow:0 10px 24px rgba(0,0,0,.08);max-height:240px;overflow:auto;z-index:20;list-style:none;margin:0;padding:4px}

.combo-opt{padding:8px 12px;border-radius:8px;cursor:pointer;font-size:13px;color:var(--m-ink)}

.combo-opt:hover{background:var(--m-bg)}

.combo-opt.active{background:var(--m-ink);color:#fff}

.combo-opt.muted{color:var(--m-muted);cursor:default}

.combo-opt.active .muted{color:#a1a1aa}

.kat-select{padding:9px 10px;border:1px solid var(--m-line);background:#fff;border-radius:10px;font-size:12.5px;color:var(--m-ink);min-height:38px;min-width:0;outline:none;box-sizing:border-box}

.kat-select:focus{border-color:var(--m-green);box-shadow:0 0 0 2px rgba(94,184,126,.18)}

.kat-select.invalid{border-color:#ef4444;box-shadow:0 0 0 2px rgba(239,68,68,.12)}

.kat-date{min-width:150px}

.kat-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1 1 auto;min-width:0}

.kat-count-top{margin-left:auto;font-size:11px;color:var(--m-muted);white-space:nowrap}

.kat-alert{margin-top:10px;padding:10px 12px;border-radius:10px;font-size:12.5px;border:1px solid}

.kat-alert.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}

@media(max-width:639px){

 .kat-page{padding:16px 14px 20px}

 .kat-head{flex-direction:column;align-items:flex-start;gap:6px}

 .kat-title{font-size:18px}

 .kat-toolbar{position:static;top:auto;z-index:auto;flex-direction:column;align-items:stretch;padding:8px 0 6px;gap:6px;border-bottom:none}

 .kat-toolbar--second{padding-top:6px;gap:6px}

 .kat-combo,.kat-field{flex:none;width:100%;min-width:0}

 .kat-search{flex:none;width:100%;min-width:0}

 .kat-filters{display:grid;grid-template-columns:1fr 1fr;width:100%;flex:none;gap:6px}

 .kat-select{width:100%;padding:7px 10px;min-height:36px;border-radius:9px}

 .kat-input{padding:7px 30px 7px 30px;min-height:36px;border-radius:9px;font-size:13.5px}

 .combo-input{padding-right:30px}

 .kat-field .kat-select,.kat-field .kat-input{min-height:36px}

 .kat-count-top{font-size:10.5px}

 .kat-combo-label,.kat-field-label{font-size:9px}

}

/* KPI */

.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:10px 0 12px}

@media(max-width:900px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}

@media(max-width:640px){.kpi-grid{grid-template-columns:1fr}}

.kpi-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;padding:16px}

.kpi-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.kpi-value{font-size:20px;font-weight:800;margin-top:6px;color:var(--m-ink)}

.kpi-muted{font-size:12px;font-weight:400;color:var(--m-muted)}

.kpi-bar{height:6px;border-radius:999px;background:var(--m-bg);overflow:hidden;margin-top:10px}

.kpi-fill{height:100%;background:var(--m-green);transition:width .3s}

.kpi-desc{font-size:11px;color:var(--m-muted);margin-top:6px;line-height:1.4}

.kpi-badge{font-size:10px;background:#fffbeb;color:#92400e;border:1px solid #fde68a;padding:2px 6px;border-radius:999px;margin-left:6px}

/* insight */

.insight-card{padding:16px;margin-bottom:12px}

.insight-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px}

.insight-title{font-size:13px;font-weight:700}

.insight-pill{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:10px;margin-left:6px}

.insight-grid{display:grid;grid-template-columns:1.6fr .9fr;gap:12px;align-items:stretch}

@media(max-width:900px){.insight-grid{grid-template-columns:1fr}}

.insight-chart{background:var(--m-bg);border:1px solid var(--m-line);border-radius:12px;padding:12px;overflow:hidden;display:flex;flex-direction:column;min-height:0}

.insight-side{display:flex;flex-direction:column;gap:8px}

.chart-title{font-size:11px;color:var(--m-muted);margin-bottom:8px;flex-shrink:0}

.chart-wrap{position:relative;flex:1;min-height:220px;width:100%;overflow:hidden;display:block}

.chart-wrap canvas{position:absolute !important;inset:0 !important;width:100% !important;height:100% !important;display:block}

@media(max-width:900px){.chart-wrap{min-height:240px;height:240px}.chart-wrap canvas{position:relative !important;inset:auto !important}}

.bars{display:flex;align-items:end;gap:8px;min-height:100px;padding-top:8px}

.bar-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1}

.bar{width:100%;max-width:36px;background:var(--m-ink);border-radius:6px 6px 0 0;transition:height .3s}

.bar-lab{font-size:10px;color:var(--m-muted)}

.bar-val{font-size:11px;font-weight:700}

.rank-card,.alert-card{background:#fff;border:1px solid var(--m-line);border-radius:12px;padding:12px}

.rank-title{font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-bottom:8px}

.rank-list{display:flex;flex-direction:column;gap:6px}

.rank-row{display:flex;align-items:center;gap:8px;padding:6px 8px;background:var(--m-bg);border-radius:8px}

.rank-medal{font-size:14px}

.rank-name{flex:1;font-size:13px;font-weight:600}

.rank-pct{font-size:12px;font-weight:700}

.rank-pct.low{color:#dc2626}

.alert-list{display:flex;flex-direction:column;gap:6px}

.alert-row{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 8px;background:#fff7f7;border:1px solid #fecaca;border-radius:8px;font-size:12px}

.alert-pct{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* table */

.rekap-single{margin-bottom:12px}

.table-card{overflow:hidden;display:flex;flex-direction:column}

.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}

.table-head{padding:12px 16px;border-bottom:1px solid var(--m-line);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;background:#fff}

.table-title{font-size:13px;font-weight:700;color:var(--m-ink)}

.table-wrap{overflow:auto;min-height:180px}

table{width:100%;border-collapse:collapse;font-size:13px}

thead{background:var(--m-bg)}

th{font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);text-align:left;padding:10px 12px;white-space:nowrap}

td{padding:10px 12px;border-top:1px solid var(--m-line);vertical-align:middle}

.pct-low{color:#dc2626;font-weight:700}

.pct-mid{color:#d97706;font-weight:600}

.badge{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid transparent}

.badge-ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.badge-muted{background:var(--m-bg);color:var(--m-muted);border-color:var(--m-line)}

.pill{font-size:11px;padding:4px 8px;border-radius:999px}

.tabs{display:flex;gap:4px;padding:4px;background:var(--m-bg);border-radius:12px}

.tab{padding:6px 12px;border-radius:8px;border:none;background:transparent;font-size:12px;font-weight:600;cursor:pointer;color:var(--m-muted)}

.tab.active{background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.06);color:var(--m-ink)}

.table-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 12px;background:var(--m-bg);border-top:1px solid var(--m-line)}

.pagination{display:flex;align-items:center;gap:6px;flex-wrap:wrap}

.btn-page{width:28px;height:28px;border-radius:999px;border:1px solid var(--m-line);background:#fff;cursor:pointer;display:grid;place-items:center;font-size:12px;color:var(--m-ink)}

.btn-page.active{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.btn-page:disabled{opacity:.4;cursor:not-allowed}

.page-ell{padding:0 4px;color:var(--m-muted)}

.jump{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--m-muted)}

.jump-in{width:56px;padding:6px 8px;border:1px solid var(--m-line);border-radius:8px;font-size:11px;background:#fff}

.skeleton-wrap{padding:12px}

.skel-row{display:flex;gap:12px;padding:10px 12px;border-top:1px solid var(--m-line)}

.skel{height:12px;background:var(--m-bg);border-radius:6px;animation:pulse 1.2s infinite;display:block}

@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

.empty{display:grid;place-items:center;padding:32px 16px;text-align:center;color:var(--m-muted);font-size:13px}

/* export */

.export-wrap{position:relative}

.export-menu{position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid var(--m-line);border-radius:12px;box-shadow:0 10px 24px rgba(0,0,0,.12);padding:6px;z-index:30;min-width:200px}

.exp-opt{width:100%;text-align:left;padding:8px 10px;border-radius:8px;border:none;background:#fff;cursor:pointer;font-size:12px;font-weight:500;color:var(--m-ink)}

.exp-opt:hover{background:var(--m-bg)}

.exp-opt:disabled{opacity:.4;cursor:not-allowed}

.exp-sep{height:1px;background:var(--m-line);margin:4px 0}

.exp-sesi-label{padding:4px 12px 2px;font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);text-transform:uppercase}
.exp-sesi-select{display:block;width:calc(100% - 24px);margin:2px 12px 6px;padding:9px 10px;font-size:12.5px;border:1px solid var(--m-line);border-radius:10px;background:#fff;color:var(--m-ink);cursor:pointer;outline:none;box-sizing:border-box}
.exp-sesi-select:focus{border-color:var(--m-green);box-shadow:0 0 0 2px rgba(94,184,126,.18)}
.exp-sesi-select option{background:#fff;color:var(--m-ink)}
.rekap-sesi-filter{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--m-line);background:var(--m-bg);flex-wrap:wrap}
.rekap-sesi-label{font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted)}
.rekap-sesi-select{padding:9px 10px;border:1px solid var(--m-line);border-radius:10px;background:#fff;color:var(--m-ink);font-size:12.5px;outline:none;min-width:220px;min-height:38px;box-sizing:border-box}
.rekap-sesi-select:focus{border-color:var(--m-green);box-shadow:0 0 0 2px rgba(94,184,126,.18)}

.spin{width:14px;height:14px;border:2px solid var(--m-line);border-top-color:var(--m-ink);border-radius:999px;display:inline-block;animation:sp 0.6s linear infinite}

.spin-white{border-color:rgba(255,255,255,.3);border-top-color:#fff}

@keyframes sp{to{transform:rotate(360deg)}}

/* drawer + toast */

.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);backdrop-filter:blur(2px);z-index:50;display:flex;justify-content:flex-end}

.drawer{width:420px;max-width:92vw;background:#fff;height:100%;overflow:auto;box-shadow:-8px 0 24px rgba(0,0,0,.12);display:flex;flex-direction:column}

.drawer-head{padding:16px;border-bottom:1px solid var(--m-line);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff}

.drawer-body{padding:12px}

.audit-list{display:flex;flex-direction:column;gap:8px}

.audit-row{padding:10px;border:1px solid var(--m-line);border-radius:12px;background:var(--m-bg)}

.audit-main{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:4px}

.toast{position:fixed;bottom:16px;right:16px;padding:12px 16px;border-radius:12px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:60}

.toast-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}

.toast-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

</style>

