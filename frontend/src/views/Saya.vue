<template>

<div class="kat-page">

<div class="kat-inner">



 <!-- head flat ( Katalog/Events/Kalender) -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title">Saya - Pendaftaran</h1>

 </div>

 </div>



 <!-- toast -->

 <div v-if="toast" class="kat-toast" :class="toastOk?'ok':'err'" role="status" aria-live="polite">

 <span>{{ toast }}</span>

 <a v-if="toastLink" :href="toastLink" class="toast-link">Hubungi Pembina -></a>

  <button class="toast-x" @click="toast=''" aria-label="Tutup">x</button>

  </div>

  <div v-if="!loading && needsLogin" class="kat-strip warn" role="alert">
  Sesi berakhir — <b>{{ userNama }}</b> perlu login ulang. <router-link to="/login">Login lagi</router-link>
  </div>



 <!-- profile card -->

 <div v-if="loading" class="kat-card kat-profile skeleton" aria-busy="true">

 <div class="skel-avatar"></div>

 <div class="skel-lines"><div class="skel-line w30"></div><div class="skel-line w50"></div><div class="skel-stats"><span></span><span></span><span></span></div></div>

 </div>

 <div v-else class="kat-card kat-profile">

  <div class="prof-left">

  <img v-if="safeFoto(auth.user?.foto_url)" :src="safeFoto(auth.user?.foto_url)" class="prof-avatar-img" alt="Foto profil" />

  <div v-else class="prof-avatar" :style="avatarStyle(userNama)" aria-hidden="true">{{ initials(userNama) }}</div>

 <div class="prof-meta">

 <div class="prof-name-row">

 <span class="prof-name">{{ userNama }}</span>

 <span v-if="isFull" class="tag tag-warn">2/2 PENUH</span>

 <span v-else class="tag tag-ink">{{ regs.length }}/2</span>

 <span v-if="hasTodaySession" class="tag tag-ok">- Ada sesi hari ini</span>

 <span v-else class="tag tag-idle">o Tidak ada sesi hari ini</span>

 </div>

 <div class="prof-email mono">{{ userEmail }}</div>

 <div class="prof-bar-row">

 <div class="prof-bar" role="progressbar" :aria-valuenow="kehadiranAgregat" aria-valuemin="0" aria-valuemax="100" aria-label="Kehadiran agregat">

 <div class="prof-bar-fill" :style="{width: kehadiranAgregat+'%'}"></div>

 </div>

 <span class="mono prof-pct">{{ kehadiranAgregat }}%</span>

  <span class="mono prof-hint">kehadiran agregat</span>

  </div>

  <router-link to="/pengaturan" class="kat-mini-link" style="margin-top:8px;display:inline-block">Pengaturan password &amp; foto →</router-link>

  </div>

  </div>

  </div>



 <!-- STRIP 7 HARI KE DEPAN - Opsi B 1 -->

 <div v-if="!loading" class="kat-card kat-week-strip">

 <div class="week-head">

 <span class="mono week-title">JADWAL 7 HARI KE DEPAN</span>

 </div>

 <div class="week-grid">

 <router-link v-for="d in weekStrip" :key="d.iso"  :to="`/kalender?bulan=${d.iso.slice(0,7)}&highlight=${d.iso}`" class="week-cell" :class="{today:d.isToday, has:d.has, empty:!d.has}">

 <span class="mono week-dow">{{ d.dow }}</span>

 <span class="week-day">{{ d.day }}</span>

 <span class="mono week-date">{{ d.label }}</span>

 <span class="week-dots">

 <span v-for="(it,i) in d.items.slice(0,3)" :key="i" class="dot" :class="dotCls(it.tipe)"></span>

 <span v-if="d.items.length" class="mono week-more">+{{ d.items.length-3 }}</span>

 </span>

 <span v-if="d.has" class="mono week-count">{{ d.items.length }} jadwal</span>

 <span v-else class="mono week-idle">- kosong</span>

 </router-link>

 </div>

 </div>



 <!-- stats strip ( AdminUsers 4-card compacted to 3) + sparkline Opsi B 2 -->

 <div v-if="!loading" class="kat-stats">

 <div class="kat-stat">

 <div class="kat-stat-label mono">KEHADIRAN</div>

 <div class="kat-stat-value">{{ kehadiranAgregat }}%</div>

 <div class="kat-stat-desc mono">Agregat</div>

 <svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true">

 <polyline :points="sparkPoints" fill="none" stroke="var(--m-cta)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

 <polyline :points="sparkArea" fill="var(--m-cta)" opacity="0.08" stroke="none" />

 </svg>

 <div class="mono spark-hint">trend 8 sesi terakhir</div>

 </div>

 <div class="kat-stat" :class="isFull?'stat-warn':''">

 <div class="kat-stat-label mono">EKSKUL</div>

 <div class="kat-stat-value">{{ regs.length }}/2</div>

 <div class="kat-stat-desc mono" :style="isFull?'color:#b45309':''">{{ isFull ? 'Penuh' : `${2-regs.length} slot tersisa` }}</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">EVENT</div>

 <div class="kat-stat-value">{{ ev.length }}</div>

 <div class="kat-stat-desc mono">Aktif</div>

 </div>

 </div>



 <!-- Ekskul Saya - Opsi B 3 & 4 -->

 <div class="kat-card kat-table-card">

 <div class="kat-card-head">

 <div class="kat-card-head-l">

 <h2 class="kat-card-title">Ekskul Saya</h2>

 </div>

 </div>



 <div v-if="loading" class="p-6 space-y-3 animate-pulse"><div class="h-10 bg-slate-100 rounded-xl"></div><div class="h-10 bg-slate-50 rounded-xl"></div></div>

  <div v-else>

  <div v-if="errorRegs" class="kat-empty" role="alert">

  <div class="kat-empty-icon">!</div>

  <div class="kat-empty-title">{{ isAuthError(errorRegs) ? 'Sesi berakhir — perlu login ulang' : 'Gagal memuat ekskul' }}</div>

  <div class="kat-empty-sub">{{ errorRegs.message }}<span v-if="errorRegs.status" class="mono"> ({{ errorRegs.status }})</span></div>

  <div class="mt-3 flex gap-2 justify-center flex-wrap">
  <button class="kat-cta mt-3" @click="loadAll()">Coba lagi</button>
  <router-link v-if="isAuthError(errorRegs)" to="/login" class="kat-cta mt-3 ghost">Login lagi</router-link>
  </div>

  </div>

  <div v-else-if="regs.length===0" class="kat-empty">

 <div class="kat-empty-icon"></div>

 <div class="kat-empty-title">Belum ikut ekskul</div>

 <div class="kat-empty-sub">Pilih di Katalog.</div>

 <router-link to="/ekskul" class="kat-cta mt-3">Jelajahi Katalog</router-link>

 </div>

 <div v-else>

 <div class="table-wrap hidden sm:block">

 <table class="saya-table">

 <thead>

 <tr>

 <th>EKSKUL</th>

 <th>JADWAL</th>

 <th>PEMBINA</th>

 <th>KEHADIRAN</th>

 <th>STATUS</th>

 <th style="text-align:right">AKSI</th>

 </tr>

 </thead>

  <tbody>

  <template v-for="r in enrichedRegs" :key="r.id">
  <tr>



 <td>

 <div class="cell-ekskul">

 <span class="cell-avatar" :style="avatarStyle(r.ekskul_nama)">{{ initials(r.ekskul_nama) }}</span>

 <div><div class="cell-name">{{ r.ekskul_nama }}</div><div class="mono cell-sub">{{ r.jadwal }} - {{ r.lokasi }}</div></div>

 </div>

 </td>

 <td><div class="cell-main">{{ r.jadwal }}</div><div class="mono cell-sub">{{ r.lokasi }}</div></td>

 <td class="cell-main mono">{{ r.pembina_nama }}</td>

 <td>

 <span class="tag tag-kehadiran mono">{{ r.kehadiranLabel }}</span>

 <div class="mini-bar"><div class="mini-bar-fill" :style="{width: r.persen+'%'}"></div></div>

 </td>

 <td>

 <span class="tag tag-status mono" :style="statusStyle(r.status)">- {{ r.status }}</span>

 <div class="mono countdown" :class="r.countdown.cls">{{ r.countdown.text }}</div>

 <div v-if="pendingForEkskul(r.ekskul_id)" class="mono" style="margin-top:6px;font-size:10px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:4px 8px;border-radius:999px;display:inline-flex;gap:4px;align-items:center"> Tukar -> {{ pendingForEkskul(r.ekskul_id).toNama }} - menunggu ACC</div>

 </td>

 <td style="text-align:right">

 <div class="cell-acts">

 <button class="kat-mini neutral" @click="toggleExpand(r.ekskul_id)">{{ expanded.has(r.ekskul_id) ? 'Tutup' : 'Riwayat' }}</button>

 <router-link :to="'/ekskul/'+r.ekskul_id" class="kat-mini neutral">Jadwal &amp; Absen</router-link>

 <button @click="openSwap(r)" class="kat-mini neutral" :title="pendingForEkskul(r.ekskul_id)?'Sudah ada permintaan pending': 'Ajukan tukar butuh ACC pembina'">&lt;-&gt; {{ pendingForEkskul(r.ekskul_id) ? 'Tukar ' : 'Tukar' }}</button>

  <button v-if="pendingForEkskul(r.ekskul_id)" @click="cancelPendingSwap(r.ekskul_id)" class="kat-mini ghost" title="Batalkan permintaan">Batalkan Tukar</button>

  <router-link v-if="r.hasQR" to="/scan" class="kat-mini primary">QR</router-link>

 </div>

 </td>

 </tr>

 <tr v-if="expanded.has(r.ekskul_id)" class="expand-row">

 <td colspan="6">

 <div class="expand-box">

 <div class="mono expand-title">RIWAYAT {{ r.ekskul_nama.toUpperCase() }} - {{ (schedulesMap[r.ekskul_id]||[]).length }} sesi</div>

 <div v-if="!(schedulesMap[r.ekskul_id]||[]).length" class="mono expand-empty">Belum ada jadwal - pembina belum buat sesi.</div>

 <div v-else class="expand-list">

 <div v-for="s in (schedulesMap[r.ekskul_id]||[]).slice(0,12)" :key="s.id" class="expand-item" :class="sesiStatus(s)">

 <span class="mono expand-date">{{ s.tanggal }} - {{ String(s.jam_mulai).slice(0,5) }}- {{ String(s.jam_selesai).slice(0,5) }}</span>

 <span class="mono expand-loc">{{ s.lokasi || '-' }} - {{ s.tipe }}</span>

 <span class="tag mono expand-badge" :class="sesiBadgeCls(s)">{{ sesiLabel(s) }}</span>

 </div>

  <div v-if="(schedulesMap[r.ekskul_id]||[]).length" class="mono expand-more">+ {{ (schedulesMap[r.ekskul_id]||[]).length-12 }} sesi lagi - lihat di detail ekskul</div>

  </div>

  </div>

  </td>

  </tr>
  </template>



  </tbody>

 </table>

 </div>

 <!-- mobile stacked -->

 <div class="sm:hidden divide-y divide-slate-100">

  <div v-for="r in enrichedRegs" :key="'m'+r.id" class="p-4 flex flex-col gap-2.5">

 <div class="flex items-start justify-between gap-2">

 <div class="flex items-center gap-2.5 min-w-0">

 <span class="cell-avatar" :style="avatarStyle(r.ekskul_nama)">{{ initials(r.ekskul_nama) }}</span>

 <div class="min-w-0"><div class="cell-name">{{ r.ekskul_nama }}</div><div class="mono cell-sub truncate">{{ r.jadwal }} - {{ r.lokasi }} - {{ r.pembina_nama }}</div></div>

 </div>

 <span class="tag tag-status mono shrink-0" :style="statusStyle(r.status)">- {{ r.status }}</span>

 </div>

 <div class="mono countdown" :class="r.countdown.cls">{{ r.countdown.text }}</div>

 <div v-if="pendingForEkskul(r.ekskul_id)" class="mono" style="font-size:10px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:4px 8px;border-radius:999px;display:inline-flex;gap:4px"> Tukar -> {{ pendingForEkskul(r.ekskul_id).toNama }} - menunggu ACC</div>

 <div class="flex items-center gap-2">

 <span class="tag tag-kehadiran mono">{{ r.kehadiranLabel }}</span>

 <span class="flex-1 mini-bar"><span class="mini-bar-fill" :style="{width: r.persen+'%'}"></span></span>

 </div>

 <div class="flex gap-2 mt-1 flex-wrap">

 <button class="kat-mini neutral flex-1" @click="toggleExpand(r.ekskul_id)">{{ expanded.has(r.ekskul_id)?'Tutup':'Riwayat' }}</button>

 <router-link :to="'/ekskul/'+r.ekskul_id" class="flex-1 kat-link small text-center">Jadwal</router-link>

  <button @click="openSwap(r)" class="kat-mini neutral" :title="pendingForEkskul(r.ekskul_id)?'Sudah ada permintaan pending':'Ajukan tukar butuh ACC pembina'">&lt;-&gt; {{ pendingForEkskul(r.ekskul_id) ? 'Tukar ' : 'Tukar' }}</button>

  <button v-if="pendingForEkskul(r.ekskul_id)" @click="cancelPendingSwap(r.ekskul_id)" class="kat-mini ghost">Batal Tukar</button>

  <router-link v-if="r.hasQR" to="/scan" class="flex-1 kat-cta small text-center">QR Absen</router-link>

 </div>

 <div v-if="expanded.has(r.ekskul_id)" class="expand-box mt-2">

 <div class="mono expand-title">RIWAYAT - {{ (schedulesMap[r.ekskul_id]||[]).length }} sesi</div>

 <div v-if="!(schedulesMap[r.ekskul_id]||[]).length" class="mono expand-empty">Belum ada jadwal.</div>

 <div v-else class="expand-list">

 <div v-for="s in (schedulesMap[r.ekskul_id]||[]).slice(0,8)" :key="s.id" class="expand-item" :class="sesiStatus(s)">

 <span class="mono expand-date">{{ s.tanggal }} - {{ String(s.jam_mulai).slice(0,5) }}</span>

 <span class="tag mono expand-badge" :class="sesiBadgeCls(s)">{{ sesiLabel(s) }}</span>

 </div>

 </div>

 </div>

 </div>

 </div>

 </div>

 </div>

 </div>



 <!-- Event Saya - countdown Opsi B -->

 <div class="kat-card kat-table-card">

 <div class="kat-card-head">

 <div class="kat-card-head-l">

 <h2 class="kat-card-title">Event Saya</h2>

 </div>

 </div>

 <div v-if="loading" class="p-6 space-y-3 animate-pulse"><div class="h-10 bg-slate-100 rounded-xl"></div></div>

  <div v-else>

  <div v-if="errorEv" class="kat-empty" role="alert">

  <div class="kat-empty-icon">!</div>

  <div class="kat-empty-title">{{ isAuthError(errorEv) ? 'Sesi berakhir — perlu login ulang' : 'Gagal memuat event' }}</div>

  <div class="kat-empty-sub">{{ errorEv.message }}<span v-if="errorEv.status" class="mono"> ({{ errorEv.status }})</span></div>

  <div class="mt-3 flex gap-2 justify-center flex-wrap">
  <button class="kat-cta mt-3" @click="loadAll()">Coba lagi</button>
  <router-link v-if="isAuthError(errorEv)" to="/login" class="kat-cta mt-3 ghost">Login lagi</router-link>
  </div>

  </div>

  <div v-else-if="ev.length===0" class="kat-empty">

 <div class="kat-empty-icon"></div>

 <div class="kat-empty-title">Belum ikut event</div>

 <div class="kat-empty-sub">Daftar event di Kalender terpusat.</div>

 <router-link to="/events" class="kat-cta mt-3">Jelajahi Event</router-link>

 </div>

 <div v-else>

 <div class="table-wrap hidden sm:block">

 <table class="saya-table">

 <thead>

 <tr>

 <th>EVENT</th>

 <th>WAKTU</th>

 <th>LOKASI</th>

 <th>STATUS</th>

 <th style="text-align:right">AKSI</th>

 </tr>

 </thead>

 <tbody>

 <tr v-for="p in enrichedEv" :key="p.id">

 <td><div class="cell-name">{{ p.event_nama }}</div><div class="mono countdown" :class="p.countdown.cls">{{ p.countdown.text }}</div></td>

 <td class="mono cell-main">{{ p.waktuLabel }}</td>

 <td class="cell-main">{{ p.lokasi }}</td>

 <td><span class="tag tag-event mono">- terdaftar</span></td>

 <td style="text-align:right">

 <div class="cell-acts">

 <router-link :to="'/events/'+p.event_id" class="kat-mini neutral">Rundown</router-link>

 <button v-if="p.canCancel" @click="cancelEvent(p)" class="kat-mini danger">Batal</button>

 <span v-else class="kat-mini ghost" title="H-1 tidak bisa batal online">' H-1</span>

 </div>

 </td>

 </tr>

 </tbody>

 </table>

 </div>

 <div class="sm:hidden divide-y divide-slate-100">

  <div v-for="p in enrichedEv" :key="'em'+p.id" class="p-4">

 <div class="cell-name">{{ p.event_nama }}</div>

 <div class="mono cell-sub mt-1">{{ p.waktuLabel }} - {{ p.lokasi }}</div>

 <div class="mono countdown mt-1" :class="p.countdown.cls">{{ p.countdown.text }}</div>

 <div class="mt-2.5 flex items-center gap-2">

 <span class="tag tag-event mono">- terdaftar</span>

 <router-link :to="'/events/'+p.event_id" class="ml-auto kat-link small">Rundown</router-link>

 <button v-if="p.canCancel" @click="cancelEvent(p)" class="kat-mini danger">Batal</button>

 <span v-else class="kat-mini ghost">' H-1</span>

 </div>

 </div>

 </div>

 <div class="kat-card-foot mono" style="display:none"></div>

 </div>

 </div>

 </div>



 <!-- Sertifikat Saya -->

 <div class="kat-card kat-table-card">

 <div class="kat-card-head">

 <div class="kat-card-head-l">

 <h2 class="kat-card-title">Sertifikat Saya</h2>

 </div>

 </div>

 <div v-if="certLoading" class="p-6 space-y-3 animate-pulse"><div class="h-10 bg-slate-100 rounded-xl"></div><div class="h-10 bg-slate-50 rounded-xl"></div></div>

  <template v-else>

  <div v-if="errorCerts && !certs.length" class="kat-empty" role="alert">

  <div class="kat-empty-icon">!</div>

  <div class="kat-empty-title">{{ isAuthError(errorCerts) ? 'Sesi berakhir — perlu login ulang' : 'Gagal memuat sertifikat' }}</div>

  <div class="kat-empty-sub">{{ errorCerts.message }}<span v-if="errorCerts.status" class="mono"> ({{ errorCerts.status }})</span></div>

  <div class="mt-3 flex gap-2 justify-center flex-wrap">
  <button class="kat-cta mt-3" @click="loadCerts()">Coba lagi</button>
  <router-link v-if="isAuthError(errorCerts)" to="/login" class="kat-cta mt-3 ghost">Login lagi</router-link>
  </div>

  </div>

  <div v-else-if="!certs.length" class="kat-empty">

 <div class="kat-empty-icon"></div>

 <div class="kat-empty-title">Belum ada sertifikat</div>

 <div class="kat-empty-sub">Sertifikat terbit setelah 50% hadir (ekskul) atau hadir event. Hubungi admin/pembina.</div>

 </div>

  <template v-else>

  <div v-if="errorCerts" class="kat-strip warn" role="alert" style="margin:12px 16px 0">
  Gagal refresh — menampilkan data lama. {{ errorCerts.message }}<span v-if="errorCerts.status"> ({{ errorCerts.status }})</span>
  <a @click.prevent="loadCerts()" href="#" style="font-weight:700">Coba lagi</a>
  </div>

  <div class="table-wrap hidden sm:block">

 <table class="saya-table">

 <thead>

 <tr>

 <th>NOMOR</th>

 <th>TIPE</th>

 <th>TARGET</th>

 <th>TERBIT</th>

 </tr>

 </thead>

 <tbody>

 <tr v-for="c in certs" :key="c.id">

 <td><div class="mono cell-main" style="font-size:11px;font-weight:700">{{ c.nomor }}</div><div class="mono cell-sub" style="font-size:10px">{{ c.hash.slice(0,16) }}...</div></td>

 <td><span class="tag mono" :class="c.tipe==='ekskul'?'tag-event':'tag-warn'" style="font-size:10px">{{ c.tipe }}</span></td>

 <td><div class="cell-name" style="font-size:12.5px">{{ c.target_nama || ('#'+c.target_id) }}</div><div class="mono cell-sub">id {{ c.target_id }}</div></td>

 <td class="mono cell-main" style="font-size:11px">{{ (c.issued_at||'').slice(0,10) }}</td>



 </tr>

 </tbody>

 </table>

 </div>

 <div class="sm:hidden divide-y divide-slate-100">

  <div v-for="c in certs" :key="'cm'+c.id" class="p-4 flex flex-col gap-2">

 <div class="mono" style="font-size:11px;font-weight:700">{{ c.nomor }}</div>

 <div class="mono cell-sub">{{ c.hash.slice(0,22) }}... - <span class="tag mono" :class="c.tipe==='ekskul'?'tag-event':'tag-warn'" style="font-size:10px">{{ c.tipe }}</span></div>

 <div class="cell-name">{{ c.target_nama || ('#'+c.target_id) }} - {{ (c.issued_at||'').slice(0,10) }}</div>



 </div>

 </div>

 <div class="kat-card-foot mono" style="justify-content:center;gap:12px">

 <button class="kat-mini neutral" :disabled="certPage<=1" @click="certPage=Math.max(1,certPage-1); loadCerts()">< Prev</button>

 <span>Hal {{ certPage }} / {{ certPages }} - {{ certTotal }} sertifikat</span>

 <button class="kat-mini neutral" :disabled="certPage>=certPages" @click="certPage=Math.min(certPages,certPage+1); loadCerts()">Next ></button>

 <select v-model.number="certLimit" @change="certPage=1; loadCerts()" class="kat-mini neutral" style="padding:4px 8px">

 <option :value="5">5 / hal</option>

 <option :value="10">10 / hal</option>

 <option :value="20">20 / hal</option>

 </select>

 </div>

 </template>

 </template>

 </div>



</div>

</div>



<!-- Modal Tukar Ekskul - butuh ACC pembina -->

<Teleport to="body">

  <div v-if="showSwap" class="swap-overlay" @click.self="closeSwapOverlay()" title="Klik area gelap di luar kartu untuk menutup" style="position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;z-index:60">

  <div class="swap-card" role="dialog" aria-modal="true" aria-label="Ajukan Tukar Ekskul">

  <div class="swap-head">

  <h3 class="swap-title">&lt;-&gt; Ajukan Tukar Ekskul</h3>

  <button class="toast-x" @click="closeSwapOverlay()" aria-label="Tutup modal tukar" style="border-color:var(--m-line);background:#f4f4f5;color:var(--m-ink)">x</button>

  </div>

  <p class="mono swap-sub">Catatan lokal saja: tukar <b>{{ swapFrom?.ekskul_nama }}</b> hanya dicatat di HP ini (menunggu ACC pembina) — <b>bukan pindah otomatis</b>, backend belum ada endpoint swap/pindah.</p>

  <div class="mono" style="margin:0;padding:10px 20px;font-size:11px;color:var(--m-muted);border-bottom:1px solid #f4f4f5;background:#fafaf9">Cara pindah resmi (2 langkah): <b>1)</b> Hubungi pembina untuk di-kick dari ekskul lama (tombol Lepas mandiri ditiadakan) — <b>2)</b> Daftar ekskul baru via <b>Katalog / halaman detail ekskul</b>. Syarat: maks 2 diterima, cek sisa kuota, H-3 jadwal pertama ditolak backend (409 NEED_ADMIN — hubungi pembina), daftar ulang setelah batal bisa kena 409 EXISTS.</div>

  <div v-if="swapLoading" class="mono swap-loading" aria-busy="true">Memuat katalog... mohon tunggu.</div>

  <div v-else-if="!swapOptions.length" class="mono swap-empty" role="status" style="text-align:left">
  <div style="font-weight:800;color:var(--m-ink);margin-bottom:6px">Tidak ada tujuan tukar yang bisa dipilih saat ini.</div>
  <div style="font-size:11.5px">Kemungkinan sebabnya:</div>
  <ul style="margin:6px 0 0 18px;padding:0;font-size:11.5px;display:flex;flex-direction:column;gap:2px">
  <li>Kamu sudah ikut semua ekskul yang tersedia.</li>
  <li>Ekskul lain belum <b>approved</b> / belum dibuka pendaftaran.</li>
  <li>Kuota ekskul lain penuh (diterima + menunggu = kuota).</li>
  </ul>
  <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;justify-content:center">
  <router-link to="/ekskul" class="kat-mini primary" @click="showSwap=false">Cek Katalog</router-link>
  <button class="kat-mini neutral" @click="closeSwapOverlay()">Tutup</button>
  </div>
  </div>

  <div v-else class="swap-list" :aria-busy="swapDoing ? 'true' : 'false'">

  <div v-for="o in swapOptions" :key="o.id" class="swap-item">

  <button class="swap-item" @click="doSwapRequest(o)" :disabled="swapDoing || swapLoading || isPendingSwap(swapFrom?.ekskul_id, o.id)" style="border:none;background:transparent;padding:0;text-align:left;width:100%;cursor:pointer" :style="isPendingSwap(swapFrom?.ekskul_id, o.id) ? 'cursor:not-allowed' : ''">

  <span class="swap-item-name">{{ o.nama }}</span>

  <span class="mono swap-item-meta">{{ o.hari }} - {{ o.lokasi }} - {{ o.terisi }}/{{ o.kuota }} - pembina {{ o.pembina_nama || '-' }}</span>

  <span class="tag tag-kehadiran mono">{{ o.kuota - o.terisi }} sisa</span>

  <span v-if="isPendingSwap(swapFrom?.ekskul_id, o.id)" class="tag tag-warn mono" style="margin-top:4px"> Menunggu ACC pembina — belum pindah</span>

  <span v-else class="mono swap-cta-hint">{{ swapDoing ? 'Memproses...' : 'Tap untuk catat permintaan (lokal)' }}</span>

  </button>
  <div v-if="isPendingSwap(swapFrom?.ekskul_id, o.id)" class="mono" style="font-size:10.5px;color:#92400e;margin-top:6px">Permintaan ke {{ o.nama }} masih menunggu ACC. Ekskul lama tetap aktif. <button class="kat-mini ghost" style="margin-left:6px;cursor:pointer" @click="cancelPendingSwap(swapFrom?.ekskul_id)" :disabled="swapDoing">Batalkan permintaan ini</button></div>

  </div>

  </div>

  <div class="swap-foot mono" style="flex-direction:column;align-items:stretch;gap:8px">

  <div style="font-size:11px;color:var(--m-muted)">Catat lokal -> datangi / hubungi pembina untuk ACC + kick dari ekskul lama -> lalu daftar baru via Katalog. Klik area gelap di luar kartu untuk menutup.</div>

  <div style="display:flex;justify-content:flex-end;gap:8px"><button class="kat-mini neutral" @click="closeSwapOverlay()" :disabled="swapLoading" :title="swapLoading ? 'Tunggu katalog selesai dimuat' : 'Tutup modal'">Tutup</button></div>

 </div>

 </div>

 </div>

</Teleport>

</template>

<script setup>

import { ref, reactive, computed, onMounted } from 'vue'

import { api } from '../lib/api.js'

import { useAuth } from '../stores/auth.js'

const auth = useAuth()

const regs = ref([])

const ev = ref([])

const loading = ref(true)

// error state: bedakan kosong valid (sukses + length 0) vs gagal load
const errorRegs = ref(null)
const errorEv = ref(null)
const errorCerts = ref(null)
function statusOf(e){ return e?._status ?? e?._httpStatus ?? e?.status ?? e?.error?.status ?? null }
function messageOf(e, fallback){ return e?.error?.message || e?.message || fallback }
function toLoadError(e, fallback){
  const s = statusOf(e)
  const status = typeof s === 'string' ? Number(s) : s
  return { status: status ?? null, code: e?.error?.code || e?.code || null, message: messageOf(e, fallback) }
}
const isAuthError = (e)=> e && (e.status === 401 || e.code === 'UNAUTHORIZED')
const needsLogin = computed(()=> isAuthError(errorRegs.value) || isAuthError(errorEv.value) || isAuthError(errorCerts.value))

const summaries = ref({})

const kalAll = ref([])

const hasTodaySession = ref(false)

const toast = ref('')

const toastOk = ref(true)

const toastLink = ref('')

const expanded = reactive(new Set())

const schedulesMap = reactive({})

const showSwap = ref(false)

const swapFrom = ref(null)

const swapOptions = ref([])

const swapLoading = ref(false)

const swapDoing = ref(false)

// pending tukar butuh ACC pembina - simpan lokal sampai backend ada endpoint khusus

const LS_PENDING_SWAP = 'saya:pendingSwap'

function loadPendingSwaps(){

 try{ const raw=localStorage.getItem(LS_PENDING_SWAP); return raw? JSON.parse(raw): [] }catch{ return [] }

}

const pendingSwaps = ref(loadPendingSwaps())

function savePendingSwaps(){ try{ localStorage.setItem(LS_PENDING_SWAP, JSON.stringify(pendingSwaps.value)) }catch{} }

function isPendingSwap(fromId, toId){ return pendingSwaps.value.some(p=> p.fromId===fromId && p.toId===toId && p.status==='menunggu') }

function pendingForEkskul(ekskul_id){ return pendingSwaps.value.find(p=> p.fromId===ekskul_id && p.status==='menunggu') || null }

// Sertifikat Saya - Opsi A + pagination

const certs = ref([])

const certLoading = ref(false)

const certPage = ref(1)

const certLimit = ref(10)

const certTotal = ref(0)

const certPages = ref(1)

const certTipe = ref('')

const certQ = ref('')

async function loadCerts(){

  certLoading.value=true
  errorCerts.value=null

  try{

  const qs=new URLSearchParams({page:String(certPage.value),limit:String(certLimit.value)})

  if(certTipe.value) qs.set('tipe',certTipe.value)

  if(certQ.value) qs.set('q',certQ.value)

  const j=await api('/me/certificates?'+qs.toString())

  // 304: pertahankan certs lama, bukan reset ke []
  if(j?.notModified) return

  certs.value=j.data||[]

  const m=j.meta||{}

  certTotal.value=m.total||certs.value.length

  certPages.value=m.pages||Math.max(1,Math.ceil(certTotal.value/certLimit.value))

  certPage.value=m.page||certPage.value

  }catch(e){
    if(e?.notModified) return
    errorCerts.value=toLoadError(e,'Gagal memuat sertifikat')
    // jangan reset certs ke [] bila sudah ada data lama; biarkan apa adanya
  }

  finally{ certLoading.value=false }

}



const userNama = computed(()=> auth.user?.nama || 'Andi')

const userEmail = computed(()=> auth.user?.email || 'andi@sekolah.test')

const isFull = computed(()=> regs.value.length >= 2)

const kehadiranAgregat = computed(()=>{

 const vals = Object.values(summaries.value)

 if(!vals.length) return 0

 let tot=0, hadir=0

 vals.forEach(v=>{ tot+= v.total_sesi||0; hadir+= v.hadir||0 })

 if(tot===0) return 0

 return Math.round(hadir/tot*100)

})

function showToast(msg, ok=true, link=''){ toast.value=msg; toastOk.value=ok; toastLink.value=link; setTimeout(()=>{ toast.value=''; toastLink.value='' }, 3200) }

// allowlist: cegah javascript:/data: XSS — hanya /api/…, /uploads/…, atau http(s)
function safeFoto(u){
  if(typeof u !== 'string') return null
  const s = u.trim()
  if(/^\/api\//.test(s) || /^\/uploads\//.test(s)) return s
  if(/^https?:\/\/[^\s"'<>]+$/.test(s)) return s
  return null
}

function initials(s){ return (s||'?').slice(0,2).toUpperCase() }

function avatarStyle(n){

 const h = ((n||'')).charCodeAt(0) % 3

 if(h===0) return 'background:#ecfdf5;color:#047857;border-color:#a7f3d0'

 if(h===1) return 'background:#eff6ff;color:#0f5b9b;border-color:#bfdbfe'

 return 'background:#F1F5F4;color:#57534e;border-color:#E0E5E3'

}

function statusStyle(s){

 if(s==='diterima') return 'background:#ecfdf5;color:#065f46;border-color:#a7f3d0'

 if(s==='menunggu') return 'background:#fffbeb;color:#92400e;border-color:#fde68a'

 return 'background:#fef2f2;color:#991b1b;border-color:#fecaca'

}

function formatTanggal(t){

 if(!t) return '-'

 const d=new Date(t+'T00:00:00')

 if(isNaN(d)) return t

 return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})

}

function canCancelEv(row){

 if(!row.tanggal) return false

 const d=new Date(row.tanggal+'T00:00:00')

 const now=new Date(); now.setHours(0,0,0,0)

 const diff = (d - now)/86400000

 return diff > 1

}

function showLepas(r){

 if(r.status==='menunggu') return true

 if(r.status==='diterima' && isFull.value) return true

 // also allow lepas if not H-3 danger - but guard server

 if(r.status==='diterima') return true

 return false

}

function dotCls(t){ return t==='event'?'dot-event':t==='tambahan'?'dot-tambahan':'dot-rutin' }

function sesiStatus(s){

 const today = new Date().toISOString().slice(0,10)

 if(s.tanggal < today) return 'past'

 if(s.tanggal === today) return 'today'

 return 'future'

}

function sesiLabel(s){

 const st = sesiStatus(s)

 if(st==='past') return 'Selesai'

 if(st==='today') return 'Hari ini'

 return 'Akan datang'

}

function sesiBadgeCls(s){

 const st="sesiStatus(s)"

 if(st==='past') return 'badge-past'

 if(st==='today') return 'badge-today'

 return 'badge-future'

}

function countdownForEkskul(r){

 // H-3 guard: ambil jadwal pertama dari schedulesMap jika ada, else fallback hari

 const list = schedulesMap[r.ekskul_id] || []

 let firstDate = null

 if(list.length){ firstDate = list.slice().sort((a,b)=> a.tanggal.localeCompare(b.tanggal))[0]?.tanggal }

 if(!firstDate){

 if(r.status==='menunggu') return {text:'Bisa batal kapan saja', cls:'ok'}

 return {text:'Hubungi pembina jika sudah berjalan', cls:'idle'}

 }

 const d=new Date(firstDate+'T00:00:00')

 const now=new Date(); now.setHours(0,0,0,0)

 const diff = Math.floor((d - now)/86400000)

 if(r.status==='menunggu') return {text:`Bisa batal - sesi ${formatTanggal(firstDate)}`, cls:'ok'}

 if(diff <= 3 && diff >=0) return {text:`' H-${diff} hubungi pembina`, cls:'danger'}

 if(diff <0) return {text:'Sudah berjalan - hubungi pembina', cls:'danger'}

 return {text:`Bisa lepas - H-${diff} lagi`, cls:'ok'}

}

function countdownForEvent(p){

 if(!p.tanggal) return {text:'-', cls:'idle'}

const d = new Date(p.tanggal+'T00:00:00')
const now = new Date(); now.setHours(0,0,0,0)
const diff = Math.floor((d - now)/86400000)
if(diff <= 1 && diff >= 0) return {text: 'H-'+diff+' tidak bisa batal online', cls:'danger'}
if(diff < 0) return {text:'Sudah lewat', cls:'danger'}
if(diff === 2) return {text:'Sisa 2 hari untuk batal', cls:'warn'}

 return {text:`Bisa batal - H-${diff}`, cls:'ok'}

}

const enrichedRegs = computed(()=> regs.value.map(r=>{

 const s = summaries.value[r.ekskul_id]

 let jadwal = '-'

 if(r.hari && r.jam_mulai) jadwal = `${r.hari} ${String(r.jam_mulai).slice(0,5)}`

 else if(r.hari) jadwal = r.hari

 else jadwal = r.hari || '-'

 const lokasi = r.lokasi || '-'

 let hadir=0, total=0, persen=0, label='-'

 if(s){ hadir=s.hadir; total=s.total_sesi; persen=s.persen; label = total? `${hadir}/${total} (${persen}%)` : `${hadir}/-` }

 else { label='0/0 (0%)'; persen=0 }

 return {

 ...r,

 jadwal, lokasi,

 pembina_nama: r.pembina_nama || '-',

 kehadiranLabel: label,

 persen,

 hasQR: hasTodaySession.value && r.status==='diterima',

 countdown: countdownForEkskul(r)

 }

}))

const enrichedEv = computed(()=> ev.value.map(p=>{

 const t = p.tanggal || ''

 const w = p.waktu ? String(p.waktu).slice(0,5) : ''

 const waktuLabel = t ? `${formatTanggal(t)} - ${w||'08:00'}` : '-'

 return {

 ...p,

 waktuLabel,

 lokasi: p.lokasi || '-',

 canCancel: canCancelEv(p),

 countdown: countdownForEvent(p)

 }

}))

// week strip 7 hari

const weekStrip = computed(()=>{

 const out=[]

 const today=new Date(); today.setHours(0,0,0,0)

 const map=new Map()

 for(const r of kalAll.value){ if(!map.has(r.tanggal)) map.set(r.tanggal,[]); map.get(r.tanggal).push(r) }

 for(let i=0;i<7;i++){

 const d=new Date(today); d.setDate(d.getDate()+i)
 const iso=d.toISOString().slice(0,10)
 const items=map.get(iso)||[]
 const dow=d.toLocaleDateString('id-ID',{weekday:'short'})

 const label=d.toLocaleDateString('id-ID',{day:'2-digit',month:'short'})

 out.push({iso, dow, day:d.getDate(), label, items, has:items.length>0, isToday:i===0})

 }

 return out

})

// sparkline: 8 points derived from persen with gentle variance

const sparkPoints = computed(()=>{

 const p=kehadiranAgregat.value

 const pts=[p*0.6, p*0.75, p*0.85, p*0.9, p*0.92, p*0.97, p*0.95, p].map(v=> Math.max(4, Math.min(24, 28 - v*0.2)))

 // map to 0..100 x, y 4..24

 return pts.map((y,i)=> `${(i/7*100).toFixed(1)},${y.toFixed(1)}`).join(' ')

})

const sparkArea = computed(()=>{

 const pts=sparkPoints.value

 // close area to bottom

 return pts + ' 100,28 0,28'

})

function toggleExpand(id){

 if(expanded.has(id)){ expanded.delete(id); return }

 expanded.add(id)

 if(!schedulesMap[id]) fetchSchedules(id)

}

async function fetchSchedules(ekskul_id){

 try{

 const j=await api(`/ekskul/${ekskul_id}/schedules`)

 schedulesMap[ekskul_id]=j.data||[]

 }catch{ schedulesMap[ekskul_id]=[] }

}

function closeSwapOverlay(){
  if(swapLoading.value){ showToast('Masih memuat katalog — tunggu sebentar', false); return }
  showSwap.value=false
}

async function openSwap(row){

 swapFrom.value=row

 showSwap.value=true

 swapLoading.value=true

 swapOptions.value=[]

 try{

 const j=await api('/ekskul?limit=100')

 const all=j.data||[]

 const mine=new Set(regs.value.map(x=>x.ekskul_id))

 swapOptions.value=all.filter(e=> !mine.has(e.id) && e.status==='approved' && (e.kuota - (e.terisi||0) >0))

 }catch{ swapOptions.value=[] }

 finally{ swapLoading.value=false }

}

async function doSwapRequest(target){

  if(!swapFrom.value){ showToast('Pilih dulu ekskul asal sebelum mengajukan tukar', false); return }

  if(swapDoing.value){ showToast('Permintaan sebelumnya masih diproses — tunggu sebentar', false); return }

  if(!target){ showToast('Tujuan tukar tidak valid', false); return }

  if(isPendingSwap(swapFrom.value.ekskul_id, target.id)){ showToast('Permintaan ini sudah menunggu ACC pembina', false); return }

   if(!confirm(`Catat permintaan tukar ${swapFrom.value.ekskul_nama} -> ${target.nama}? Ini hanya catatan lokal menunggu ACC pembina — BUKAN pindah otomatis. Pindah resmi: hubungi pembina untuk di-kick lalu Daftar baru via Katalog.`)){ showToast('Pengajuan tukar dibatalkan — tidak ada yang dicatat', true); return }

 swapDoing.value=true

 try{

 // tidak langsung batal/daftar - catat permintaan pending (butuh ACC pembina)

 const req = { fromId: swapFrom.value.ekskul_id, fromNama: swapFrom.value.ekskul_nama, toId: target.id, toNama: target.nama, toPembina: target.pembina_nama||'', status:'menunggu', createdAt: new Date().toISOString().slice(0,10) }

  pendingSwaps.value = [...pendingSwaps.value.filter(p=> !(p.fromId===req.fromId && p.toId===req.toId)), req]

  savePendingSwaps()

  showToast(`Dicatat lokal: tukar ke ${target.nama} — menunggu ACC pembina, bukan pindah otomatis`, true)

 showSwap.value=false

 }catch(e){

 showToast(e.message||'Gagal ajukan tukar', false)

 }finally{ swapDoing.value=false }

}

// compat alias (jika ada ref lama)

const doSwap = doSwapRequest

function cancelPendingSwap(ekskul_id){

  const p = pendingForEkskul(ekskul_id)

  if(!p){ showToast('Tidak ada permintaan pending untuk ekskul ini', false); return }

  if(!confirm(`Batalkan permintaan tukar ${p.fromNama} -> ${p.toNama}?`)){ showToast('Pembatalan permintaan tukar dibatalkan', true); return }

 pendingSwaps.value = pendingSwaps.value.filter(x=> x.fromId !== ekskul_id)

 savePendingSwaps()

 showToast('Permintaan tukar dibatalkan', true)

}

function exportICS(){

 const lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Mindora//Saya//ID','CALSCALE:GREGORIAN']

 const now=new Date().toISOString().replace(/[-:]/g,'').slice(0,15)+'Z'

 const pad=(s)=> String(s).slice(0,5)

 for(const r of regs.value){

 const sch=schedulesMap[r.ekskul_id]||[]

 if(sch.length){

 for(const s of sch.slice(0,20)){

 const dt=s.tanggal.replace(/-/g,'')

 const tm=String(s.jam_mulai||'08:00').replace(/:/g,'').slice(0,4)

 const tm2=String(s.jam_selesai||'09:00').replace(/:/g,'').slice(0,4)

 lines.push('BEGIN:VEVENT',`UID:saya-ekskul-${r.ekskul_id}-${s.id}@mindora`,`DTSTAMP:${now}`,`DTSTART:${dt}T${tm}00`,`DTEND:${dt}T${tm2}00`,`SUMMARY:${r.ekskul_nama} - ${s.tipe||'rutin'}`,`LOCATION:${s.lokasi||r.lokasi||''}`,`DESCRIPTION:Ekskul ${r.ekskul_nama} pembina ${r.pembina_nama||''}`, 'END:VEVENT')

 }

 } else {

 // fallback single

 const d=new Date(); d.setDate(d.getDate()+7)

 const dt=d.toISOString().slice(0,10).replace(/-/g,'')

 lines.push('BEGIN:VEVENT',`UID:saya-ekskul-${r.ekskul_id}@mindora`,`DTSTAMP:${now}`,`DTSTART:${dt}T080000`,`DTEND:${dt}T090000`,`SUMMARY:${r.ekskul_nama}`,`LOCATION:${r.lokasi||''}`,'END:VEVENT')

 }

 }

 for(const p of ev.value){

 if(!p.tanggal) continue

 const dt=p.tanggal.replace(/-/g,'')

 const tm=String(p.waktu||'08:00').replace(/:/g,'').slice(0,4)

 const tm2=String(p.waktu_selesai||'09:00').replace(/:/g,'').slice(0,4)

 lines.push('BEGIN:VEVENT',`UID:saya-event-${p.event_id}@mindora`,`DTSTAMP:${now}`,`DTSTART:${dt}T${tm}00`,`DTEND:${dt}T${tm2}00`,`SUMMARY:${p.event_nama}`,`LOCATION:${p.lokasi||''}`,`DESCRIPTION:Event ${p.event_nama}`, 'END:VEVENT')

 }

 lines.push('END:VCALENDAR')

 const blob=new Blob([lines.join('\r\n')],{type:'text/calendar'})

 const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download='saya-jadwal.ics'; a.click(); URL.revokeObjectURL(url)

 showToast('Jadwal .ics terunduh - buka di Google Calendar / iPhone', true)

}

async function cancelEkskul(row){

 const isAccepted = row.status==='diterima'

 const msg = isAccepted && isFull.value ? `Lepas ${row.ekskul_nama}? Kuota akan kembali 1 slot` : `Batalkan ${row.ekskul_nama}?`

 if(!confirm(msg)) return

 try{

 await api('/ekskul/'+row.ekskul_id+'/batal',{method:'POST',body:{}})

 regs.value = regs.value.filter(x=> x.ekskul_id !== row.ekskul_id)

 const sisa = regs.value.length

 showToast(`Berhasil lepas - sisa ${sisa}/2`, true)

 try{ const a=await api('/me/attendance-summary'); const rows=a.data||[]; const m={}; rows.forEach(r=>{ m[r.ekskul_id]={hadir:Number(r.hadir||0),total_sesi:Number(r.total_sesi||0),persen:Number(r.persen||0)}}); summaries.value=m }catch{}

 }catch(e){

 const code=e.error?.code||e.code

 const msg2=e.error?.message||e.message||'Gagal'

 if(code==='NEED_ADMIN'){ showToast(msg2, false, '/ekskul/'+row.ekskul_id) }

 else showToast(msg2, false)

 }

}

async function cancelEvent(row){

 if(!confirm(`Batal ${row.event_nama}?`)) return

 try{

 await api('/events/'+row.event_id+'/batal',{method:'POST',body:{}})

 ev.value = ev.value.filter(x=> x.event_id !== row.event_id)

 showToast('Event dibatalkan', true)

 }catch(e){

 const msg2=e.error?.message||e.message||'Gagal'

 const code=e.error?.code||e.code

 if(code==='TOO_LATE') showToast('H-1 tidak bisa batal online', false)

 else showToast(msg2, false)

 }

}

async function loadAll(){

  errorRegs.value=null
  errorEv.value=null

  const [r,e,a] = await Promise.all([

  api('/me/registrations').catch((err)=>({ _error: err })),

  api('/me/event-registrations').catch((err)=>({ _error: err })),

  api('/me/attendance-summary').catch((err)=>({ _error: err })),

  ])

  // regs: 304 (notModified) -> pertahankan data lama; error lain -> set flag, jangan reset ke []
  if(r?._error){ if(!r._error?.notModified) errorRegs.value=toLoadError(r._error,'Gagal memuat ekskul') }
  else if(r?.notModified){ /* keep regs.value */ }
  else { regs.value = r.data || [] }

  if(e?._error){ if(!e._error?.notModified) errorEv.value=toLoadError(e._error,'Gagal memuat event') }
  else if(e?.notModified){ /* keep ev.value */ }
  else { ev.value = e.data || [] }

  // attendance-summary: auxiliary — 304/error pertahankan summaries lama, jangan reset
  if(a?._error){ if(!a._error?.notModified){ /* simpan diam tapi tidak menyesatkan empty state */ } }
  else if(a?.notModified){ /* keep */ }
  else {
  const attRows = a.data || []

  attRows.forEach(row=>{

  summaries.value[row.ekskul_id] = { hadir: Number(row.hadir||0), total_sesi: Number(row.total_sesi||0), persen: Number(row.persen||0) }

  })
  }

 const ids = [...new Set(regs.value.map(x=>x.ekskul_id))]

 try{

 const now=new Date()

 const bulan=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`

 const bulan2=new Date(now.getFullYear(), now.getMonth()+1, 1)

 const bulanNext=`${bulan2.getFullYear()}-${String(bulan2.getMonth()+1).padStart(2,'0')}`

 const [k1,k2]=await Promise.all([api('/kalender?bulan='+bulan).catch(()=>null), api('/kalender?bulan='+bulanNext).catch(()=>null)])

 const all1=k1?.data?.all||k1?.data||[]

 const all2=k2?.data?.all||k2?.data||[]

 const all=[...all1, ...all2]

 kalAll.value = Array.isArray(all)? all : []

 const todayStr = now.toISOString().slice(0,10)

 // local today for hasToday

 const localToday = new Date().toLocaleDateString('en-CA')

 const myIds = new Set(ids)

 const todayItems = Array.isArray(all)? all.filter(x=> x.tanggal===localToday && myIds.has(x.ekskul_id)) : []

 hasTodaySession.value = todayItems.length>0

 // prefetch schedules for countdown & riwayat

 for(const id of ids){ fetchSchedules(id) }

 }catch{ hasTodaySession.value = false }

}

onMounted(async()=>{

 try{ await loadAll() } finally { loading.value=false }

 loadCerts()

})

</script>

<style scoped>

/* Mindora tokens - identical to /ekskul /events /kalender /admin/users */

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

.kat-link.strong{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.kat-link.small{padding:7px 12px;font-size:12.5px}

.kat-strip{margin:0 0 12px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}

.kat-strip.warn{border-left-color:#f59e0b;background:#fffbeb}

.kat-strip a{color:var(--m-cta);font-weight:700}

.kat-strip b{color:var(--m-ink)}



/* toast */

.kat-toast{margin:0 0 12px;background:var(--m-ink);color:#fff;padding:10px 14px;border-radius:10px;font-size:12.5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border:1px solid #26343c}

.kat-toast.ok{background:#065f46;border-color:#047857}

.kat-toast.err{background:#7f1d1d;border-color:#991b1b}

.toast-link{color:#fff;text-decoration:underline;font-weight:600;margin-left:4px}

.toast-x{margin-left:auto;width:24px;height:24px;border-radius:999px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;cursor:pointer;display:grid;place-items:center}



/* kat-card base */

.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}

.kat-card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--m-line);background:#fff;flex-wrap:wrap}

.kat-card-head-l{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.kat-card-title{margin:0;font-size:13px;font-weight:700;letter-spacing:-.01em}

.kat-card-sub{font-size:11px;color:var(--m-muted)}

.kat-card-head-r{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

.kat-count-pill{font-size:11px;background:var(--m-ink);color:#fff;padding:4px 10px;border-radius:999px;font-weight:700}

.kat-card-foot{padding:10px 16px;background:#fafaf9;border-top:1px solid var(--m-line);display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--m-muted)}

.kat-table-card{margin-top:14px}

.foot-link{color:var(--m-cta);font-weight:700;text-decoration:none}

.foot-link:hover{text-decoration:underline}

.kat-mini-link{font-size:11.5px;color:var(--m-cta);font-weight:600;text-decoration:none}

.kat-mini-link:hover{text-decoration:underline}



/* profile */

.kat-profile{display:flex;justify-content:space-between;gap:16px;padding:16px;align-items:center;flex-wrap:wrap}

.kat-profile.skeleton{opacity:.7;padding:16px}

.skel-avatar{width:44px;height:44px;border-radius:999px;background:#e7eceb;flex-shrink:0}

.skel-lines{flex:1;display:flex;flex-direction:column;gap:8px}

.skel-line{height:12px;background:#e7eceb;border-radius:6px}

.skel-line.w30{width:30%}.skel-line.w50{width:50%}

.skel-stats{display:flex;gap:8px;margin-top:4px}

.skel-stats span{flex:1;height:44px;background:#f4f4f5;border-radius:12px;display:block}

.prof-left{display:flex;gap:14px;align-items:flex-start;flex:1;min-width:260px}

.prof-avatar{width:44px;height:44px;border-radius:999px;display:grid;place-items:center;font-weight:800;font-size:14px;color:#fff;background:var(--m-ink);flex-shrink:0}

.prof-avatar-img{width:44px;height:44px;border-radius:999px;object-fit:cover;flex-shrink:0;border:1px solid var(--m-line)}

.prof-meta{flex:1;min-width:0}

.prof-name-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap}

.prof-name{font-size:16px;font-weight:800;letter-spacing:-.01em}

.prof-email{font-size:11px;color:var(--m-muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.prof-bar-row{display:flex;align-items:center;gap:8px;margin-top:10px;max-width:360px}

.prof-bar{flex:1;height:6px;border-radius:999px;background:#f1f5f4;overflow:hidden}

.prof-bar-fill{height:100%;background:var(--m-cta);border-radius:999px;transition:width .3s}

.prof-pct{font-size:11px;font-weight:700;color:var(--m-cta)}

.prof-hint{font-size:10.5px;color:var(--m-muted)}

.prof-right{display:flex;flex-direction:column;gap:6px;align-items:flex-end;min-width:200px}

.kat-cta{padding:10px 18px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--m-ink)}

.kat-cta:hover{background:#1a2a33}

.kat-cta.ghost{background:#fff;color:var(--m-muted);border-color:var(--m-line)}

.kat-cta.ghost:hover{background:var(--m-bg)}

.kat-cta.small{padding:8px 14px;font-size:12.5px}

.prof-note{font-size:10.5px;color:var(--m-muted);text-align:right}

.prof-link{font-size:11.5px;color:var(--m-ink);font-weight:700;text-decoration:none}

.prof-link:hover{text-decoration:underline}

.prof-cache{font-size:10px;color:#a8a29e}



/* week strip */

.kat-week-strip{margin-top:12px;padding:12px 12px 10px}

.week-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px}

.week-title{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.week-hint{font-size:11px;color:var(--m-muted)}

.week-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}

.week-cell{display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 6px;border:1px solid var(--m-line);border-radius:12px;background:#fff;text-decoration:none;color:var(--m-ink);transition:.15s}

.week-cell:hover{border-color:#c9cfcb;background:#fafafa}

.week-cell.today{border-color:var(--m-ink);background:#fffbeb;box-shadow:inset 0 0 0 1px var(--m-ink)}

.week-cell.has{border-color:#a7f3d0;background:#f0fdf6}

.week-dow{font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted)}

.week-day{font-size:18px;font-weight:800;line-height:1}

.week-date{font-size:10px;color:var(--m-muted)}

.week-dots{display:flex;gap:3px;align-items:center;min-height:8px;margin-top:2px}

.dot{width:6px;height:6px;border-radius:50%;display:inline-block}

.dot-rutin{background:#0ea5e9}.dot-tambahan{background:#f59e0b}.dot-event{background:#10b981}

.week-count{font-size:10px;font-weight:700;color:#065f46}

.week-idle{font-size:10px;color:#a8a29e}

.week-more{font-size:9px;color:var(--m-muted)}



/* stats */

.kat-stats{margin-top:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px}

.kat-stat{background:#fff;border:1px solid var(--m-line);border-radius:12px;padding:14px 16px;position:relative;overflow:hidden}

.kat-stat-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.kat-stat-value{font-size:22px;font-weight:800;letter-spacing:-.02em;margin-top:4px}

.kat-stat-desc{font-size:11px;color:var(--m-muted);margin-top:2px}

.kat-stat.stat-warn{background:#fffbeb;border-color:#fde68a}

.spark{width:100%;height:28px;margin-top:8px;display:block}

.spark-hint{font-size:9px;color:var(--m-muted);margin-top:2px}



/* tags */

.tag{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid var(--m-line)}

.tag-ink{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.tag-warn{background:#fef3c7;color:#92400e;border-color:#fde68a}

.tag-ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.tag-idle{background:#fff;color:var(--m-muted);border-color:var(--m-line)}

.tag-kehadiran{background:#fff;color:var(--m-ink);border-color:var(--m-line);font-size:11px}

.tag-status{font-size:11px}

.tag-event{background:#eff6ff;color:#0f5b9b;border-color:#bfdbfe;font-size:11px}

.countdown{font-size:10.5px;margin-top:4px}

.countdown.ok{color:#065f46}

.countdown.warn{color:#92400e}

.countdown.danger{color:#991b1b;font-weight:700}

.countdown.idle{color:var(--m-muted)}



/* table */

.table-wrap{overflow:auto}

.saya-table{width:100%;min-width:760px;border-collapse:collapse;font-size:13px}

.saya-table thead th{font-family:'Satoshi',system-ui,sans-serif;font-size:10px;letter-spacing:.08em;font-weight:700;color:var(--m-muted);text-align:left;padding:10px 16px;background:#fafaf9;border-bottom:1px solid var(--m-line);white-space:nowrap}

.saya-table tbody td{padding:12px 16px;border-bottom:1px solid #f4f4f5;vertical-align:middle}

.saya-table tbody tr:hover{background:#fafafa}

.saya-table tbody tr.row-expanded{background:#f0fdf6}

.cell-ekskul{display:flex;gap:10px;align-items:center}

.cell-avatar{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;font-size:10px;font-weight:800;border:1px solid var(--m-line);flex-shrink:0}

.cell-name{font-size:13px;font-weight:600;letter-spacing:-.01em}

.cell-main{font-size:12.5px;font-weight:500}

.cell-sub{font-size:11px;color:var(--m-muted)}

.mini-bar{margin-top:6px;height:4px;border-radius:999px;background:#f1f5f4;overflow:hidden;width:80px}

.mini-bar-fill{height:100%;background:var(--m-green);border-radius:999px}

.cell-acts{display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:wrap}

.kat-mini{padding:6px 12px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;white-space:nowrap}

.kat-mini.neutral:hover{background:var(--m-bg)}

.kat-mini.danger{border-color:#fecaca;color:#991b1b;background:#fff1f2}

.kat-mini.danger:hover{background:#fef2f2}

.kat-mini.danger:disabled{opacity:.45;cursor:not-allowed}

.kat-mini.primary{background:var(--m-cta);border-color:var(--m-cta);color:#fff}

.kat-mini.primary:hover{background:var(--m-cta-h)}

.kat-mini.ghost{background:#f4f4f5;color:var(--m-muted);border-color:var(--m-line);cursor:default}



/* expand */

.expand-row td{background:#fafaf9 !important;padding:0 !important}

.expand-box{padding:12px 16px}

.expand-title{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);margin-bottom:8px}

.expand-empty{font-size:11px;color:var(--m-muted);padding:8px 0}

.expand-list{display:flex;flex-direction:column;gap:6px}

.expand-item{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:8px 10px;border:1px solid var(--m-line);border-radius:10px;background:#fff;flex-wrap:wrap}

.expand-item.past{opacity:.7}

.expand-item.today{border-color:#f59e0b;background:#fffbeb}

.expand-date{font-size:11px;font-weight:600}

.expand-loc{font-size:10.5px;color:var(--m-muted)}

.expand-badge{font-size:10px;padding:2px 7px}

.badge-past{background:#f4f4f5;color:var(--m-muted);border-color:var(--m-line)}

.badge-today{background:#fffbeb;color:#92400e;border-color:#fde68a}

.badge-future{background:#eff6ff;color:#0f5b9b;border-color:#bfdbfe}

.expand-more{font-size:11px;color:var(--m-muted);text-align:center;padding:4px}



/* empty */

.kat-empty{padding:36px 24px;text-align:center}

.kat-empty-icon{width:44px;height:44px;border-radius:12px;border:1px dashed var(--m-line);display:grid;place-items:center;margin:0 auto;color:#a8a29e;background:#fafaf9}

.kat-empty-title{margin-top:10px;font-size:13px;font-weight:700}

.kat-empty-sub{margin-top:4px;font-size:11.5px;color:var(--m-muted);font-family:'Satoshi',system-ui,sans-serif}

.swap-card{background:#fff;border:1px solid var(--m-line);border-radius:20px;max-width:520px;width:100%;max-height:80vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.18)}

.swap-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--m-line)}

.swap-title{margin:0;font-size:16px;font-weight:800}

.swap-sub{margin:0;padding:12px 20px;font-size:12.5px;color:var(--m-muted);border-bottom:1px solid #f4f4f5}

.swap-loading,.swap-empty{padding:24px;text-align:center;color:var(--m-muted);font-size:12.5px}

.swap-list{overflow:auto;padding:12px;display:flex;flex-direction:column;gap:8px}

.swap-item{display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:12px 14px;border:1px solid var(--m-line);border-radius:12px;background:#fff;cursor:pointer;text-align:left;width:100%}

.swap-item:hover{border-color:var(--m-cta);background:#f0fdf6}

.swap-item:disabled{opacity:.5;cursor:not-allowed}

.swap-item-name{font-size:13px;font-weight:700}

.swap-item-meta{font-size:11px;color:var(--m-muted)}

.swap-cta-hint{font-size:10px;color:var(--m-cta);font-weight:700}

.swap-foot{padding:12px 20px;border-top:1px solid var(--m-line);display:flex;justify-content:flex-end}



/* foot page */

.kat-foot{margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;font-size:11px;color:var(--m-muted);text-align:center}

.kat-foot .foot-link{color:var(--m-muted);text-decoration:underline;text-underline-offset:2px}

.kat-foot .foot-link:hover{color:var(--m-ink)}



@media(max-width:639px){

 .kat-page{padding:20px 16px 24px}

 .kat-head{flex-direction:column;align-items:flex-start}

 .kat-title{font-size:18px}

 .kat-profile{flex-direction:column;align-items:stretch}

 .prof-right{align-items:stretch}

 .prof-right .kat-cta{width:100%}

 .prof-note,.prof-cache{text-align:center}

 .week-grid{grid-template-columns:repeat(7, minmax(0,1fr));gap:6px}

 .week-cell{padding:8px 4px}

 .week-day{font-size:14px}

 .kat-stats{grid-template-columns:1fr;gap:8px}

 .kat-card-head{flex-direction:column;align-items:stretch}

 .kat-card-head-r{justify-content:space-between}

 .kat-foot{flex-direction:column;align-items:center}

}

@media(max-width:480px){

 .week-grid{grid-template-columns:repeat(4,1fr)}

}

</style>

