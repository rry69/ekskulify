<template>
<div class="detail-root bg-[#f1f5f1] -mx-4 sm:-mx-6 -mt-6 text-[#0f172a]" style="font-family:Inter,sans-serif">
  <header class="sticky top-[56px] z-20 bg-white border-b border-slate-200">
    <div class="max-w-[480px] md:max-w-[1020px] mx-auto px-4 h-[56px] flex items-center gap-3">
      <router-link to="/katalog" class="w-9 h-9 rounded-full hover:bg-slate-100 flex items-center justify-center shrink-0" aria-label="Kembali">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 10H5M9 4L3 10l6 6"/></svg>
      </router-link>
      <h1 class="font-semibold text-[15px]" style="font-family:'Plus Jakarta Sans',sans-serif">Detail ekskul</h1>
      <div class="hidden md:flex items-center gap-2 ml-3 pl-3 border-l border-slate-200">
        <span class="text-xs text-slate-400">Lihat sebagai:</span>
        <span class="text-xs font-semibold bg-slate-900 text-white rounded-full px-3 py-1.5">{{ roleLabel }}</span>
      </div>
      <div class="ml-auto flex items-center gap-2">
        <div class="relative">
          <button @click.stop="notifOpen=!notifOpen" class="relative w-9 h-9 rounded-full bg-white border border-slate-200 flex items-center justify-center hover:bg-slate-50" aria-label="Notifikasi">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 15a2 2 0 0 0 4 0"/><path d="M4.5 12V9a4.5 4.5 0 0 1 9 0v3l1 1H3.5l1-1Z"/></svg>
            <span v-if="notifUnread>0" class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold w-5 h-5 rounded-full flex items-center justify-center">{{ notifUnread }}</span>
          </button>
          <div v-if="notifOpen" class="absolute right-0 top-[44px] w-[340px] bg-white rounded-2xl border border-slate-200 shadow-xl overflow-hidden z-30">
            <div class="px-4 py-3 flex items-center justify-between border-b border-slate-100">
              <h4 class="text-sm font-bold">Notifikasi</h4>
              <button @click="markAllRead" class="text-xs font-semibold text-[#1a5632] hover:underline">Tandai sudah dibaca</button>
            </div>
            <div class="max-h-[320px] overflow-auto divide-y divide-slate-50">
              <div v-if="!notifs.length" class="p-4 text-xs text-slate-400 text-center">Belum ada notifikasi</div>
              <div v-for="n in notifs" :key="n.id" class="p-3 flex gap-3" :class="n.is_read?'opacity-60':'bg-amber-50/40'">
                <span class="w-8 h-8 rounded-full bg-slate-900 text-white flex items-center justify-center text-xs shrink-0">{{ n.tipe==='like_post'?'♥': n.tipe==='mention'?'@' : '💬' }}</span>
                <div class="flex-1"><p class="text-xs leading-relaxed">{{ n.message }} <span v-if="n.actor_count>1" class="font-bold"> ({{ n.actor_count }} orang)</span></p><p class="text-[11px] text-slate-400 mt-1">{{ timeAgo(n.updated_at||n.created_at) }} · {{ n.tipe }}</p></div>
                <button v-if="!n.is_read" @click="markRead(n)" class="text-[11px] text-[#1a5632] self-start">Baca</button>
              </div>
            </div>
          </div>
        </div>
        <span class="hidden md:block text-xs text-slate-400">MVP v2</span>
      </div>
    </div>
    <div class="md:hidden px-4 pb-3 flex items-center gap-2 border-t border-slate-100 pt-3">
      <span class="text-xs text-slate-400">Lihat sebagai:</span>
      <span class="text-xs font-semibold bg-slate-900 text-white rounded-full px-3 py-1.5 flex-1 text-center">{{ roleLabel }}</span>
    </div>
  </header>

  <div v-if="loading" class="max-w-[480px] md:max-w-[1020px] mx-auto px-4 py-5 space-y-4">
    <div class="h-6 w-32 bg-white rounded-full animate-pulse border border-slate-200"></div>
    <div class="bg-white rounded-[20px] border border-slate-200 p-5 space-y-3 animate-pulse"><div class="h-6 w-40 bg-slate-100 rounded"></div><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-2 w-full bg-slate-100 rounded-full"></div></div>
  </div>

  <main v-else-if="e" class="max-w-[480px] md:max-w-[1020px] mx-auto px-4 py-5 md:grid md:grid-cols-[1.35fr_0.9fr] md:gap-6 md:items-start">
    <div class="space-y-4">
      <section class="bg-white rounded-[20px] border border-slate-200 overflow-hidden">
        <div class="p-5 pb-4">
          <div class="flex items-start justify-between gap-3">
            <h2 class="font-bold text-[22px] leading-none" style="font-family:'Plus Jakarta Sans',sans-serif">{{ e.nama }}</h2>
            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-widest uppercase px-3 py-1.5 rounded-full bg-[#e8f5e9] text-[#1a5632] border border-[#c8e6c9] shrink-0"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> {{ (e.status||'approved').toUpperCase() }}</span>
          </div>
          <p class="text-[13px] text-slate-500 mt-2">{{ e.deskripsi ? (e.deskripsi.slice(0,120)+(e.deskripsi.length>120?'…':'')) : 'Ekskul wajib Pramuka — Jumat 15:00' }} <span v-if="e.hari">— {{ e.hari }} {{ fmtJam(e.jam_mulai) }}{{ e.jam_selesai ? '–'+fmtJam(e.jam_selesai) : '' }}</span></p>
          <p class="text-[13px] text-slate-500 flex items-center gap-1.5 mt-1"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 12c3-2.3 5-4.5 5-6.5A5 5 0 0 0 2 5.5C2 7.5 4 9.7 7 12Z"/><circle cx="7" cy="5.5" r="1.5"/></svg> {{ e.lokasi || 'Lapangan Utama' }} · Pembina: {{ e.pembina_nama || '—' }}</p>
          <div class="mt-4"><div class="h-2.5 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-[#1a5632] rounded-full transition-all" :style="{width: kuotaPercent+'%'}"></div></div><div class="flex justify-between text-xs mt-2"><span class="font-semibold text-[#1a5632]">{{ terisi }}/{{ e.kuota }} ({{ kuotaPercent }}%)</span><span class="text-slate-400">{{ terisi>=e.kuota ? 'Kuota penuh' : 'Kuota tersedia' }}</span></div></div>
          <div class="flex items-center gap-3 mt-4 flex-wrap">
            <button v-if="auth.user?.role==='siswa'" @click="handleDaftar" :disabled="isRegistered" :class="isRegistered ? 'bg-emerald-600' : 'bg-[#1a5632] hover:bg-[#143d24]'" class="text-white text-sm font-semibold px-6 py-2.5 rounded-full transition disabled:opacity-60">{{ isRegistered ? 'Terdaftar ✓' : 'Daftar' }}</button>
            <span v-else class="text-xs text-slate-400">Login sebagai siswa untuk mendaftar</span>
            <span class="text-xs text-slate-500 bg-slate-50 border border-slate-200 px-3 py-2 rounded-full">{{ e.requires_approval ? 'Butuh approval' : 'Otomatis diterima' }}</span>
            <button v-if="isEditAllowed" @click="openEdit" class="ml-auto text-xs font-semibold px-3 py-2 rounded-full border border-slate-200 hover:bg-slate-50">Edit Ekskul</button>
          </div>
          <p v-if="daftarMsg" class="text-xs mt-2" :class="daftarOk?'text-emerald-600':'text-red-600'" role="status">{{ daftarMsg }}</p>
          <div class="grid grid-cols-2 gap-3 mt-5 pt-4 border-t border-slate-100">
            <div class="bg-[#f8faf8] rounded-xl px-3 py-3 border border-slate-100"><div class="text-[11px] tracking-widest font-semibold text-slate-400">KATEGORI</div><div class="text-sm font-semibold mt-1">{{ e.requires_approval ? 'Selektif' : 'Wajib' }}</div></div>
            <div class="bg-[#f8faf8] rounded-xl px-3 py-3 border border-slate-100"><div class="text-[11px] tracking-widest font-semibold text-slate-400">JADWAL RUTIN</div><div class="text-sm font-semibold mt-1">{{ e.hari || 'Jumat' }} {{ fmtJam(e.jam_mulai) || '15:00' }}</div></div>
          </div>
        </div>
      </section>

      <section class="bg-white rounded-[20px] border border-slate-200 overflow-hidden" ref="jadwalSectionRef">
        <div class="px-5 py-4 flex items-center justify-between border-b border-slate-100">
          <h3 class="font-bold text-[15px]" style="font-family:'Plus Jakarta Sans',sans-serif">Jadwal yang akan datang</h3><span class="text-xs font-medium px-2.5 py-1 rounded-full bg-slate-900 text-white">{{ scheds.length }} sesi</span>
        </div>
        <div v-if="scheds.length" class="overflow-x-auto">
          <table class="w-full text-[12.5px]"><thead class="text-[11px] tracking-widest text-slate-400 font-semibold"><tr class="border-b border-slate-100"><th class="text-left px-5 py-3">TANGGAL</th><th class="text-left px-3 py-3">JAM</th><th class="text-left px-3 py-3">LOKASI</th><th class="text-left px-5 py-3">TIPE</th><th v-if="isPembina" class="text-right px-5 py-3">AKSI</th></tr></thead>
          <tbody class="divide-y divide-slate-50">
            <tr v-for="s in displayedScheds" :key="s.id" :class="highlightSchedId===s.id ? 'bg-amber-50' : ''"><td class="px-5 py-3 whitespace-nowrap">{{ s.tanggal }}</td><td class="px-3 py-3 whitespace-nowrap">{{ fmtJam(s.jam_mulai) }} - {{ fmtJam(s.jam_selesai) }}</td><td class="px-3 py-3">{{ s.lokasi || '—' }}</td><td class="px-5 py-3"><span :class="s.tipe==='tambahan' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-slate-900 text-white'" class="text-[11px] px-2 py-1 rounded-full">{{ s.tipe }}</span></td><td v-if="isPembina" class="px-5 py-3 text-right"><button @click="confirmDeleteSched(s)" class="text-[11px] px-2 py-1 rounded-full border border-red-200 text-red-600 hover:bg-red-50">Hapus</button></td></tr>
          </tbody></table>
        </div>
        <div v-else class="px-5 py-8 text-center text-sm text-slate-400">Belum ada jadwal</div>
        <button v-if="scheds.length>3" @click="showAllSched=!showAllSched" class="w-full text-xs font-semibold text-slate-500 py-3 hover:bg-slate-50 border-t border-slate-100">{{ showAllSched ? 'Sembunyikan ↑' : 'Lihat semua jadwal →' }}</button>
        <div v-if="isPembina" class="border-t border-slate-100" style="background:#fafaf9">
          <button @click="showJadwalForm=!showJadwalForm" :aria-expanded="String(showJadwalForm)" class="w-full text-left px-5 py-3 text-[13px] font-medium hover:underline" style="color:#1a5632">+ Tambah Jadwal</button>
          <div v-show="showJadwalForm" class="px-5 pb-4 pt-2 border-t bg-white border-slate-100">
            <form @submit.prevent="addSched" novalidate class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-end">
              <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Tanggal *</span><input type="date" v-model="fs.tanggal" required class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-slate-900 border-slate-200"/></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Jam mulai *</span><input type="time" v-model="fs.jam_mulai" required :class="jamError?'border-red-300':''" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-slate-900 border-slate-200"/></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Jam selesai *</span><input type="time" v-model="fs.jam_selesai" required :class="jamError?'border-red-300':''" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-slate-900 border-slate-200"/></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Lokasi</span><input v-model="fs.lokasi" placeholder="Lapangan" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-slate-900 border-slate-200"/></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Tipe</span><select v-model="fs.tipe" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"><option value="rutin">rutin</option><option value="tambahan">tambahan</option></select></label>
              <div class="col-span-2 sm:col-span-5 flex flex-wrap gap-2 mt-1">
                <button type="submit" :disabled="!isJadwalValid" class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold disabled:opacity-40 disabled:cursor-not-allowed hover:bg-black bg-slate-900">Tambah Jadwal</button>
                <span v-if="jamError" class="text-[11px] text-red-600 self-center" role="alert">{{ jamError }}</span>
                <span v-if="jadwalMsg" class="text-[11px] self-center" :class="jadwalOk?'text-emerald-600':'text-red-600'">{{ jadwalMsg }}</span>
              </div>
            </form>
          </div>
        </div>
      </section>

       <section class="hidden md:block bg-white rounded-[20px] border border-slate-200 p-5">
        <div class="flex items-center justify-between"><h3 class="font-bold text-[14px]" style="font-family:'Plus Jakarta Sans',sans-serif">Anggota — {{ anggota.length }} siswa</h3><a href="#" @click.prevent="openAnggotaTab()" class="text-xs font-semibold text-[#1a5632] hover:underline">Lihat semua</a></div>
        <div v-if="anggota.length" class="flex gap-3 mt-4 overflow-x-auto scrollbar-hide">
          <div v-for="a in anggota.slice(0,5)" :key="a.id" class="text-center min-w-[56px]"><router-link :to="'/u/'+a.user_id"><img :src="`https://i.pravatar.cc/100?img=${(a.user_id%70)+1}`" class="w-11 h-11 rounded-full mx-auto"/></router-link><div class="text-[11px] mt-1 font-medium truncate max-w-[56px]">{{ a.nama.split(' ')[0] }}</div></div>
          <div v-if="anggota.length>5" class="min-w-[56px] flex flex-col items-center justify-center"><div class="w-11 h-11 rounded-full bg-slate-900 text-white flex items-center justify-center text-xs font-bold">+{{ anggota.length-5 }}</div><div class="text-[11px] mt-1 text-slate-400">lainnya</div></div>
        </div>
        <div v-else class="text-xs text-slate-400 mt-3">Belum ada anggota — daftar untuk bergabung</div>
      </section>

      <section v-if="isPembina" class="bg-white rounded-[20px] border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between"><h3 class="font-bold text-[14px]" style="font-family:'Plus Jakarta Sans',sans-serif">Absensi per Sesi</h3><span class="text-[11px] px-2 py-1 rounded-full bg-slate-900 text-white">{{ scheds.length }} sesi</span></div>
        <div class="p-4">
          <div class="flex flex-wrap gap-2 items-center">
            <select v-model="selSched" class="w-full sm:w-[320px] px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200">
              <option value="">-- pilih jadwal untuk absensi --</option>
              <option v-for="s in scheds" :key="s.id" :value="String(s.id)">{{ s.tanggal }} {{ fmtJam(s.jam_mulai) }} ({{ s.tipe }})</option>
            </select>
            <button v-if="selSched" @click="genQR(parseInt(selSched))" class="px-4 py-2.5 rounded-full text-white text-[13px] font-semibold hover:bg-black bg-slate-900">Generate QR (5m)</button>
          </div>
          <div v-if="selSched && qr.token" class="mt-3 text-center border rounded-2xl p-4 bg-[#f8faf8] border-slate-200">
            <div class="text-[11px] text-slate-500">QR expiry: {{ qr.expiry }} (5 menit, one-time)</div>
            <canvas ref="canvasRef" class="border rounded-xl p-2 bg-white mx-auto mt-2" width="220" height="220" style="border-color:#e7e2dc"></canvas>
            <div class="text-[11px] mt-2 text-slate-400">Token: {{ qr.token.slice(0,12) }}…</div>
          </div>
          <div v-if="selSched" class="mt-4 overflow-auto rounded-xl border border-slate-200">
            <table class="w-full min-w-[480px]"><thead class="text-[10px] tracking-widest font-semibold text-slate-500 bg-[#f8faf8] border-b border-slate-100"><tr><th class="text-left px-4 py-2.5">NAMA</th><th class="text-left px-4 py-2.5">STATUS</th><th class="text-left px-4 py-2.5">AKSI</th></tr></thead>
            <tbody class="divide-y divide-slate-50"><tr v-for="a in anggota.filter(x=>x.status==='diterima')" :key="a.user_id" class="hover:bg-slate-50/70"><td class="px-4 py-3 text-[13px] font-medium">{{ a.nama }}</td><td class="px-4 py-3"><span v-if="attMap[a.user_id]" class="text-[11px] px-2 py-1 rounded-full border font-medium" :class="attMap[a.user_id]==='hadir'?'bg-emerald-600 text-white border-emerald-600':attMap[a.user_id]==='izin'?'bg-amber-400 border-amber-400':'bg-slate-100 border-slate-200'">{{ attMap[a.user_id] }}</span><span v-else class="text-[11px] text-slate-400">-</span></td><td class="px-4 py-3"><label class="inline-flex items-center gap-1.5 text-[11px] cursor-pointer"><input type="checkbox" :checked="attMap[a.user_id]==='hadir'" @change="markAtt(a.user_id,$event.target.checked?'hadir':'alpa')" class="rounded"/>Hadir</label><select :value="attMap[a.user_id]||''" @change="markAtt(a.user_id,$event.target.value)" class="ml-2 px-2 py-1 rounded-lg border bg-white text-[12px] border-slate-200"><option value="">-</option><option value="hadir">hadir</option><option value="izin">izin</option><option value="alpa">alpa</option></select></td></tr></tbody></table>
          </div>
        </div>
      </section>
       <section v-if="isAnggota && !isPembina" class="bg-white rounded-[20px] border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between"><h3 class="font-bold text-[14px]" style="font-family:'Plus Jakarta Sans',sans-serif">Rekap Saya</h3><button v-if="!myRekapLoaded" @click="fetchMyRekapIfEmpty()" class="text-[11px] px-3 py-1 rounded-full border border-slate-200 hover:bg-slate-50">Muat</button></div>
        <div v-if="myRekapLoading" class="p-5 space-y-3 animate-pulse"><div class="h-3 w-32 bg-slate-100 rounded"></div><div class="h-2 w-full bg-slate-100 rounded-full"></div><div class="h-3 w-24 bg-slate-100 rounded"></div></div>
        <div v-else-if="myRekap" class="p-5">
          <div class="text-[13px] font-medium">Kehadiran saya: {{ myRekap.hadir }}/{{ myRekap.total_sesi }} ({{ myRekap.persen }}%)</div>
          <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-3"><div class="h-full bg-[#1a5632] rounded-full transition-all" :style="{width: (myRekap.persen||0)+'%'}"></div></div>
          <div class="text-[11px] text-slate-400 mt-2">{{ e?.nama }} · bar 4px</div>
        </div>
        <div v-else class="px-5 py-8 text-center text-xs text-slate-400">Belum ada rekap · {{ myRekapLoaded ? 'tidak ada sesi' : 'klik Muat' }}</div>
       </section>
       <section v-if="isPembina" class="bg-white rounded-[20px] border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between"><h3 class="font-bold text-[14px]" style="font-family:'Plus Jakarta Sans',sans-serif">Rekap Kehadiran</h3><button v-if="!rekapLoaded" @click="fetchRekapIfEmpty()" class="text-[11px] px-3 py-1 rounded-full bg-slate-900 text-white hover:bg-black">Muat Rekap</button><span v-else class="text-[11px] px-2 py-1 rounded-full bg-slate-100">{{ summary.length }} siswa</span></div>
        <div v-if="rekapLoading" class="p-5 space-y-2 animate-pulse"><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-3 w-3/4 bg-slate-100 rounded"></div><div class="h-3 w-1/2 bg-slate-100 rounded"></div></div>
        <div v-else-if="summary.length" class="overflow-auto"><table class="w-full min-w-[520px]"><thead class="text-[10px] tracking-widest font-semibold text-slate-500 bg-[#f8faf8] border-b border-slate-100"><tr><th class="text-left px-4 py-2.5">NAMA</th><th class="text-left px-4 py-2.5">REKAP</th><th class="text-right px-4 py-2.5">%</th></tr></thead><tbody class="divide-y divide-slate-50"><tr v-for="s in summary" :key="s.id" class="hover:bg-slate-50/70"><td class="px-4 py-3 text-[13px] font-medium">{{ s.nama }}</td><td class="px-4 py-3 text-[12px] text-slate-600">Rekap {{ s.nama }} sepanjang ekskul {{ s.hadir_count }}/{{ s.total_sesi }}={{ s.persen }}%</td><td class="px-4 py-3 text-right"><span class="text-[11px] px-2 py-1 rounded-full border bg-white">{{ s.persen }}%</span></td></tr></tbody></table></div>
        <div v-else class="px-5 py-8 text-center text-xs text-slate-400">{{ rekapLoaded ? 'Belum ada rekap' : 'Klik Muat Rekap untuk lihat (lazy)' }}</div>
       </section>
      <section v-if="isPembina && anggota.length" class="bg-white rounded-[20px] border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between"><h3 class="font-bold text-[13px]">Kelola Anggota</h3><span class="text-[11px] px-2 py-1 rounded-full bg-slate-900 text-white">{{ anggota.length }}</span></div>
        <div class="overflow-auto"><table class="w-full min-w-[520px]"><thead class="text-[10px] tracking-widest font-semibold text-slate-500 bg-[#f8faf8]"><tr><th class="text-left px-4 py-2.5">NAMA</th><th class="text-left px-4 py-2.5">STATUS</th><th class="text-right px-4 py-2.5">AKSI</th></tr></thead><tbody class="divide-y divide-slate-50"><tr v-for="a in anggota" :key="a.id" class="hover:bg-slate-50/70"><td class="px-4 py-3"><div class="text-[13px] font-medium">{{ a.nama }}</div><div class="text-[11px] text-slate-400">{{ a.email }}</div></td><td class="px-4 py-3"><span class="text-[11px] px-2 py-1 rounded-full border" :class="a.status==='diterima'?'bg-emerald-600 text-white border-emerald-600':a.status==='menunggu'?'bg-amber-400 border-amber-400':'bg-red-50 border-red-200'">{{ a.status }}</span></td><td class="px-4 py-3 text-right"><button @click="openKick(a)" class="text-[11px] px-3 py-1 rounded-full border border-red-200 text-red-600">Kick</button><button v-if="a.status==='menunggu'" @click="acc(a.id,'approve')" class="ml-1 text-[11px] px-3 py-1 rounded-full bg-slate-900 text-white">ACC</button><button v-if="a.status==='menunggu'" @click="acc(a.id,'reject')" class="ml-1 text-[11px] px-3 py-1 rounded-full border border-slate-200">Tolak</button></td></tr></tbody></table></div>
      </section>
    </div>

    <div class="space-y-4 mt-4 md:mt-0">
      <section class="bg-white rounded-[20px] border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 flex items-center gap-2 border-b border-slate-100">
          <span class="w-7 h-7 rounded-full bg-amber-100 flex items-center justify-center text-[13px]">📢</span>
          <h3 class="font-bold text-[14px]" style="font-family:'Plus Jakarta Sans',sans-serif">Pengumuman</h3>
          <span class="text-[11px] px-2 py-1 rounded-full bg-amber-100 text-amber-700 font-semibold">{{ pengList.length }} baru</span>
          <span class="ml-auto text-[11px] text-slate-400 hidden md:block">Hanya pembina</span>
        </div>
        <div :class="isPengumumanAllowed ? 'border-amber-200 bg-amber-50/50' : 'border-slate-200 bg-slate-50 opacity-60'" class="mx-3 mt-3 rounded-2xl border-2 border-dashed p-3">
          <div class="flex items-center gap-2 text-xs font-semibold" :class="isPengumumanAllowed?'text-amber-800':'text-slate-500'"><span class="w-6 h-6 rounded-full flex items-center justify-center" :class="isPengumumanAllowed?'bg-amber-500 text-white':'bg-slate-300 text-white'">✦</span> Buat pengumuman (khusus Pembina)</div>
          <textarea v-model="pengInput" :disabled="!isPengumumanAllowed" :placeholder="isPengumumanAllowed ? 'Tulis pengumuman penting... akan di-pin & notifikasi ke semua anggota' : 'Hanya Pembina yang bisa memposting pengumuman'" rows="2" class="mt-2 w-full bg-white border rounded-xl px-3 py-2.5 text-[13px] placeholder:text-slate-400 focus:outline-none disabled:bg-slate-50" :class="isPengumumanAllowed?'border-amber-200 focus:border-amber-400':'border-slate-200'"></textarea>
          <div class="flex items-center gap-2 mt-2 flex-wrap">
            <button @click="postPengumuman" :disabled="!isPengumumanAllowed || !pengInput.trim()" :class="(!isPengumumanAllowed || !pengInput.trim()) ? 'opacity-40 cursor-not-allowed' : 'hover:bg-amber-600'" class="ml-auto bg-amber-500 text-white text-xs font-bold px-4 py-2 rounded-full">Posting</button>
          </div>
          <p v-if="!isPengumumanAllowed" class="text-[11px] text-slate-500 mt-2 bg-white border border-slate-200 rounded-xl px-3 py-2">🔒 Kamu login sebagai <b>{{ auth.user?.role || 'Guest' }}</b> — hanya Pembina/Admin yang bisa memposting pengumuman.</p>
        </div>
        <div class="p-3 space-y-3">
          <article v-for="p in pengList" :key="p.id" :class="p.is_pinned ? 'border-amber-200 bg-amber-50/60' : 'border-slate-100 bg-slate-50'" class="rounded-2xl border p-4">
            <div class="flex items-center gap-2 flex-wrap">
              <img :src="`https://i.pravatar.cc/100?img=12`" class="w-7 h-7 rounded-full"/><span class="text-xs font-semibold">{{ p.creator_nama || 'Pak Andi Wijaya' }}</span><span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(p.creator_role)">{{ roleBadgeLabel(p.creator_role) }}</span><span v-if="p.is_pinned" class="text-[11px] px-2 py-0.5 rounded-full bg-[#1a5632] text-white font-semibold">📌 PINNED</span><span class="ml-auto text-[11px] text-slate-400">{{ timeAgo(p.created_at) }}</span>
              <div v-if="isPengumumanAllowed" class="relative"><button @click.stop="togglePengMenu(p.id)" class="w-7 h-7 rounded-full hover:bg-white flex items-center justify-center text-slate-400">⋯</button><div v-if="pengMenuOpen===p.id" class="absolute right-0 top-8 w-44 bg-white rounded-xl border border-slate-200 shadow-lg py-1 text-xs z-10"><button @click="togglePin(p); pengMenuOpen=null" class="w-full text-left px-3 py-2 hover:bg-slate-50">{{ p.is_pinned ? '📌 Lepas pin' : '📌 Pin ke atas' }}</button><button @click="deletePeng(p); pengMenuOpen=null" class="w-full text-left px-3 py-2 hover:bg-slate-50 text-red-600">🗑️ Hapus</button></div></div>
            </div>
            <p class="text-[13px] leading-relaxed mt-2 break-words">{{ p.isi }}</p>
          </article>
          <div v-if="!pengList.length" class="text-xs text-slate-400 text-center py-4 border border-dashed border-slate-200 rounded-2xl bg-slate-50">Belum ada pengumuman</div>
        </div>
      </section>

      <section class="bg-white rounded-[20px] border border-slate-200 overflow-hidden">
        <div class="px-2 pt-2 flex gap-1 overflow-x-auto border-b border-slate-100" style="scrollbar-width:none">
          <button @click="switchTab('diskusi')" :class="activeTab==='diskusi' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-50'" class="whitespace-nowrap px-4 py-2.5 rounded-full text-xs font-semibold">Diskusi <span class="ml-1 px-1.5 py-0.5 rounded-full text-[11px]" :class="activeTab==='diskusi'?'bg-white/20':'bg-slate-100'">{{ counts.diskusi }}</span></button>
          <button @click="switchTab('tanya')" :class="activeTab==='tanya' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-50'" class="whitespace-nowrap px-4 py-2.5 rounded-full text-xs font-semibold">Tanya Jawab <span class="ml-1 px-1.5 py-0.5 rounded-full text-[11px]" :class="activeTab==='tanya'?'bg-white/20':'bg-slate-100'">{{ counts.tanya }}</span></button>
          <button @click="switchTab('postingan')" :class="activeTab==='postingan' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-50'" class="whitespace-nowrap px-4 py-2.5 rounded-full text-xs font-semibold">Postingan <span class="ml-1 px-1.5 py-0.5 rounded-full text-[11px]" :class="activeTab==='postingan'?'bg-white/20':'bg-slate-100'">{{ counts.postingan }}</span></button>
          <button @click="switchTab('anggota')" :class="activeTab==='anggota' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-50 md:hidden'" class="whitespace-nowrap px-4 py-2.5 rounded-full text-xs font-semibold md:hidden">Anggota</button>
        </div>

        <div v-if="activeTab!=='anggota' && canPost" class="p-4 border-b border-slate-100">
          <div class="flex gap-3">
            <img :src="`https://i.pravatar.cc/100?img=${(auth.user?.id%70)+1}`" class="w-8 h-8 rounded-full flex-none"/>
            <div class="flex-1 min-w-0">
              <div v-if="activeTab==='tanya'" class="space-y-2 mb-2">
                <input v-model="composerJudul" placeholder="Judul pertanyaan..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-[13px] focus:bg-white focus:border-slate-900 outline-none"/>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" v-model="showPollComposer" class="rounded"/> Tambah polling (2-6 opsi)</label>
                <div v-if="showPollComposer" class="space-y-1 p-3 bg-amber-50/60 border border-amber-200 rounded-xl">
                  <div v-for="(op,idx) in pollOptions" :key="idx" class="flex gap-1"><input v-model="pollOptions[idx]" :placeholder="`Opsi ${idx+1}`" class="flex-1 px-3 py-2 rounded-xl border bg-white text-xs border-slate-200"/><button v-if="pollOptions.length>2" @click="pollOptions.splice(idx,1)" class="px-2 text-xs">✕</button></div>
                  <button v-if="pollOptions.length<6" @click="pollOptions.push('')" class="text-xs text-[#1a5632]">+ opsi</button>
                </div>
              </div>
              <div v-if="pollsList.length" class="space-y-2 mb-3">
                <div v-for="pl in pollsList" :key="pl.id" class="p-3 bg-white border border-slate-200 rounded-xl">
                  <div class="text-xs font-semibold">{{ pl.question }}<span class="ml-2 text-[11px] text-slate-400">{{ pl.total_votes }} suara</span></div>
                  <div class="mt-2 space-y-1">
                    <label v-for="o in pl.options" :key="o.id" class="flex items-center gap-2 text-xs cursor-pointer">
                      <input type="radio" :name="'poll-'+pl.id" :checked="pl.my_vote===o.id" @change="votePoll(pl,o.id)"/> {{ o.label }} <span class="ml-auto text-[11px] text-slate-500">{{ o.votes }} ({{ o.percent }}%)</span>
                    </label>
                  </div>
                  <div v-for="o in pl.options" :key="'bar-'+o.id" class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-[#1a5632]" :style="{width:o.percent+'%'}"></div></div>
                </div>
              </div>
              <div class="relative">
                <textarea ref="composerEl" v-model="composerInput" @input="onComposerInput" @keydown="onComposerKeydown" :placeholder="composerPlaceholder" rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 pr-12 text-[13px] placeholder:text-slate-400 focus:outline-none focus:border-[#1a5632] focus:bg-white resize-none"></textarea>
                <button @click="kirimPost" :disabled="!composerInput.trim() && !pendingUploads.length" class="absolute right-2 bottom-2 w-8 h-8 rounded-full bg-[#1a5632] text-white flex items-center justify-center hover:bg-[#143d24] disabled:opacity-40">↑</button>
                <div v-if="mentionOpen && mentionTarget?.kind==='composer' && mentionFiltered.length" class="absolute left-0 right-12 bottom-[48px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-40 overflow-auto z-10">
                  <button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx? 'bg-slate-900 text-white':'hover:bg-slate-50'" class="w-full text-left px-3 py-2 text-xs flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}<span class="ml-auto text-[11px] opacity-60">{{ u.role }}</span></button>
                </div>
              </div>
              <div class="flex items-center gap-2 mt-2 flex-wrap">
                <label class="inline-flex items-center gap-1.5 text-xs bg-white border border-slate-200 px-2.5 py-1.5 rounded-full cursor-pointer hover:bg-slate-50">📎 File/gambar <input type="file" multiple class="hidden" @change="onFileChange" :accept="activeTab==='postingan'?'image/*':''"></label>
                <span class="text-[11px] text-slate-400">Maks 5MB/file · @mention didukung · <span v-if="pendingUploads.length">{{ pendingUploads.length }} file siap</span></span>
              </div>
              <div v-if="pendingUploads.length" class="grid grid-cols-3 gap-2 mt-2">
                <div v-for="(f,i) in pendingUploads" :key="i" class="relative bg-slate-50 rounded-xl p-2 border text-[11px]">
                  <div class="truncate font-medium">{{ f.name }}</div><div class="text-slate-400">{{ (f.size/1024).toFixed(1) }} KB</div>
                  <button @click="pendingUploads.splice(i,1)" class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-slate-900 text-white text-xs">✕</button>
                  <div v-if="f.isImage" class="mt-1"><img :src="f.preview" class="w-full h-16 object-cover rounded-lg"/></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else-if="activeTab!=='anggota' && !canPost" class="p-3 mx-3 mt-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800">🔒 Hanya anggota ekskul ini yang bisa posting. Daftar dulu untuk ikut diskusi.</div>

        <div v-if="activeTab!=='anggota'" class="divide-y divide-slate-100">
          <div v-if="postsLoading" class="p-4 space-y-3 animate-pulse"><div class="h-3 w-24 bg-slate-100 rounded"></div><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-3 w-2/3 bg-slate-100 rounded"></div></div>
          <div v-else-if="!currentPosts.length" class="p-8 text-center text-xs text-slate-400">Belum ada {{ activeTab }} — jadi yang pertama posting!</div>
          <article v-for="th in currentPosts" :key="th.id" v-memo="[th.id, th.likes, th.is_liked, th.comments_count, th._expanded]" class="p-4 hover:bg-slate-50/40 transition">
            <div class="flex gap-3">
              <router-link :to="'/u/'+th.user_id"><img :src="`https://i.pravatar.cc/100?img=${(th.user_id%70)+1}`" class="w-8 h-8 rounded-full flex-none"/></router-link>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <router-link :to="'/u/'+th.user_id" class="text-[13px] font-semibold hover:underline">{{ th.author_nama }}</router-link><span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(th.author_role)">{{ roleBadgeLabel(th.author_role) }}</span><span class="text-[11px] text-slate-400">· {{ timeAgo(th.created_at) }}</span>
                  <div class="ml-auto flex items-center gap-1">
                    <router-link :to="`/ekskul/${$route.params.id}/${activeTab==='diskusi'?'d':activeTab==='tanya'?'t':'p'}/${th.id}`" class="text-[11px] px-2 py-1 rounded-full border hover:bg-white">Buka</router-link>
                    <button v-if="th.user_id===auth.user?.id || isPembina" @click="deletePost(th)" class="text-[11px] text-red-500 px-2">Hapus</button><button v-if="th.user_id!==auth.user?.id" @click="reportPost(th)" class="text-[11px] text-amber-600 px-2">⚑ Laporkan</button>
                  </div>
                </div>
                <h4 v-if="th.judul" class="text-[13.5px] font-semibold leading-snug mt-1">{{ th.judul }}</h4>
                <p class="text-[13px] text-slate-600 leading-relaxed mt-1 whitespace-pre-wrap break-words">{{ th.isi }}</p>
                <div v-if="th.uploads?.length" class="grid gap-2 mt-3" :class="th.uploads.length===1?'grid-cols-1':'grid-cols-2'">
                  <a v-for="u in th.uploads" :key="u.id" :href="u.url" target="_blank" class="block group">
                    <img v-if="u.mime && u.mime.startsWith('image/')" :src="u.url" class="w-full h-28 object-cover rounded-xl border border-slate-200 cursor-pointer group-hover:opacity-90" @click.prevent="openLightbox(u.url)"/>
                    <div v-else class="p-3 rounded-xl border bg-slate-50 text-xs flex items-center gap-2"><span>📄</span><span class="truncate">{{ u.original_name }}</span><span class="ml-auto text-[11px] text-slate-400">{{ (u.size/1024).toFixed(1) }}KB</span></div>
                    <div class="text-[11px] text-slate-400 mt-1 truncate">{{ u.original_name }} · klik untuk {{ u.mime?.startsWith('image/')?'preview':'unduh' }}</div>
                  </a>
                </div>
                <div class="flex items-center gap-2 mt-3 flex-wrap">
                  <button @click="toggleLikePost(th)" :class="th.is_liked ? 'bg-rose-50 border-rose-200 text-rose-600' : 'border-slate-200 hover:border-slate-300'" class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-full border">{{ th.is_liked ? '♥' : '♡' }} {{ th.likes }}</button>
                  <button @click="expandPost(th)" class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-full bg-slate-900 text-white">💬 {{ th.comments_count }} balasan</button>
                  <button @click="copyPostLink(th)" class="text-[11px] px-2 py-1 rounded-full border hover:bg-white">🔗 Salin link</button>
                </div>
                <div v-if="th._expanded" class="mt-3 bg-[#f8faf8] rounded-2xl border border-slate-100 p-3 space-y-3">
                  <div v-for="c in th._comments" :key="c.id" class="flex gap-2">
                    <router-link :to="'/u/'+c.user_id"><img :src="`https://i.pravatar.cc/100?img=${(c.user_id%70)+1}`" class="w-6 h-6 rounded-full flex-none"/></router-link>
                    <div class="flex-1 bg-white rounded-xl border border-slate-100 px-3 py-2">
                      <div class="flex items-center gap-2 flex-wrap"><router-link :to="'/u/'+c.user_id" class="text-xs font-semibold hover:underline">{{ c.author_nama }}</router-link><span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(c.author_role)">{{ roleBadgeLabel(c.author_role) }}</span><span class="text-[11px] text-slate-400">· {{ timeAgo(c.created_at) }}</span>
                        <button @click="toggleLikeComment(c)" :class="c.is_liked?'text-rose-600':''" class="ml-auto text-xs px-2 py-1 rounded-full border">{{ c.is_liked?'♥':'♡' }} {{ c.likes }}</button>
                        <button v-if="c.user_id===auth.user?.id || isPembina" @click="deleteComment(c, th)" class="text-[11px] text-red-500">Hapus</button>
                      </div>
                      <p class="text-xs text-slate-600 mt-1 whitespace-pre-wrap">{{ c.isi }}</p>
                      <div v-if="c.uploads?.length" class="flex flex-wrap gap-1 mt-1"><a v-for="u in c.uploads" :key="u.id" :href="u.url" target="_blank" class="text-[11px] underline">{{ u.original_name }}</a></div>
                      <div v-if="c.replies?.length" class="mt-2 ml-2 pl-3 border-l-2 border-slate-100 space-y-2">
                        <div v-for="r in c.replies" :key="r.id" class="bg-slate-50 rounded-xl px-3 py-2 flex gap-2">
                          <router-link :to="'/u/'+r.user_id"><img :src="`https://i.pravatar.cc/100?img=${(r.user_id%70)+1}`" class="w-5 h-5 rounded-full flex-none"/></router-link>
                           <div class="flex-1"><div class="text-xs flex items-center gap-1.5 flex-wrap"><router-link :to="'/u/'+r.user_id" class="font-semibold hover:underline">{{ r.author_nama }}</router-link><span class="text-[10px] px-1.5 py-0.5 rounded-full" :class="roleBadgeClass(r.author_role)">{{ roleBadgeLabel(r.author_role) }}</span> <span class="text-[11px] text-slate-400">· {{ timeAgo(r.created_at) }}</span><button @click="toggleLikeComment(r)" class="ml-2 text-xs">{{ r.is_liked?'♥':'♡' }} {{ r.likes }}</button></div><p class="text-xs text-slate-600 mt-1">{{ r.isi }}</p></div>
                        </div>
                      </div>
                      <div class="flex gap-1 mt-2 relative"><input v-model="c._replyInput" @input="e=>onReplyInput(e,c,th)" @keydown="e=>onReplyKeydown(e,c,th)" @keydown.enter="sendReply(c, th)" placeholder="Balas @mention..." class="flex-1 bg-slate-50 border rounded-full px-3 py-1.5 text-xs focus:bg-white outline-none"/><button @click="sendReply(c, th)" class="px-3 py-1.5 rounded-full bg-slate-900 text-white text-xs">Balas</button><div v-if="mentionOpen && mentionTarget?.kind==='reply' && mentionTarget?.c===c && mentionFiltered.length" class="absolute left-0 right-12 bottom-[36px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-32 overflow-auto z-10"><button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx? 'bg-slate-900 text-white':'hover:bg-slate-50'" class="w-full text-left px-3 py-1.5 text-xs flex items-center gap-1.5"><span class="w-5 h-5 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}</button></div></div>
                    <div v-if="c.id && pollCommentMap[c.id]" class="mt-2 p-2 bg-white border rounded-xl"><div class="text-xs font-semibold">{{ pollCommentMap[c.id].question }}</div><div class="mt-1 space-y-1"><label v-for="o in pollCommentMap[c.id].options" :key="o.id" class="flex items-center gap-2 text-xs"><input type="radio" :name="'poll-c-'+c.id" :checked="pollCommentMap[c.id].my_vote===o.id" @change="votePoll(pollCommentMap[c.id],o.id)"/>{{ o.label }} <span class="ml-auto">{{ o.percent }}%</span></label></div><div v-for="o in pollCommentMap[c.id].options" :key="'bar-'+o.id" class="h-1 bg-slate-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-[#1a5632]" :style="{width:o.percent+'%'}"></div></div></div>
                    </div>
                  </div>
                  <div class="flex gap-2 pt-2 border-t border-slate-100"><img :src="`https://i.pravatar.cc/100?img=${(auth.user?.id%70)+1}`" class="w-6 h-6 rounded-full"/><div class="flex-1 relative"><input v-model="th._newComment" @input="e=>onCommentInput(e,th)" @keydown="e=>onCommentKeydown(e,th)" @keydown.enter="sendComment(th)" placeholder="Tulis balasan... bisa @mention" class="w-full bg-white border border-slate-200 rounded-full pl-3 pr-12 py-2 text-xs focus:outline-none focus:border-[#1a5632]"/><button @click="sendComment(th)" class="absolute right-1 top-1 w-7 h-7 rounded-full bg-[#1a5632] text-white flex items-center justify-center text-xs">↑</button><div v-if="mentionOpen && mentionTarget?.kind==='comment' && mentionTarget?.th===th && mentionFiltered.length" class="absolute left-0 right-0 bottom-[40px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-32 overflow-auto z-10"><button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx? 'bg-slate-900 text-white':'hover:bg-slate-50'" class="w-full text-left px-3 py-1.5 text-xs flex items-center gap-1.5"><span class="w-5 h-5 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}</button></div></div></div>
                </div>
              </div>
            </div>
          </article>
          <div v-if="postsHasMore && !postsLoading && currentPosts.length" class="px-4 py-3 text-center border-t border-slate-100"><button @click="loadMorePosts()" class="text-xs font-semibold px-4 py-2 rounded-full border border-slate-200 hover:bg-slate-50">Muat lagi</button></div>
        </div>

        <div v-if="activeTab==='anggota'" class="p-4">
          <div class="relative"><input v-model="anggotaSearch" placeholder="Cari anggota..." class="w-full bg-slate-50 border border-slate-200 rounded-full pl-9 pr-4 py-2.5 text-xs focus:outline-none focus:border-[#1a5632]"/><svg class="absolute left-3 top-3 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="5"/><path d="M11 11l2 2"/></svg></div>
          <div class="mt-4 space-y-3 max-h-[320px] overflow-auto">
            <div v-for="a in filteredAnggota" :key="a.id" class="flex items-center gap-3"><router-link :to="'/u/'+a.user_id"><img :src="`https://i.pravatar.cc/100?img=${(a.user_id%70)+1}`" class="w-9 h-9 rounded-full"/></router-link><div><router-link :to="'/u/'+a.user_id" class="text-xs font-semibold hover:underline">{{ a.nama }}</router-link><div class="text-[11px]" :class="shouldHeartbeat && a.is_online? 'text-emerald-600':'text-slate-400'"><span v-if="shouldHeartbeat" class="inline-block w-2 h-2 rounded-full mr-1" :class="a.is_online?'bg-emerald-500':'bg-slate-300'"></span><span v-if="shouldHeartbeat">{{ a.is_online? 'Online':'Offline' }} · </span>{{ a.email }} · {{ a.status }}</div></div></div>
            <div v-if="!filteredAnggota.length" class="text-xs text-slate-400 text-center py-4">Tidak ada anggota</div>
          </div>
        </div>
      </section>

    </div>
  </main>
  <div v-else class="max-w-[480px] md:max-w-[1020px] mx-auto px-4 py-10 text-center text-sm text-slate-400">Ekskul tidak ditemukan</div>

  <div v-if="lightboxSrc" class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" @click.self="lightboxSrc=null"><img :src="lightboxSrc" class="max-w-full max-h-[85vh] rounded-2xl"/><button @click="lightboxSrc=null" class="absolute top-4 right-4 w-9 h-9 rounded-full bg-white text-slate-900 flex items-center justify-center">✕</button></div>

  <div class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex flex-col gap-2 items-center">
    <div v-for="t in toasts" :key="t.id" class="bg-slate-900 text-white text-xs font-medium px-4 py-2.5 rounded-full shadow-lg flex items-center gap-2"><span>{{ t.msg }}</span><button @click="toasts=toasts.filter(x=>x.id!==t.id)" class="opacity-70 hover:opacity-100">✕</button></div>
  </div>

  <Teleport to="body">
    <div v-if="showEdit" class="fixed inset-0 z-40">
      <div class="absolute inset-0 bg-[#0f172a]/40 backdrop-blur-[6px]" @click="closeEdit"></div>
      <div class="absolute inset-0 grid place-items-center p-4 overflow-auto">
        <div class="w-full max-w-[680px] max-h-[90vh] overflow-auto rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] flex flex-col" style="border-color:#e2e8f0">
          <div class="sticky top-0 bg-white px-6 py-4 border-b flex items-center justify-between border-slate-100"><h3 class="font-bold text-[18px]" style="font-family:'Plus Jakarta Sans',sans-serif">Edit Ekskul</h3><button @click="closeEdit" class="w-8 h-8 rounded-full border grid place-items-center hover:bg-slate-50 border-slate-200">✕</button></div>
          <form @submit.prevent="saveEdit" novalidate class="flex-1">
            <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
              <label class="block sm:col-span-1"><span class="text-[10px] tracking-widest font-semibold">NAMA *</span><input v-model="editForm.nama" required class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-slate-900 border-slate-200"/></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold">KUOTA *</span><input type="number" min="1" v-model.number="editForm.kuota" required class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>
              <label class="block sm:col-span-2"><span class="text-[10px] tracking-widest font-semibold">DESKRIPSI *</span><textarea rows="3" v-model="editForm.deskripsi" required class="mt-1 w-full px-3 py-3 rounded-xl border bg-white text-[13px] min-h-[80px] resize-none border-slate-200"></textarea></label>
              <label v-if="auth.user?.role==='admin'" class="block sm:col-span-2"><span class="text-[10px] tracking-widest font-semibold">PEMBINA</span><input v-model="pembinaSearch" @input="onPembinaInput" placeholder="Cari nama / NIP pembina..." class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/><div v-if="pembinaList.length" class="mt-2 rounded-xl border overflow-hidden border-slate-200 max-h-[140px] overflow-auto"><button v-for="p in pembinaList" :key="p.id" type="button" @click="selectPembina(p)" class="w-full text-left px-3 py-2 hover:bg-slate-50 flex justify-between items-center text-[13px] border-b last:border-0 border-slate-50"><span class="font-medium">{{ p.nama }}</span><span class="text-[11px] text-slate-400">{{ p.nip || p.email }}</span></button></div></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold">HARI</span><select v-model="editForm.hari" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"><option value="">—</option><option>Senin</option><option>Selasa</option><option>Rabu</option><option>Kamis</option><option>Jumat</option><option>Sabtu</option><option>Minggu</option></select></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold">LOKASI</span><input v-model="editForm.lokasi" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold">JAM MULAI</span><input type="time" v-model="editForm.jam_mulai" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>
              <label class="block"><span class="text-[10px] tracking-widest font-semibold">JAM SELESAI</span><input type="time" v-model="editForm.jam_selesai" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>
              <label class="flex items-center gap-2 sm:col-span-2 mt-1 cursor-pointer"><input type="checkbox" v-model="editForm.requires_approval" class="rounded"/> <span class="text-[12px] font-medium">Butuh approval</span></label>
              <div v-if="editJamError" class="sm:col-span-2 text-[11px] text-red-600">{{ editJamError }}</div>
              <div v-if="editMsg" class="sm:col-span-2 text-[11px]" :class="editOk?'text-emerald-600':'text-red-600'">{{ editMsg }}</div>
            </div>
            <div class="sticky bottom-0 bg-white px-6 py-4 border-t flex justify-end gap-2 border-slate-100"><button type="button" @click="closeEdit" class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium border-slate-200">Batal</button><button type="submit" :disabled="!!editJamError || !editForm.nama || !editForm.kuota" class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold hover:bg-black disabled:opacity-40 bg-slate-900">Simpan</button></div>
          </form>
        </div>
      </div>
    </div>
  </Teleport>
  <Teleport to="body">
    <div v-if="kickTarget" class="fixed inset-0 z-40"><div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="kickTarget=null"></div><div class="absolute inset-0 grid place-items-center p-4"><div class="w-full max-w-[440px] rounded-[20px] border bg-white p-6 shadow-xl border-slate-200"><h3 class="text-[16px] font-semibold">Konfirmasi Kick</h3><p class="text-[13px] mt-1 text-slate-500">Kick {{ kickTarget.nama }}? kuota {{ terisi }}/{{ e.kuota }} → {{ Math.max(0,terisi-1) }}/{{ e.kuota }}</p><div class="mt-4 flex justify-end gap-2"><button @click="kickTarget=null" class="px-5 py-2.5 rounded-full border bg-white text-[13px] border-slate-200">Batal</button><button @click="doKick" class="px-5 py-2.5 rounded-full text-white text-[13px] bg-red-600">Kick</button></div></div></div></div>
  </Teleport>
  <Teleport to="body">
    <div v-if="delSchedTarget" class="fixed inset-0 z-40"><div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="delSchedTarget=null"></div><div class="absolute inset-0 grid place-items-center p-4"><div class="w-full max-w-[440px] rounded-[20px] border bg-white p-6 shadow-xl border-slate-200"><h3 class="text-[16px] font-semibold">Hapus jadwal?</h3><p class="text-[13px] mt-1 text-slate-500">{{ delSchedTarget.tanggal }} {{ fmtJam(delSchedTarget.jam_mulai) }}–{{ fmtJam(delSchedTarget.jam_selesai) }}?</p><div class="mt-4 flex justify-end gap-2"><button @click="delSchedTarget=null" class="px-5 py-2.5 rounded-full border bg-white text-[13px] border-slate-200">Batal</button><button @click="doDeleteSched" class="px-5 py-2.5 rounded-full text-white text-[13px] bg-red-600">Hapus</button></div></div></div></div>
  </Teleport>
</div>
</template>
<script setup>
import { ref, computed, onMounted, watch, nextTick, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '../stores/auth.js'
import { api, apiUpload } from '../lib/api.js'
import QRCode from 'qrcode'
const route=useRoute(), router=useRouter(), auth=useAuth()
const e=ref(null), terisi=ref(0), scheds=ref([]), anggota=ref([]), summary=ref([]), attList=ref([]), attMap=ref({}), selSched=ref(''), qr=ref({}), fs=ref({tanggal:'',jam_mulai:'',jam_selesai:'',lokasi:'',tipe:'rutin'}), canvasRef=ref(null)
const loading=ref(true), showJadwalForm=ref(false), jadwalSectionRef=ref(null), highlightSchedId=ref(null), jadwalMsg=ref(''), jadwalOk=ref(false), showAllSched=ref(false)
const showEdit=ref(false), editForm=ref({nama:'',deskripsi:'',pembina_id:'',kuota:0,requires_approval:false,hari:'',jam_mulai:'',jam_selesai:'',lokasi:''}), editMsg=ref(''), editOk=ref(false), pembinaSearch=ref(''), pembinaList=ref([]), pembinaTimer=null
const kickTarget=ref(null), delSchedTarget=ref(null), toasts=ref([])
const notifOpen=ref(false), notifs=ref([]), notifUnread=ref(0)
const pengList=ref([]), pengInput=ref(''), pengPin=ref(true), pengMenuOpen=ref(null)
const activeTab=ref('diskusi'), composerInput=ref(''), composerJudul=ref(''), pendingUploads=ref([])
const lightboxSrc=ref(null), anggotaSearch=ref(''), daftarMsg=ref(''), daftarOk=ref(false)
const isRegistered=ref(false)
const posts=ref([]), postsLoading=ref(false), counts=ref({diskusi:0,tanya:0,postingan:0})
const tabPostsLoaded=ref({diskusi:false,tanya:false,postingan:false}), postsHasMore=ref(false), postsPage=ref({diskusi:1,tanya:1,postingan:1})
const myRekap=ref(null), myRekapLoading=ref(false), myRekapLoaded=ref(false), rekapLoading=ref(false), rekapLoaded=ref(false), anggotaFullLoaded=ref(false)
const composerEl=ref(null), mentionOpen=ref(false), mentionQuery=ref(''), mentionIdx=ref(0), mentionSelectedIds=ref([]), pollCommentMap=ref({})
const showPollComposer=ref(false), pollOptions=ref(['','']), pollsList=ref([]), pollsLoaded=ref(false)
const mentionCache=ref([])
// shared target: {kind:'composer'|'comment'|'reply', el, th, c}
const mentionTarget=ref(null)
const mentionFiltered=computed(()=> {
  const q=mentionQuery.value.toLowerCase()
  const base=(anggota.value.length? anggota.value : mentionCache.value).slice(0,20)
  if(!q) return base.slice(0,8)
  return base.filter(u=> u.nama.toLowerCase().includes(q)).slice(0,8)
})
async function fetchMentionCache(q=''){
  try{
    const j=await api('/ekskul/'+route.params.id+'/members?limit=20'+(q?'&q='+encodeURIComponent(q):''))
    const rows=j.data||[]
    if(!anggota.value.length) mentionCache.value=rows
    return rows
  }catch{ return [] }
}
function openMentionFor(val, pos, target){
  const before=val.slice(0,pos); const m=before.match(/@([A-Za-z0-9_ ]{0,20})$/)
  if(m){
    mentionQuery.value=m[1]||''; mentionOpen.value=true; mentionIdx.value=0; mentionTarget.value=target
    if(!anggota.value.length) fetchMentionCache(mentionQuery.value).catch(()=>{})
    else if(mentionQuery.value) fetchMentionCache(mentionQuery.value).then(rows=>{ if(rows.length) mentionCache.value=rows }).catch(()=>{})
  } else { mentionOpen.value=false; mentionTarget.value=null }
}
function onComposerInput(){
  const el=composerEl.value; if(!el) return
  openMentionFor(composerInput.value, el.selectionStart||0, {kind:'composer', el})
}
function onComposerKeydown(e){
  if(!mentionOpen.value || mentionTarget.value?.kind!=='composer') return
  if(e.key==='ArrowDown'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value+1)%mentionFiltered.value.length }
  else if(e.key==='ArrowUp'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value-1+mentionFiltered.value.length)%mentionFiltered.value.length }
  else if(e.key==='Enter' && mentionFiltered.value[mentionIdx.value]){ e.preventDefault(); selectMention(mentionFiltered.value[mentionIdx.value]) }
  else if(e.key==='Escape'){ mentionOpen.value=false; mentionTarget.value=null }
}
function onCommentInput(e, th){
  openMentionFor(th._newComment||'', e.target.selectionStart||0, {kind:'comment', el:e.target, th})
}
function onCommentKeydown(e, th){
  if(!mentionOpen.value || mentionTarget.value?.kind!=='comment' || mentionTarget.value?.th!==th) return
  if(e.key==='ArrowDown'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value+1)%mentionFiltered.value.length }
  else if(e.key==='ArrowUp'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value-1+mentionFiltered.value.length)%mentionFiltered.value.length }
  else if(e.key==='Enter' && mentionFiltered.value[mentionIdx.value]){ e.preventDefault(); selectMention(mentionFiltered.value[mentionIdx.value]) }
  else if(e.key==='Escape'){ mentionOpen.value=false; mentionTarget.value=null }
}
function onReplyInput(e, c, th){
  openMentionFor(c._replyInput||'', e.target.selectionStart||0, {kind:'reply', el:e.target, th, c})
}
function onReplyKeydown(e, c, th){
  if(!mentionOpen.value || mentionTarget.value?.kind!=='reply' || mentionTarget.value?.c!==c) return
  if(e.key==='ArrowDown'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value+1)%mentionFiltered.value.length }
  else if(e.key==='ArrowUp'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value-1+mentionFiltered.value.length)%mentionFiltered.value.length }
  else if(e.key==='Enter' && mentionFiltered.value[mentionIdx.value]){ e.preventDefault(); selectMention(mentionFiltered.value[mentionIdx.value]) }
  else if(e.key==='Escape'){ mentionOpen.value=false; mentionTarget.value=null }
}
function selectMention(u){
  const t=mentionTarget.value
  if(!t || t.kind==='composer'){
    const el=composerEl.value; if(!el) return
    const pos=el.selectionStart||0; const val=composerInput.value
    const before=val.slice(0,pos); const after=val.slice(pos)
    const m=before.match(/@([A-Za-z0-9_ ]{0,20})$/); if(!m) return
    const start=pos - m[0].length; const insert='@'+u.nama+' '
    composerInput.value=val.slice(0,start)+insert+after
    if(!mentionSelectedIds.value.includes(u.user_id)) mentionSelectedIds.value.push(u.user_id)
    mentionOpen.value=false; mentionTarget.value=null
    nextTick(()=>{ el.focus(); el.selectionStart=el.selectionEnd=start+insert.length })
    return
  }
  if(t.kind==='comment'){
    const el=t.el; const th=t.th; const pos=el.selectionStart||0; const val=th._newComment||''
    const m=val.slice(0,pos).match(/@([A-Za-z0-9_ ]{0,20})$/); if(!m) return
    const start=pos - m[0].length; const insert='@'+u.nama+' '
    th._newComment=val.slice(0,start)+insert+val.slice(pos)
    th._mentionIds = th._mentionIds||[]; if(!th._mentionIds.includes(u.user_id)) th._mentionIds.push(u.user_id)
    mentionOpen.value=false; mentionTarget.value=null
    nextTick(()=>{ el.focus(); el.selectionStart=el.selectionEnd=start+insert.length })
    return
  }
  if(t.kind==='reply'){
    const el=t.el; const c=t.c; const pos=el.selectionStart||0; const val=c._replyInput||''
    const m=val.slice(0,pos).match(/@([A-Za-z0-9_ ]{0,20})$/); if(!m) return
    const start=pos - m[0].length; const insert='@'+u.nama+' '
    c._replyInput=val.slice(0,start)+insert+val.slice(pos)
    c._replyMentionIds = c._replyMentionIds||[]; if(!c._replyMentionIds.includes(u.user_id)) c._replyMentionIds.push(u.user_id)
    mentionOpen.value=false; mentionTarget.value=null
    nextTick(()=>{ el.focus(); el.selectionStart=el.selectionEnd=start+insert.length })
  }
}
let heartbeatTimer=null, anggotaTimer=null
function startPresence(){
  if(!auth.user) return
  if(!shouldHeartbeat.value) return
  const beat=()=> api('/presence/heartbeat',{method:'POST',body:{}}).catch(()=>{})
  beat(); heartbeatTimer=setInterval(beat,60000)
  const refresh=async()=>{ try{ const a=await api('/ekskul/'+route.params.id+'/anggota'); anggota.value=a.data }catch{} }
  anggotaTimer=setInterval(refresh,30000)
}
function stopPresence(){ if(heartbeatTimer) clearInterval(heartbeatTimer); if(anggotaTimer) clearInterval(anggotaTimer); heartbeatTimer=null; anggotaTimer=null }
async function loadPolls(){ try{ const j=await api('/ekskul/'+route.params.id+'/polls'); pollsList.value=j.data||[]; pollsLoaded.value=true }catch{ pollsList.value=[] } }
async function fetchPollsIfEmpty(){ if(pollsLoaded.value) return; await loadPolls() }
async function votePoll(pl, optId){ try{ const j=await api('/polls/'+pl.id+'/vote',{method:'POST',body:{option_id:optId}}); pl.options=j.data.options; pl.total_votes=j.data.total; pl.my_vote=j.data.my_vote }catch(ex){ toast(ex.error?.message||'Gagal vote',false) } }
function roleBadgeLabel(r){
  if(r==='admin') return 'Admin'
  if(r==='kepsek') return 'Kepsek'
  if(r==='pembina') return 'Pembina'
  return 'Siswa'
}
function roleBadgeClass(r){
  if(r==='admin') return 'bg-amber-500 text-white'
  if(r==='kepsek') return 'bg-purple-600 text-white'
  if(r==='pembina') return 'bg-[#1a5632] text-white'
  return 'bg-slate-100 text-slate-600'
}
function fmtJam(v){ if(!v) return '—'; return String(v).slice(0,5) }
function timeAgo(s){ if(!s) return 'baru saja'; const d=new Date(s), diff=(Date.now()-d.getTime())/1000; if(diff<60) return 'baru saja'; if(diff<3600) return Math.floor(diff/60)+' menit lalu'; if(diff<86400) return Math.floor(diff/3600)+' jam lalu'; return Math.floor(diff/86400)+' hari lalu' }
const roleLabel=computed(()=> {
  const r=auth.user?.role
  if(r==='pembina') return 'Pembina - '+(auth.user?.nama||'Pak Andi')
  if(r==='admin') return 'Admin'
  if(r==='kepsek') return 'Kepsek'
  if(r==='siswa') return 'Siswa - Anggota'
  return 'Guest'
})
const isPembina=computed(()=> auth.user && (auth.user.role==='admin' || (auth.user.role==='pembina' && auth.user.id===e.value?.pembina_id)))
const isAdmin=computed(()=> auth.user?.role==='admin')
const shouldHeartbeat=computed(()=> isPembina.value || isAdmin.value)
const isAnggota=computed(()=> anggota.value.some(a=> String(a.user_id)===String(auth.user?.id)) || isRegistered.value)
const isEditAllowed=computed(()=> isPembina.value)
const isPengumumanAllowed=computed(()=> isPembina.value)
const canPost=computed(()=> {
  if(!auth.user) return false
  if(isPembina.value) return true
  // anggota check: anggota array contains user_id
  return anggota.value.some(a=> String(a.user_id)===String(auth.user.id))
})
const kuotaPercent=computed(()=> e.value?.kuota ? Math.round(terisi.value / e.value.kuota * 100) : 0)
const displayedScheds=computed(()=> showAllSched.value ? scheds.value : scheds.value.slice(0,3))
const filteredAnggota=computed(()=> {
  const q=anggotaSearch.value.trim().toLowerCase()
  if(!q) return anggota.value
  return anggota.value.filter(a=> a.nama.toLowerCase().includes(q) || a.email.toLowerCase().includes(q))
})
const composerPlaceholder=computed(()=> {
  const m={diskusi:'Tulis pertanyaan atau mulai diskusi... (bisa @mention)', tanya:'Ajukan pertanyaan...', postingan:'Tulis caption untuk foto...'}
  return m[activeTab.value]||m.diskusi
})
const currentPosts=computed(()=> posts.value.filter(p=> p.tipe===activeTab.value))
const jamError=computed(()=>{
  const a=fs.value.jam_mulai, b=fs.value.jam_selesai
  if(a && b){ const av=new Date('2000-01-01T'+a), bv=new Date('2000-01-01T'+b); if(av>=bv) return 'Jam selesai harus lebih besar dari jam mulai' }
  return ''
})
const isJadwalValid=computed(()=> fs.value.tanggal && fs.value.jam_mulai && fs.value.jam_selesai && !jamError.value)
const editJamError=computed(()=>{
  const a=editForm.value.jam_mulai, b=editForm.value.jam_selesai
  if(a && b){ const av=new Date('2000-01-01T'+a), bv=new Date('2000-01-01T'+b); if(av>=bv) return 'Jam selesai harus lebih besar dari jam mulai' }
  return ''
})
function toast(msg, ok=true){ const id=Date.now()+Math.random(); toasts.value.push({id, msg, ok}); setTimeout(()=>{ toasts.value=toasts.value.filter(t=>t.id!==id) }, 2600) }
function openLightbox(src){ lightboxSrc.value=src }
function togglePengMenu(id){ pengMenuOpen.value = pengMenuOpen.value===id ? null : id }
function copyPostLink(th){
  const prefix=th.tipe==='diskusi'?'d':th.tipe==='tanya'?'t':'p'
  const url=location.origin+`/ekskul/${route.params.id}/${prefix}/${th.id}`
  navigator.clipboard?.writeText(url).catch(()=>{})
  toast('Link disalin: '+url)
}

async function load(){
  loading.value=true
  try{
    const id=route.params.id
    const results=await Promise.all([
      api('/ekskul/'+id),
      api('/ekskul/'+id+'/schedules'),
      api('/ekskul/'+id+'/pengumuman').catch(()=>({data:[]})),
      auth.user ? api('/ekskul/'+id+'/anggota?limit=5').catch(()=>({data:[]})): Promise.resolve({data:[]})
    ])
    const j=results[0], s=results[1], p=results[2], a=results[3]
    e.value=j.data; terisi.value=j.data.terisi ?? 0
    scheds.value=s.data||[]
    pengList.value=p.data||[]
    anggota.value=a.data||[]
    if(auth.user?.role==='siswa'){
      const found=anggota.value.find(x=> String(x.user_id)===String(auth.user.id))
      if(found && ['diterima','menunggu'].includes(found.status)) isRegistered.value=true
      else {
        try{ const mr=await api('/me/registrations'); const f=mr.data.find(r=> String(r.ekskul_id)===String(id)); isRegistered.value=!!f && ['diterima','menunggu'].includes(f.status) }catch{ isRegistered.value=!!found }
      }
    }
    startPresence()
    // auto-load Rekap Saya for anggota (non-pembina) so it shows immediately after 4-fast load
    if(isAnggota.value && !isPembina.value) fetchMyRekapIfEmpty().catch(()=>{})
  } finally { loading.value=false }
}
async function fetchPosts(tipe='diskusi', page=1, append=false){
  if(!auth.user) return
  if(!append) postsLoading.value=true
  try{
    const j=await api(`/ekskul/${route.params.id}/posts?tipe=${tipe}&limit=10&page=${page}`)
    const mapped=(j.data||[]).map(p=> ({...p, _expanded:false, _comments:[], _newComment:'', _replyInput:'', _mentionIds:[]}))
    if(append){
      const other=posts.value.filter(p=> p.tipe!==tipe)
      const existing=posts.value.filter(p=> p.tipe===tipe)
      posts.value=[...other, ...existing, ...mapped]
    } else {
      const other=posts.value.filter(p=> p.tipe!==tipe)
      posts.value=[...other, ...mapped]
    }
    postsHasMore.value=(j.meta?.pages??1) > page
    postsPage.value[tipe]=page
    tabPostsLoaded.value[tipe]=true
    const c={diskusi:0,tanya:0,postingan:0}
    posts.value.forEach(p=>{ if(c[p.tipe]!==undefined) c[p.tipe]++ })
    // estimate counts from meta total if first page
    counts.value=c
  }catch{} finally { postsLoading.value=false }
}
async function fetchPostsIfEmpty(tipe){
  if(tabPostsLoaded.value[tipe]) return
  await fetchPosts(tipe,1,false)
}
async function loadMorePosts(){
  const t=activeTab.value
  if(t==='anggota' || !postsHasMore.value) return
  const next=(postsPage.value[t]||1)+1
  await fetchPosts(t,next,true)
}
async function loadAllPosts(){
  // legacy alias for compat (kirimPost refresh all)
  await fetchPosts(activeTab.value==='anggota'?'diskusi':activeTab.value,1,false)
}
async function fetchRekapIfEmpty(){
  if(rekapLoaded.value) return
  if(!isPembina.value) return
  rekapLoading.value=true
  try{
    let sm; try{ sm=await api('/ekskul/'+route.params.id+'/rekap') }catch{ sm=await api('/attendance/summary/'+route.params.id) }
    summary.value=sm.data||[]; rekapLoaded.value=true
  }catch{ summary.value=[] } finally { rekapLoading.value=false }
}
async function fetchMyRekapIfEmpty(){
  if(myRekapLoaded.value) return
  if(!isAnggota.value || isPembina.value) return
  myRekapLoading.value=true
  try{
    const j=await api('/me/attendance-summary')
    const rows=j.data||[]
    const found=rows.find(r=> String(r.ekskul_id)===String(route.params.id))
    myRekap.value=found||null; myRekapLoaded.value=true
  }catch{ myRekap.value=null; myRekapLoaded.value=true } finally { myRekapLoading.value=false }
}
async function fetchAnggotaFull(){
  if(anggotaFullLoaded.value) return
  try{
    const j=await api('/ekskul/'+route.params.id+'/anggota')
    anggota.value=j.data||[]; anggotaFullLoaded.value=true
  }catch{}
}
async function openAnggotaTab(){
  activeTab.value='anggota'
  await fetchAnggotaFull()
}
async function switchTab(t){
  activeTab.value=t
  if(t==='anggota'){ await fetchAnggotaFull() }
  else if(['diskusi','tanya','postingan'].includes(t)){
    if(!tabPostsLoaded.value[t]) await fetchPostsIfEmpty(t)
    if(t==='tanya' && !pollsLoaded.value) await fetchPollsIfEmpty()
  }
}
async function loadNotifs(){
  if(!auth.user) return
  try{ const j=await api('/notifications?limit=20'); notifs.value=j.data||[]; notifUnread.value=j.meta?.unread||0 }catch{ notifs.value=[] }
}
async function markRead(n){ await api('/notifications/'+n.id+'/read',{method:'POST',body:{}}); n.is_read=1; notifUnread.value=Math.max(0,notifUnread.value-1) }
async function markAllRead(){ await api('/notifications/read-all',{method:'POST',body:{}}); notifs.value.forEach(n=>n.is_read=1); notifUnread.value=0 }
function onFileChange(e){
  const files=[...e.target.files]
  files.forEach(f=>{
    if(f.size>5*1024*1024){ toast(f.name+' >5MB ditolak', false); return }
    const isImage=f.type.startsWith('image/')
    const preview=isImage?URL.createObjectURL(f):null
    pendingUploads.value.push({file:f, name:f.name, size:f.size, isImage, preview})
  })
  e.target.value=''
}
async function kirimPost(){
  const isi=composerInput.value.trim()
  if(!isi && !pendingUploads.value.length) return
  if(!canPost.value){ toast('Hanya anggota bisa posting', false); return }
  const tipe=activeTab.value==='anggota'?'diskusi':activeTab.value
  const judul=tipe==='tanya'?composerJudul.value.trim():''
  let uploadIds=[]
  if(pendingUploads.value.length){
    const files=pendingUploads.value.map(p=>p.file)
    try{
      const fd=new FormData(); files.forEach(f=> fd.append('files[]', f))
      const j=await api('/ekskul/'+route.params.id+'/upload',{method:'POST', body:fd})
      uploadIds=(j.data||[]).map(x=>x.id)
    }catch(ex){ toast(ex.error?.message||'Upload gagal', false); return }
  }
  try{
    await api('/ekskul/'+route.params.id+'/posts',{method:'POST',body:{tipe, judul:judul||null, isi: isi||'(file)', upload_ids:uploadIds, mention_ids: mentionSelectedIds.value}})
    // if tanya + poll composer, create poll after post
    if(tipe==='tanya' && showPollComposer.value){
      const opts=pollOptions.value.map(s=>s.trim()).filter(Boolean)
      if(opts.length>=2){
        try{ await api('/ekskul/'+route.params.id+'/polls',{method:'POST',body:{question: judul||isi, options: opts}}); await loadPolls() }catch(ex){ toast('Poll: '+(ex.error?.message||'gagal'),false) }
        pollOptions.value=['','']; showPollComposer.value=false
      }
    }
    composerInput.value=''; composerJudul.value=''; mentionSelectedIds.value=[]; mentionOpen.value=false
    pendingUploads.value.forEach(p=>{ if(p.preview) try{URL.revokeObjectURL(p.preview)}catch{} }); pendingUploads.value=[]
    toast('Dipost ✓')
    await loadAllPosts()
  }catch(ex){ toast(ex.error?.message||'Gagal posting', false) }
}
async function toggleLikePost(th){
  try{
    const j=await api('/ekskul/'+route.params.id+'/posts/'+th.id+'/like',{method:'POST',body:{}})
    th.is_liked=j.data.liked; th.likes=j.data.likes
  }catch(ex){ toast(ex.error?.message,false) }
}
async function deletePost(th){
  if(!confirm('Hapus postingan?')) return
  await api('/ekskul/'+route.params.id+'/posts/'+th.id,{method:'DELETE',body:{}})
  posts.value=posts.value.filter(p=>p.id!==th.id)
  toast('Post dihapus')
}
async function reportPost(th){
  try{
    await api(`/ekskul/${route.params.id}/posts/${th.id}/report`,{method:'POST',body:{reason:'dilaporkan'}})
    toast('Dilaporkan ke pembina')
  }catch(ex){
    if(ex.status===409) toast('Sudah dilaporkan', false)
    else toast(ex.error?.message||'Gagal laporkan', false)
  }
}
async function expandPost(th){
  th._expanded=!th._expanded
  if(th._expanded){
    try{
      const j=await api('/ekskul/'+route.params.id+'/posts/'+th.id)
      th._comments=(j.data.comments||[]).map(c=> ({...c, _replyInput:'', _replyMentionIds:[]}))
    }catch{ th._comments=[] }
  }
}
async function sendComment(th){
  const v=(th._newComment||'').trim(); if(!v) return
  const mids=th._mentionIds||[]
  await api('/ekskul/'+route.params.id+'/posts/'+th.id+'/comments',{method:'POST',body:{isi:v, mention_ids:mids}})
  th._newComment=''; th._mentionIds=[]
  const j=await api('/ekskul/'+route.params.id+'/posts/'+th.id)
  th._comments=(j.data.comments||[]).map(c=> ({...c, _replyInput:'', _replyMentionIds:[]}))
  th.comments_count=(j.data.comments_count||th._comments.length)
  toast('Balasan terkirim')
}
async function sendReply(c, th){
  const v=(c._replyInput||'').trim(); if(!v) return
  const mids=c._replyMentionIds||[]
  await api('/ekskul/'+route.params.id+'/posts/'+th.id+'/comments',{method:'POST',body:{isi:v, parent_id:c.id, mention_ids:mids}})
  c._replyInput=''; c._replyMentionIds=[]
  const j=await api('/ekskul/'+route.params.id+'/posts/'+th.id)
  th._comments=(j.data.comments||[]).map(x=> ({...x, _replyInput:'', _replyMentionIds:[]}))
  th.comments_count=j.data.comments_count||th._comments.length
  toast('Balasan terkirim')
}
async function toggleLikeComment(c){
  const j=await api('/comments/'+c.id+'/like',{method:'POST',body:{}})
  c.is_liked=j.data.liked; c.likes=j.data.likes
}
async function deleteComment(c, th){
  await api('/comments/'+c.id,{method:'DELETE',body:{}})
  const j=await api('/ekskul/'+route.params.id+'/posts/'+th.id)
  th._comments=(j.data.comments||[]).map(x=> ({...x, _replyInput:'', _replyMentionIds:[]}))
}
async function handleDaftar(){
  daftarMsg.value=''; daftarOk.value=false
  try{ const j=await api('/ekskul/'+route.params.id+'/daftar',{method:'POST',body:{}}); daftarOk.value=true; daftarMsg.value='Berhasil: '+j.data.status; isRegistered.value=true; toast('Terdaftar'); await load() }catch(ex){ daftarOk.value=false; daftarMsg.value=ex.error?.message || ex.message; toast(daftarMsg.value,false); if((daftarMsg.value||'').includes('Sudah terdaftar')) isRegistered.value=true }
}
async function addSched(){
  if(!isJadwalValid.value) return
  jadwalMsg.value=''; jadwalOk.value=false
  try{
    await api('/ekskul/'+route.params.id+'/schedules',{method:'POST',body:fs.value});
    jadwalOk.value=true; jadwalMsg.value='Jadwal ditambahkan'
    const prevLen=scheds.value.length
    fs.value={tanggal:'',jam_mulai:'',jam_selesai:'',lokasi:'',tipe:'rutin'}
    await load()
    toast('Jadwal ditambahkan', true)
    await nextTick()
    if(scheds.value.length > prevLen){
      const last=scheds.value[scheds.value.length-1]
      highlightSchedId.value=last.id
      document.getElementById('sched-'+last.id)?.scrollIntoView({behavior:'smooth', block:'center'})
      setTimeout(()=> highlightSchedId.value=null, 2500)
    }
    jadwalSectionRef.value?.scrollIntoView({behavior:'smooth'})
  }catch(ex){ jadwalOk.value=false; jadwalMsg.value=ex.error?.message || 'Gagal tambah jadwal'; toast(jadwalMsg.value,false) }
}
function confirmDeleteSched(s){ delSchedTarget.value=s }
async function doDeleteSched(){
  const id=delSchedTarget.value.id
  delSchedTarget.value=null
  try{ await api('/schedules/'+id,{method:'DELETE',body:{}}); toast('Jadwal dihapus', true); await load(); if(String(selSched.value)===String(id)) selSched.value='' }catch(ex){ toast(ex.error?.message||'Gagal hapus', false) }
}
async function genQR(sid){ try{ const j=await api('/attendance/qr-generate',{method:'POST',body:{schedule_id:sid}}); qr.value=j.data; await nextTick(); if(canvasRef.value) QRCode.toCanvas(canvasRef.value, j.data.token, {width:220}) }catch(ex){ toast(ex.error?.message||'Gagal generate QR', false) } }
function openKick(a){ kickTarget.value=a }
async function doKick(){
  const uid=kickTarget.value.user_id, nama=kickTarget.value.nama
  const before=terisi.value
  kickTarget.value=null
  try{
    const j=await api('/ekskul/'+route.params.id+'/kick/'+uid,{method:'POST',body:{}})
    const after=j.data?.terisi ?? Math.max(0, before-1)
    const kuota=j.data?.kuota ?? e.value.kuota
    toast(`Kick ${nama}? kuota ${before}/${kuota}→${after}/${kuota}`, true)
    await load()
  }catch(ex){ toast(ex.error?.message||'Gagal kick', false) }
}
async function acc(rid,action){ try{ await api('/registrations/'+rid+'/status',{method:'POST',body:{action}}); toast(action==='approve'?'ACC berhasil':'Ditolak', true); await load() }catch(ex){ toast(ex.error?.message,false) } }
async function markAtt(uid,status){ if(!selSched.value) return; if(!status) return; try{ await api('/attendance/mark',{method:'POST',body:{schedule_id:parseInt(selSched.value),user_id:uid,status}}); await refreshAtt(); await loadSummaryOnly(); toast('Absensi diupdate', true) }catch(ex){ toast(ex.error?.message,false) } }
async function refreshAtt(){ if(!selSched.value){ attList.value=[]; attMap.value={}; return } try{ const j=await api('/attendance/list/'+selSched.value); attList.value=j.data; const m={}; j.data.forEach(r=>m[r.user_id]=r.status); attMap.value=m }catch{ attList.value=[]; attMap.value={} } }
async function loadSummaryOnly(){ try{ let sm; try{ sm=await api('/ekskul/'+route.params.id+'/rekap') }catch{ sm=await api('/attendance/summary/'+route.params.id) } summary.value=sm.data }catch{} }
watch(selSched, refreshAtt)
function openEdit(){
  editForm.value={ nama:e.value.nama||'', deskripsi:e.value.deskripsi||'', pembina_id:e.value.pembina_id||'', kuota:e.value.kuota||0, requires_approval: !!e.value.requires_approval, hari:e.value.hari||'', jam_mulai: (e.value.jam_mulai||'').slice(0,5), jam_selesai: (e.value.jam_selesai||'').slice(0,5), lokasi:e.value.lokasi||'' }
  pembinaSearch.value=''; pembinaList.value=[]; editMsg.value=''; showEdit.value=true
}
function closeEdit(){ showEdit.value=false; editMsg.value='' }
async function onPembinaInput(){
  clearTimeout(pembinaTimer)
  pembinaTimer=setTimeout(async()=>{
    const q=pembinaSearch.value.trim()
    if(!q){ pembinaList.value=[]; return }
    try{ const j=await api('/users?role=pembina&search='+encodeURIComponent(q)+'&limit=20'); pembinaList.value=j.data }catch{ pembinaList.value=[] }
  }, 300)
}
function selectPembina(p){ editForm.value.pembina_id=p.id; pembinaSearch.value=p.nama+' ('+(p.nip||p.email)+')'; pembinaList.value=[] }
async function saveEdit(){
  if(editJamError.value) return
  editMsg.value=''; editOk.value=false
  try{
    const body={ ...editForm.value }
    if(!body.hari) body.hari=null
    if(!body.lokasi) body.lokasi=null
    if(!body.jam_mulai) body.jam_mulai=null
    if(!body.jam_selesai) body.jam_selesai=null
    await api('/ekskul/'+route.params.id,{method:'PATCH',body})
    editOk.value=true; editMsg.value='Tersimpan'
    toast('Ekskul diperbarui', true)
    showEdit.value=false
    await load()
  }catch(ex){ editOk.value=false; editMsg.value=ex.error?.message || 'Gagal simpan'; toast(editMsg.value,false) }
}
async function postPengumuman(){
  const v=pengInput.value.trim(); if(!v) return
  if(!isPengumumanAllowed.value){ toast('Hanya Pembina yang bisa posting', false); return }
  try{
    const j=await api('/ekskul/'+route.params.id+'/pengumuman',{method:'POST',body:{isi:v, is_pinned: pengPin.value?1:0}})
    pengList.value.unshift(j.data)
    pengInput.value=''
    toast('Pengumuman dipost')
  }catch(ex){ toast(ex.error?.message||'Gagal posting', false) }
}
async function deletePeng(p){
  try{ await api('/ekskul/'+route.params.id+'/pengumuman/'+p.id,{method:'DELETE',body:{}}); pengList.value=pengList.value.filter(x=>x.id!==p.id); toast('Pengumuman dihapus') }catch(ex){ toast(ex.error?.message||'Gagal hapus', false) }
}
async function togglePin(p){
  try{ const j=await api('/ekskul/'+route.params.id+'/pengumuman/'+p.id+'/pin',{method:'POST',body:{}}); p.is_pinned=j.data.is_pinned;
    pengList.value.sort((a,b)=> (b.is_pinned - a.is_pinned) || new Date(b.created_at)-new Date(a.created_at)); toast(p.is_pinned?'Di-pin ke atas':'Lepas pin') }catch(ex){ toast(ex.error?.message||'Gagal', false) }
}
function onDocClick(e){
  if(!e.target.closest('button') && !e.target.closest('.relative')) pengMenuOpen.value=null
}
onMounted(()=>{ load(); document.addEventListener('click', onDocClick); setInterval(loadNotifs, 30000) })
onBeforeUnmount(()=>{ document.removeEventListener('click', onDocClick); stopPresence() })
watch(notifOpen, (v)=>{ if(v) setTimeout(()=>{ const h=(e2)=>{ if(!e2.target.closest('.relative')){ notifOpen.value=false; document.removeEventListener('click', h) } }; setTimeout(()=>document.addEventListener('click', h), 50) }) })
</script>
<style scoped>
.detail-root{ --brand:#1a5632; }
</style>
