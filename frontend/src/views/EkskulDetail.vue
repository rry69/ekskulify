<template>

<div class="kat-page">

<div class="kat-inner">



 <div v-if="loading" class="kat-wrap space-y-4">

 <div class="h-6 w-32 bg-white rounded-full animate-pulse border border-slate-200"></div>

 <div class="bg-white rounded-[20px] border border-slate-200 p-5 space-y-3 animate-pulse"><div class="h-6 w-40 bg-slate-100 rounded"></div><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-2 w-full bg-slate-100 rounded-full"></div></div>

 </div>



 <div v-else-if="loadError" class="kat-wrap">

 <div class="bg-white rounded-[20px] border border-slate-200 p-8 text-center">

 <p class="text-[15px] font-semibold">{{ loadError }}</p>

 <p class="text-xs text-slate-400 mt-1">Ekskul tidak ditemukan atau di luar akses Anda.</p>

  <router-link to="/ekskul" class="inline-block mt-4 text-xs font-semibold px-4 py-2.5 rounded-full bg-[#2F3E46] text-white">Kembali ke daftar</router-link>

 </div>

 </div>



 <main v-else-if="e" class="kat-wrap kat-cols">

 <div class="space-y-6 min-w-0">

 <section class="overflow-hidden">

  <div v-if="e.cover_url" class="relative w-full bg-slate-50 overflow-hidden rounded-2xl border border-slate-200/70">

  <img :src="e.cover_url" alt="Cover ekskul" class="w-full h-auto max-h-[420px] sm:max-h-[460px] object-contain object-center bg-slate-50 block" />

  </div>

 <div class="pt-4">

 <div class="flex items-start justify-between gap-3">

 <h2 class="kat-h2 min-w-0 flex-1">{{ e.nama }}</h2>

  <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-wider uppercase px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200/70 shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>{{ (e.status||'approved').toUpperCase() }}</span>

 </div>

 <p class="text-[13.5px] text-slate-600 leading-relaxed mt-2 break-words">{{ e.deskripsi || 'Belum ada deskripsi.' }}</p>

 <div class="flex items-center gap-1.5 mt-3 flex-wrap text-xs text-slate-500">

  <span v-if="e.hari" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white border border-slate-200/70">- {{ e.hari }} {{ fmtJam(e.jam_mulai) }}{{ e.jam_selesai ? '-'+fmtJam(e.jam_selesai) : '' }}</span>

  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white border border-slate-200/70">{{ e.lokasi || 'Lapangan Utama' }}</span>

  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white border border-slate-200/70">{{ e.pembina_nama || '-' }}</span>

 <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white border border-slate-200/70">{{ e.requires_approval ? 'Butuh approval' : 'Otomatis diterima' }}</span>

 </div>

 <div class="mt-4 flex flex-col sm:flex-row sm:items-center gap-4">

  <div class="flex-1 min-w-0 sm:max-w-md"><div class="h-1.5 bg-slate-200/70 rounded-full overflow-hidden"><div class="h-full bg-[#4A7875] rounded-full transition-all" :style="{width: kuotaPercent+'%'}"></div></div><div class="flex justify-between text-xs mt-1.5"><span class="font-semibold text-[#4A7875]">{{ terisi }}/{{ e.kuota }} ({{ kuotaPercent }}%)</span><span class="text-slate-400">{{ terisi>=e.kuota ? 'Kuota penuh' : 'Kuota tersedia' }}</span></div></div>

  <div class="flex items-center gap-2 flex-wrap sm:ml-auto">

   <button v-if="auth.user?.role==='siswa'" @click="handleDaftar" :disabled="isRegistered" :class="isRegistered ? 'bg-emerald-600' : 'kat-btn-p'" class="kat-btn-p">{{ isRegistered ? 'Terdaftar' : 'Daftar' }}</button>

  <button v-if="isEditAllowed" @click="openEdit" class="text-xs font-semibold px-3.5 py-2.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50">Edit Ekskul</button>

 </div>

 </div>

  <p v-if="daftarMsg" class="text-xs mt-2" :class="daftarOk?'text-emerald-600':'text-red-600'" role="status">{{ daftarMsg }}</p>

 </div>

 </section>



 <!-- Tabbar top-level: Ringkasan | Jadwal | Anggota | Absensi* | Komunitas (*pembina only) -->

  <nav class="sticky top-[56px] z-20 -mx-1 px-1 py-2 bg-[#F8FAF9]/95 backdrop-blur border-b border-slate-200/70 flex flex-wrap gap-1.5" role="tablist" aria-label="Navigasi detail ekskul">

  <button v-for="t in allowedTabs" :key="t" @click="setDetailTab(t)" role="tab" :aria-selected="detailTab===t" :class="detailTab===t ? 'bg-[#2F3E46] text-white' : 'text-slate-500 hover:bg-slate-100'" class="whitespace-nowrap px-3 sm:px-4 py-2 rounded-full text-xs font-semibold min-h-[36px]">{{ tabLabels[t] }}<span v-if="t==='jadwal' && scheds.length" class="ml-1.5 px-1.5 py-0.5 rounded-full text-[11px]" :class="detailTab===t?'bg-white/20':'bg-slate-200/70 text-slate-600'">{{ scheds.length }}</span><span v-if="t==='anggota' && anggota.length" class="ml-1.5 px-1.5 py-0.5 rounded-full text-[11px]" :class="detailTab===t?'bg-white/20':'bg-slate-200/70 text-slate-600'">{{ anggota.length }}</span></button>

 </nav>



  <section v-show="detailTab==='overview'" role="tabpanel" aria-label="Ringkasan">

  <div v-if="scheds.length" class="rounded-xl border border-slate-200/70 bg-white divide-y divide-slate-100 overflow-hidden">

   <div class="px-4 py-2.5 flex items-center justify-between"><span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Jadwal berikutnya</span><button @click="setDetailTab('jadwal')" class="text-xs font-semibold px-3.5 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50 text-slate-700">Semua</button></div>

  <div v-for="s in scheds.slice(0,3)" :key="'ov-'+s.id" class="px-4 py-2.5 flex items-center gap-3 min-w-0">

 <div class="min-w-0 flex-1"><div class="text-[13px] font-semibold truncate">{{ s.tanggal }}</div><div class="text-[11px] text-slate-500 truncate">{{ fmtJam(s.jam_mulai) }}- {{ fmtJam(s.jam_selesai) }} - {{ s.lokasi || '-' }}</div></div>

  <span class="text-[11px] px-2 py-0.5 rounded-full shrink-0" :class="s.tipe==='tambahan'?'bg-emerald-50 text-emerald-700 border border-emerald-100':'bg-slate-100 text-slate-600'">{{ s.tipe }}</span>

  </div>

  </div>

  <div v-if="pengList.length" class="mt-3 rounded-xl border border-amber-200/70 bg-amber-50/50 divide-y divide-amber-100 overflow-hidden lg:hidden">

   <div class="px-4 py-2.5 flex items-center justify-between"><span class="text-xs font-semibold uppercase tracking-wider text-amber-700"> Pengumuman terbaru</span><button @click="setDetailTab('komunitas'); switchTab('pengumuman')" class="text-xs font-semibold px-3.5 py-1.5 rounded-full border border-amber-200 bg-white hover:bg-amber-50 text-amber-700">Semua</button></div>

   <div v-for="p in pengList.filter(x=>x.is_pinned).concat(pengList.filter(x=>!x.is_pinned)).slice(0,2)" :key="'ovp-'+p.id" class="px-4 py-2.5"><p class="text-[13px] leading-relaxed break-words line-clamp-2">{{ p.isi }}</p><div class="flex items-center gap-2 mt-1"><span class="text-[11px] text-slate-400">{{ p.creator_nama || 'Pembina' }} - {{ timeAgo(p.created_at) }}</span><span v-if="p.has_gambar" class="text-[11px] text-amber-600"> -  ada gambar</span><button @click="openPengView(p)" class="ml-auto text-[11px] font-semibold px-3 py-1 rounded-full border border-amber-200 bg-white hover:bg-amber-50 text-amber-700 shrink-0">Buka</button></div></div>

  </div>

  </section>



  <section v-show="detailTab==='jadwal'" ref="jadwalSectionRef" role="tabpanel" aria-label="Jadwal">

 <div class="flex items-center justify-between pb-3">

 <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Jadwal yang akan datang</h3><span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ scheds.length }} sesi</span>

 </div>

 <div v-if="scheds.length" class="hidden md:block overflow-x-auto bg-white rounded-xl border border-slate-200/70">

  <table class="w-full text-[12.5px]"><thead class="text-[11px] tracking-widest text-slate-400 font-semibold bg-slate-50/60"><tr class="border-b border-slate-100"><th class="text-left px-5 py-3">TANGGAL</th><th class="text-left px-3 py-3">JAM</th><th class="text-left px-3 py-3">LOKASI</th><th class="text-left px-5 py-3">TIPE</th><th v-if="isPembina" class="text-right px-5 py-3">AKSI</th></tr></thead>

  <tbody class="divide-y divide-slate-50">
  <template v-for="s in displayedScheds" :key="s.id">
  <tr v-if="jadwalEditId===s.id" :class="highlightSchedId===s.id ? 'bg-amber-50' : 'bg-amber-50/40'" class="align-top">
    <td class="px-3 py-2"><input type="date" v-model="jadwalEditForm.tanggal" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[12px]"/></td>
    <td class="px-3 py-2"><div class="flex items-center gap-1"><input type="time" v-model="jadwalEditForm.jam_mulai" class="w-[88px] px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[12px]"/><span class="text-slate-400">-</span><input type="time" v-model="jadwalEditForm.jam_selesai" class="w-[88px] px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[12px]"/></div></td>
    <td class="px-3 py-2"><input v-model="jadwalEditForm.lokasi" placeholder="Lapangan" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[12px]"/></td>
    <td class="px-3 py-2"><select v-model="jadwalEditForm.tipe" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[12px]"><option value="rutin">rutin</option><option value="tambahan">tambahan</option></select></td>
    <td v-if="isPembina" class="px-3 py-2 text-right"><div class="flex gap-1 justify-end"><button @click="saveEditSched" class="px-3 py-1 rounded-full bg-slate-900 text-white text-[11px] font-semibold">Simpan</button><button @click="cancelEditSched" class="px-3 py-1 rounded-full border border-slate-200 bg-white text-[11px]">Batal</button></div><div v-if="jadwalEditMsg" class="text-[11px] text-red-600 mt-1 text-right">{{ jadwalEditMsg }}</div></td>
  </tr>
  <tr v-else :class="highlightSchedId===s.id ? 'bg-amber-50' : ''"><td class="px-5 py-3 whitespace-nowrap">{{ s.tanggal }}</td><td class="px-3 py-3 whitespace-nowrap">{{ fmtJam(s.jam_mulai) }} - {{ fmtJam(s.jam_selesai) }}</td><td class="px-3 py-3">{{ s.lokasi || '-' }}</td><td class="px-5 py-3"><span :class="s.tipe==='tambahan' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'kat-ink'" class="text-[11px] px-2 py-1 rounded-full">{{ s.tipe }}</span></td><td v-if="isPembina" class="px-5 py-3 text-right"><div class="flex gap-1 justify-end"><button @click="startEditSched(s)" class="text-[11px] px-2.5 py-1 rounded-full border border-slate-200 text-slate-600 hover:bg-slate-50">Edit</button><button @click="confirmDeleteSched(s)" class="text-[11px] px-2 py-1 rounded-full border border-red-200 text-red-600 hover:bg-red-50">Hapus</button></div></td></tr>
  </template>
  </tbody></table>

 </div>

  <ul v-if="scheds.length" class="md:hidden divide-y divide-slate-100 bg-white rounded-xl border border-slate-200/70 overflow-hidden">
  <li v-for="s in displayedScheds" :key="'m-'+s.id" :class="highlightSchedId===s.id ? 'bg-amber-50' : (jadwalEditId===s.id?'bg-amber-50/40':'')">
    <div v-if="jadwalEditId===s.id" class="px-5 py-3 space-y-2">
      <div class="grid grid-cols-2 gap-2">
        <label class="block"><span class="text-[10px] font-semibold text-slate-500">Tanggal</span><input type="date" v-model="jadwalEditForm.tanggal" class="mt-1 w-full px-2 py-2 rounded-lg border border-slate-200 text-[12px]"/></label>
        <label class="block"><span class="text-[10px] font-semibold text-slate-500">Tipe</span><select v-model="jadwalEditForm.tipe" class="mt-1 w-full px-2 py-2 rounded-lg border border-slate-200 text-[12px] bg-white"><option value="rutin">rutin</option><option value="tambahan">tambahan</option></select></label>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <label class="block"><span class="text-[10px] font-semibold text-slate-500">Jam mulai</span><input type="time" v-model="jadwalEditForm.jam_mulai" class="mt-1 w-full px-2 py-2 rounded-lg border border-slate-200 text-[12px]"/></label>
        <label class="block"><span class="text-[10px] font-semibold text-slate-500">Jam selesai</span><input type="time" v-model="jadwalEditForm.jam_selesai" class="mt-1 w-full px-2 py-2 rounded-lg border border-slate-200 text-[12px]"/></label>
      </div>
      <label class="block"><span class="text-[10px] font-semibold text-slate-500">Lokasi</span><input v-model="jadwalEditForm.lokasi" placeholder="Lapangan" class="mt-1 w-full px-2 py-2 rounded-lg border border-slate-200 text-[12px]"/></label>
      <div class="flex gap-2"><button @click="saveEditSched" class="flex-1 py-2 rounded-full bg-slate-900 text-white text-[12px] font-semibold">Simpan</button><button @click="cancelEditSched" class="flex-1 py-2 rounded-full border border-slate-200 bg-white text-[12px]">Batal</button></div>
      <div v-if="jadwalEditMsg" class="text-[11px] text-red-600">{{ jadwalEditMsg }}</div>
    </div>
    <div v-else class="px-5 py-3 flex items-center gap-3 min-w-0">
      <div class="min-w-0 flex-1">
        <div class="text-[13px] font-semibold truncate">{{ s.tanggal }}</div>
        <div class="text-[11px] text-slate-500 truncate">{{ fmtJam(s.jam_mulai) }} - {{ fmtJam(s.jam_selesai) }} - {{ s.lokasi || '-' }}</div>
      </div>
      <span :class="s.tipe==='tambahan' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'kat-ink'" class="text-[11px] px-2 py-1 rounded-full shrink-0">{{ s.tipe }}</span>
      <div v-if="isPembina" class="flex gap-1 shrink-0"><button @click="startEditSched(s)" class="text-[11px] px-2.5 py-1 rounded-full border border-slate-200 text-slate-600">Edit</button><button @click="confirmDeleteSched(s)" class="text-[11px] px-2 py-1 rounded-full border border-red-200 text-red-600">Hapus</button></div>
    </div>
  </li>
 </ul>

 <div v-else class="px-5 py-8 text-center text-sm text-slate-400">Belum ada jadwal</div>

   <button v-if="scheds.length>3" @click="showAllSched=!showAllSched" class="w-full text-xs font-semibold px-4 py-2.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 mt-2">{{ showAllSched ? 'Sembunyikan' : 'Lihat semua jadwal' }}</button>

  <div v-if="isPembina" class="mt-3">

   <button @click="showJadwalForm=!showJadwalForm" :aria-expanded="String(showJadwalForm)" :class="showJadwalForm ? 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50' : 'bg-[#4A7875] border-[#4A7875] text-white hover:bg-[#3d6562] shadow-sm'" class="w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-full border text-[13px] font-semibold transition">{{ showJadwalForm ? 'Tutup' : 'Tambah Jadwal' }}</button>

   <div v-show="showJadwalForm" class="mt-3 p-5 rounded-xl border border-slate-200 bg-white">

  <form @submit.prevent="addSched" novalidate class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-end">

  <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Tanggal *</span><input type="date" v-model="fs.tanggal" required class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-[#4A7875] border-slate-200"/></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Jam mulai *</span><input type="time" v-model="fs.jam_mulai" required :class="jamError?'border-red-300':''" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-[#4A7875] border-slate-200"/></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Jam selesai *</span><input type="time" v-model="fs.jam_selesai" required :class="jamError?'border-red-300':''" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-[#4A7875] border-slate-200"/></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Lokasi</span><input v-model="fs.lokasi" placeholder="Lapangan" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-[#4A7875] border-slate-200"/></label>

 <label class="block"><span class="text-[10px] tracking-widest font-semibold text-slate-500">Tipe</span><select v-model="fs.tipe" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"><option value="rutin">rutin</option><option value="tambahan">tambahan</option></select></label>

 <div class="col-span-2 sm:col-span-5 flex flex-wrap gap-2 mt-1">

 <button type="submit" :disabled="!isJadwalValid" class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold disabled:opacity-40 disabled:cursor-not-allowed kat-ink-h bg-slate-900">Tambah Jadwal</button>

 <span v-if="jamError" class="text-[11px] text-red-600 self-center" role="alert">{{ jamError }}</span>

  <span v-if="jadwalMsg" class="text-[11px] self-center" :class="jadwalOk?'text-emerald-600':'text-red-600'">{{ jadwalMsg }}</span>

 </div>

 </form>

 </div>

 </div>

 </section>



  <section v-show="detailTab==='anggota'" class="border-t border-slate-200/80 pt-5" role="tabpanel" aria-label="Anggota">

  <div class="relative"><input v-model="anggotaSearch" placeholder="Cari anggota..." class="w-full bg-white border border-slate-200 rounded-full pl-9 pr-4 py-2.5 text-xs focus:outline-none focus:border-[#4A7875] min-h-[40px]"/><svg class="absolute left-3 top-3 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7" cy="7" r="5"/><path d="M11 11l2 2"/></svg></div>

  <div v-if="filteredAnggota.length" class="flex flex-wrap gap-3 mt-4 items-center">
   <div v-for="a in anggotaPreview" :key="a.id" class="text-center min-w-[56px]"><router-link :to="'/u/'+a.user_id"><UserAvatar :user-id="a.user_id" :name="a.nama" size="w-11 h-11 mx-auto" /></router-link><div class="text-[11px] mt-1 font-medium truncate max-w-[56px]">{{ a.nama.split(' ')[0] }}</div></div>
   <button v-if="anggotaSisa>0" @click="showAllAnggotaModal=true" class="text-xs font-semibold px-3.5 py-2 rounded-full bg-white border border-slate-200 hover:bg-slate-50">dan {{ anggotaSisa }} lainnya</button>
  </div>
  <div v-else class="text-xs text-slate-400 mt-3">Belum ada anggota - daftar untuk bergabung</div>
   <Teleport to="body">
     <div v-if="showAllAnggotaModal" class="fixed inset-0 z-50" @keydown.esc="showAllAnggotaModal=false" tabindex="-1"><div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="showAllAnggotaModal=false"></div><div class="absolute inset-0 grid place-items-center p-4 overflow-auto" @click.self="showAllAnggotaModal=false"><div class="w-full max-w-[560px] max-h-[80vh] rounded-2xl border bg-white shadow-xl border-slate-200 flex flex-col overflow-hidden"><div class="sticky top-0 bg-white px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0"><h3 class="font-semibold text-sm">Semua Anggota ({{ filteredAnggota.length }})</h3><button @click="showAllAnggotaModal=false" class="w-8 h-8 rounded-full border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-700 hover:border-slate-300 grid place-items-center transition shrink-0" aria-label="Tutup"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M1 1L13 13"/><path d="M13 1L1 13"/></svg></button></div><div class="overflow-auto p-5"><div class="grid grid-cols-3 sm:grid-cols-4 gap-3"><div v-for="a in filteredAnggota" :key="'all-'+a.id" class="text-center"><router-link :to="'/u/'+a.user_id" @click="showAllAnggotaModal=false"><UserAvatar :user-id="a.user_id" :name="a.nama" size="w-12 h-12 mx-auto" /></router-link><div class="text-[11px] mt-1 font-medium truncate">{{ a.nama }}</div><div class="text-[10px] text-slate-400 truncate">{{ a.status }}</div></div></div></div></div></div></div>
   </Teleport>

 <div v-if="isAnggota && !isPembina" class="mt-4">

 <div class="flex items-center justify-between pb-2"><span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rekap Saya</span><button v-if="!myRekapLoaded" @click="fetchMyRekapIfEmpty()" class="text-[11px] px-3 py-1.5 rounded-full border border-slate-200 hover:bg-slate-50 min-h-[32px]">Muat</button></div>

 <div v-if="myRekapLoading" class="p-4 space-y-3 animate-pulse" aria-busy="true"><div class="h-3 w-32 bg-slate-100 rounded"></div><div class="h-2 w-full bg-slate-100 rounded-full"></div></div>

  <div v-else-if="myRekap" class="rounded-xl border border-slate-200/70 bg-white p-4">

  <div class="text-[13px] font-medium">Kehadiran saya: {{ myRekap.hadir }}/{{ myRekap.total_sesi }} ({{ myRekap.persen }}%)</div>

  <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-3"><div class="h-full bg-[#4A7875] rounded-full transition-all" :style="{width: (myRekap.persen||0)+'%'}"></div></div>

 </div>

 <div v-else class="py-6 text-center text-xs text-slate-400">{{ myRekapLoaded ? 'Belum ada rekap' : 'Klik Muat' }}</div>

 </div>

 </section>



  <section v-show="detailTab==='absensi' && isPembina" class="border-t border-slate-200/80 pt-5" role="tabpanel" aria-label="Absensi">

 <div class="flex items-center justify-between pb-3"><h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Absensi per Sesi</h3><span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ scheds.length }} sesi</span></div>

  <div class="bg-white rounded-xl border border-slate-200/70 p-4">

 <div class="flex flex-wrap gap-2 items-center">

 <select v-model="selSched" class="w-full sm:w-[320px] px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200">

  <option value="">-- pilih jadwal untuk absensi --</option>

 <option v-for="s in scheds" :key="s.id" :value="String(s.id)">{{ s.tanggal }} {{ fmtJam(s.jam_mulai) }} ({{ s.tipe }})</option>

 </select>

 <button v-if="selSched" @click="genQR(parseInt(selSched))" class="px-4 py-2.5 rounded-full text-white text-[13px] font-semibold kat-ink-h bg-slate-900">Generate QR (5m)</button>

 </div>

 <div v-if="selSched && qr.token" class="mt-3 text-center border rounded-2xl p-4 bg-[#F1F5F4] border-slate-200">

 <div class="text-[11px] text-slate-500">QR expiry: {{ qr.expiry }} (5 menit, one-time)</div>

 <canvas ref="canvasRef" class="border rounded-xl p-2 bg-white mx-auto mt-2 max-w-full h-auto" width="220" height="220" style="border-color:#e7e2dc"></canvas>

 <div class="text-[11px] mt-2 text-slate-400">Token: {{ qr.token.slice(0,12) }}...</div>

 </div>

 <div v-if="selSched" class="mt-4 overflow-auto rounded-xl border border-slate-200">

 <table class="w-full min-w-[480px]"><thead class="text-[10px] tracking-widest font-semibold text-slate-500 bg-[#F1F5F4] border-b border-slate-100"><tr><th class="text-left px-4 py-2.5">NAMA</th><th class="text-left px-4 py-2.5">STATUS</th><th class="text-left px-4 py-2.5">AKSI</th></tr></thead>

  <tbody class="divide-y divide-slate-50"><tr v-for="a in anggota.filter(x=>x.status==='diterima')" :key="a.user_id" class="hover:bg-slate-50/70"><td class="px-4 py-3 text-[13px] font-medium">{{ a.nama }}</td><td class="px-4 py-3"><span v-if="attMap[a.user_id]" class="text-[11px] px-2 py-1 rounded-full border font-medium" :class="attMap[a.user_id]==='hadir'?'bg-emerald-600 text-white border-emerald-600':attMap[a.user_id]==='izin'?'bg-amber-400 border-amber-400':'bg-slate-100 border-slate-200'">{{ attMap[a.user_id] }}</span><span v-else class="text-[11px] text-slate-400">-</span></td><td class="px-4 py-3"><label class="inline-flex items-center gap-1.5 text-[11px] cursor-pointer"><input type="checkbox" :checked="attMap[a.user_id]==='hadir'" @change="markAtt(a.user_id,$event.target.checked?'hadir':'alpa')" class="rounded"/>Hadir</label><select :value="attMap[a.user_id]||''" @change="markAtt(a.user_id,$event.target.value)" class="ml-2 px-2 py-1 rounded-lg border bg-white text-[12px] border-slate-200"><option value="">-</option><option value="hadir">hadir</option><option value="izin">izin</option><option value="alpa">alpa</option></select></td></tr></tbody></table>

 </div>

 </div>

 </section>

  <section v-show="detailTab==='anggota' && isAnggota && !isPembina" class="border-t border-slate-200/80 pt-5" aria-label="Rekap Saya">

 <div class="flex items-center justify-between pb-3"><h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rekap Saya</h3><button v-if="!myRekapLoaded" @click="fetchMyRekapIfEmpty()" class="text-[11px] px-3 py-1.5 rounded-full border border-slate-200 hover:bg-slate-50 min-h-[32px]">Muat</button></div>

 <div v-if="myRekapLoading" class="p-5 space-y-3 animate-pulse"><div class="h-3 w-32 bg-slate-100 rounded"></div><div class="h-2 w-full bg-slate-100 rounded-full"></div><div class="h-3 w-24 bg-slate-100 rounded"></div></div>

 <div v-else-if="myRekap" class="p-5">

 <div class="text-[13px] font-medium">Kehadiran saya: {{ myRekap.hadir }}/{{ myRekap.total_sesi }} ({{ myRekap.persen }}%)</div>

  <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-3"><div class="h-full bg-[#4A7875] rounded-full transition-all" :style="{width: (myRekap.persen||0)+'%'}"></div></div>

 <div class="text-[11px] text-slate-400 mt-2">{{ e?.nama }} - bar 4px</div>

 </div>

 <div v-else class="px-5 py-8 text-center text-xs text-slate-400">Belum ada rekap - {{ myRekapLoaded ? 'tidak ada sesi' : 'klik Muat' }}</div>

 </section>

  <section v-show="detailTab==='absensi' && isPembina" class="border-t border-slate-200/80 pt-5" aria-label="Rekap Kehadiran">

 <div class="flex items-center justify-between pb-3"><h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rekap Kehadiran</h3><button v-if="!rekapLoaded" @click="fetchRekapIfEmpty()" class="text-[11px] px-3 py-1 rounded-full kat-ink kat-ink-h">Muat Rekap</button><span v-else class="text-[11px] px-2 py-1 rounded-full bg-slate-100">{{ summary.length }} siswa</span></div>

  <div v-if="rekapLoading" class="p-5 space-y-2 animate-pulse"><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-3 w-3/4 bg-slate-100 rounded"></div><div class="h-3 w-1/2 bg-slate-100 rounded"></div></div>

 <div v-else-if="summary.length" class="overflow-auto bg-white rounded-xl border border-slate-200/70"><table class="w-full min-w-[520px]"><thead class="text-[10px] tracking-widest font-semibold text-slate-500 bg-[#F1F5F4] border-b border-slate-100"><tr><th class="text-left px-4 py-2.5">NAMA</th><th class="text-left px-4 py-2.5">REKAP</th><th class="text-right px-4 py-2.5">%</th></tr></thead><tbody class="divide-y divide-slate-50"><tr v-for="s in summary" :key="s.id" class="hover:bg-slate-50/70"><td class="px-4 py-3 text-[13px] font-medium">{{ s.nama }}</td><td class="px-4 py-3 text-[12px] text-slate-600">Rekap {{ s.nama }} sepanjang ekskul {{ s.hadir_count }}/{{ s.total_sesi }}={{ s.persen }}%</td><td class="px-4 py-3 text-right"><span class="text-[11px] px-2 py-1 rounded-full border bg-white">{{ s.persen }}%</span></td></tr></tbody></table></div>

 <div v-else class="px-5 py-8 text-center text-xs text-slate-400">{{ rekapLoaded ? 'Belum ada rekap' : 'Klik Muat Rekap untuk lihat (lazy)' }}</div>

 </section>

  <section v-show="detailTab==='anggota' && isPembina && anggota.length" class="border-t border-slate-200/80 pt-5" aria-label="Kelola Anggota">

 <div class="flex items-center justify-between pb-3"><h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Kelola Anggota</h3><div class="flex items-center gap-2"><span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ anggota.length }}</span><button @click="showManagePanel=!showManagePanel" class="text-[11px] font-semibold px-3 py-1.5 rounded-full border" :class="showManagePanel?'bg-[#2F3E46] text-white border-[#2F3E46]':'bg-white border-slate-200'">{{ showManagePanel?'Tutup':'Kelola' }}</button></div></div>
 <div v-show="showManagePanel">

 <div class="hidden md:block overflow-auto bg-white rounded-xl border border-slate-200/70"><table class="w-full min-w-[520px]"><thead class="text-[10px] tracking-widest font-semibold text-slate-500 bg-[#F1F5F4]"><tr><th class="text-left px-4 py-2.5">NAMA</th><th class="text-left px-4 py-2.5">STATUS</th><th class="text-right px-4 py-2.5">AKSI</th></tr></thead><tbody class="divide-y divide-slate-50"><tr v-for="a in anggota" :key="a.id" class="hover:bg-slate-50/70"><td class="px-4 py-3"><div class="text-[13px] font-medium">{{ a.nama }}</div><div class="text-[11px] text-slate-400">{{ a.email }}</div></td><td class="px-4 py-3"><span class="text-[11px] px-2 py-1 rounded-full border" :class="a.status==='diterima'?'bg-emerald-600 text-white border-emerald-600':a.status==='menunggu'?'bg-amber-400 border-amber-400':'bg-red-50 border-red-200'">{{ a.status }}</span></td><td class="px-4 py-3 text-right"><button @click="openKick(a)" class="text-[11px] px-3 py-1 rounded-full border border-red-200 text-red-600">Kick</button><button v-if="a.status==='menunggu'" @click="acc(a.id,'approve')" class="ml-1 text-[11px] px-3 py-1 rounded-full kat-ink">ACC</button><button v-if="a.status==='menunggu'" @click="acc(a.id,'reject')" class="ml-1 text-[11px] px-3 py-1 rounded-full border border-slate-200">Tolak</button></td></tr></tbody></table></div>

  <ul class="md:hidden divide-y divide-slate-100 bg-white rounded-xl border border-slate-200/70 overflow-hidden">

  <li v-for="a in anggota" :key="'m-'+a.id" class="px-4 py-3 flex items-center gap-3 min-w-0">

 <div class="min-w-0 flex-1">

 <div class="text-[13px] font-medium truncate">{{ a.nama }}</div>

 <div class="text-[11px] text-slate-400 truncate">{{ a.email }}</div>

 </div>

  <span class="stchip" :class="a.status==='diterima'?'st-ok':a.status==='menunggu'?'st-wait':'st-bad'">{{ a.status }}</span>

  <div class="flex gap-1 shrink-0"><button @click="openKick(a)" class="text-[11px] px-2.5 py-1 rounded-full border border-red-200 text-red-600">Kick</button><button v-if="a.status==='menunggu'" @click="acc(a.id,'approve')" class="text-[11px] px-2.5 py-1 rounded-full kat-ink">ACC</button><button v-if="a.status==='menunggu'" @click="acc(a.id,'reject')" class="text-[11px] px-2.5 py-1 rounded-full border border-slate-200">Tolak</button></div>

  </li>

  </ul>
  </div>
  </section>

  <section v-show="detailTab==='komunitas'" class="border-t border-slate-200/80 pt-5" role="tabpanel" aria-label="Komunitas">

 <div class="flex gap-1 overflow-x-auto pb-3" style="scrollbar-width:none" role="tablist" aria-label="Komunitas">

 <button @click="switchTab('diskusi')" :class="activeTab==='diskusi' ? 'bg-[#2F3E46] text-white' : 'text-slate-500 hover:bg-slate-100'" class="whitespace-nowrap px-4 py-2 rounded-full text-xs font-semibold min-h-[36px]" role="tab" :aria-selected="activeTab==='diskusi'">Diskusi <span class="ml-1 px-1.5 py-0.5 rounded-full text-[11px]" :class="unreadCounts.diskusi>0 && activeTab!=='diskusi' ? 'bg-red-500 text-white' : activeTab==='diskusi'?'bg-white/20':'bg-slate-100'">{{ unreadCounts.diskusi }}</span></button>

 <button @click="switchTab('tanya')" :class="activeTab==='tanya' ? 'bg-[#2F3E46] text-white' : 'text-slate-500 hover:bg-slate-100'" class="whitespace-nowrap px-4 py-2 rounded-full text-xs font-semibold min-h-[36px]" role="tab" :aria-selected="activeTab==='tanya'">Tanya Jawab <span class="ml-1 px-1.5 py-0.5 rounded-full text-[11px]" :class="unreadCounts.tanya>0 && activeTab!=='tanya' ? 'bg-red-500 text-white' : activeTab==='tanya'?'bg-white/20':'bg-slate-100'">{{ unreadCounts.tanya }}</span></button>

  <button @click="switchTab('postingan')" :class="activeTab==='postingan' ? 'bg-[#2F3E46] text-white' : 'text-slate-500 hover:bg-slate-100'" class="whitespace-nowrap px-4 py-2 rounded-full text-xs font-semibold min-h-[36px]" role="tab" :aria-selected="activeTab==='postingan'">Postingan <span class="ml-1 px-1.5 py-0.5 rounded-full text-[11px]" :class="unreadCounts.postingan>0 && activeTab!=='postingan' ? 'bg-red-500 text-white' : activeTab==='postingan'?'bg-white/20':'bg-slate-100'">{{ unreadCounts.postingan }}</span></button>

  </div>
  <!-- Laporan pembina  -  Opsi B moderasi -->
  <div v-if="isPembina" class="pb-3">
    <button @click="showReports=!showReports; if(showReports) fetchReports()" :class="showReports ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-amber-700 border-amber-200 hover:bg-amber-50'" class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-xs font-semibold border">
      <span class="w-2 h-2 rounded-full" :class="reportsPending>0 ? 'bg-red-500 animate-pulse' : 'bg-amber-400'"></span>
      Laporan <span v-if="reportsPending>0" class="px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[11px]">{{ reportsPending }}</span><span v-else class="px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px]">0</span>
      <span class="text-[11px] opacity-70">{{ showReports ? 'Tutup' : 'Kelola' }}</span>
    </button>
    <div v-if="showReports" class="mt-3 bg-white rounded-xl border border-amber-200/70 overflow-hidden">
      <div class="px-4 py-3 flex items-center justify-between border-b border-slate-100 bg-amber-50/50">
        <h4 class="text-xs font-bold uppercase tracking-wider text-amber-800">Antrean Laporan</h4>
        <div class="flex gap-1">
          <button @click="reportsTab='pending'; fetchReports()" :class="reportsTab==='pending'?'bg-[#2F3E46] text-white':'bg-white border border-slate-200'" class="px-3 py-1 rounded-full text-[11px] font-semibold">Pending</button>
          <button @click="reportsTab='dismissed'; fetchReports()" :class="reportsTab==='dismissed'?'bg-[#2F3E46] text-white':'bg-white border border-slate-200'" class="px-3 py-1 rounded-full text-[11px] font-semibold">Diabaikan</button>
          <button @click="reportsTab='resolved'; fetchReports()" :class="reportsTab==='resolved'?'bg-[#2F3E46] text-white':'bg-white border border-slate-200'" class="px-3 py-1 rounded-full text-[11px] font-semibold">Resolved</button>
        </div>
      </div>
      <div v-if="reportsLoading" class="p-4 space-y-2 animate-pulse"><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-3 w-2/3 bg-slate-100 rounded"></div></div>
      <div v-else-if="!reports.length" class="p-6 text-center text-xs text-slate-400">Tidak ada laporan {{ reportsTab }}</div>
      <div v-else class="divide-y divide-slate-100 max-h-[420px] overflow-auto">
        <div v-for="r in reports" :key="r.report_id" class="p-4 flex gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold" :class="r.category==='spam'?'bg-slate-900 text-white': r.category==='kasar'?'bg-red-100 text-red-700': r.category==='hoax'?'bg-amber-100 text-amber-700': r.category==='sara'?'bg-purple-100 text-purple-700':'bg-slate-100 text-slate-600'">{{ r.category }}</span>
              <span class="text-[11px] text-slate-400">{{ timeAgo(r.report_at) }}  -  oleh {{ r.reporter_nama }}</span>
              <span class="text-[11px] px-2 py-0.5 rounded-full" :class="r.status==='pending'?'bg-amber-100 text-amber-700': r.status==='dismissed'?'bg-slate-100 text-slate-500':'bg-emerald-100 text-emerald-700'">{{ r.status }}</span>
              <span v-if="r.report_count>1" class="text-[11px] font-semibold text-red-600">x{{ r.report_count }} laporan</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">Alasan: {{ r.reason }}</p>
            <div class="mt-2 p-3 rounded-xl bg-slate-50 border border-slate-100">
              <div class="text-[11px] text-slate-400">{{ r.post_tipe }}  -  {{ r.post_author_nama }}  -  {{ timeAgo(r.post_created_at) }}</div>
              <p class="text-[13px] font-medium mt-1 break-words line-clamp-3">{{ r.post_judul || r.post_isi }}</p>
              <p v-if="r.post_judul" class="text-[12px] text-slate-500 mt-1 line-clamp-2">{{ r.post_isi }}</p>
            </div>
          </div>
          <div v-if="r.status==='pending'" class="flex flex-col gap-1.5 shrink-0">
            <button @click="handleReport(r,'resolve_delete')" class="px-3 py-1.5 rounded-full bg-red-600 text-white text-[11px] font-semibold hover:bg-red-700">Hapus Post</button>
            <button @click="handleReport(r,'dismiss')" class="px-3 py-1.5 rounded-full border border-slate-200 bg-white text-[11px] font-semibold hover:bg-slate-50">Abaikan</button>
            <button @click="handleReport(r,'dismiss_post')" class="px-3 py-1.5 rounded-full border border-slate-200 bg-white text-[11px] hover:bg-slate-50">Abaikan semua</button>
            <button @click="openPostModal({id:r.post_id, tipe:r.post_tipe})" class="px-3 py-1 rounded-full border border-amber-200 bg-amber-50 text-amber-700 text-[11px]">Buka</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="bg-white rounded-xl border border-slate-200/70 overflow-hidden">

  <div v-if="canPost" class="p-4 border-b border-slate-100">

 <div class="flex gap-3">

  <UserAvatar :user-id="auth.user?.id" :name="auth.user?.nama" size="w-8 h-8 flex-none" />

 <div class="flex-1 min-w-0">

 <div v-if="activeTab==='tanya'" class="space-y-2 mb-2">

  <input v-model="composerJudul" placeholder="Judul pertanyaan..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-[13px] focus:bg-white focus:border-[#4A7875] outline-none"/>

  <label class="flex items-center gap-2 text-xs"><input type="checkbox" v-model="showPollComposer" class="rounded"/> Tambah polling (2-6 opsi)</label>

  <div v-if="showPollComposer" class="space-y-1 p-3 bg-amber-50/60 border border-amber-200 rounded-xl">

  <div v-for="(op,idx) in pollOptions" :key="idx" class="flex gap-1"><input v-model="pollOptions[idx]" :placeholder="'Opsi '+(idx+1)" class="flex-1 px-3 py-2 rounded-xl border bg-white text-xs border-slate-200"/><button v-if="pollOptions.length>2" @click="pollOptions.splice(idx,1)" class="px-2 text-xs">x</button></div>

  <button v-if="pollOptions.length<6" @click="pollOptions.push('')" class="text-xs text-[#4A7875]">+ opsi</button>

 </div>

 </div>

  <!-- legacy global pollsList hidden  -  poll now lives inside post card th.poll -->
  <div v-if="false && pollsList.length" class="space-y-2 mb-3">

  <div v-for="pl in pollsList" :key="pl.id" class="p-3 bg-white border border-slate-200 rounded-xl">

   <div class="text-xs font-semibold">{{ pl.question }}<span v-if="pl.is_closed||pl.closed_at" class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-slate-100">closed</span><span class="ml-2 text-[11px] text-slate-400">{{ pl.total_votes }} suara</span></div>

  <div class="mt-2 space-y-1">

   <label v-for="o in pl.options" :key="o.id" class="flex items-center gap-2 text-xs cursor-pointer" :class="(pl.is_closed||pl.closed_at)?'opacity-60':''">

    <input type="radio" :name="'poll-'+pl.id" :checked="pl.my_vote===o.id" :disabled="!!(pl.is_closed||pl.closed_at)" @change="votePoll(pl,o.id)"/> {{ o.label }} <span class="ml-auto text-[11px] text-slate-500">{{ o.votes }} ({{ o.percent }}%)</span>

   </label>

  </div>

   <div v-for="o in pl.options" :key="'bar-'+o.id" class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-[#4A7875]" :style="{width:o.percent+'%'}"></div></div>

  </div>

  </div>

 <div class="relative">

 <textarea ref="composerEl" v-model="composerInput" @input="onComposerInput" @keydown="onComposerKeydown" :placeholder="composerPlaceholder" rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 pr-12 text-[13px] placeholder:text-slate-400 focus:outline-none focus:border-[#4A7875] focus:bg-white resize-none"></textarea>

  <button @click="kirimPost" :disabled="!composerInput.trim() && !pendingUploads.length" title="Kirim" aria-label="Kirim postingan" class="absolute right-2 bottom-2 w-9 h-9 rounded-full bg-[#4A7875] text-white grid place-items-center shadow-sm hover:bg-[#3d6562] active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-[#4A7875] transition-all duration-150"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg></button>

  <div v-if="mentionOpen && mentionTarget?.kind==='composer' && mentionFiltered.length" class="absolute left-0 right-12 bottom-[48px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-40 overflow-auto z-10">

  <button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx ? 'kat-ink':'hover:bg-slate-50'" class="w-full text-left px-3 py-2 text-xs flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}<span class="ml-auto text-[11px] opacity-60">{{ u.role }}</span></button>

  </div>

  </div>

  <div class="flex items-center gap-2 mt-2 flex-wrap">

   <label class="inline-flex items-center gap-1.5 text-xs bg-white border border-slate-200 px-2.5 py-1.5 rounded-full cursor-pointer hover:bg-slate-50"> <span v-if="activeTab==='postingan' || activeTab==='diskusi'">Sisipkan gambar</span><span v-else>File/gambar</span> <input type="file" multiple class="hidden" @change="onFileChange" :accept="(activeTab==='postingan'||activeTab==='diskusi')?'image/*':''"/></label>

 <span class="text-[11px] text-slate-400">Maks 5MB/file - @mention didukung - <span v-if="pendingUploads.length">{{ pendingUploads.length }} file siap</span></span>

 </div>

 <div v-if="pendingUploads.length" class="grid grid-cols-3 gap-2 mt-2">

 <div v-for="(f,i) in pendingUploads" :key="i" class="relative bg-slate-50 rounded-xl p-2 border text-[11px]">

 <div class="truncate font-medium">{{ f.name }}</div><div class="text-slate-400">{{ (f.size/1024).toFixed(1) }} KB</div>

  <button @click="removePending(i)" class="absolute -top-1 -right-1 w-5 h-5 rounded-full kat-ink text-xs">x</button>

 <div v-if="f.isImage" class="mt-1"><img :src="f.preview" class="w-full h-16 object-cover" rounded-lg/></div>

 </div>

 </div>

 </div>

 </div>

 </div>

  <div v-if="!canPost" class="p-3 mx-3 mt-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800"> Hanya anggota ekskul ini yang bisa posting. Daftar dulu untuk ikut diskusi.</div>



  <div class="divide-y divide-slate-100">

  <div v-if="postsLoading" class="p-4 space-y-3 animate-pulse"><div class="h-3 w-24 bg-slate-100 rounded"></div><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-3 w-2/3 bg-slate-100 rounded"></div></div>

  <div v-else-if="!currentPosts.length" class="p-8 text-center text-xs text-slate-400">Belum ada {{ activeTab }} - jadi yang pertama posting!</div>

  <article v-for="th in currentPosts" :key="th.id" v-memo="[th.id, th.likes, th.is_liked, th.comments_count, th._expanded, th.poll?.my_vote, th.poll?.total_votes, th.poll?.is_closed, th.poll && th.poll.options && th.poll.options.map(o=>o.votes).join(',')]" class="p-4 hover:bg-slate-50/40 transition">

 <div class="flex gap-3">

  <router-link :to="'/u/'+th.user_id"><UserAvatar :user-id="th.user_id" :name="th.author_nama" size="w-8 h-8 flex-none" /></router-link>

  <div class="flex-1 min-w-0">

  <div class="flex items-center gap-2 flex-wrap">

  <router-link :to="'/u/'+th.user_id" class="text-[13px] font-semibold hover:underline">{{ th.author_nama }}</router-link><span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(th.author_role)">{{ roleBadgeLabel(th.author_role) }}</span><span class="text-[11px] text-slate-400"> - {{ timeAgo(th.created_at) }}</span>

  <div class="ml-auto flex items-center gap-1">

   <button @click="openPostModal(th)" class="text-[11px] px-2.5 py-1 rounded-full border border-slate-200 bg-white hover:bg-slate-50 font-medium">Buka</button>

  <button v-if="th.user_id===auth.user?.id || isPembina" @click="deletePost(th)" class="text-[11px] font-medium text-red-600 px-2.5 py-1 rounded-full border border-red-200 bg-white hover:bg-red-50">Hapus</button><button v-if="canPost && th.user_id!==auth.user?.id" @click="openReportModal(th)" :disabled="th._reported" :class="th._reported ? 'bg-amber-50 text-amber-700 border-amber-200 cursor-default' : 'bg-white text-slate-600 border-slate-200 hover:bg-amber-50 hover:text-amber-700 hover:border-amber-200'" class="inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-1 rounded-full border"><svg v-if="!th._reported" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.5v3"/><path d="M6 8.5h.01"/><path d="M2 9l4-7 4 7H2z"/></svg><span>{{ th._reported ? 'Dilaporkan v' : 'Laporkan' }}</span></button>

 </div>

 </div>

 <h4 v-if="th.judul" class="text-[13.5px] font-semibold leading-snug mt-1">{{ th.judul }}</h4>

  <p class="text-[13px] text-slate-600 leading-relaxed mt-1 whitespace-pre-wrap break-words">{{ th.isi }}</p>

  <div v-if="th.poll" class="mt-3 p-3 bg-[#F8FAF9] border border-slate-200 rounded-xl">
   <div class="text-xs font-semibold">{{ th.poll.question }}<span v-if="th.poll.is_closed||th.poll.closed_at" class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-slate-100">closed</span><span class="ml-2 text-[11px] text-slate-400">{{ th.poll.total_votes }} suara</span><button v-if="(isPembina || th.user_id===auth.user?.id) && !(th.poll.is_closed||th.poll.closed_at)" @click="closePoll(th.poll)" class="ml-2 text-[11px] text-amber-700 underline">Tutup</button></div>
   <div class="mt-2 space-y-1">
    <label v-for="o in th.poll.options" :key="o.id" class="flex items-center gap-2 text-xs" :class="((th.poll.is_closed||th.poll.closed_at)||th.poll.my_vote||isPembina||auth.user?.role==='kepsek')?'opacity-70 cursor-default':'cursor-pointer'">
     <input type="radio" :name="'poll-post-'+th.id" :checked="th.poll.my_vote===o.id" :disabled="!!(th.poll.is_closed||th.poll.closed_at||th.poll.my_vote||isPembina||auth.user?.role==='kepsek')" @change="votePoll(th.poll,o.id)"/> {{ o.label }} <span class="ml-auto text-[11px] text-slate-500">{{ o.votes }} ({{ o.percent }}%)</span>
    </label>
   </div>
   <div v-for="o in th.poll.options" :key="'bar-'+o.id+'-'+th.id" class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-[#4A7875]" :style="{width:o.percent+'%'}"></div></div>
  </div>

   <div v-if="th.uploads?.length" class="grid gap-2 mt-3" :class="th.uploads.length===1?'grid-cols-1':'grid-cols-2'">

  <a v-for="u in th.uploads" :key="u.id" :href="u.url" target="_blank" class="block group">

  <img v-if="u.mime && u.mime.startsWith('image/')" :src="u.url" class="w-full h-28 object-cover rounded-xl border border-slate-200 cursor-pointer group-hover:opacity-90" @click.prevent="openLightbox(u.url)"/>

 <div v-else class="p-3 rounded-xl border bg-slate-50 text-xs flex items-center gap-2"><span></span><span class="truncate">{{ u.original_name }}</span><span class="ml-auto text-[11px] text-slate-400">{{ (u.size/1024).toFixed(1) }}KB</span></div>

 <div class="text-[11px] text-slate-400 mt-1 truncate">{{ u.original_name }} - klik untuk {{ u.mime?.startsWith('image/')?'preview':'unduh' }}</div>

 </a>

 </div>

 <div class="flex items-center gap-2 mt-3 flex-wrap">

  <button @click="toggleLikePost(th)" :class="th.is_liked ? 'bg-rose-50 border-rose-200 text-rose-600' : 'border-slate-200 hover:border-slate-300'" class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-full border">{{ th.is_liked ? '' : '' }} {{ th.likes }}</button>

  <button @click="expandPost(th)" class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-full kat-ink"> {{ th.comments_count }} balasan</button>

  <button @click="copyPostLink(th)" class="text-[11px] px-2 py-1 rounded-full border hover:bg-white"> Salin link</button>

  </div>

  <div v-if="th._expanded" class="mt-3 bg-[#F1F5F4] rounded-2xl border border-slate-100 p-3 space-y-3">

  <div v-for="c in th._comments" :key="c.id" class="flex gap-2">

  <router-link :to="'/u/'+c.user_id"><UserAvatar :user-id="c.user_id" :name="c.author_nama" size="w-6 h-6 flex-none" /></router-link>

  <div class="flex-1 bg-white rounded-xl border border-slate-100 px-3 py-2">

  <div class="flex items-center gap-2 flex-wrap"><router-link :to="'/u/'+c.user_id" class="text-xs font-semibold hover:underline">{{ c.author_nama }}</router-link><span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(c.author_role)">{{ roleBadgeLabel(c.author_role) }}</span><span class="text-[11px] text-slate-400"> - {{ timeAgo(c.created_at) }}</span>

  <button @click="toggleLikeComment(c)" :class="c.is_liked?'text-rose-600':''" class="ml-auto text-xs px-2 py-1 rounded-full border">{{ c.is_liked?'':'' }} {{ c.likes }}</button>

  <button v-if="c.user_id===auth.user?.id || isPembina" @click="deleteComment(c, th)" class="text-[11px] text-red-500">Hapus</button>

 </div>

 <p class="text-xs text-slate-600 mt-1 whitespace-pre-wrap">{{ c.isi }}</p>

 <div v-if="c.uploads?.length" class="flex flex-wrap gap-1 mt-1"><a v-for="u in c.uploads" :key="u.id" :href="u.url" target="_blank" class="text-[11px] underline">{{ u.original_name }}</a></div>

 <div v-if="c.replies?.length" class="mt-2 ml-2 pl-3 border-l-2 border-slate-100 space-y-2">

 <div v-for="r in c.replies" :key="r.id" class="bg-slate-50 rounded-xl px-3 py-2 flex gap-2">

  <router-link :to="'/u/'+r.user_id"><UserAvatar :user-id="r.user_id" :name="r.author_nama" size="w-5 h-5 flex-none" /></router-link>

  <div class="flex-1"><div class="text-xs flex items-center gap-1.5 flex-wrap"><router-link :to="'/u/'+r.user_id" class="font-semibold hover:underline">{{ r.author_nama }}</router-link><span class="text-[10px] px-1.5 py-0.5 rounded-full" :class="roleBadgeClass(r.author_role)">{{ roleBadgeLabel(r.author_role) }}</span> <span class="text-[11px] text-slate-400"> - {{ timeAgo(r.created_at) }}</span><button @click="toggleLikeComment(r)" class="ml-2 text-xs">{{ r.is_liked?'':'' }} {{ r.likes }}</button></div><p class="text-xs text-slate-600 mt-1">{{ r.isi }}</p></div>

  </div>

  </div>

     <div class="flex gap-1 mt-2 relative"><input v-model="c._replyInput" @input="e=>onReplyInput(e,c,th)" @keydown="e=>onReplyKeydown(e,c,th)" @keydown.enter="sendReply(c, th)" placeholder="Balas @mention..." class="flex-1 bg-slate-50 border border-slate-200 rounded-full px-3 py-1.5 text-xs focus:bg-white focus:border-[#4A7875] outline-none"/><button @click="sendReply(c, th)" title="Kirim balasan" aria-label="Kirim balasan" class="px-3.5 py-1.5 rounded-full bg-[#4A7875] text-white text-xs font-semibold inline-flex items-center gap-1.5 shadow-sm hover:bg-[#3d6562] active:scale-95 transition-all duration-150"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg> Balas</button><div v-if="mentionOpen && mentionTarget?.kind==='reply' && mentionTarget?.c===c && mentionFiltered.length" class="absolute left-0 right-12 bottom-[36px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-32 overflow-auto z-10"><button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx ? 'kat-ink':'hover:bg-slate-50'" class="w-full text-left px-3 py-1.5 text-xs flex items-center gap-1.5"><span class="w-5 h-5 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}</button></div></div>

   <div v-if="c.id && pollCommentMap[c.id]" class="mt-2 p-2 bg-white border rounded-xl"><div class="text-xs font-semibold">{{ pollCommentMap[c.id].question }}<span v-if="pollCommentMap[c.id].is_closed||pollCommentMap[c.id].closed_at" class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-slate-100">closed</span></div><div class="mt-1 space-y-1"><label v-for="o in pollCommentMap[c.id].options" :key="o.id" class="flex items-center gap-2 text-xs" :class="(pollCommentMap[c.id].is_closed||pollCommentMap[c.id].closed_at||isPembina||auth.user?.role==='kepsek')?'opacity-60 cursor-default':''"><input type="radio" :name="'poll-c-'+c.id" :checked="pollCommentMap[c.id].my_vote===o.id" :disabled="!!(pollCommentMap[c.id].is_closed||pollCommentMap[c.id].closed_at||isPembina||auth.user?.role==='kepsek')" @change="votePoll(pollCommentMap[c.id],o.id)"/>{{ o.label }} <span class="ml-auto">{{ o.percent }}%</span></label></div><div v-for="o in pollCommentMap[c.id].options" :key="'bar-'+o.id" class="h-1 bg-slate-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-[#4A7875]" :style="{width:o.percent+'%'}"></div></div></div>

 </div>

 </div>

     <div class="flex gap-2 pt-2 border-t border-slate-100"><UserAvatar :user-id="auth.user?.id" :name="auth.user?.nama" size="w-6 h-6" /><div class="flex-1 relative"><input v-model="th._newComment" @input="e=>onCommentInput(e,th)" @keydown="e=>onCommentKeydown(e,th)" @keydown.enter="sendComment(th)" placeholder="Tulis balasan... bisa @mention" class="w-full bg-white border border-slate-200 rounded-full pl-3 pr-12 py-2 text-xs focus:outline-none focus:border-[#4A7875]"/><button @click="sendComment(th)" title="Kirim balasan" aria-label="Kirim balasan" class="absolute right-1 top-1 w-[30px] h-[30px] rounded-full bg-[#4A7875] text-white grid place-items-center shadow-sm hover:bg-[#3d6562] active:scale-95 transition-all duration-150"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg></button><div v-if="mentionOpen && mentionTarget?.kind==='comment' && mentionTarget?.th===th && mentionFiltered.length" class="absolute left-0 right-0 bottom-[40px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-32 overflow-auto z-10"><button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx ? 'kat-ink':'hover:bg-slate-50'" class="w-full text-left px-3 py-1.5 text-xs flex items-center gap-1.5"><span class="w-5 h-5 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}</button></div></div></div>

 </div>

 </div>

 </div>

 </article>

 <div v-if="postsHasMore && !postsLoading && currentPosts.length" class="px-4 py-3 text-center border-t border-slate-100"><button @click="loadMorePosts()" class="text-xs font-semibold px-4 py-2 rounded-full border border-slate-200 hover:bg-slate-50">Muat lagi</button></div>

 </div>

 </div>

 </section>

 </div>



  <div class="min-w-0 mt-6 lg:mt-0 lg:sticky lg:top-[120px] lg:max-h-[calc(100vh-136px)] lg:overflow-y-auto side-rail lg:pb-1" :class="detailTab==='overview' ? 'hidden lg:block' : ''" aria-label="Pengumuman">

  <section class="bg-white rounded-2xl border border-slate-200/70 overflow-visible" aria-label="Pengumuman">

 <div class="px-4 py-3 flex items-center gap-2 border-b border-slate-100">

 <span class="text-[13px]"></span>

 <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pengumuman</span>

 <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-semibold">{{ pengList.length }} baru</span>

 </div>

 <div>

  <template v-if="isPengumumanAllowed">
  <div class="px-4 py-3 flex items-center gap-2">

  <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-semibold">{{ pengList.length }} baru</span>

  <span class="ml-auto text-[11px] text-slate-400">Hanya pembina</span>

  </div>

   <div class="mx-3 mt-3 rounded-2xl border-2 border-dashed p-3 border-amber-200 bg-amber-50/50">

    <div class="flex items-center gap-2 text-xs font-semibold text-amber-800"><span class="w-6 h-6 rounded-full flex items-center justify-center bg-amber-500 text-white">*</span> Buat pengumuman (khusus Pembina)</div>

    <textarea v-model="pengInput" placeholder="Tulis pengumuman penting... akan di-pin & notifikasi ke semua anggota" rows="2" class="mt-2 w-full bg-white border rounded-xl px-3 py-2.5 text-[13px] placeholder:text-slate-400 focus:outline-none border-amber-200 focus:border-amber-400"></textarea>

    <div v-if="pengGambarPreview" class="mt-2 relative rounded-xl overflow-hidden border border-amber-200 bg-white">
      <img :src="pengGambarPreview" alt="Preview gambar pengumuman" class="w-full max-h-[180px] object-cover"/>
      <button @click="removePengGambar" class="absolute top-2 right-2 w-7 h-7 rounded-full bg-black/60 text-white flex items-center justify-center text-xs hover:bg-black/80">x</button>
      <div class="px-2 py-1 text-[11px] text-slate-500 truncate">{{ pengGambarFile?.name }}  -  {{ (pengGambarFile?.size/1024).toFixed(0) }} KB</div>
    </div>
    <p v-if="pengGambarMsg" class="text-[11px] mt-1" :class="pengGambarOk?'text-emerald-600':'text-red-600'">{{ pengGambarMsg }}</p>

    <div class="flex items-center gap-2 mt-2 flex-wrap">
    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border bg-white text-xs font-medium cursor-pointer hover:bg-slate-50 border-amber-200 text-amber-700">
      Sisipkan gambar
      <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onPengGambarChange" ref="pengGambarInputRef" />
    </label>
    <button @click="postPengumuman" :disabled="!pengInput.trim()" :class="!pengInput.trim() ? 'opacity-40 cursor-not-allowed' : 'hover:bg-amber-600 active:scale-95 shadow-sm'" class="ml-auto bg-amber-500 text-white text-xs font-bold px-4 py-2 rounded-full inline-flex items-center gap-1.5 transition-all duration-150"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg> Posting</button>

    </div>

  </div>
  </template>

 <div class="p-3 space-y-3">

  <article v-for="p in pengList" :key="p.id" :class="p.is_pinned ? 'border-amber-200 bg-amber-50/60' : 'border-slate-100 bg-slate-50'" class="rounded-2xl border p-4">

  <div class="flex items-center gap-2 flex-wrap">

  <UserAvatar :user-id="p.creator_id" :name="p.creator_nama" size="w-7 h-7" /><span class="text-xs font-semibold">{{ p.creator_nama || 'Pak Andi Wijaya' }}</span><span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(p.creator_role)">{{ roleBadgeLabel(p.creator_role) }}</span><span v-if="p.is_pinned" class="text-[11px] px-2 py-0.5 rounded-full bg-[#4A7875] text-white font-semibold"> PINNED</span><span class="ml-auto text-[11px] text-slate-400">{{ timeAgo(p.created_at) }}</span>

   <div v-if="isPengumumanAllowed"><button data-peng-menu-btn @click.stop="togglePengMenu(p.id, $event)" class="w-7 h-7 rounded-full hover:bg-white flex items-center justify-center text-slate-400 text-[14px] leading-none">...</button></div>

  </div>

   <p v-if="pengEditId!==p.id" class="text-[13px] leading-relaxed mt-2 break-words whitespace-pre-wrap">{{ p.isi }}</p>
   <div v-if="p.has_gambar && pengEditId!==p.id" class="mt-2">
     <button v-if="!p._showGambar" @click="p._showGambar=true" class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100">Lihat gambar</button>
     <div v-else class="space-y-1.5">
       <img :src="p.gambar_url" alt="Gambar pengumuman" class="w-full rounded-xl border border-slate-200 cursor-zoom-in object-cover max-h-[320px]" @click="lightboxSrc=p.gambar_url" loading="lazy" />
       <button @click="p._showGambar=false" class="text-xs font-medium px-3 py-1 rounded-full border border-slate-200 bg-white hover:bg-slate-50">Sembunyikan</button>
     </div>
   </div>
    <div v-if="pengEditId===p.id" class="mt-2 space-y-2">
     <textarea v-model="pengEditText" rows="3" class="w-full bg-white border border-amber-300 rounded-xl px-3 py-2.5 text-[13px] focus:outline-none focus:border-amber-400 resize-y"></textarea>
     <!-- edit gambar preview / existing -->
     <div v-if="pengEditGambarPreview" class="relative rounded-xl overflow-hidden border border-amber-200 bg-white">
       <img :src="pengEditGambarPreview" alt="Preview edit" class="w-full max-h-[180px] object-cover"/>
       <button @click="removePengEditGambar" class="absolute top-2 right-2 w-7 h-7 rounded-full bg-black/60 text-white flex items-center justify-center text-xs hover:bg-black/80">x</button>
       <div class="px-2 py-1 text-[11px] text-slate-500 truncate">{{ pengEditGambarFile?.name || 'Gambar baru' }}  -  {{ pengEditGambarFile ? (pengEditGambarFile.size/1024).toFixed(0)+' KB' : '' }}</div>
     </div>
     <div v-else-if="p.has_gambar && !pengEditRemoveGambar" class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
       <span class="text-[11px] text-slate-600">Gambar terlampir</span>
       <button @click="pengEditGambarPreview=p.gambar_url; pengEditRemoveGambar=false" class="text-[11px] font-semibold text-amber-700 hover:underline">Lihat</button>
       <button @click="pengEditRemoveGambar=true" class="ml-auto text-[11px] font-semibold text-red-600 hover:underline">Hapus gambar</button>
     </div>
     <div v-else-if="pengEditRemoveGambar" class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[11px] text-red-700">
       <span>Gambar akan dihapus saat disimpan</span>
       <button @click="pengEditRemoveGambar=false" class="ml-auto font-semibold underline">Batal hapus</button>
     </div>
     <p v-if="pengEditGambarMsg" class="text-[11px]" :class="pengEditRemoveGambar?'text-amber-600':'text-red-600'">{{ pengEditGambarMsg }}</p>
     <div class="flex items-center gap-2 flex-wrap">
       <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border bg-white text-xs font-medium cursor-pointer hover:bg-slate-50 border-amber-200 text-amber-700">
         {{ pengEditGambarFile ? 'Ganti gambar' : (p.has_gambar ? 'Ganti gambar' : 'Sisipkan gambar') }}
         <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onPengEditGambarChange" ref="pengEditGambarInputRef" />
       </label>
       <button @click="savePengEdit(p)" :disabled="!pengEditText.trim()" class="text-xs font-semibold px-4 py-2 rounded-full bg-[#2F3E46] text-white hover:bg-slate-800 disabled:opacity-40">Simpan</button>
        <button @click="cancelPengEdit()" class="text-xs font-semibold px-4 py-2 rounded-full border border-slate-200 bg-white hover:bg-slate-50">Batal</button>
      </div>
    </div>
    <div v-if="pengEditId!==p.id" class="mt-3 flex items-center gap-2">
      <button @click="openPengView(p)" class="text-[11px] font-semibold px-3 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50">Buka</button>
      <span v-if="p.has_gambar" class="text-[11px] text-slate-400"> -  ada gambar</span>
    </div>

  </article>

 <div v-if="!pengList.length" class="text-xs text-slate-400 text-center py-4 border border-dashed border-slate-200 rounded-2xl bg-slate-50">Belum ada pengumuman</div>

 </div>

 </div>

 </section>



 </div>

  <div class="bg-white rounded-xl border border-slate-200/70 overflow-hidden">

  </div>

  </main>

  <div v-else class="kat-wrap kat-empty">Ekskul tidak ditemukan</div>



  <div v-if="lightboxSrc" class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" @click.self="lightboxSrc=null"><img :src="lightboxSrc" class="max-w-full max-h-[85vh] rounded-2xl"/><button @click="lightboxSrc=null" class="absolute top-4 right-4 w-9 h-9 rounded-full bg-white text-slate-900 flex items-center justify-center">x</button></div>

  <!-- Pengumuman kebab menu: Teleport to body so it escapes overflow-hidden / overflow-y-auto clipping -->
  <Teleport to="body">
    <div v-if="pengMenuOpen!==null" class="fixed inset-0 z-[70]" @click="pengMenuOpen=null">
      <div id="peng-menu-teleport" :style="{ top: pengMenuPos.top + 'px', left: pengMenuPos.left + 'px' }" class="absolute w-44 bg-white rounded-xl border border-slate-200 shadow-xl py-1 text-xs" @click.stop>
        <template v-if="pengMenuCurrent()">
          <button @click="startPengEdit(pengMenuCurrent()); pengMenuOpen=null" class="w-full text-left px-3 py-2 hover:bg-slate-50">Edit</button>
          <button @click="togglePin(pengMenuCurrent()); pengMenuOpen=null" class="w-full text-left px-3 py-2 hover:bg-slate-50">{{ pengMenuCurrent().is_pinned ? 'Lepas pin' : 'Pin ke atas' }}</button>
          <button @click="deletePeng(pengMenuCurrent()); pengMenuOpen=null" class="w-full text-left px-3 py-2 hover:bg-slate-50 text-red-600">Hapus</button>
        </template>
      </div>
    </div>
  </Teleport>

  <!-- Pengumuman detail modal  -  all roles, tampilkan isi lengkap + Lihat gambar -->
  <Teleport to="body">
    <div v-if="pengView" class="fixed inset-0 z-50" @keydown.esc="closePengView()" tabindex="-1">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="closePengView()"></div>
      <div class="absolute inset-0 grid place-items-center p-4 overflow-auto" @click.self="closePengView()">
        <div class="w-full max-w-[560px] max-h-[85vh] overflow-auto rounded-2xl border bg-white shadow-xl border-slate-200 flex flex-col" @click.stop>
          <div class="sticky top-0 bg-white px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 class="font-semibold text-sm flex items-center gap-2">Pengumuman <span v-if="pengView.is_pinned" class="text-[11px] px-2 py-0.5 rounded-full bg-[#4A7875] text-white">PINNED</span></h3>
            <button @click="closePengView()" class="w-8 h-8 rounded-full border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 grid place-items-center" aria-label="Tutup"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M1 1L13 13"/><path d="M13 1L1 13"/></svg></button>
          </div>
          <div class="p-5 space-y-3">
            <div class="flex items-center gap-2 flex-wrap text-xs">
              <UserAvatar :user-id="pengView.creator_id" :name="pengView.creator_nama" size="w-7 h-7" />
              <span class="font-semibold">{{ pengView.creator_nama || 'Pembina' }}</span>
              <span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(pengView.creator_role)">{{ roleBadgeLabel(pengView.creator_role) }}</span>
              <span class="ml-auto text-[11px] text-slate-400">{{ timeAgo(pengView.created_at) }}</span>
            </div>
            <p class="text-[14px] leading-relaxed whitespace-pre-wrap break-words">{{ pengView.isi }}</p>
            <div v-if="pengView.has_gambar" class="pt-1">
              <button v-if="!pengViewShowGambar" @click="pengViewShowGambar=true" class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100">Lihat gambar</button>
              <div v-else class="space-y-1.5">
                <img :src="pengView.gambar_url" alt="Gambar pengumuman" class="w-full rounded-xl border border-slate-200 object-cover max-h-[420px] cursor-zoom-in" @click="lightboxSrc=pengView.gambar_url" loading="lazy" />
                <div class="flex gap-2">
                  <button @click="pengViewShowGambar=false" class="text-xs font-medium px-3 py-1 rounded-full border border-slate-200 bg-white hover:bg-slate-50">Sembunyikan</button>
                  <button @click="lightboxSrc=pengView.gambar_url" class="text-xs font-medium px-3 py-1 rounded-full border border-slate-200 bg-white hover:bg-slate-50">Perbesar</button>
                </div>
              </div>
            </div>
          </div>
          <div class="sticky bottom-0 bg-white px-5 py-3 border-t border-slate-100 flex justify-end">
            <button @click="closePengView()" class="px-4 py-2 rounded-full bg-[#2F3E46] text-white text-xs font-semibold hover:bg-slate-800">Tutup</button>
          </div>
        </div>
      </div>
    </div>
   </Teleport>

  <!-- Postingan / Diskusi detail modal  -  gantikan tab baru, tampil sebagai modal + gambar inline -->
  <Teleport to="body">
    <div v-if="postView || postViewLoading" class="fixed inset-0 z-50" @keydown.esc="closePostModal()" tabindex="-1">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="closePostModal()"></div>
      <div class="absolute inset-0 grid place-items-center p-4 overflow-auto" @click.self="closePostModal()">
        <div class="w-full max-w-[640px] max-h-[88vh] overflow-auto rounded-2xl border bg-white shadow-xl border-slate-200 flex flex-col" @click.stop>
          <div class="sticky top-0 bg-white px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 class="font-semibold text-sm flex items-center gap-2 capitalize">{{ postView?.tipe || 'Postingan' }} <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-900 text-white">{{ postView?.tipe || ' - ' }}</span></h3>
            <button @click="closePostModal()" class="w-8 h-8 rounded-full border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 grid place-items-center" aria-label="Tutup"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M1 1L13 13"/><path d="M13 1L1 13"/></svg></button>
          </div>
          <div v-if="postViewLoading" class="p-6 space-y-3 animate-pulse"><div class="h-4 w-32 bg-slate-100 rounded"></div><div class="h-3 w-full bg-slate-100 rounded"></div><div class="h-40 bg-slate-100 rounded-xl"></div></div>
          <div v-else-if="postViewError" class="p-8 text-center text-sm text-red-600">{{ postViewError }}</div>
          <div v-else-if="postView" class="p-5 space-y-4">
            <div class="flex gap-3">
              <UserAvatar :user-id="postView.user_id" :name="postView.author_nama" size="w-8 h-8 flex-none" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-[13px] font-semibold">{{ postView.author_nama }}</span>
                  <span class="text-[11px] px-2 py-0.5 rounded-full" :class="roleBadgeClass(postView.author_role)">{{ roleBadgeLabel(postView.author_role) }}</span>
                  <span class="text-[11px] text-slate-400"> -  {{ timeAgo(postView.created_at) }}</span>
                </div>
                <h4 v-if="postView.judul" class="text-[15px] font-bold leading-snug mt-1">{{ postView.judul }}</h4>
                <p class="text-[13.5px] text-slate-700 leading-relaxed mt-2 whitespace-pre-wrap break-words">{{ postView.isi }}</p>
                <!-- gambar postingan/diskusi -->
                <div v-if="postView.uploads?.length" class="grid gap-2 mt-3" :class="postView.uploads.length===1?'grid-cols-1':'grid-cols-2'">
                  <div v-for="u in postView.uploads" :key="u.id" class="group">
                    <img v-if="u.mime && u.mime.startsWith('image/')" :src="u.url" :alt="u.original_name" class="w-full max-h-[320px] object-cover rounded-xl border border-slate-200 cursor-zoom-in group-hover:opacity-90" @click="lightboxSrc=u.url" loading="lazy"/>
                    <a v-else :href="u.url" target="_blank" class="block p-3 rounded-xl border bg-slate-50 text-xs flex items-center gap-2 hover:bg-slate-100">
                      <span class="truncate">{{ u.original_name }}</span><span class="ml-auto text-[11px] text-slate-400">{{ (u.size/1024).toFixed(1) }}KB</span>
                    </a>
                    <div class="text-[11px] text-slate-400 mt-1 truncate">{{ u.original_name }}  -  klik untuk {{ u.mime?.startsWith('image/')?'perbesar':'unduh' }}</div>
                  </div>
                </div>
                <!-- poll jika ada -->
                <div v-if="postView.poll" class="mt-3 p-3 bg-[#F8FAF9] border border-slate-200 rounded-xl">
                  <div class="text-xs font-semibold">{{ postView.poll.question }}<span v-if="postView.poll.is_closed||postView.poll.closed_at" class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-slate-100">closed</span><span class="ml-2 text-[11px] text-slate-400">{{ postView.poll.total_votes }} suara</span></div>
                  <div class="mt-2 space-y-1">
                    <label v-for="o in postView.poll.options" :key="o.id" class="flex items-center gap-2 text-xs" :class="((postView.poll.is_closed||postView.poll.closed_at)||postView.poll.my_vote)?'opacity-70 cursor-default':'cursor-pointer'">
                      <input type="radio" :name="'poll-modal-'+postView.poll.id" :checked="postView.poll.my_vote===o.id" :disabled="!!(postView.poll.is_closed||postView.poll.closed_at||postView.poll.my_vote)" @change="votePoll(postView.poll,o.id)"/> {{ o.label }} <span class="ml-auto text-[11px] text-slate-500">{{ o.votes }} ({{ o.percent }}%)</span>
                    </label>
                  </div>
                  <div v-for="o in postView.poll.options" :key="'bar-'+o.id+'-modal'" class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-[#4A7875]" :style="{width:o.percent+'%'}"></div></div>
                </div>
                <div class="flex items-center gap-2 mt-4 flex-wrap">
                  <button @click="toggleLikePostModal()" :class="postView.is_liked?'bg-rose-50 border-rose-200 text-rose-600':'border-slate-200 hover:border-slate-300'" class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-full border">{{ postView.is_liked?'♥':'♡' }} {{ postView.likes }}</button>
                  <span class="text-xs text-slate-400">{{ postView.comments_count }} komentar</span>
                  <button @click="copyPostLink(postView)" class="ml-auto text-[11px] px-2 py-1 rounded-full border hover:bg-slate-50">Salin link</button>
                </div>
              </div>
            </div>
            <!-- komentar -->
            <div class="pt-4 border-t border-slate-100 space-y-3">
              <h5 class="font-semibold text-xs uppercase tracking-wider text-slate-500">Komentar ({{ (postView.comments||[]).length }})</h5>
              <div class="flex gap-2">
                <UserAvatar :user-id="auth.user?.id" :name="auth.user?.nama" size="w-7 h-7 flex-none" />
                <div class="flex-1 flex gap-2">
                  <input v-model="postViewNewComment" @keydown.enter="sendPostModalComment()" placeholder="Tulis komentar... bisa @mention" class="flex-1 px-3 py-2 rounded-full border border-slate-200 bg-slate-50 text-xs focus:bg-white focus:border-[#4A7875] outline-none"/>
                  <button @click="sendPostModalComment()" title="Kirim komentar" aria-label="Kirim komentar" class="px-4 py-2 rounded-full bg-[#4A7875] text-white text-xs font-semibold inline-flex items-center gap-1.5 shadow-sm hover:bg-[#3d6562] active:scale-95 transition-colors"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg> Kirim</button>
                </div>
              </div>
              <div v-if="(postView.comments||[]).length===0" class="text-xs text-slate-400 text-center py-4">Belum ada komentar  -  jadi yang pertama!</div>
              <div v-for="c in (postView.comments||[])" :key="c.id" class="flex gap-2">
                <UserAvatar :user-id="c.user_id" :name="c.author_nama" size="w-6 h-6 flex-none" />
                <div class="flex-1 bg-slate-50 rounded-xl border border-slate-100 px-3 py-2">
                  <div class="flex items-center gap-2 flex-wrap"><span class="text-xs font-semibold">{{ c.author_nama }}</span><span class="text-[11px] px-1.5 py-0.5 rounded-full" :class="roleBadgeClass(c.author_role)">{{ roleBadgeLabel(c.author_role) }}</span><span class="text-[11px] text-slate-400"> -  {{ timeAgo(c.created_at) }}</span><button @click="toggleLikePostModalComment(c)" :class="c.is_liked?'text-rose-600':''" class="ml-auto text-xs px-2 py-1 rounded-full border bg-white">{{ c.is_liked?'♥':'♡' }} {{ c.likes }}</button></div>
                  <p class="text-xs text-slate-600 mt-1 whitespace-pre-wrap break-words">{{ c.isi }}</p>
                  <div v-if="c.replies?.length" class="mt-2 ml-3 pl-3 border-l-2 border-slate-200 space-y-2">
                    <div v-for="r in c.replies" :key="r.id" class="bg-white rounded-xl px-3 py-2 flex gap-2 border border-slate-100">
                      <UserAvatar :user-id="r.user_id" :name="r.author_nama" size="w-5 h-5 flex-none" />
                      <div class="flex-1"><div class="text-xs flex items-center gap-1.5 flex-wrap"><span class="font-semibold">{{ r.author_nama }}</span><span class="text-[10px] px-1.5 py-0.5 rounded-full" :class="roleBadgeClass(r.author_role)">{{ roleBadgeLabel(r.author_role) }}</span><span class="text-[11px] text-slate-400"> -  {{ timeAgo(r.created_at) }}</span></div><p class="text-xs text-slate-600 mt-1 break-words">{{ r.isi }}</p></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="sticky bottom-0 bg-white px-5 py-3 border-t border-slate-100 flex justify-end gap-2">
            <button @click="closePostModal()" class="px-4 py-2 rounded-full border border-slate-200 bg-white text-xs font-semibold hover:bg-slate-50">Tutup</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- Laporkan modal  -  Opsi B: kategori + alasan -->
  <Teleport to="body">
    <div v-if="reportTarget" class="fixed inset-0 z-50" @keydown.esc="closeReportModal()" tabindex="-1">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="closeReportModal()"></div>
      <div class="absolute inset-0 grid place-items-center p-4 overflow-auto" @click.self="closeReportModal()">
        <div class="w-full max-w-[460px] rounded-2xl border bg-white shadow-xl border-slate-200 overflow-hidden" @click.stop>
          <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-semibold text-sm flex items-center gap-2"><span class="w-7 h-7 rounded-full bg-amber-100 text-amber-700 grid place-items-center">!</span> Laporkan Postingan</h3>
            <button @click="closeReportModal()" class="w-8 h-8 rounded-full border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 grid place-items-center" aria-label="Tutup"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M1 1L13 13"/><path d="M13 1L1 13"/></svg></button>
          </div>
          <div class="p-5 space-y-4">
            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
              <div class="text-[11px] text-slate-400">Postingan yang dilaporkan</div>
              <p class="text-[13px] font-medium mt-1 line-clamp-2 break-words">{{ reportTarget.judul || reportTarget.isi }}</p>
              <p v-if="reportTarget.judul" class="text-[12px] text-slate-500 mt-1 line-clamp-2">{{ reportTarget.isi }}</p>
            </div>
            <div>
              <label class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Kategori *</label>
              <div class="grid grid-cols-2 gap-2 mt-2">
                <button v-for="cat in ['spam','kasar','hoax','sara','lainnya']" :key="cat" @click="reportCategory=cat" :class="reportCategory===cat ? 'bg-[#2F3E46] text-white border-[#2F3E46]' : 'bg-white border-slate-200 hover:bg-slate-50'" class="px-3 py-2 rounded-full border text-xs font-semibold capitalize">{{ cat }}</button>
              </div>
            </div>
            <div>
              <label class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Alasan detail *</label>
              <textarea v-model="reportReason" rows="3" placeholder="Jelaskan mengapa postingan ini perlu ditinjau pembina..." class="mt-2 w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-white text-[13px] focus:outline-none focus:border-amber-400 resize-none"></textarea>
              <div class="text-[11px] text-slate-400 mt-1">{{ reportReason.length }}/300  -  minimal 3 karakter</div>
            </div>
          </div>
          <div class="px-5 py-3 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50">
            <button @click="closeReportModal()" class="px-4 py-2 rounded-full border border-slate-200 bg-white text-xs font-semibold hover:bg-slate-50">Batal</button>
            <button @click="submitReport()" :disabled="reportSending || reportReason.trim().length<3" class="px-5 py-2 rounded-full bg-amber-500 text-white text-xs font-semibold hover:bg-amber-600 disabled:opacity-40 disabled:cursor-not-allowed">{{ reportSending ? 'Mengirim...' : 'Kirim Laporan' }}</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>

    <div class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex flex-col gap-2 items-center w-full max-w-[calc(100vw-32px)] px-4 pointer-events-none">

  <div v-for="t in toasts" :key="t.id" class="kat-ink text-xs font-medium px-4 py-2.5 rounded-full shadow-lg flex items-center gap-2 max-w-full pointer-events-auto"><span class="truncate">{{ t.msg }}</span><button @click="toasts=toasts.filter(x=>x.id!==t.id)" class="opacity-70 hover:opacity-100">x</button></div>

 </div>



 <Teleport to="body">

 <div v-if="showEdit" class="fixed inset-0 z-40">

  <div class="absolute inset-0 bg-[#0f172a]/40 backdrop-blur-[6px]" @click="closeEdit"></div>

  <div class="absolute inset-0 grid place-items-center p-4 overflow-auto">

  <div class="w-full max-w-[680px] max-h-[90vh] overflow-auto rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] flex flex-col" style="border-color:#e2e8f0">

  <div class="sticky top-0 bg-white px-6 py-4 border-b flex items-center justify-between border-slate-100"><h3 class="font-bold text-[18px]" style="font-family:'Satoshi',system-ui,sans-serif">Edit Ekskul</h3><button @click="closeEdit" class="w-8 h-8 rounded-full border grid place-items-center hover:bg-slate-50 border-slate-200">x</button></div>

  <form @submit.prevent="saveEdit" novalidate class="flex-1">

  <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-3">

  <label class="block sm:col-span-1"><span class="text-[10px] tracking-widest font-semibold">NAMA *</span><input v-model="editForm.nama" required class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none focus:border-[#4A7875] border-slate-200"/></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold">KUOTA *</span><input type="number" min="1" v-model.number="editForm.kuota" required class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>

  <label class="block sm:col-span-2"><span class="text-[10px] tracking-widest font-semibold">DESKRIPSI *</span><textarea rows="3" v-model="editForm.deskripsi" required class="mt-1 w-full px-3 py-3 rounded-xl border bg-white text-[13px] min-h-[80px] resize-none border-slate-200"></textarea></label>

  <label v-if="auth.user?.role==='admin'" class="block sm:col-span-2"><span class="text-[10px] tracking-widest font-semibold">PEMBINA</span><input v-model="pembinaSearch" @input="onPembinaInput" placeholder="Cari nama / NIP pembina..." class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/><div v-if="pembinaList.length" class="mt-2 rounded-xl border overflow-hidden border-slate-200 max-h-[140px] overflow-auto"><button v-for="p in pembinaList" :key="p.id" type="button" @click="selectPembina(p)" class="w-full text-left px-3 py-2 hover:bg-slate-50 flex justify-between items-center text-[13px] border-b last:border-0 border-slate-50"><span class="font-medium">{{ p.nama }}</span><span class="text-[11px] text-slate-400">{{ p.nip || p.email }}</span></button></div></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold">HARI</span><select v-model="editForm.hari" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"><option value="">-</option><option>Senin</option><option>Selasa</option><option>Rabu</option><option>Kamis</option><option>Jumat</option><option>Sabtu</option><option>Minggu</option></select></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold">LOKASI</span><input v-model="editForm.lokasi" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold">JAM MULAI</span><input type="time" v-model="editForm.jam_mulai" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>

  <label class="block"><span class="text-[10px] tracking-widest font-semibold">JAM SELESAI</span><input type="time" v-model="editForm.jam_selesai" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] border-slate-200"/></label>

  <label class="flex items-center gap-2 sm:col-span-2 mt-1 cursor-pointer"><input type="checkbox" v-model="editForm.requires_approval" class="rounded" /> <span class="text-[12px] font-medium">Butuh approval</span></label>

 <div class="sm:col-span-2 mt-1 rounded-xl border p-3 bg-slate-50 border-slate-200">

 <div class="text-[10px] tracking-widest font-semibold text-slate-500">COVER (opsional)</div>

 <div class="flex items-start gap-3 mt-2">

 <div class="w-20 h-20 rounded-xl overflow-hidden border bg-slate-100 shrink-0 flex items-center justify-center" style="border-color:#e2e8f0">

 <img v-if="editCoverPreview" :src="editCoverPreview" alt="Preview cover" class="w-full h-full object-cover" />

 <span v-else class="text-slate-300 text-[24px]">-</span>

 </div>

 <div class="flex-1 min-w-0">

 <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-full border bg-white text-[12px] font-medium cursor-pointer hover:bg-slate-50"> Pilih gambar<input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onEditCoverChange" /></label>

  <button v-if="editCoverPreview" type="button" @click="hapusEditCover" class="ml-2 px-3 py-2 rounded-full border bg-white text-[12px] font-medium text-red-600 hover:bg-red-50">x Hapus</button>

  <p class="text-[11px] mt-2 text-slate-400">JPG/PNG/WEBP - maks 2MB</p>

  <p v-if="editCoverMsg" class="text-[11px] mt-1" :class="editCoverOk?'text-emerald-600':'text-red-600'">{{ editCoverMsg }}</p>

 </div>

 </div>

 </div>

 <div v-if="editJamError" class="sm:col-span-2 text-[11px] text-red-600">{{ editJamError }}</div>

  <div v-if="editMsg" class="sm:col-span-2 text-[11px]" :class="editOk?'text-emerald-600':'text-red-600'">{{ editMsg }}</div>

 </div>

 <div class="sticky bottom-0 bg-white px-6 py-4 border-t flex justify-end gap-2 border-slate-100"><button type="button" @click="closeEdit" class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium border-slate-200">Batal</button><button type="submit" :disabled="!!editJamError || !editForm.nama || !editForm.kuota" class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold kat-ink-h disabled:opacity-40 bg-slate-900">Simpan</button></div>

 </form>

 </div>

 </div>

 </div>

 </Teleport>

 <Teleport to="body">

  <div v-if="kickTarget" class="fixed inset-0 z-40"><div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="kickTarget=null"></div><div class="absolute inset-0 grid place-items-center p-4"><div class="w-full max-w-[440px] rounded-[20px] border bg-white p-6 shadow-xl border-slate-200"><h3 class="text-[16px] font-semibold">Konfirmasi Kick</h3><p class="text-[13px] mt-1 text-slate-500">Kick {{ kickTarget.nama }}? kuota {{ terisi }}/{{ e.kuota }} -> {{ Math.max(0,terisi-1) }}/{{ e.kuota }}</p><div class="mt-4 flex justify-end gap-2"><button @click="kickTarget=null" class="px-5 py-2.5 rounded-full border bg-white text-[13px] border-slate-200">Batal</button><button @click="doKick" class="px-5 py-2.5 rounded-full text-white text-[13px] bg-red-600">Kick</button></div></div></div></div>

  </Teleport>

  <Teleport to="body">

  <div v-if="delSchedTarget" class="fixed inset-0 z-40"><div class="absolute inset-0 bg-black/40 backdrop-blur-[6px]" @click="delSchedTarget=null"></div><div class="absolute inset-0 grid place-items-center p-4"><div class="w-full max-w-[440px] rounded-[20px] border bg-white p-6 shadow-xl border-slate-200"><h3 class="text-[16px] font-semibold">Hapus jadwal?</h3><p class="text-[13px] mt-1 text-slate-500">{{ delSchedTarget.tanggal }} {{ fmtJam(delSchedTarget.jam_mulai) }}- {{ fmtJam(delSchedTarget.jam_selesai) }}?</p><div class="mt-4 flex justify-end gap-2"><button @click="delSchedTarget=null" class="px-5 py-2.5 rounded-full border bg-white text-[13px] border-slate-200">Batal</button><button @click="doDeleteSched" class="px-5 py-2.5 rounded-full text-white text-[13px] bg-red-600">Hapus</button></div></div></div></div>

 </Teleport>

</div>

</div>

</template>

<script setup>

import { ref, computed, onMounted, watch, nextTick, onBeforeUnmount } from 'vue'

import { useRoute, useRouter } from 'vue-router'

import { useAuth } from '../stores/auth.js'

import { api, apiUpload, apiCover, apiDeleteCover } from '../lib/api.js'

import QRCode from 'qrcode'
import UserAvatar from '../components/UserAvatar.vue'

const route=useRoute(), router=useRouter(), auth=useAuth()

const e=ref(null), terisi=ref(0), scheds=ref([]), anggota=ref([]), summary=ref([]), attList=ref([]), attMap=ref({}), selSched=ref(''), qr=ref({}), fs=ref({tanggal:'',jam_mulai:'',jam_selesai:'',lokasi:'',tipe:'rutin'}), canvasRef=ref(null)

const loading=ref(true), loadError=ref(''), showJadwalForm=ref(false), jadwalSectionRef=ref(null), highlightSchedId=ref(null), jadwalMsg=ref(''), jadwalOk=ref(false), showAllSched=ref(false)
const jadwalEditId=ref(null), jadwalEditForm=ref({tanggal:'',jam_mulai:'',jam_selesai:'',lokasi:'',tipe:'rutin'}), jadwalEditMsg=ref(''), jadwalEditOk=ref(false)

const showEdit=ref(false), editForm=ref({nama:'',deskripsi:'',pembina_id:'',kuota:0,requires_approval:false,hari:'',jam_mulai:'',jam_selesai:'',lokasi:''}), editMsg=ref(''), editOk=ref(false), pembinaSearch=ref(''), pembinaList=ref([]), pembinaTimer=null

const editCoverPreview=ref(''), editCoverMsg=ref(''), editCoverOk=ref(false)

const kickTarget=ref(null), delSchedTarget=ref(null), toasts=ref([])

const pengList=ref([]), pengInput=ref(''), pengPin=ref(true), pengMenuOpen=ref(null), pengEditId=ref(null), pengEditText=ref('')
const pengGambarFile=ref(null), pengGambarPreview=ref(''), pengGambarMsg=ref(''), pengGambarOk=ref(false), pengGambarInputRef=ref(null)
const pengEditGambarFile=ref(null), pengEditGambarPreview=ref(''), pengEditGambarMsg=ref(''), pengEditRemoveGambar=ref(false), pengEditGambarInputRef=ref(null)
const pengMenuPos=ref({ top: 0, left: 0 })
const pengView=ref(null), pengViewShowGambar=ref(false)
const postView=ref(null), postViewLoading=ref(false), postViewError=ref(''), postViewShowGambar=ref({}), postViewNewComment=ref(''), postViewMentionIds=ref([])
const reportTarget=ref(null), reportCategory=ref('spam'), reportReason=ref(''), reportSending=ref(false)
const reports=ref([]), reportsLoading=ref(false), reportsPending=ref(0), reportsTab=ref('pending'), showReports=ref(false)

const activeTab=ref('diskusi'), composerInput=ref(''), composerJudul=ref(''), pendingUploads=ref([])

const lightboxSrc=ref(null), anggotaSearch=ref(''), daftarMsg=ref(''), daftarOk=ref(false)

const isRegistered=ref(false)

const posts=ref([]), postsLoading=ref(false), counts=ref({diskusi:0,tanya:0,postingan:0}), unreadCounts=ref({diskusi:0,tanya:0,postingan:0}), unreadPollingId=ref(null)

const tabPostsLoaded=ref({diskusi:false,tanya:false,postingan:false}), postsHasMore=ref(false), postsPage=ref({diskusi:1,tanya:1,postingan:1})

const myRekap=ref(null), myRekapLoading=ref(false), myRekapLoaded=ref(false), rekapLoading=ref(false), rekapLoaded=ref(false), anggotaFullLoaded=ref(false)

const composerEl=ref(null), mentionOpen=ref(false), mentionQuery=ref(''), mentionIdx=ref(0), mentionSelectedIds=ref([]), pollCommentMap=ref({})

const showPollComposer=ref(false), pollOptions=ref(['','']), pollsList=ref([]), pollsLoaded=ref(false)

const mentionCache=ref([])

const showManagePanel=ref(false)
const showAllAnggotaModal=ref(false)
const anggotaCountsCache=ref({diskusi:0,tanya:0,postingan:0, ts:0})

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

async function votePoll(pl, optId){
 // locked: closed or already voted → block client-side
 if(isPembina || auth.user?.role==='kepsek'){ toast('Hanya siswa yang boleh memberikan suara', false); return }
 if(pl.closed_at && new Date(pl.closed_at) < new Date()){ toast('Poll sudah ditutup', false); return }
  if(pl.is_closed){ toast('Poll sudah ditutup', false); return }
  if(pl.my_vote){ toast('Sudah vote, tidak bisa diubah', false); return }
  const prev={ options: JSON.parse(JSON.stringify(pl.options)), total_votes: pl.total_votes, my_vote: pl.my_vote }
  // optimistic: set my_vote immediately
  pl.my_vote=optId
  try{
    const j=await api('/polls/'+pl.id+'/vote',{method:'POST',body:{option_id:optId}})
    pl.options=j.data.options; pl.total_votes=j.data.total ?? j.data.total_votes; pl.my_vote=j.data.my_vote
    if(j.error?.code==='CLOSED' || j.data?.is_closed) pl.is_closed=true
    toast('Suara tercatat')
  }catch(ex){
    // rollback optimistic
    pl.options=prev.options; pl.total_votes=prev.total_votes; pl.my_vote=prev.my_vote
    const code=ex.error?.code||ex.code
    if(code==='CLOSED' || ex.status===410) { pl.is_closed=true; pl.closed_at=new Date().toISOString(); toast('Poll sudah ditutup', false) }
    else if(code==='ALREADY_VOTED' || code==='EXISTS' || ex.status===409) { toast(ex.error?.message||'Sudah vote, tidak bisa diubah', false); await loadPolls() }
    else toast(ex.error?.message||'Gagal vote',false)
  }
}
async function closePoll(pl){
  if(!confirm('Tutup polling ini?')) return
  try{ await api('/polls/'+pl.id+'/close',{method:'POST',body:{}}); pl.is_closed=true; pl.closed_at=new Date().toISOString(); toast('Poll ditutup') }
  catch(ex){ toast(ex.error?.message||'Gagal tutup poll', false) }
}

function roleBadgeLabel(r){

 if(r==='admin') return 'Admin'

 if(r==='kepsek') return 'Kepsek'

 if(r==='pembina') return 'Pembina'

 return 'Siswa'

}

function roleBadgeClass(r){

 if(r==='admin') return 'bg-amber-500 text-white'

 if(r==='kepsek') return 'bg-purple-600 text-white'

 if(r==='pembina') return 'kat-ink'

 return 'bg-slate-100 text-slate-600'

}

function fmtJam(v){ if(!v) return '-'; return String(v).slice(0,5) }

function timeAgo(s){ if(!s) return 'baru saja'; const d=new Date(s), diff=(Date.now()-d.getTime())/1000; if(diff<60) return 'baru saja'; if(diff<3600) return Math.floor(diff/60)+' menit lalu'; if(diff<86400) return Math.floor(diff/3600)+' jam lalu'; return Math.floor(diff/86400)+' hari lalu' }

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
const isMobileAnggota=ref(false)
function updateIsMobile(){ try{ isMobileAnggota.value=window.innerWidth<640 }catch{} }
const anggotaPreviewCount=computed(()=> isMobileAnggota.value?3:5)
const anggotaPreview=computed(()=> filteredAnggota.value.slice(0, anggotaPreviewCount.value))
const anggotaSisa=computed(()=> Math.max(0, filteredAnggota.value.length - anggotaPreviewCount.value))

const composerPlaceholder=computed(()=> {

 const m={diskusi:'Tulis pertanyaan atau mulai diskusi... (bisa @mention)', tanya:'Ajukan pertanyaan...', postingan:'Tulis caption untuk foto...'}

 return m[activeTab.value]||m.diskusi

})

const currentPosts=computed(()=> posts.value.filter(p=> p.tipe===activeTab.value))

const isKomunitasPost=computed(()=> ['diskusi','tanya','postingan'].includes(activeTab.value))

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

function togglePengMenu(id, ev){
  if(pengMenuOpen.value===id){ pengMenuOpen.value=null; return }
  if(ev?.currentTarget){
    const r=ev.currentTarget.getBoundingClientRect()
    const vw=Math.max(document.documentElement.clientWidth, window.innerWidth||0)
    const vh=Math.max(document.documentElement.clientHeight, window.innerHeight||0)
    const menuW=176, menuH=112
    let left=r.right - menuW
    let top=r.bottom + 6
    if(left < 8) left=8
    if(left+menuW > vw-8) left=vw - menuW - 8
    if(top+menuH > vh-8) top=r.top - menuH - 6
    if(top < 8) top=8
    pengMenuPos.value={ top, left }
  }
  pengMenuOpen.value=id
}
function pengMenuCurrent(){ return pengList.value.find(x=>x.id===pengMenuOpen.value) || null }
function openPengView(p){ pengView.value=p; pengViewShowGambar.value=false }
function closePengView(){ pengView.value=null; pengViewShowGambar.value=false }

// === Post detail modal  -  ganti tombol Buka yang dulu router-link ke tab baru ===
async function openPostModal(th){
  // optimistic: tampilkan data list dulu, lalu fetch detail lengkap
  postView.value={...th, comments:[], _loadingComments:true}
  postViewLoading.value=true
  postViewError.value=''
  postViewNewComment.value=''
  postViewMentionIds.value=[]
  try{
    const j=await api('/ekskul/'+route.params.id+'/posts/'+th.id)
    // j.data berisi post lengkap + comments tree + uploads + poll
    postView.value=j.data
    // sinkronkan likes/is_liked ke list utama juga
    const idx=posts.value.findIndex(p=>p.id===th.id)
    if(idx!==-1){ posts.value[idx].likes=j.data.likes; posts.value[idx].is_liked=j.data.is_liked; posts.value[idx].comments_count=j.data.comments_count }
  }catch(ex){ postViewError.value=ex.error?.message||'Gagal memuat postingan'; postView.value=null }
  finally{ postViewLoading.value=false }
}
function closePostModal(){ postView.value=null; postViewLoading.value=false; postViewError.value=''; postViewNewComment.value=''; postViewMentionIds.value=[] }
async function toggleLikePostModal(){
  if(!postView.value) return
  try{
    const j=await api('/ekskul/'+route.params.id+'/posts/'+postView.value.id+'/like',{method:'POST',body:{}})
    postView.value.is_liked=j.data.liked; postView.value.likes=j.data.likes
    const idx=posts.value.findIndex(p=>p.id===postView.value.id)
    if(idx!==-1){ posts.value[idx].is_liked=j.data.liked; posts.value[idx].likes=j.data.likes }
  }catch(ex){ toast(ex.error?.message||'Gagal like',false) }
}
async function sendPostModalComment(){
  if(!postView.value) return
  const v=postViewNewComment.value.trim(); if(!v) return
  try{
    await api('/ekskul/'+route.params.id+'/posts/'+postView.value.id+'/comments',{method:'POST',body:{isi:v, mention_ids: postViewMentionIds.value}})
    postViewNewComment.value=''; postViewMentionIds.value=[]
    const j=await api('/ekskul/'+route.params.id+'/posts/'+postView.value.id)
    postView.value=j.data
    const idx=posts.value.findIndex(p=>p.id===postView.value.id)
    if(idx!==-1) posts.value[idx].comments_count=j.data.comments_count
    toast('Balasan terkirim')
  }catch(ex){ toast(ex.error?.message||'Gagal kirim komentar',false) }
}
async function toggleLikePostModalComment(c){
  try{
    const j=await api('/comments/'+c.id+'/like',{method:'POST',body:{}})
    c.is_liked=j.data.liked; c.likes=j.data.likes
  }catch(ex){ toast(ex.error?.message||'Gagal like',false) }
}

function copyPostLink(th){

 const prefix=th.tipe==='diskusi'?'d':th.tipe==='tanya'?'t':'p'

 const url=location.origin+`/ekskul/${route.params.id}/${prefix}/${th.id}`

 navigator.clipboard?.writeText(url).catch(()=>{})

 toast('Link disalin: '+url)

}



async function load(){

 loading.value=true; loadError.value=''

 try{

 const id=route.params.id

 const res=await api('/ekskul/'+id).catch(ex=>{

 const c=ex?.error?.code||ex?.code

 loadError.value=(c==='NOT_FOUND'||c==='FORBIDDEN')?'Tidak dapat membuka ekskul ini':'Gagal memuat ekskul'

 return null

 })

 if(!res){ e.value=null; return }

 const j=res

 const results=await Promise.all([

 api('/ekskul/'+id+'/schedules').catch(()=>({data:[]})),

 api('/ekskul/'+id+'/pengumuman').catch(()=>({data:[]})),

 auth.user ? api('/ekskul/'+id+'/anggota?limit=5').catch(()=>({data:[]})): Promise.resolve({data:[]})

 ])

  const s=results[0], p=results[1], a=results[2]

  e.value=j.data; terisi.value=j.data.terisi ?? 0
  try{ const _n=String(j.data.nama||'').trim(); document.title=(_n?_n.slice(0,40):'Ekskul')+' | Eskulify' }catch{}

 scheds.value=s.data||[]

  pengList.value=(p.data||[]).map(x=> ({...x, _showGambar:false}))

  anggota.value=a.data||[]

 if(auth.user?.role==='siswa'){

 const found=anggota.value.find(x=> String(x.user_id)===String(auth.user.id))

 if(found && ['diterima','menunggu'].includes(found.status)) isRegistered.value=true

 else {

 try{ const mr=await api('/me/registrations'); const f=mr.data.find(r=> String(r.ekskul_id)===String(id)); isRegistered.value=!!f && ['diterima','menunggu'].includes(f.status) }catch{ isRegistered.value=!!found }

 }

 }

 startPresence()

 initDetailTab()

 } finally { loading.value=false }

}

async function fetchPosts(tipe='diskusi', page=1, append=false){

 if(!auth.user) return

 if(!append) postsLoading.value=true

 try{

 const j=await api(`/ekskul/${route.params.id}/posts?tipe=${tipe}&limit=10&page=${page}&_t=${Date.now()}`)

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

  // use meta.total for accurate badge, fallback to local length
  if(j.meta?.total !== undefined) counts.value[tipe]=j.meta.total
  else {
    const c={diskusi:0,tanya:0,postingan:0}
    posts.value.forEach(p=>{ if(c[p.tipe]!==undefined) c[p.tipe]++ })
    counts.value=c
  }
  // update cache
  anggotaCountsCache.value={...counts.value, ts: Date.now()}

 }catch{} finally { postsLoading.value=false }

}

async function fetchPostsIfEmpty(tipe){

 if(tabPostsLoaded.value[tipe]) return

 await fetchPosts(tipe,1,false)

}

async function loadMorePosts(){

 const t=activeTab.value

 if(!postsHasMore.value) return

 const next=(postsPage.value[t]||1)+1

 await fetchPosts(t,next,true)

}

async function loadAllPosts(){

 // legacy alias for compat (kirimPost refresh all)

 await fetchPosts(activeTab.value,1,false)

}
async function fetchCountsEager(force=false){
 if(!auth.user) return
 const now=Date.now()
 if(!force && (now - anggotaCountsCache.value.ts < 60000) && anggotaCountsCache.value.ts){
   counts.value={diskusi: anggotaCountsCache.value.diskusi||0, tanya: anggotaCountsCache.value.tanya||0, postingan: anggotaCountsCache.value.postingan||0}
   return
 }
 try{
   const [a,b,c]=await Promise.all([
     api(`/ekskul/${route.params.id}/posts?tipe=diskusi&limit=1`),
     api(`/ekskul/${route.params.id}/posts?tipe=tanya&limit=1`).catch(()=>({meta:{total:0}})),
     api(`/ekskul/${route.params.id}/posts?tipe=postingan&limit=1`).catch(()=>({meta:{total:0}}))
   ])
   // a may also fail
   counts.value={diskusi: a?.meta?.total ?? 0, tanya: b?.meta?.total ?? 0, postingan: c?.meta?.total ?? 0}
   anggotaCountsCache.value={...counts.value, ts: now}
   }catch{}
   }

   async function fetchUnreadCounts(){
   if(!auth.user||!route.params.id) return
   try{
    const j=await api(`/ekskul/${route.params.id}/unread-counts?_t=${Date.now()}`)
    if(j.data) unreadCounts.value={diskusi:j.data.diskusi||0, tanya:j.data.tanya||0, postingan:j.data.postingan||0}
   }catch{}
   }

   async function markTabRead(tipe){
   if(!auth.user||!route.params.id) return
   try{
    await api(`/ekskul/${route.params.id}/mark-read`,{method:'POST',body:{tipe}})
    unreadCounts.value[tipe]=0
   }catch{}
   }

   function startUnreadPolling(){
   stopUnreadPolling()
   unreadPollingId.value=setInterval(()=>{ if(auth.user&&route.params.id) fetchUnreadCounts() },30000)
   }
   function stopUnreadPolling(){ if(unreadPollingId.value){ clearInterval(unreadPollingId.value); unreadPollingId.value=null } }

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

 if(!auth.user) return

 try{

 const j=await api('/ekskul/'+route.params.id+'/anggota')

 anggota.value=j.data||[]; anggotaFullLoaded.value=true

 }catch{}

}

const detailTab=ref('overview')

const tabLabels={overview:'Ringkasan',jadwal:'Jadwal',anggota:'Anggota',absensi:'Absensi',komunitas:'Komunitas'}

// Anti-leak: tab allowed per role. Guest: ringkasan/jadwal/komunitas. Login: +anggota. Pembina/admin: +absensi.

const allowedTabs=computed(()=>{

 const t=['overview','jadwal']

 if(auth.user) t.push('anggota')

 if(isPembina.value) t.push('absensi')

 t.push('komunitas')

 return t

})

async function setDetailTab(t){

 if(!allowedTabs.value.includes(t)) t='overview'

 detailTab.value=t

 try{ if(route.query.tab!==t) router.replace({query:{...route.query, tab:t}}).catch(()=>{}) }catch{}

 // Lazy per tab: hanya fetch saat tab dibuka (performa)

 if(t==='anggota'){ await fetchAnggotaFull(); if(isAnggota.value && !isPembina.value) await fetchMyRekapIfEmpty() }

 else if(t==='absensi'){ await fetchAnggotaFull(); await fetchRekapIfEmpty() }

  else if(t==='komunitas'){ await fetchCountsEager(); fetchUnreadCounts(); const st=activeTab.value; if(['diskusi','tanya','postingan'].includes(st)){ if(!tabPostsLoaded.value[st]) await fetchPostsIfEmpty(st); if(st==='tanya' && !pollsLoaded.value) await fetchPollsIfEmpty() } if(isPembina.value) fetchReports() }

}

function initDetailTab(){

 const q=String(route.query.tab||'')

 setDetailTab(allowedTabs.value.includes(q)?q:'overview')

}

async function openAnggotaTab(){ await setDetailTab('anggota') }

async function switchTab(t){

 activeTab.value=t

 if(['diskusi','tanya','postingan'].includes(t)){

 if(!tabPostsLoaded.value[t]) await fetchPostsIfEmpty(t)

 if(t==='tanya' && !pollsLoaded.value) await fetchPollsIfEmpty()

 markTabRead(t).then(()=> fetchUnreadCounts()).catch(()=>{})

 }

}

function onFileChange(e){

 const files=[...e.target.files]
 const needImage=(activeTab.value==='diskusi'||activeTab.value==='postingan')

 files.forEach(f=>{

 if(f.size>5*1024*1024){ toast(f.name+' >5MB ditolak', false); return }
 if(needImage && !f.type.startsWith('image/')){ toast(f.name+'  -  hanya gambar yang diizinkan untuk '+activeTab.value, false); return }

 const isImage=f.type.startsWith('image/')

 const preview=isImage?URL.createObjectURL(f):null

 pendingUploads.value.push({file:f, name:f.name, size:f.size, isImage, preview})

 })

  e.target.value=''

  }

function removePending(i){
  const p=pendingUploads.value[i]
  if(p?.preview) try{ URL.revokeObjectURL(p.preview) }catch{}
  pendingUploads.value.splice(i,1)
}

async function kirimPost(){

 const isi=composerInput.value.trim()
 const tipe=activeTab.value
  const judul=tipe==='tanya'?composerJudul.value.trim():''

  // allow tanya poll without isi/judul (auto-fill), still block completely empty non-poll post
 if(!isi && !pendingUploads.value.length && !(tipe==='tanya' && (judul || showPollComposer.value))) return

 if(!canPost.value){ toast('Hanya anggota bisa posting', false); return }

 // pre-validate poll before creating post  -  auto-fill question if empty
 let effectiveJudul=judul
 let effectiveIsi=isi
 if(tipe==='tanya' && showPollComposer.value){
   const preOpts=pollOptions.value.map(s=>s.trim()).filter(Boolean)
   if(preOpts.length<2){ toast('Poll butuh minimal 2 opsi terisi', false); return }
   if(preOpts.length>6){ toast('Poll maksimal 6 opsi', false); return }
   const q=(judul||isi).trim()
   if(q.length<5){
     // auto-fill from options to satisfy BE min 5 & tanya min 10
     const autoQ='Poll: ' + preOpts.slice(0,2).join(' vs ')
     effectiveJudul=judul || autoQ
     effectiveIsi=isi || autoQ
     if((effectiveJudul||effectiveIsi).length<5) effectiveJudul=autoQ
     if(tipe==='tanya' && !effectiveJudul && effectiveIsi.length<10) effectiveIsi=autoQ + ' - pilih salah satu'
   }
 }


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

  const postRes=await api('/ekskul/'+route.params.id+'/posts',{method:'POST',body:{tipe, judul:effectiveJudul||null, isi: effectiveIsi||'(poll)', upload_ids:uploadIds, mention_ids: mentionSelectedIds.value}})
  const newPostId=postRes.data?.id||null
  // optimistic badge increment
  counts.value[tipe]=(counts.value[tipe]||0)+1
  anggotaCountsCache.value={...counts.value, ts: Date.now()}

   // if tanya + poll composer, create poll after post (pre-validated, with fallback question)
   if(tipe==='tanya' && showPollComposer.value){

   const opts=pollOptions.value.map(s=>s.trim()).filter(Boolean)

    try{
      const pollQ=(effectiveJudul||effectiveIsi||judul||isi||'Poll: '+opts.slice(0,2).join(' vs ')).trim()
      const pollBody={question: pollQ.length>=5?pollQ:('Poll: '+opts.join(' / ')), options: opts}
      if(newPostId) pollBody.post_id=newPostId
      await api('/ekskul/'+route.params.id+'/polls',{method:'POST',body:pollBody}); await loadPolls()
    }catch(ex){ toast('Poll: '+(ex.error?.message||'gagal')+'  -  post tetap terkirim tanpa poll',false); console.error('poll create fail', ex) }

     pollOptions.value=['','']; showPollComposer.value=false

    }

   composerInput.value=''; composerJudul.value=''; mentionSelectedIds.value=[]; mentionOpen.value=false

  pendingUploads.value.forEach(p=>{ if(p.preview) try{URL.revokeObjectURL(p.preview)}catch{} }); pendingUploads.value=[]

  toast('Dipost v')

  await loadAllPosts()
  // re-sync counts from server meta after loadAllPosts, then refresh eager
  await fetchCountsEager(true)
  fetchUnreadCounts()
  markTabRead(activeTab.value).then(()=> fetchUnreadCounts()).catch(()=>{})

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

 const tipe=th.tipe
 posts.value=posts.value.filter(p=>p.id!==th.id)
 if(tipe && counts.value[tipe]>0){ counts.value[tipe]-- ; anggotaCountsCache.value={...counts.value, ts: Date.now()} }
 toast('Post dihapus')

}

function openReportModal(th){
  if(!canPost.value){ toast('Hanya anggota ekskul ini yang bisa melapor', false); return }
  if(th._reported){ toast('Sudah dilaporkan', false); return }
  if(String(th.user_id)===String(auth.user?.id)){ toast('Tidak bisa melaporkan postingan sendiri', false); return }
  reportTarget.value=th
  reportCategory.value='lainnya'
  reportReason.value=''
}
function closeReportModal(){ reportTarget.value=null; reportReason.value=''; reportSending.value=false }
async function submitReport(){
  const th=reportTarget.value; if(!th) return
  const cat=reportCategory.value
  const reason=reportReason.value.trim()
  if(reason.length<3){ toast('Alasan minimal 3 karakter', false); return }
  reportSending.value=true
  try{
    await api(`/ekskul/${route.params.id}/posts/${th.id}/report`,{method:'POST',body:{category:cat, reason}})
    th._reported=true
    toast('Laporan terkirim ke pembina  -  terima kasih')
    closeReportModal()
    if(isPembina.value) fetchReports()
  }catch(ex){
    if(ex.status===409){ th._reported=true; toast('Sudah dilaporkan sebelumnya', false); closeReportModal() }
    else toast(ex.error?.message||'Gagal laporkan', false)
  } finally{ reportSending.value=false }
}
async function reportPost(th){ openReportModal(th) }

async function fetchReports(){
  if(!isPembina.value) return
  reportsLoading.value=true
  try{
    const j=await api(`/ekskul/${route.params.id}/reports?status=${reportsTab.value}`)
    reports.value=j.data||[]; reportsPending.value=j.meta?.pending??0
  }catch{ reports.value=[] } finally{ reportsLoading.value=false }
}
async function handleReport(r, action){
  const label=action==='resolve_delete' ? 'Hapus postingan yang dilaporkan?' : 'Abaikan laporan ini?'
  if(!confirm(label)) return
  try{
    await api(`/ekskul/${route.params.id}/reports/${r.report_id}/handle`,{method:'POST',body:{action}})
    if(action==='resolve_delete'){
      // remove post from list
      posts.value=posts.value.filter(p=>p.id!==r.post_id)
      if(postView.value?.id===r.post_id) closePostModal()
      toast('Post dihapus & laporan resolved')
    } else {
      toast('Laporan diabaikan')
    }
    await fetchReports()
  }catch(ex){ toast(ex.error?.message||'Gagal proses laporan', false) }
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
function startEditSched(s){
  jadwalEditId.value=s.id
  jadwalEditForm.value={ tanggal:s.tanggal||'', jam_mulai:String(s.jam_mulai||'').slice(0,5), jam_selesai:String(s.jam_selesai||'').slice(0,5), lokasi:s.lokasi||'', tipe:s.tipe||'rutin' }
  jadwalEditMsg.value=''; jadwalEditOk.value=false
}
function cancelEditSched(){ jadwalEditId.value=null; jadwalEditMsg.value='' }
async function saveEditSched(){
  const id=jadwalEditId.value; if(!id) return
  const f=jadwalEditForm.value
  if(!f.tanggal || !f.jam_mulai || !f.jam_selesai){ jadwalEditMsg.value='Tanggal & jam wajib'; return }
  if(!f.lokasi || !f.lokasi.trim()){ jadwalEditMsg.value='Lokasi wajib'; return }
  const a=new Date('2000-01-01T'+f.jam_mulai), b=new Date('2000-01-01T'+f.jam_selesai)
  if(a>=b){ jadwalEditMsg.value='Jam selesai harus > jam mulai'; return }
  try{
    await api('/schedules/'+id,{method:'PATCH',body:{ tanggal:f.tanggal, jam_mulai:f.jam_mulai, jam_selesai:f.jam_selesai, lokasi:f.lokasi.trim(), tipe:f.tipe }})
    const curId=id; jadwalEditId.value=null
    await load()
    highlightSchedId.value=curId; setTimeout(()=> highlightSchedId.value=null, 2200)
    toast('Jadwal diupdate', true)
  }catch(ex){ jadwalEditOk.value=false; jadwalEditMsg.value=ex.error?.message||'Gagal update'; toast(jadwalEditMsg.value,false) }
}

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

 toast(`Kick ${nama}? kuota ${before}/${kuota}->${after}/${kuota}`, true)

 await load()

 }catch(ex){ toast(ex.error?.message||'Gagal kick', false) }

}

async function acc(rid,action){ try{ await api('/registrations/'+rid+'/status',{method:'POST',body:{action}}); toast(action==='approve'?'ACC berhasil':'Ditolak', true); await load() }catch(ex){ toast(ex.error?.message,false) } }

async function markAtt(uid,status){ if(!selSched.value) return; if(!status) return; try{ await api('/attendance/mark',{method:'POST',body:{schedule_id:parseInt(selSched.value),user_id:uid,status}}); await refreshAtt(); await loadSummaryOnly(); toast('Absensi diupdate', true) }catch(ex){ toast(ex.error?.message,false) } }

async function refreshAtt(){ if(!selSched.value){ attList.value=[]; attMap.value={}; return } try{ const j=await api('/attendance/list/'+selSched.value); attList.value=j.data; const m={}; j.data.forEach(r=>m[r.user_id]=r.status); attMap.value=m }catch{ attList.value=[]; attMap.value={} } }

async function loadSummaryOnly(){ try{ let sm; try{ sm=await api('/ekskul/'+route.params.id+'/rekap') }catch{ sm=await api('/attendance/summary/'+route.params.id) } summary.value=sm.data }catch{} }

watch(selSched, refreshAtt)

watch(()=> auth.user?.role, ()=>{

 // Role berubah (login/logout): reset tab ilegal agar tidak leak

 if(!allowedTabs.value.includes(detailTab.value)) setDetailTab('overview')

})

function openEdit(){

  editForm.value={ nama:e.value.nama||'', deskripsi:e.value.deskripsi||'', pembina_id:e.value.pembina_id||'', kuota:e.value.kuota||0, requires_approval: !!e.value.requires_approval, hari:e.value.hari||'', jam_mulai: (e.value.jam_mulai||'').slice(0,5), jam_selesai: (e.value.jam_selesai||'').slice(0,5), lokasi:e.value.lokasi||'' }

  pembinaSearch.value=''; pembinaList.value=[]; editMsg.value=''; showEdit.value=true

  editCoverPreview.value=e.value?.cover_url||''; editCoverMsg.value=''; editCoverOk.value=false

}

function closeEdit(){ if(editCoverPreview.value && editCoverPreview.value.startsWith('blob:')) try{ URL.revokeObjectURL(editCoverPreview.value) }catch{}; editCoverPreview.value=''; showEdit.value=false; editMsg.value=''; editCoverMsg.value=''; editCoverOk.value=false }

async function onEditCoverChange(ev){

 const f=ev.target.files?.[0]; ev.target.value=''

 if(!f) return

 if(f.size>2*1024*1024){ editCoverMsg.value='Ukuran >2MB'; editCoverOk.value=false; return }

 if(!['image/jpeg','image/png','image/webp'].includes(f.type)){ editCoverMsg.value='Hanya JPG/PNG/WEBP'; editCoverOk.value=false; return }

 editCoverMsg.value='Mengunggah...'; editCoverOk.value=false

  try{

  const j=await apiCover('ekskul', route.params.id, f)

  if(editCoverPreview.value && editCoverPreview.value.startsWith('blob:')) try{ URL.revokeObjectURL(editCoverPreview.value) }catch{}
  editCoverPreview.value=j.data?.cover_url||URL.createObjectURL(f)

 e.value.cover_url=j.data?.cover_url||e.value.cover_url

 editCoverMsg.value='Cover diperbarui'; editCoverOk.value=true

 }catch(ex){ editCoverMsg.value=ex.error?.message||'Gagal upload'; editCoverOk.value=false }

}

async function hapusEditCover(){

 if(!confirm('Hapus cover?')) return

 editCoverMsg.value='Menghapus...'; editCoverOk.value=false

 try{

  await apiDeleteCover('ekskul', route.params.id)

   if(editCoverPreview.value && editCoverPreview.value.startsWith('blob:')) try{ URL.revokeObjectURL(editCoverPreview.value) }catch{}
   editCoverPreview.value=''; if(e.value) e.value.cover_url=null

 editCoverMsg.value='Cover dihapus'; editCoverOk.value=true

 }catch(ex){ editCoverMsg.value=ex.error?.message||'Gagal hapus'; editCoverOk.value=false }

}

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

function onPengGambarChange(e){
  const f=e.target.files?.[0]; e.target.value=''
  if(!f) return
  if(f.size>2*1024*1024){ pengGambarMsg.value='Gambar maksimal 2MB'; pengGambarOk.value=false; return }
  if(!['image/jpeg','image/png','image/webp'].includes(f.type)){ pengGambarMsg.value='Hanya JPG/PNG/WEBP'; pengGambarOk.value=false; return }
  if(pengGambarPreview.value) try{ URL.revokeObjectURL(pengGambarPreview.value)}catch{}
  pengGambarFile.value=f; pengGambarPreview.value=URL.createObjectURL(f); pengGambarMsg.value=''; pengGambarOk.value=false
}
function removePengGambar(){
  if(pengGambarPreview.value) try{ URL.revokeObjectURL(pengGambarPreview.value)}catch{}
  pengGambarFile.value=null; pengGambarPreview.value=''; pengGambarMsg.value=''; if(pengGambarInputRef.value) pengGambarInputRef.value.value=''
}

async function postPengumuman(){

 const v=pengInput.value.trim(); if(!v) return

 if(!isPengumumanAllowed.value){ toast('Hanya Pembina yang bisa posting', false); return }

 try{
  let j
  if(pengGambarFile.value){
    const fd=new FormData(); fd.append('isi', v); fd.append('is_pinned', pengPin.value?1:0); fd.append('gambar', pengGambarFile.value)
    j=await api('/ekskul/'+route.params.id+'/pengumuman',{method:'POST',body:fd})
  } else {
    j=await api('/ekskul/'+route.params.id+'/pengumuman',{method:'POST',body:{isi:v, is_pinned: pengPin.value?1:0}})
  }
  const item={...j.data, _showGambar:false}
  pengList.value.unshift(item)

  pengInput.value=''; removePengGambar()

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
function startPengEdit(p){ pengEditId.value=p.id; pengEditText.value=p.isi; pengEditGambarFile.value=null; pengEditGambarPreview.value=''; pengEditGambarMsg.value=''; pengEditRemoveGambar.value=false; if(pengEditGambarInputRef.value) pengEditGambarInputRef.value.value='' }
function cancelPengEdit(){ if(pengEditGambarPreview.value && pengEditGambarFile.value) URL.revokeObjectURL(pengEditGambarPreview.value); pengEditId.value=null; pengEditText.value=''; pengEditGambarFile.value=null; pengEditGambarPreview.value=''; pengEditGambarMsg.value=''; pengEditRemoveGambar.value=false; if(pengEditGambarInputRef.value) pengEditGambarInputRef.value.value='' }
function onPengEditGambarChange(e){
  const f=e.target.files?.[0]; pengEditGambarMsg.value='';
  if(!f) return;
  if(f.size>2*1024*1024){ pengEditGambarMsg.value='Gambar maksimal 2MB'; e.target.value=''; return }
  const ok=['image/jpeg','image/png','image/webp']; if(!ok.includes(f.type)){ pengEditGambarMsg.value='Hanya JPG/PNG/WEBP'; e.target.value=''; return }
  if(pengEditGambarPreview.value && pengEditGambarFile.value) URL.revokeObjectURL(pengEditGambarPreview.value);
  pengEditGambarFile.value=f; pengEditGambarPreview.value=URL.createObjectURL(f); pengEditRemoveGambar.value=false;
}
function removePengEditGambar(){
  if(pengEditGambarPreview.value && pengEditGambarFile.value) URL.revokeObjectURL(pengEditGambarPreview.value);
  pengEditGambarFile.value=null; pengEditGambarPreview.value=''; pengEditGambarMsg.value='';
  if(pengEditGambarInputRef.value) pengEditGambarInputRef.value.value='';
  // if had existing gambar, mark no extra action; preview cleared but existing still shown via has_gambar block fallback
}
async function savePengEdit(p){
  const v=pengEditText.value.trim(); if(!v){ toast('Isi tidak boleh kosong', false); return }
  if(v.length<3){ toast('Minimal 3 karakter', false); return }
  const hasGambarChange = !!pengEditGambarFile.value || pengEditRemoveGambar.value;
  const isiChanged = v!==p.isi;
  if(!isiChanged && !hasGambarChange){ cancelPengEdit(); return }
  try{
    let j;
    if(hasGambarChange){
      const fd=new FormData(); fd.append('isi', v);
      if(pengEditGambarFile.value) fd.append('gambar', pengEditGambarFile.value);
      if(pengEditRemoveGambar.value) fd.append('remove_gambar','1');
      // PATCH + multipart tidak mengisi $_POST/$_FILES di PHP, jadi pakai POST untuk FormData (backend menerima PATCH/POST di route edit)
      j=await api('/ekskul/'+route.params.id+'/pengumuman/'+p.id,{method:'POST', body: fd})
    } else {
      j=await api('/ekskul/'+route.params.id+'/pengumuman/'+p.id,{method:'PATCH',body:{isi:v}})
    }
    p.isi=j.data.isi; p.has_gambar=j.data.has_gambar; p.gambar_url=j.data.gambar_url; p._showGambar=false;
    if(pengEditGambarPreview.value && pengEditGambarFile.value) URL.revokeObjectURL(pengEditGambarPreview.value);
    pengEditId.value=null; pengEditText.value=''; pengEditGambarFile.value=null; pengEditGambarPreview.value=''; pengEditRemoveGambar.value=false;
    toast('Pengumuman diperbarui')
  }catch(ex){ toast(ex.error?.message||'Gagal edit', false) }
}

function onDocClick(e){
 // menu now teleported (fixed); keep as fallback for outside clicks that bypass overlay
 if(pengMenuOpen.value===null) return
 const menuEl=document.getElementById('peng-menu-teleport')
 if(menuEl && (menuEl.contains(e.target) || e.target.closest('[data-peng-menu-btn]'))) return
 if(!e.target.closest('button')) pengMenuOpen.value=null
}

function onKeyEsc(e){ if(e.key==='Escape'){ if(reportTarget.value) closeReportModal(); if(postView.value) closePostModal(); if(showAllAnggotaModal.value) showAllAnggotaModal.value=false; if(showEdit.value) closeEdit(); if(kickTarget.value) kickTarget.value=null; if(delSchedTarget.value) delSchedTarget.value=null; if(pengView.value) closePengView() } }
onMounted(()=>{ load(); document.addEventListener('click', onDocClick); updateIsMobile(); window.addEventListener('resize', updateIsMobile); window.addEventListener('keydown', onKeyEsc); startUnreadPolling() })

onBeforeUnmount(()=>{ document.removeEventListener('click', onDocClick); window.removeEventListener('resize', updateIsMobile); window.removeEventListener('keydown', onKeyEsc); stopPresence(); stopUnreadPolling(); try{ pendingUploads.value.forEach(p=>{ if(p.preview) URL.revokeObjectURL(p.preview) }) }catch{}; try{ if(editCoverPreview.value && editCoverPreview.value.startsWith('blob:')) URL.revokeObjectURL(editCoverPreview.value) }catch{}; try{ if(pengGambarPreview.value) URL.revokeObjectURL(pengGambarPreview.value) }catch{}; try{ if(pengEditGambarPreview.value && pengEditGambarFile.value) URL.revokeObjectURL(pengEditGambarPreview.value) }catch{} })

</script>

<style scoped>

/* Modern flat shell: single 1360 container, subtle dividers, no card shadows */

.kat-page{

 --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3;

 --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85;

 background:#F8FAF9;color:var(--m-ink);font-family:'Satoshi',system-ui,sans-serif;

 margin:-24px calc(50% - 50vw) 0;padding:24px max(16px,calc(50vw - 680px)) 32px;

 overflow-x:clip;

}

.kat-inner{max-width:1360px;margin:0 auto}

.kat-wrap{margin:0 auto;padding:0}

.kat-cols{display:grid;gap:24px;align-items:start;grid-template-columns:minmax(0,1fr)}

.kat-cols>*{min-width:0}

@media(min-width:1024px){ .kat-cols{grid-template-columns:minmax(0,1fr) 360px} }

@media(min-width:1280px){ .kat-cols{grid-template-columns:minmax(0,1fr) 380px;gap:32px} }

.kat-h2{font-family:'Satoshi',system-ui,sans-serif;font-weight:700;font-size:24px;line-height:1.2;letter-spacing:-.01em;margin:0;min-width:0;overflow-wrap:anywhere}

@media(min-width:640px){ .kat-h2{font-size:26px} }

.kat-ink{background:var(--m-ink);color:#fff}

.kat-ink-h:hover{background:var(--m-cta-h)}

.kat-cta-h:hover{background:var(--m-cta-h)}

.kat-btn-p{background:var(--m-cta);color:#fff;font-size:14px;font-weight:600;padding:10px 24px;border-radius:999px;transition:background .15s}

.kat-btn-p:hover:not(:disabled){background:var(--m-cta-h)}

.kat-btn-p:disabled{opacity:.6;cursor:default}

.kat-empty{background:#fff;border:1px dashed var(--m-line);border-radius:16px;padding:40px;text-align:center;color:var(--m-muted);font-size:13px}

/* soften remaining data-card borders (beats per-card Tailwind edits) */

.kat-page section.bg-white{border-color:rgb(226 232 240 / .7)}

.kat-btn-p:focus-visible{outline:2px solid var(--m-green);outline-offset:2px}

.side-rail{scrollbar-width:thin}

@media(max-width:639px){

 .kat-page{margin:-24px -16px 0;padding:20px 16px 24px}

 .kat-h2{font-size:22px}

}

.kat-page img,.kat-page canvas,.kat-page table{max-width:100%}

.kat-page section,.kat-page article,.kat-page div{min-width:0}

</style>


