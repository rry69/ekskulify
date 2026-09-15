<template>

<div class="kat-page">

<div class="kat-inner">



 <!-- head flat ( Katalog/Events/Kalender) -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title">{{ headTitle }}<span class="kat-title-hi"> - {{ greeting }}</span></h1>

 </div>

 </div>

 <div v-if="error || siswaError" class="kat-strip warn" role="alert" style="display:flex;justify-content:space-between;align-items:center;gap:12px">

 <span class="mono" style="font-size:12px;color:#991b1b">{{ error || siswaError }}</span>

 <button class="kat-link small" @click="load">Coba lagi</button>

 </div>



 <!-- ===== SISWA ===== -->

 <template v-if="isSiswa">

 <div v-if="siswaLoading" class="kat-stats" aria-busy="true"><div v-for="i in 3" :key="i" class="kat-stat"><div class="skel-line w40"></div><div class="skel-line w70"></div></div></div>

 <template v-else>

 <div class="kat-stats kat-stats-3" aria-label="Ringkasan siswa">

 <div class="kat-stat">

 <div class="kat-stat-label mono">EKSKUL DIIKUTI</div>

 <div class="kat-stat-value">{{ regs.length }}<span class="kat-stat-unit">/2</span></div>

 <div class="kat-stat-desc mono">{{ regs.length>=2 ? 'Penuh' : `${2-regs.length} slot tersisa` }}</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">EVENT DIIKUTI</div>

 <div class="kat-stat-value">{{ ev.length }}</div>

 <div class="kat-stat-desc mono">Aktif</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">JADWAL TERDEKAT</div>

 <div class="kat-stat-value">{{ jadwalItems.length }}</div>

 <div class="kat-stat-desc mono">{{ isShowingUpcoming ? 'Mendatang' : 'Hari ini' }}</div>

 </div>

 </div>



 <div class="dash-m-tabs" role="tablist" aria-label="Navigasi dashboard">

 <button v-for="t in dashTabs" :key="t.id" role="tab" :aria-selected="mobileTab===t.id" class="dash-m-tab" :class="{active: mobileTab===t.id}" @click="mobileTab=t.id">{{ t.label }}<span v-if="t.badge||t.badge==='0'" class="dash-m-tab-n">{{ t.badge }}</span></button>

 </div>

 <div class="dash-siswa-layout">

 <div class="dash-siswa-col dash-siswa-col-left">

 <div class="kat-card dash-card dash-card-ekskul" :class="{'dash-hidden-mobile': mobileTab!=='ekskul'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Ekskul saya</h2>

 </div>

 <p v-if="!regs.length" class="mono dash-empty">Belum daftar ekskul. <router-link to="/ekskul" class="dash-link">Cek katalog ›</router-link></p>

 <ul v-else class="dash-rows" v-memo="[regsTop]">

 <li v-for="r in regsTop" :key="r.id" class="dash-row" v-memo="[r.id, r.status]">

 <span class="dash-ava" :style="avaStyle(r.ekskul_nama)">{{ initials(r.ekskul_nama) }}</span>

  <span class="dash-grow"><b>{{ r.ekskul_nama }}</b><i class="mono">{{ r.created_at ? new Date(r.created_at).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'}) : '' }} - {{ r.status }}</i></span>

 <span class="mono dash-pill" :class="pillStatus(r.status)">{{ r.status }}</span>

 </li>

 </ul>

 </div>

 <div class="kat-card dash-card dash-card-ann" :class="{'dash-hidden-mobile': mobileTab!=='pengumuman'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Pengumuman - {{ ann.length }}</h2>

 </div>

 <p v-if="!ann.length" class="mono dash-empty">Belum ada pengumuman.</p>

 <ul v-else class="dash-annlist" v-memo="[ann]">

 <li v-for="a in ann" :key="a.id" class="dash-ann" v-memo="[a.id]">

 <b>{{ a.judul }}</b>

 <p class="mono">{{ a.isi }}</p>

 <i class="mono">{{ a.created_at ? new Date(a.created_at).toLocaleDateString('id-ID',{day:'numeric',month:'short'}) : '' }} - {{ a.creator || a.author || 'Admin' }}</i>

 </li>

 </ul>

 </div>

 </div>

 <div class="dash-siswa-col dash-siswa-col-right">

 <div class="kat-card dash-card dash-card-jadwal" :class="{'dash-hidden-mobile': mobileTab!=='jadwal'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">{{ jadwalTitle }}</h2>

 </div>

 <div class="dash-cal" role="grid" aria-label="Kalender bulan ini" v-memo="[kalData]">

 <span v-for="d in weekDays" :key="d" class="mono dash-wd">{{ d }}</span>

 <template v-for="cell in calCells" :key="cell.key">

 <span v-if="cell.isEmpty"></span>

 <span v-else class="dash-day" :class="{today:cell.isToday}">{{ cell.day }}<span v-if="hasEventDot(cell.day)" class="dash-dot" aria-hidden="true"></span></span>

 </template>

 </div>

 <p v-if="!jadwalItems.length" class="mono dash-empty">Tidak ada jadwal hari ini - lihat mendatang di Kalender.</p>

 <ul v-else class="dash-rows dash-rows-compact" v-memo="[jadwalItems]">

 <li v-for="(j,i) in jadwalItems" :key="i" class="dash-row" v-memo="[j.tanggal, j.jam_mulai, j.tipe]">

 <span class="mono dash-pill" :class="pillTipe(j.tipe)">{{ j.tipe }}</span>

 <span class="dash-grow"><b>{{ j.ekskul_nama || j.nama }}</b><i class="mono">{{ j.tanggal }} - {{ (j.jam_mulai||'').slice(0,5) }}- {{ (j.jam_selesai||'').slice(0,5) }} - {{ j.lokasi || '-' }}</i></span>

 </li>

 </ul>

 </div>

 </div>

 </div>

 </template>

 </template>



 <!-- ===== PEMBINA ===== -->

 <template v-else-if="isPembina">

 <div v-if="siswaLoading" class="kat-stats" aria-busy="true"><div v-for="i in 4" :key="i" class="kat-stat"><div class="skel-line w40"></div><div class="skel-line w70"></div></div></div>

 <template v-else>

 <div class="kat-stats" aria-label="Ringkasan pembina" v-memo="[regsPembina.length, dashCountPersonal, jadwalItems.length, evGlobal.length]">

 <div class="kat-stat">

 <div class="kat-stat-label mono">EKSKUL BINAAN</div>

 <div class="kat-stat-value">{{ regsPembina.length || dashCountPersonal }}</div>

 <div class="kat-stat-desc mono">Terdaftar</div>

 <router-link to="/ekskul" class="mono kat-stat-link">Katalog binaan ></router-link>

 </div>

  <div class="kat-stat" :class="(dash?.counts?.pending_registrations||0)>0?'stat-warn':''">

 <div class="kat-stat-label mono">MENUNGGU ACC</div>

 <div class="kat-stat-value">{{ dash?.counts?.pending_registrations ?? '-' }}</div>

 <div class="kat-stat-desc mono">{{ (dash?.counts?.pending_registrations||0)>0 ? 'Perlu tindakan' : 'Kosong' }}</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">JADWAL TERDEKAT</div>

 <div class="kat-stat-value">{{ jadwalItems.length }}</div>

 <div class="kat-stat-desc mono">Hari ini</div>

 <router-link to="/kalender" class="mono kat-stat-link">Kalender ></router-link>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">EVENT BERJALAN</div>

 <div class="kat-stat-value">{{ evGlobal.length }}</div>

 <div class="kat-stat-desc mono">Aktif</div>

 <router-link to="/events" class="mono kat-stat-link">Event ></router-link>

 </div>

 </div>



 <div class="dash-quick">

 <span class="mono dash-quick-kicker">KELOLA BINAAN</span>

 <div class="dash-quick-actions">

 <router-link to="/ekskul" class="kat-link strong">+ Kelola Ekskul</router-link>

 <router-link to="/events" class="kat-link">Kelola Event</router-link>

 <router-link to="/kalender" class="kat-link">Kalender</router-link>

 <router-link to="/pembina/laporan" class="kat-link">Laporan Binaan <span class="kat-link-arrow" aria-hidden="true">›</span></router-link>

 </div>

 </div>



 <div class="dash-m-tabs" role="tablist" aria-label="Navigasi dashboard">

 <button v-for="t in dashTabs" :key="t.id" role="tab" :aria-selected="mobileTab===t.id" class="dash-m-tab" :class="{active: mobileTab===t.id}" @click="mobileTab=t.id">{{ t.label }}<span v-if="t.badge||t.badge==='0'" class="dash-m-tab-n">{{ t.badge }}</span></button>

 </div>

 <div class="dash-siswa-layout">

 <div class="dash-siswa-col dash-siswa-col-left">

 <div class="kat-card dash-card dash-card-ekskul" :class="{'dash-hidden-mobile': mobileTab!=='binaan'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Ekskul binaan - {{ regsPembina.length || dashCountPersonal }}</h2>

 <router-link to="/ekskul" class="mono dash-card-link">Katalog ></router-link>

 </div>

 <p v-if="!(regsPembina.length || dashCountPersonal)" class="mono dash-empty">Belum ada binaan - hubungi admin untuk assignment.</p>

 <ul v-else class="dash-rows" v-memo="[pembinaTop]">

 <li v-for="e in pembinaTop" :key="e.id" class="dash-row" v-memo="[e.id]">

 <span class="dash-ava" :style="avaStyle(e.nama || e.ekskul_nama)">{{ initials(e.nama || e.ekskul_nama) }}</span>

 <span class="dash-grow"><b>{{ e.nama || e.ekskul_nama }}</b><i class="mono">{{ e.status || 'approved' }} - {{ e.lokasi || '-' }}</i></span>

 <span class="mono dash-pill neutral">binaan</span>

 </li>

 </ul>

 <div class="dash-card-foot">

 <router-link to="/ekskul" class="kat-link small">Lihat semua binaan</router-link>

 </div>

 </div>

 <div class="kat-card dash-card dash-card-ann" :class="{'dash-hidden-mobile': mobileTab!=='pengumuman'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Pengumuman - {{ ann.length }}</h2>

 </div>

 <p v-if="!ann.length" class="mono dash-empty">Belum ada pengumuman.</p>

 <ul v-else class="dash-annlist" v-memo="[ann]">

 <li v-for="a in ann" :key="a.id" class="dash-ann" v-memo="[a.id]"><b>{{ a.judul }}</b><p class="mono">{{ a.isi }}</p><i class="mono">{{ a.created_at ? new Date(a.created_at).toLocaleDateString('id-ID',{day:'numeric',month:'short'}) : '' }} - {{ a.creator || a.author || 'Admin' }}</i></li>

 </ul>

 </div>

 </div>

 <div class="dash-siswa-col dash-siswa-col-right">

 <div class="kat-card dash-card dash-card-jadwal" :class="{'dash-hidden-mobile': mobileTab!=='jadwal'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">{{ jadwalTitle }}</h2>

 <span class="mono dash-pill neutral">{{ todayLabel }}</span>

 </div>

 <div class="dash-cal" role="grid" aria-label="Kalender bulan ini" v-memo="[kalData]">

 <span v-for="d in weekDays" :key="d" class="mono dash-wd">{{ d }}</span>

 <template v-for="cell in calCells" :key="cell.key">

 <span v-if="cell.isEmpty"></span>

 <span v-else class="dash-day" :class="{today:cell.isToday}">{{ cell.day }}<span v-if="hasEventDot(cell.day)" class="dash-dot" aria-hidden="true"></span></span>

 </template>

 </div>

 <ul class="dash-rows dash-rows-compact" v-memo="[jadwalItems]">

 <li v-for="(j,i) in jadwalItems" :key="i" class="dash-row" v-memo="[j.tanggal, j.jam_mulai]">

 <span class="mono dash-pill" :class="pillTipe(j.tipe)">{{ j.tipe }}</span>

 <span class="dash-grow"><b>{{ j.ekskul_nama || j.nama }}</b><i class="mono">{{ j.tanggal }} - {{ (j.jam_mulai||'').slice(0,5) }}- {{ (j.jam_selesai||'').slice(0,5) }} - {{ j.lokasi || '-' }}</i></span>

 </li>

 </ul>

 <div class="dash-card-foot">

 <router-link to="/kalender" class="kat-link small">Lihat kalender penuh</router-link>

 <router-link to="/ekskul" class="kat-link small strong">Kelola jadwal</router-link>

 </div>

 </div>

 <div class="kat-card dash-card dash-card-pintas" :class="{'dash-hidden-mobile': mobileTab!=='pintas'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Pintasan Pembina</h2>

 </div>

 <div class="dash-pintas">

 <router-link to="/ekskul" class="dash-pintas-row"><span class="dash-pintas-ico"></span><span><b>Tambah ekskul binaan</b></span><span>></span></router-link>

 <router-link to="/events" class="dash-pintas-row"><span class="dash-pintas-ico"><</span><span><b>Buat event binaan</b></span><span>></span></router-link>

 <router-link to="/pembina/laporan" class="dash-pintas-row"><span class="dash-pintas-ico"></span><span><b>Laporan binaan</b></span><span>></span></router-link>

 </div>

  <p v-if="annToast" class="mono dash-toast" :class="annToastOk ? 'ok' : 'warn'">{{ annToast }}</p>

 </div>

 </div>

 </div>

 </template>

 </template>



 <!-- ===== ADMIN / KEPSEK ===== -->

 <template v-else>

 <div v-if="loading" class="kat-stats" aria-busy="true"><div v-for="i in 4" :key="i" class="kat-stat"><div class="skel-line w40"></div><div class="skel-line w70"></div></div></div>

 <template v-else>

 <div v-if="!isKepsek" class="dash-statwrap">
<div class="dash-tabs" role="tablist" aria-label="Tampilan ringkasan">
<button role="tab" :aria-selected="statTab==='ringkas'" class="dash-tab" :class="{active: statTab==='ringkas'}" @click="statTab='ringkas'">Ringkasan</button>
<button role="tab" :aria-selected="statTab==='analisis'" class="dash-tab" :class="{active: statTab==='analisis'}" @click="openAnalisis">Analisis</button>
</div>
<div v-if="statTab==='ringkas'" class="kat-stats" aria-label="Indikator utama" v-memo="[dash?.counts]">

  <button class="kat-stat kat-stat-click" @click="go('/ekskul')">

 <span class="kat-stat-label mono">TOTAL EKSKUL</span>

 <span class="kat-stat-value">{{ dash?.counts?.total_ekskul ?? '-' }}</span>

 <span class="mono kat-stat-link">Buka katalog</span>

 </button>

 <button class="kat-stat kat-stat-click" @click="go(roleLink.siswa)">

 <span class="kat-stat-label mono">TOTAL SISWA</span>

 <span class="kat-stat-value">{{ dash?.counts?.total_siswa ?? '-' }}</span>

 <span class="mono kat-stat-link">Lihat pengguna ></span>

 </button>

  <button id="cardMenunggu" class="kat-stat kat-stat-click" :class="(dash?.counts?.pending_registrations||0)>0?'stat-warn':''" @click="(dash?.counts?.pending_registrations||0)>0 && highlightRecent()">

 <span class="kat-stat-label mono">MENUNGGU ACC</span>

 <span class="kat-stat-value">{{ dash?.counts?.pending_registrations ?? '-' }}</span>

  <span class="mono kat-stat-link" v-if="(dash?.counts?.pending_registrations||0)>0">Lihat daftar ></span>

 </button>

 <button class="kat-stat kat-stat-click" @click="go(roleLink.event)">

 <span class="kat-stat-label mono">TOTAL EVENT</span>

 <span class="kat-stat-value">{{ dash?.counts?.total_event ?? '-' }}</span>

 <span class="mono kat-stat-link">Buka event ></span>

 </button>

 </div>
<div v-if="statTab==='analisis'" class="dash-charts" aria-label="Analisis">
  <div class="dash-chart-card dash-chart-wide">
   <h3 class="dash-chart-title">Top 5 Ekskul Terpopuler <span class="dash-chart-sub">% terisi - klik bar -> detail</span></h3>
   <p v-if="chartLoading" class="mono dash-empty">Memuat analisis...</p>
   <ApexChart v-else-if="top5Series[0].data.length" type="bar" :height="210" :options="top5Opts" :series="top5Series" :events="top5Events" />
   <p v-else class="mono dash-empty">Belum ada data.</p>
  </div>
  <div class="dash-chart-card">
   <h3 class="dash-chart-title">Status Persetujuan <span class="dash-chart-sub">klik segmen -> katalog</span></h3>
   <p v-if="chartLoading" class="mono dash-empty">Memuat analisis...</p>
   <ApexChart v-else-if="chartEkskul.length" type="donut" :height="210" :options="statusOpts" :series="statusSeries" :events="statusEvents" />
   <p v-else class="mono dash-empty">Belum ada data.</p>
  </div>
  <div class="dash-chart-card">
   <h3 class="dash-chart-title">Okupansi Event <span class="dash-chart-sub">rata-rata % terisi - klik -> event</span></h3>
   <p v-if="chartLoading" class="mono dash-empty">Memuat analisis...</p>
   <ApexChart v-else-if="chartEvents.length" type="radialBar" :height="210" :options="okupansiOpts" :series="okupansiSeries" :events="okupansiEvents" />
   <p v-else class="mono dash-empty">Belum ada data.</p>
  </div>
</div>
 </div>

 <div v-else class="kat-stats" aria-label="Indikator utama" v-memo="[dash?.counts]">

 <div class="kat-stat">

 <span class="kat-stat-label mono">TOTAL EKSKUL</span>

 <span class="kat-stat-value">{{ dash?.counts?.total_ekskul ?? '-' }}</span>

 </div>

 <div class="kat-stat">

 <span class="kat-stat-label mono">TOTAL SISWA</span>

 <span class="kat-stat-value">{{ dash?.counts?.total_siswa ?? '-' }}</span>

 </div>

  <button class="kat-stat kat-stat-click stat-warn" @click="go('/kepsek/approval')">

 <span class="kat-stat-label mono">MENUNGGU APPROVAL</span>

 <span class="kat-stat-value">{{ dash?.counts?.pending_registrations ?? '-' }}</span>

 <span class="mono kat-stat-link">Buka approval ></span>

 </button>

 <div class="kat-stat">

 <span class="kat-stat-label mono">TOTAL EVENT</span>

 <span class="kat-stat-value">{{ dash?.counts?.total_event ?? '-' }}</span>

 </div>

 </div>



 <div class="dash-quick">

 <span class="mono dash-quick-kicker">{{ isKepsek ? 'APPROVAL CEPAT' : 'ADMIN CEPAT' }}</span>

 <div class="dash-quick-actions">

 <template v-if="isKepsek">

 <router-link to="/kepsek/approval" class="kat-link strong">Buka approval<span v-if="dash?.counts?.pending_registrations" class="kat-link-n">{{ dash.counts.pending_registrations }}</span></router-link>

 <router-link to="/kepsek/laporan" class="kat-link">Laporan Kepsek <span class="kat-link-arrow" aria-hidden="true">›</span></router-link>

 <router-link to="/kalender" class="kat-link">Kalender <span class="kat-link-arrow" aria-hidden="true">›</span></router-link>

 </template>

 <template v-else>

 <button class="kat-link" @click="exportCsv" :disabled="exporting || loading">{{ exporting ? 'Menyiapkan...' : 'Export CSV' }}</button>

 <router-link to="/admin/users" class="kat-link">Kelola Users <span class="kat-link-arrow" aria-hidden="true">›</span></router-link>

 <router-link to="/admin/sertifikat" class="kat-link">Sertifikat <span class="kat-link-arrow" aria-hidden="true">›</span></router-link>

 <router-link to="/kalender" class="kat-link">Kalender <span class="kat-link-arrow" aria-hidden="true">›</span></router-link>

 </template>

 </div>

 </div>



 <div class="dash-m-tabs" role="tablist" aria-label="Navigasi dashboard">

 <button v-for="t in dashTabs" :key="t.id" role="tab" :aria-selected="mobileTab===t.id" class="dash-m-tab" :class="{active: mobileTab===t.id}" @click="mobileTab=t.id">{{ t.label }}<span v-if="t.badge||t.badge==='0'" class="dash-m-tab-n">{{ t.badge }}</span></button>

 </div>

 <div class="dash-siswa-layout">

 <div class="dash-siswa-col dash-siswa-col-left">

 <div class="kat-card dash-card dash-card-ekskul" id="recent-table" :class="{'dash-hidden-mobile': mobileTab!=='daftar'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title" id="t-recent">5 Pendaftaran terbaru</h2>

 <router-link :to="isKepsek ? '/kepsek/approval' : '/ekskul'" class="mono dash-card-link">{{ isKepsek ? 'Ke approval >' : 'Ke katalog >' }}</router-link>

 </div>

 <p v-if="!dash" class="mono dash-empty">Memuat data...</p>

 <p v-else-if="!dash.recent.length" class="mono dash-empty">Belum ada pendaftaran.</p>

 <ul v-else class="dash-rows" v-memo="[dash.recent]">

 <li v-for="r in dash.recent" :key="r.id" class="dash-row" v-memo="[r.id, r.status]">

 <span class="dash-ava" :style="avaStyle(r.nama)">{{ initials(r.nama) }}</span>

 <span class="dash-grow"><b>{{ r.nama }}</b><i class="mono">{{ r.ekskul_nama }} - {{ r.status }}</i></span>

 <span class="mono dash-pill" :class="pillStatus(r.status)">{{ r.status }}</span>

 </li>

 </ul>

 </div>

 <div class="kat-card dash-card dash-card-ann" :class="{'dash-hidden-mobile': mobileTab!=='pengumuman'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Pengumuman - {{ ann.length }}</h2>

 <span class="mono dash-pill neutral">{{ ann.length ? 'publik' : 'kosong' }}</span>

 </div>

 <p v-if="!ann.length" class="mono dash-empty">Belum ada pengumuman.</p>

 <ul v-else class="dash-annlist" v-memo="[ann]">

 <li v-for="a in ann" :key="a.id" class="dash-ann" v-memo="[a.id]"><b>{{ a.judul }}</b><p class="mono">{{ a.isi }}</p><i class="mono">{{ a.created_at ? new Date(a.created_at).toLocaleDateString('id-ID',{day:'numeric',month:'short'}) : '' }} - {{ a.creator || a.author || 'Admin' }}</i></li>

 </ul>

 </div>

 </div>

 <div class="dash-siswa-col dash-siswa-col-right">

 <div class="kat-card dash-card dash-card-jadwal" :class="{'dash-hidden-mobile': mobileTab!=='jadwal'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Jadwal hari ini</h2>

 <span class="mono dash-pill neutral">{{ dash ? formatTanggal(dash.today_date) : todayLabel }}</span>

 </div>

 <div class="dash-cal" role="grid" aria-label="Kalender bulan ini" v-memo="[kalData, dash?.today]">

 <span v-for="d in weekDays" :key="d" class="mono dash-wd">{{ d }}</span>

 <template v-for="cell in calCellsAdmin" :key="cell.key">

 <span v-if="cell.isEmpty"></span>

 <span v-else class="dash-day" :class="{today:cell.isToday}">{{ cell.day }}<span v-if="hasEventDotAdmin(cell.day)" class="dash-dot" aria-hidden="true"></span></span>

 </template>

 </div>

 <template v-if="dash?.today?.length">

 <div v-memo="[dash.today]" class="dash-todaybox">

 <ul class="dash-rows dash-rows-compact">

 <li v-for="(j,i) in dash.today" :key="i" class="dash-row" v-memo="[j.nama, j.jam_mulai]">

 <span class="mono dash-pill" :class="pillTipe(j.tipe)">{{ j.tipe }}</span>

 <span class="dash-grow"><b>{{ j.nama }}</b><i class="mono">{{ j.jam_mulai }}- {{ j.jam_selesai }} - {{ j.lokasi || '-' }}</i></span>

 </li>

 </ul>

 <p v-if="hasOverlap" class="mono dash-warn" role="alert">! Potensi bentrok jam terdeteksi - cek lokasi & jam.</p>

 </div>

 </template>

 <p v-else-if="dash" class="mono dash-empty">Tidak ada jadwal hari ini</p>

 <p v-else class="mono dash-empty">Memuat jadwal...</p>

 <div class="dash-card-foot">

 <router-link to="/kalender" class="kat-link small">Lihat kalender penuh</router-link>

 <router-link v-if="!isKepsek" to="/ekskul" class="kat-link small strong">Buka katalog</router-link>

 <router-link v-else to="/kepsek/approval" class="kat-link small strong">Buka approval</router-link>

 </div>

 </div>

 <div class="kat-card dash-card dash-card-pintas" :class="{'dash-hidden-mobile': mobileTab!=='posting'}">

 <div class="dash-card-head">

 <h2 class="dash-card-title">Posting Pengumuman</h2>

  <span class="mono dash-pill" :class="auth.user?.role==='admin' ? 'ok' : 'neutral'">{{ auth.user?.role==='admin' ? 'admin only' : 'read-only' }}</span>

 </div>

  <template v-if="auth.user?.role==='admin'">

 <label class="dash-fld"><span class="mono">JUDUL</span><input v-model="form.judul" placeholder="Judul pengumuman" aria-label="Judul pengumuman" class="dash-input" /></label>

 <label class="dash-fld"><span class="mono">ISI</span><textarea v-model="form.isi" placeholder="Isi pengumuman..." rows="4" aria-label="Isi pengumuman" class="dash-input"></textarea></label>

 <button @click="postAnn" class="kat-cta dash-cta">Posting</button>

  <p v-if="annToast" class="mono dash-toast" :class="annToastOk ? 'ok' : 'warn'">{{ annToast }}</p>

 </template>

 <template v-else>

 <p class="mono dash-empty">Hanya admin dapat posting pengumuman.</p>

  <p v-if="annToast" class="mono dash-toast" :class="annToastOk ? 'ok' : 'warn'">{{ annToast }}</p>

 </template>

 <div class="dash-pintas" style="margin-top:14px">

 <router-link v-if="isKepsek" to="/kepsek/laporan" class="dash-pintas-row"><span class="dash-pintas-ico"></span><span><b>Laporan Kepsek</b></span><span>></span></router-link>

 </div>

 </div>

 </div>

 </div>

 </template>

 </template>







</div>

</div>

</template>



<script setup>

import { ref, computed, onMounted, watch } from 'vue'

import { useRouter } from 'vue-router'

import { useAuth } from '../stores/auth.js'

import { api } from '../lib/api.js'

import ApexChart from '../components/ApexChart.vue'


const statTab = ref('ringkas')
const chartLoading = ref(false)
const chartLoaded = ref(false)
const chartEkskul = ref([])
const chartEvents = ref([])

function openAnalisis(){ statTab.value='analisis'; loadAnalisis() }
async function loadAnalisis(){
  if(chartLoaded.value || chartLoading.value) return
  if(!(isAdmin.value || isKepsek.value)) return
  chartLoading.value = true
  try{
    const acc=[], acv=[]; let p=1, q=1
    for(let i=0;i<10;i++){
      const [je, jv] = await Promise.all([
        api('/ekskul?limit=100&page='+p).catch(()=>({data:[]})),
        api('/events?limit=100&page='+q+'&sort=tanggal_asc').catch(()=>({data:[]})),
      ])
      const re=je.data||[], rv=jv.data||[]
      acc.push(...re); acv.push(...rv)
      const pe=je.meta?.pages||1, pv=jv.meta?.pages||1
      if(p>=pe && q>=pv) break
      if(p<pe) p++; if(q<pv) q++
    }
    chartEkskul.value = acc; chartEvents.value = acv; chartLoaded.value = true
  }catch{}finally{ chartLoading.value = false }
}
function pctFill(e){ const k=Number(e.kuota)||0; if(k<=0) return 0; return Math.min(100, Math.round((Number(e.terisi)||0)/k*100)) }
const top5Rows = computed(()=> [...chartEkskul.value].sort((a,b)=> pctFill(b)-pctFill(a)).slice(0,5))
const top5Series = computed(()=> [{ name:'% Terisi', data: top5Rows.value.map(pctFill) }])
const top5Opts = computed(()=> ({ plotOptions:{ bar:{ horizontal:true, borderRadius:6, barHeight:'55%' } }, colors:['#4A7875'], xaxis:{ categories: top5Rows.value.map(e=> (e.nama||'?').slice(0,18)), max:100, labels:{ formatter:v=> v+'%' } }, dataLabels:{ enabled:true, formatter:v=> v+'%' }, tooltip:{ y:{ formatter:v=> v+'%' } }, grid:{ strokeDasharray:3 } }))
const top5Events = { dataPointSelection(e, ctx, cfg){ const r = top5Rows.value[cfg?.dataPointIndex ?? -1]; if(r?.id) go('/ekskul/'+r.id) } }
const statusCounts = computed(()=>{
  const c = { approved:0, pending:0, rejected:0 }
  for(const e of chartEkskul.value){
    if(e.status==='approved') c.approved++
    else if(e.status==='pending') c.pending++
    else if(e.status==='rejected') c.rejected++
  }
  return c
})
const statusSeries = computed(()=> [statusCounts.value.approved, statusCounts.value.pending, statusCounts.value.rejected])
const statusOpts = computed(()=> ({ labels:['Approved','Pending','Rejected'], colors:['#5EB87E','#f59e0b','#ef4444'], legend:{ position:'bottom' }, dataLabels:{ enabled:true }, tooltip:{ y:{ formatter:v=> v+' ekskul' } } }))
const statusEvents = { dataPointSelection(){ go('/ekskul') } }
const okupansiAvg = computed(()=>{
  if(!chartEvents.value.length) return 0
  const s = chartEvents.value.reduce((a,e)=>{ const k=Number(e.kuota)||0; return a + (k<=0?0:Math.min(100,Math.round((Number(e.terisi)||0)/k*100))) }, 0)
  return Math.round(s/chartEvents.value.length)
})
const okupansiSeries = computed(()=> [okupansiAvg.value])
const okupansiOpts = computed(()=> ({ colors:['#5EB87E'], plotOptions:{ radialBar:{ hollow:{ size:'60%' }, dataLabels:{ name:{ show:false }, value:{ fontSize:'26px', fontWeight:'700', formatter:v=> v+'%' } } } }, labels:['Okupansi'] }))
const okupansiEvents = { dataPointSelection(){ go('/events') } }



const auth = useAuth()

const router = useRouter()

const activeTab = ref('list')

const mobileTab = ref('ekskul')

const dashTabs = computed(()=>{

 if(isSiswa.value) return [

 {id:'ekskul', label:'Ekskul', badge: regs.value.length},

 {id:'jadwal', label:'Jadwal', badge: jadwalItems.value.length},

 {id:'pengumuman', label:'Pengumuman', badge: ann.value.length},

 ]

 if(isPembina.value) return [

 {id:'binaan', label:'Binaan', badge: (regsPembina.value.length||dashCountPersonal.value||0)},

 {id:'jadwal', label:'Jadwal', badge: jadwalItems.value.length},

 {id:'pengumuman', label:'Pengumuman', badge: ann.value.length},

 {id:'pintas', label:'Pintasan'},

 ]

 return [

 {id:'daftar', label:'Pendaftaran', badge: dash.value?.recent?.length ?? dash.value?.counts?.pending_registrations ?? 0},

 {id:'jadwal', label:'Jadwal', badge: dash.value?.today?.length ?? jadwalItems.value.length ?? 0},

 {id:'pengumuman', label:'Pengumuman', badge: ann.value.length},

 {id:'posting', label:'Posting'},

 ]

})

function syncMobileTab(){

 const tabs = dashTabs.value

 if(!tabs.length) return

 if(!tabs.find(t=>t.id===mobileTab.value)) mobileTab.value = tabs[0].id

}

const loading = ref(true)

const error = ref('')

const dash = ref(null)

const ann = ref([])

const form = ref({ judul:'', isi:'' })

const exporting = ref(false)

const annToast = ref('')

const annToastOk = ref(true)



const regs = ref([])

const ev = ref([])

const kalData = ref(null)

const siswaLoading = ref(true)

const siswaError = ref('')

const todayDate = computed(()=> new Date().toISOString().slice(0,10))

const isSiswa = computed(()=> auth.user?.role === 'siswa')

const isPembina = computed(()=> auth.user?.role === 'pembina')

const isKepsek = computed(()=> auth.user?.role === 'kepsek')

const isAdmin = computed(()=> auth.user?.role === 'admin')



const regsPembina = ref([])

const evGlobal = ref([])

const dashCountPersonal = computed(()=> dash.value?.counts?.total_ekskul ?? regsPembina.value.length)



const greeting = computed(()=>{

 const h = new Date().getHours()

 if(h < 11) return 'Selamat pagi'

 if(h < 15) return 'Selamat siang'

 if(h < 19) return 'Selamat sore'

 return 'Selamat malam'

})

const roleLabel = computed(()=>{

 const r=auth.user?.role||'guest'

 if(r==='siswa') return 'SISWA - maks 2 ekskul'

 if(r==='pembina') return 'PEMBINA - kelola binaan'

 if(r==='kepsek') return 'KEPSEK - approval'

 if(r==='admin') return 'ADMIN - kelola sistem'

 return 'TAMU - login untuk akses penuh'

})

const headTitle = computed(()=>{

 if(isSiswa.value) return 'Beranda Siswa'

 if(isPembina.value) return 'Beranda Pembina'

 if(isKepsek.value) return 'Beranda Kepsek'

 if(isAdmin.value) return 'Beranda Admin'

 return 'Beranda'

})

const headSub = computed(()=>{

 if(isSiswa.value) return `${regs.value.length}/2 ekskul - ${ev.value.length} event - ${jadwalItems.value.length} jadwal`

 if(isPembina.value) return `${regsPembina.value.length||dashCountPersonal.value} binaan - ${dash.value?.counts?.pending_registrations ?? '-'} menunggu - ${jadwalItems.value.length} jadwal`

 return `${dash.value?.counts?.total_ekskul ?? '-'} ekskul - ${dash.value?.counts?.total_siswa ?? '-'} siswa - ${dash.value?.counts?.pending_registrations ?? '-'} menunggu - ${dash.value?.counts?.total_event ?? '-'} event`

})

const stripText = computed(()=>{

 const r=auth.user?.role

 if(!r) return 'Login untuk mendaftar, absen QR, dan melihat jadwal personal.'

 if(r==='kepsek' && (dash.value?.counts?.pending_registrations||0)>0) return `${dash.value.counts.pending_registrations} pendaftaran menunggu keputusan Kepsek.`

 if(r==='siswa' && regs.value.length===0) return 'Belum ikut ekskul - jelajahi Katalog.'

 if(r==='pembina' && (dash.value?.counts?.pending_registrations||0)>0) return `${dash.value.counts.pending_registrations} menunggu ACC.`

  if(r==='admin' && (dash.value?.counts?.pending_registrations||0)>0) return `${dash.value.counts.pending_registrations} menunggu - teruskan ke Kepsek.`

  return ''

 })

const stripCls = computed(()=>{

 const r=auth.user?.role

 if(r==='kepsek'||r==='admin') return 'warn'

  if(r==='siswa' && regs.value.length>=2) return 'warn'

  return ''

 })

const stripLink = computed(()=>{

 const r=auth.user?.role

 if(r==='kepsek') return {to:'/kepsek/approval', label:'Buka approval ->'}

 if(!r) return {to:'/login', label:'Login ->'}

 if(r==='siswa' && !regs.value.length) return {to:'/ekskul', label:'Ke katalog ->'}

 return null

})



const regsTop = computed(()=> regs.value.slice(0,5))

const pembinaTop = computed(()=> regsPembina.value.slice(0,6))



const roleLink = computed(()=>{

 const r = auth.user?.role

 return {

 siswa: r==='admin' ? '/admin/users' : '/ekskul',

 event: '/events',

 }

})



function go(path){ router.push(path) }

function highlightRecent(){

 const el=document.getElementById('recent-table')

 el?.scrollIntoView({behavior:'smooth', block:'center'})

}

function initials(n){

 if(!n) return '?'

 const p=n.trim().split(/\s+/)

 if(p.length===1) return p[0].slice(0,2).toUpperCase()

 return (p[0][0]+p[p.length-1][0]).toUpperCase()

}

function avaStyle(n){

 const c=(n||'').charCodeAt(0)%3

 if(c===0) return 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0'

 if(c===1) return 'background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe'

 return 'background:#F1F5F4;color:#57534e;border:1px solid #E0E5E3'

}

function pillStatus(s){

 if(s==='diterima') return 'ok'

 if(s==='menunggu') return 'warn'

 if(s==='ditolak') return 'err'

 return 'neutral'

}

function pillTipe(t){

 if(t==='event') return 'event'

 if(t==='tambahan') return 'tambahan'

 return 'rutin'

}

function formatTanggal(s){

 if(!s) return ''

 try{ return new Date(s+'T00:00:00').toLocaleDateString('id-ID',{weekday:'long', day:'numeric', month:'long', year:'numeric'}) }catch{ return s }

}

const todayLabel = computed(()=>{

 try{ return new Date().toLocaleDateString('id-ID',{day:'numeric', month:'short'}) }catch{ return '' }

})

const weekDays = ['MIN','SEN','SEL','RAB','KAM','JUM','SAB']

const monthLabel = computed(()=>{

 try{ return new Date().toLocaleDateString('id-ID',{month:'long', year:'numeric'}) }catch{ return '' }

})

const calCellsAdmin = computed(()=>{

 const now = new Date()

 const y = now.getFullYear(), m = now.getMonth()

 const firstDay = new Date(y,m,1).getDay()

 const daysInMonth = new Date(y,m+1,0).getDate()

 const today = now.getDate()

 const cells=[]

 for(let i=0;i<firstDay;i++) cells.push({key:'e'+i, day:'', isEmpty:true, label:''})

  for(let d=1; d<=daysInMonth; d++) cells.push({key:'d'+d, day:d, isToday:d===today, isEmpty:false, label:d===today?'Hari ini':''})

 return cells

})

function hasEventDotAdmin(day){

 if(!day) return false

 if(kalData.value?.all){

 const y = new Date().getFullYear()

 const m = String(new Date().getMonth()+1).padStart(2,'0')

 const ds = `${y}-${m}-${String(day).padStart(2,'0')}`

 if(kalData.value.all.some(r=> r.tanggal===ds)) return true

 }

 if(dash.value?.today?.length){

 const y = new Date().getFullYear()

 const m = String(new Date().getMonth()+1).padStart(2,'0')

 const ds = `${y}-${m}-${String(day).padStart(2,'0')}`

 if(dash.value.today.some(j=> j.tanggal===ds)) return true

 }

 return false

}

const calCells = computed(()=>{

 const now = new Date()

 const y = now.getFullYear(), m = now.getMonth()

 const firstDay = new Date(y,m,1).getDay()

 const daysInMonth = new Date(y,m+1,0).getDate()

 const today = now.getDate()

 const cells=[]

 for(let i=0;i<firstDay;i++) cells.push({key:'e'+i, day:'', isEmpty:true, label:''})

  for(let d=1; d<=daysInMonth; d++) cells.push({key:'d'+d, day:d, isToday:d===today, isEmpty:false, label:d===today?'Hari ini':''})

 return cells

})

function hasEventDot(day){

 if(!day || !kalData.value?.all) return false

 const y = new Date().getFullYear()

 const m = String(new Date().getMonth()+1).padStart(2,'0')

 const ds = `${y}-${m}-${String(day).padStart(2,'0')}`

 return kalData.value.all.some(r=> r.tanggal===ds)

}

const jadwalItems = computed(()=>{

 if(!kalData.value?.all) return []

 const t = todayDate.value

 const todayList = kalData.value.all.filter(x=> x.tanggal===t)

 if(todayList.length) return todayList.slice(0,5)

 const upcoming = kalData.value.all.filter(x=> x.tanggal > t).sort((a,b)=> a.tanggal.localeCompare(b.tanggal) || a.jam_mulai.localeCompare(b.jam_mulai))

 return upcoming.slice(0,3)

})

const isShowingUpcoming = computed(()=>{

 if(!kalData.value?.all) return false

 const t = todayDate.value

 return !kalData.value.all.some(x=> x.tanggal===t) && jadwalItems.value.length>0

})

const jadwalTitle = computed(()=> isShowingUpcoming.value ? 'Jadwal Mendatang' : 'Jadwal Hari Ini')

const hasOverlap = computed(()=>{

 if(!dash.value?.today?.length) return false

 const arr = dash.value.today

  for(let i=0;i<arr.length;i++) for(let j=i+1;j<arr.length;j++){ const a=arr[i], b=arr[j]; if(a.jam_mulai < b.jam_selesai && b.jam_mulai < a.jam_selesai) return true

 }

 return false

})



async function load(){

 error.value=''; siswaError.value=''

 if(auth.loading){

 try{ await auth.me() }catch{}

 }

 if(isSiswa.value){

  siswaLoading.value=true; try{ const j2 = await api('/announcements'); ann.value = Array.isArray(j2.data)? j2.data : [] }catch{ ann.value=[] } try{ const bulan = new Date().toISOString().slice(0,7)

 const [r1, r2, r3] = await Promise.all([

 api('/me/registrations').catch(e=>{ throw e }),

 api('/me/event-registrations').catch(()=> ({data:[]})),

 api(`/kalender?bulan=${bulan}`).catch(()=> ({data:{all:[]}})),

 ])

 regs.value = Array.isArray(r1.data) ? r1.data : []

 ev.value = Array.isArray(r2.data) ? r2.data : []

 kalData.value = r3.data || {all:[]}

 }catch(e){

 siswaError.value = e?.error?.message || e?.message || 'Gagal memuat dashboard siswa'

 }finally{ siswaLoading.value=false; loading.value=false }

 } else if(isPembina.value){

 siswaLoading.value=true

 try{ const j2 = await api('/announcements'); ann.value = Array.isArray(j2.data)? j2.data : [] }catch{ ann.value=[] }

 try{

 const bulan = new Date().toISOString().slice(0,7)

 const dashP = api('/laporan/rekap?dashboard=1').catch(()=>null)

 const kalP = api(`/kalender?bulan=${bulan}`).catch(()=> ({data:{all:[]}}))

 const ekskulP = api('/ekskul?limit=100').catch(()=>({data:[]}))

 const eventsP = api('/events?limit=20').catch(()=>({data:[]}))

 const [dj, kj, ej, evj] = await Promise.all([dashP, kalP, ekskulP, eventsP])

 if(dj?.data) dash.value = dj.data

 kalData.value = kj?.data || {all:[]}

 regsPembina.value = Array.isArray(ej?.data) ? ej.data : (Array.isArray(ej) ? ej : [])

 const allEvents = (kalData.value.all||[]).filter(x=>x.tipe==='event')

 if(allEvents.length) evGlobal.value = allEvents

 else evGlobal.value = Array.isArray(evj?.data) ? evj.data : []

 }catch(e){

 siswaError.value = e?.error?.message || e?.message || 'Gagal memuat dashboard pembina'

 }finally{ siswaLoading.value=false; loading.value=false }

 } else {

 loading.value = true

 try{

 const bulan = new Date().toISOString().slice(0,7)

 const [j, kj] = await Promise.all([api('/laporan/rekap?dashboard=1'), api(`/kalender?bulan=${bulan}`).catch(()=>({data:{all:[]}}))])

 dash.value = j.data

 kalData.value = kj?.data || {all:[]}

 }catch(e){

 error.value = e?.error?.message || e?.message || 'Gagal memuat dashboard'

 }finally{ loading.value=false; siswaLoading.value=false }

 try{ const j2 = await api('/announcements'); ann.value = Array.isArray(j2.data)? j2.data : ann.value }catch{ ann.value=[] }

 }

}

async function postAnn(){

 if(!form.value.judul.trim() || !form.value.isi.trim()){

 annToast.value='Lengkapi Judul & Isi dulu'

 annToastOk.value=false

 setTimeout(()=> annToast.value='', 1800)

 return

 }

 try{

 await api('/announcements',{method:'POST', body: form.value})

 form.value={judul:'', isi:''}

 const j=await api('/announcements'); ann.value=Array.isArray(j.data)? j.data : ann.value

 annToast.value='Pengumuman diposting'

 annToastOk.value=true

 }catch(e){

 annToast.value='Gagal: '+(e?.error?.message||e?.message||'error')

 annToastOk.value=false

 }

 setTimeout(()=> annToast.value='', 1800)

}

async function exportCsv(){

 exporting.value=true

 try{

 const res = await fetch('/api/laporan/rekap?dashboard=1&format=csv', { credentials:'include', headers:{'X-CSRF-Token': auth.csrf || ''} })

 if(!res.ok) throw new Error('Export gagal '+res.status)

 const blob = await res.blob()

 const url = URL.createObjectURL(blob)

 const a = document.createElement('a')

 a.href=url; a.download='rekap-dashboard-'+new Date().toISOString().slice(0,10)+'.csv'

 document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url)

 }catch(e){

 error.value = e.message

 }finally{ exporting.value=false }

}



watch(()=> auth.user?.role, ()=> { activeTab.value='list'; statTab.value='ringkas'; syncMobileTab(); load() })

watch(dashTabs, syncMobileTab)

onMounted(()=>{ syncMobileTab(); load() })

</script>



<style scoped>

/* Mindora flat tool - identical to /ekskul /events /kalender /admin/users /saya /verify */

.kat-page{

 --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3;

 --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85;

 background:var(--m-bg);color:var(--m-ink);

 margin:-24px calc(50% - 50vw) 0;padding:20px max(16px,calc(50vw - 680px)) 24px;

}

.kat-inner{max-width:1360px;margin:0 auto}

.mono{font-family:'Satoshi',system-ui,sans-serif}

.kat-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:2px 0 10px;flex-wrap:wrap}

.kat-title{margin:0;font-family:'Satoshi',system-ui,sans-serif;font-size:20px;font-weight:800;letter-spacing:-.01em;line-height:1.15}

.kat-title-hi{font-weight:600;color:var(--m-muted);font-size:16px;letter-spacing:-.01em}

.kat-sub{margin:2px 0 0;font-size:11.5px;color:var(--m-muted);font-family:'Satoshi',system-ui,sans-serif;line-height:1.5}

.kat-head-r{display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}

.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-family:'Satoshi',system-ui,sans-serif}

.kat-link:hover{border-color:#d4d4d8}

.kat-link.strong{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.kat-link.strong:hover{background:#1a2a33;border-color:#1a2a33}

.kat-link:focus-visible,.dash-tab:focus-visible,.dash-m-tab:focus-visible{outline:2px solid var(--m-green);outline-offset:2px}

.kat-link .kat-link-arrow{color:var(--m-muted);font-weight:700}

.kat-link.strong .kat-link-arrow{color:rgba(255,255,255,.7)}

.kat-link.small{padding:7px 12px;font-size:12.5px}

.kat-link-n{background:rgba(255,255,255,.2);padding:1px 7px;border-radius:999px;font-size:11px;margin-left:4px}

.kat-strip{margin:0 0 12px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}

.kat-strip.warn{border-left-color:#f59e0b;background:#fffbeb}

.kat-strip a{color:var(--m-cta);font-weight:700}

.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}



/* stats - flex column to prevent angka+desc menempel (fix mobile berantakan) */

.kat-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}

.kat-stats-3{grid-template-columns:repeat(3,1fr)}

.kat-stat{background:#fff;border:1px solid var(--m-line);border-radius:14px;padding:12px 14px;position:relative;text-align:left;display:flex;flex-direction:column;align-items:flex-start;gap:2px;min-width:0;overflow:hidden}

.kat-stat-click{cursor:pointer;width:100%}

.kat-stat-click:hover{border-color:#c9cfcb}

.kat-stat.stat-warn{background:#fffbeb;border-color:#fde68a}

.kat-stat-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);line-height:1.2}

.kat-stat-value{font-size:22px;font-weight:800;letter-spacing:-.02em;line-height:1.05;display:block;white-space:nowrap}

.kat-stat-unit{font-size:14px;font-weight:600;color:var(--m-muted);margin-left:2px}

.kat-stat-desc{font-size:11px;color:var(--m-muted);line-height:1.35;display:block;white-space:normal;word-break:break-word}

.kat-stat-link{font-size:11px;font-weight:700;color:var(--m-cta);margin-top:4px;display:inline-block;text-decoration:none;line-height:1.3}

.kat-stat-link:hover{text-decoration:underline}



/* quick bar */
/* stat tabs: Ringkasan | Analisis */
.dash-statwrap{margin-bottom:12px}
.dash-tabs{display:inline-flex;gap:4px;background:#fff;border:1px solid var(--m-line);border-radius:999px;padding:3px;margin-bottom:10px}
.dash-tab{border:0;background:transparent;padding:7px 16px;border-radius:999px;font-size:12.5px;font-weight:700;color:var(--m-muted);cursor:pointer;line-height:1;font-family:'Satoshi',system-ui,sans-serif}
.dash-tab.active{background:var(--m-ink);color:#fff}
.dash-statwrap .kat-stats{margin-bottom:0}
/* analisis charts: full-width Top5 + donut/radial 2 kolom (compact mobile) */
.dash-charts{display:grid;gap:10px;grid-template-columns:repeat(2,1fr)}
.dash-chart-card{background:#fff;border:1px solid var(--m-line);border-radius:14px;padding:14px;min-width:0;overflow:hidden}
.dash-chart-wide{grid-column:1/-1}
.dash-chart-title{margin:0 0 8px;font-size:13px;font-weight:800;letter-spacing:-.01em;font-family:'Satoshi',system-ui,sans-serif}
.dash-chart-sub{font-weight:400;color:var(--m-muted);font-size:11px}
@media(min-width:1024px){.dash-charts{grid-template-columns:repeat(3,1fr);gap:12px}.dash-chart-wide{grid-column:auto}}
@media(max-width:640px){.dash-chart-card{padding:10px}.dash-chart-title{font-size:12px}}

.dash-quick{margin:0 0 12px;padding:10px 12px;background:#fff;border:1px solid var(--m-line);border-radius:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}

.dash-quick-kicker{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);white-space:nowrap}

.dash-quick-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}



/* grid 1.45fr/.85fr - stack <900 */

.dash-grid{display:grid;grid-template-columns:1.45fr .85fr;gap:12px;margin-bottom:12px;align-items:start}

.dash-grid-ann{grid-template-columns:1fr 1fr}

@media(max-width:900px){ .dash-grid{grid-template-columns:1fr} }



/* mobile tabs - hidden desktop, flex scroll di mobile */

.dash-m-tabs{display:none;gap:8px;margin:0 0 12px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}

.dash-m-tabs::-webkit-scrollbar{display:none}

.dash-m-tab{flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12.5px;font-weight:700;color:var(--m-ink);cursor:pointer;white-space:nowrap;line-height:1}

.dash-m-tab.active{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.dash-m-tab-n{min-width:18px;height:18px;padding:0 5px;border-radius:999px;display:grid;place-items:center;font-size:10.5px;font-weight:800;background:rgba(0,0,0,.08)}

.dash-m-tab.active .dash-m-tab-n{background:rgba(255,255,255,.22);color:#fff}

@media(max-width:900px){

 .dash-m-tabs{display:flex}

 .dash-hidden-mobile{display:none !important}

}



/* siswa: 2-kolom mason fix - hapus gap kosong Ekskul(2 item) vs Jadwal */

.dash-siswa-layout{display:grid;grid-template-columns:1.45fr .85fr;gap:12px;align-items:start;margin-bottom:12px}

.dash-siswa-col{display:flex;flex-direction:column;gap:12px;min-width:0}

.dash-siswa-col > .dash-card{width:100%}

/* tablet: sedikit lebih seimbang, tetap 2 kolom */

@media(max-width:1024px) and (min-width:901px){

 .dash-siswa-layout{grid-template-columns:1.35fr .9fr}

}

/* tablet & desktop kecil: stack 1 kolom, urutan tetap Ekskul -> Jadwal -> Pengumuman -> Pintasan */

@media(max-width:900px){

 .dash-siswa-layout{grid-template-columns:1fr}

 .dash-siswa-col{display:contents}

 .dash-siswa-col-left > .dash-card-ekskul{order:1}

 .dash-siswa-col-right > .dash-card-jadwal{order:2}

 .dash-siswa-col-left > .dash-card-ann{order:3}

 .dash-siswa-col-right > .dash-card-pintas{order:4}

}



/* dash-card */

.dash-card{padding:16px}

.dash-card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}

.dash-card-title{margin:0;font-size:13px;font-weight:800;letter-spacing:-.01em}

.dash-card-link{font-size:11.5px;color:var(--m-cta);font-weight:700;text-decoration:none}

.dash-card-link:hover{text-decoration:underline}

.dash-card-foot{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--m-line)}

.dash-link{color:var(--m-cta);font-weight:700;text-decoration:none}

.dash-link:hover{text-decoration:underline}



/* rows */

.dash-rows{list-style:none;margin:0;padding:0;display:flex;flex-direction:column}

.dash-rows-compact{gap:0}

.dash-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f4f4f5;min-width:0}

.dash-row:last-child{border-bottom:none}

.dash-ava{width:32px;height:32px;border-radius:999px;display:grid;place-items:center;font-weight:800;font-size:11px;flex-shrink:0}

.dash-grow{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}

.dash-grow b{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.dash-grow i{font-style:normal;font-size:11px;color:var(--m-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.dash-pill{display:inline-flex;align-items:center;font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-muted);white-space:nowrap;flex-shrink:0}

.dash-pill.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.dash-pill.warn{background:#fffbeb;color:#92400e;border-color:#fde68a}

.dash-pill.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}

.dash-pill.event{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}

.dash-pill.tambahan{background:#fffbeb;color:#92400e;border-color:#fde68a}

.dash-pill.rutin{background:#f0fdf6;color:#065f46;border-color:#a7f3d0}

.dash-pill.neutral{background:#fafaf9;color:var(--m-muted)}

.dash-empty{font-size:12.5px;color:var(--m-muted);background:#fafaf9;border:1px dashed var(--m-line);border-radius:10px;padding:12px;text-align:center;margin:8px 0;line-height:1.5}

.dash-foot-hint{font-size:11px;color:var(--m-muted);margin:8px 0 0;line-height:1.5}

.dash-foot-hint.cap{text-transform:capitalize}

.dash-warn{font-size:11px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:8px 10px;border-radius:8px;margin-top:8px}

.dash-todaybox{border-top:1px solid var(--m-line);margin-top:8px;padding-top:8px}



/* cal mini - same as kalender but compact */

.dash-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin:8px 0 6px;background:#fff}

.dash-wd{font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);padding:4px 0}

.dash-day{width:28px;height:28px;margin:0 auto;display:grid;place-items:center;font-size:11px;font-weight:600;position:relative;border-radius:999px}

.dash-day.today{background:var(--m-ink);color:#fff;font-weight:800}

.dash-dot{position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:var(--m-cta)}

.dash-day.today .dash-dot{background:#fff}



/* ann list */

.dash-annlist{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}

.dash-ann{border:1px solid var(--m-line);border-radius:12px;padding:12px 14px;background:#fff;min-width:0}

.dash-ann b{font-size:13px;font-weight:700;display:block;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.dash-ann p{font-size:12.5px;color:var(--m-muted);line-height:1.55;margin:0 0 6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

.dash-ann i{font-style:normal;font-size:11px;color:#a8a29e}



/* pintas rows */

.dash-pintas{display:flex;flex-direction:column;gap:8px}

.dash-pintas-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--m-line);border-radius:12px;background:#fff;text-decoration:none;color:var(--m-ink)}

.dash-pintas-row:hover{border-color:#d4d4d8;background:#fafaf9}

.dash-pintas-ico{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:var(--m-bg);border:1px solid var(--m-line);flex-shrink:0;font-size:12px}

.dash-pintas-row b{font-size:13px;font-weight:700;display:block}

.dash-pintas-row i{font-size:11px;color:var(--m-muted);display:block;margin-top:2px}

.dash-pintas-row span:last-child{margin-left:auto;color:#a8a29e}



/* form */

.dash-fld{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}

.dash-fld span{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.dash-input{border:1px solid var(--m-line);border-radius:12px;padding:10px 12px;font-size:13px;font-family:inherit;color:var(--m-ink);background:#fff;width:100%;outline:none}

.dash-input:focus{border-color:var(--m-ink);box-shadow:0 0 0 3px rgba(47,62,70,.08)}

.dash-cta{padding:10px 18px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:13px;font-weight:700;border:1px solid var(--m-ink);cursor:pointer;display:inline-flex;align-items:center;gap:8px}

.dash-cta:hover{background:#1a2a33}

.dash-toast{font-size:12px;margin-top:8px;padding:8px 10px;border-radius:10px;border:1px solid var(--m-line)}

.dash-toast.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.dash-toast.warn{background:#fef2f2;color:#991b1b;border-color:#fecaca}

.skel-line{height:12px;background:#f4f4f5;border-radius:6px;margin-bottom:6px}

.skel-line.w40{width:40%}.skel-line.w70{width:70%}

.kat-foot{margin-top:12px;padding-top:12px;border-top:1px solid var(--m-line);display:flex;gap:8px;flex-wrap:wrap;justify-content:center;font-size:11px;color:var(--m-muted)}

.kat-foot a{color:var(--m-muted);text-decoration:underline;text-underline-offset:2px}

.kat-foot a:hover{color:var(--m-ink)}

.kat-cta{padding:10px 18px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:13px;font-weight:700;border:1px solid var(--m-ink);cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none}

.kat-cta:hover{background:#1a2a33}



@media(max-width:640px){

 .kat-page{padding:16px 14px 20px}

 .kat-head{flex-direction:column;align-items:flex-start}

 .kat-title{font-size:18px}

 .kat-title-hi{font-size:14px}

 .kat-stats{grid-template-columns:1fr 1fr;gap:8px}

 .kat-stats-3{grid-template-columns:1fr 1fr;gap:8px}

 .kat-stat{padding:11px 12px;gap:3px}

 .kat-stat-value{font-size:20px}

 .kat-stat-desc{font-size:10px;line-height:1.35}

 .kat-stat-link{font-size:10.5px;margin-top:2px}

 .dash-quick{flex-direction:column;align-items:stretch}

}

@media(max-width:480px){

 .kat-stats{grid-template-columns:1fr 1fr;gap:8px}

 .kat-stats-3{grid-template-columns:1fr}

 .kat-stat{padding:10px 11px}

 .dash-card{padding:12px}

}

</style>

