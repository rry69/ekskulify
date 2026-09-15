<template>



<div class="kat-page">



<div class="kat-inner">



 <!-- head: flat tool header, no hero card ( Katalog) -->



 <div class="kat-head">



 <div class="kat-head-l">



 <h1 class="kat-title" :title="headSub">{{ headTitle }}</h1>



 </div>



 <div class="kat-head-r">



 <button v-if="canManageAny" @click="openCreate" class="kat-link" style="cursor:pointer">+ Tambah</button>



 </div>



 </div>







 <!-- toolbar: sticky tool strip, not a card ( Katalog) -->



 <div class="kat-toolbar">



 <div class="kat-search">



 <span class="kat-search-icon" aria-hidden="true"></span>



 <input v-model="search" placeholder="Cari Porseni, LDKS..." class="kat-input" aria-label="Cari event" />



  <button v-if="search" class="kat-clear" @click="search=''" aria-label="Hapus pencarian">x</button>



 </div>



 <div class="kat-filters">



  <select v-if="showPendingFilter" v-model="statusFilter" class="kat-select" aria-label="Filter status event" :title="attention.pending ? attention.pending+' pending' : 'Tidak ada pending'">

 

  <option value="">Status: Semua</option>



 <option value="approved">Approved</option>



 <option value="pending">Pending</option>



 <option value="rejected">Rejected</option>



 </select>



  <select v-else v-model="statusFilter" class="kat-select" aria-label="Filter status event" :title="myCount ? myCount+' sudah daftar' : 'Belum ikut event'">

 

  <option value="">Status: Semua</option>



 <option value="sudah_daftar">Sudah Daftar</option>



 <option value="tersedia">Tersedia</option>



 </select>



 <select v-model="sortFilter" class="kat-select" aria-label="Sort event">



 <option value="tanggal_asc">Terdekat</option>



 <option value="terisi_desc">Terlengkap</option>



 </select>



 <button class="kat-reset" @click="clearFilters" aria-label="Reset filter">Reset</button>



 </div>



 </div>







  <div v-if="msg" class="kat-alert" :class="ok?'ok':'err'" role="alert">{{ msg }}</div>








 <!-- charts: ringkas analitik event (ApexCharts). Tren = kumulatif terisi per tanggal event (label jujur di sub-judul) -->
 <div v-if="canSeeCharts && !loading && chartList.length" class="kat-charts" aria-label="Analitik event">
  <div class="kat-chart-card">
   <h3 class="kat-chart-title">Tren Pendaftaran <span class="kat-chart-sub">kumulatif terisi per tanggal event</span></h3>
   <ApexChart type="area" :height="230" :options="trenOpts" :series="trenSeries" />
  </div>
  <div class="kat-chart-card">
   <h3 class="kat-chart-title">Okupansi <span class="kat-chart-sub">rata-rata % keterisian kuota</span></h3>
   <ApexChart type="radialBar" :height="230" :options="okupansiOpts" :series="okupansiSeries" />
  </div>
  <div class="kat-chart-card">
   <h3 class="kat-chart-title">Kategori Event <span class="kat-chart-sub">Umum vs Ekskul</span></h3>
   <ApexChart type="bar" :height="230" :options="kategoriOpts" :series="kategoriSeries" />
  </div>
 </div>

 <!-- skeleton -->



 <div v-if="loading" class="kat-grid" aria-busy="true" aria-label="Memuat event">



 <div v-for="i in 6" :key="i" class="kat-card skeleton" aria-hidden="true">



 <div class="skel-line w40"></div>



 <div class="skel-line w80"></div>



 <div class="skel-line w90"></div>



 </div>



 </div>







 <!-- empty -->



 <div v-else-if="!filteredList.length" class="kat-empty">



  <span v-if="statusFilter==='sudah_daftar'">Belum ikut event apapun</span>



  <span v-else-if="statusFilter==='tersedia'">Tidak ada event tersedia</span>



 <span v-else>Tidak ada event</span>



 </div>







 <!-- grid: dense work cards, title-first ( Katalog) -->



 <div v-else class="kat-grid" v-memo="[filteredKey, page, openRundown]">



  <article v-for="e in filteredList" :key="e.id" class="kat-card" :class="cardCls(e)" role="article" :aria-label="'Event '+e.nama+', '+schedLine(e)+', kuota '+e.terisi+'/'+e.kuota+' '+kuotaMeta(e).label+' '+(Number(e.is_registered)?'sudah daftar':'')">



 <div class="kat-top">



 <div class="kat-top-l">



 <h3 class="kat-name" :title="e.nama">{{ e.nama }}</h3>



 <p class="kat-sched mono">{{ schedLine(e) }}</p>



 </div>



 <div class="kat-top-r">



  <img v-if="safeCover(e)" :src="safeCover(e)" alt="" loading="lazy" decoding="async" class="kat-thumb" />



 <div v-else class="kat-thumb kat-thumb-empty" aria-hidden="true"><span>{{ initial(e.nama) }}</span></div>



 </div>



 </div>



 <div class="kat-tags">



  <span v-if="showPendingFilter && e.status!=='approved'" class="tag status">{{ e.status }}</span>



 <span v-if="Number(e.is_registered)" class="tag mine-ok">- Sudah daftar</span>



 <span class="tag kuota" :class="kuotaMeta(e).cls">{{ kuotaMeta(e).short }}</span>



 <template v-if="e.ekskul_list && e.ekskul_list.length"><span v-for="ek in e.ekskul_list" :key="ek.id" class="tag" style="background:#eef2ff;color:#4338ca;border-color:#c7d2fe"> {{ ek.nama }}</span></template>



 <span v-else-if="e.ekskul_id" class="tag" style="background:#eef2ff;color:#4338ca;border-color:#c7d2fe"> {{ e.ekskul_nama || ('Ekskul #'+e.ekskul_id) }}</span>



  <span v-else class="tag" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0">Umum</span>



  <span v-if="e.can_register==='false'" class="tag" style="background:#fefce8;color:#854d0e;border-color:#fde68a" :title="e.register_block_reason">Khusus anggota</span>



  <span v-if="role==='kepsek' && e.status==='pending'" class="tag act">-> Approval</span>



 </div>



 <p v-if="showDesc(e)" class="kat-desc">{{ displayDesc(e) }}</p>



 <div class="kat-foot">



  <span class="kat-pembina" :title="e.creator||''">{{ e.creator || '-' }}</span>



 <span class="kat-quota mono"><b>{{ e.terisi ?? 0 }}/{{ e.kuota }}</b> - {{ kuotaMeta(e).pct }}%</span>



 </div>



  <div class="kat-bar"><div class="kat-bar-fill" :class="kuotaMeta(e).bar" :style="{width: kuotaMeta(e).pct + '%'}"></div></div>



 <div class="kat-periode mono">Pendaftaran: {{ formatPeriode(e.registration_start, e.registration_end) }}</div>



 <div v-if="rundownItems(e).length" class="kat-rundown">



  <button type="button" @click="toggleRundown(e.id)" class="kat-rundown-toggle" :aria-expanded="openRundown===e.id" :aria-controls="'rundown-'+e.id">



 <span class="kat-rundown-label">RUNDOWN</span>



 <span class="kat-rundown-right">



 <span class="kat-rundown-count">{{ rundownItems(e).length+' item' }}</span>



 </span>



 </button>



  <div v-if="openRundown!==e.id" class="kat-rundown-preview" @click="toggleRundown(e.id)" role="button" tabindex="0" @keydown.enter="toggleRundown(e.id)" @keydown.space.prevent="toggleRundown(e.id)">



 <div class="kat-rundown-list">



 <div class="kat-rundown-row">



 <span class="kat-rundown-time mono">{{ rundownItems(e)[0].time }}</span>



 <span class="kat-rundown-text">{{ rundownItems(e)[0].text }}</span>



 </div>



 </div>



  <div v-if="rundownItems(e).length>1" class="kat-rundown-more mono">+{{ rundownItems(e).length-1 }} lainnya</div>



 </div>



  <div v-else :id="'rundown-'+e.id" class="kat-rundown-body">



 <div class="kat-rundown-list">



 <div v-for="(r,i) in rundownItems(e)" :key="i" class="kat-rundown-row">



 <span class="kat-rundown-time mono">{{ r.time }}</span>



 <span class="kat-rundown-text">{{ r.text }}</span>



 </div>



 </div>



 </div>



 </div>



 <div v-else class="kat-rundown kat-rundown--empty">



 <div class="kat-rundown-toggle" style="cursor:default">



 <span class="kat-rundown-label">RUNDOWN</span>



 <span class="kat-rundown-count">belum diisi</span>



 </div>



 </div>



 <div class="kat-cta">



   <router-link :to="`/events/${e.id}`" class="kat-detail"><span>Detail</span><span class="kat-detail-arrow" aria-hidden="true">→</span></router-link>



 <template v-if="canManage(e)">



  <button class="kat-mini neutral" @click="openEdit(e)" :aria-label="'Edit '+e.nama">Edit</button>



  <button v-if="isAdmin" class="kat-mini danger" @click="hapus(e)" :aria-label="'Hapus '+e.nama">Hapus</button>



 </template>



  <button v-else-if="Number(e.is_registered)" class="kat-mini danger" @click="batal(e)" :aria-label="'Batal daftar '+e.nama">Batal</button>



  <button v-else-if="isSiswa" class="kat-mini primary" :disabled="!canDaftar(e)" :aria-label="canDaftar(e) ? 'Daftar event '+e.nama : buttonLabel(e)+' event '+e.nama" @click="daftar(e)">{{ buttonLabel(e) }}</button>



  <router-link v-else-if="role==='guest'" to="/login" class="kat-mini primary" :aria-label="'Login untuk daftar '+e.nama">Daftar</router-link>



 </div>



 <p v-if="isSiswa && !Number(e.is_registered) && !canDaftar(e)" class="kat-helper mono">{{ disabledHelper(e) }}</p>



 </article>



 </div>







 <!-- pagination -->



  <div v-if="total>0" class="kat-paging">



  <div class="kat-paging-btns">



  <button class="kat-page-btn" :disabled="page<=1" @click="goPage(page-1)" aria-label="Halaman sebelumnya">Prev</button>



  <span class="kat-page-num mono" :title="total ? ('Menampilkan ' + rangeText + ' dari ' + total) : 'Tidak ada event'">{{ page }} / {{ pages }}</span>



  <button class="kat-page-btn" :disabled="page>=pages" @click="goPage(page+1)" aria-label="Halaman berikutnya">Next</button>



 </div>



 </div>







 <!-- Modal Tambah/Edit (admin + pembina creator) -->



 <Teleport to="body">



 <div v-if="showModal" class="fixed inset-0 z-40">



  <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="closeModal"></div>



 <div class="absolute inset-0 grid place-items-center p-4 overflow-auto">



 <div class="w-full max-w-[640px] max-h-[90vh] overflow-auto rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] flex flex-col" style="border-color:var(--m-line,#E0E5E3)">



 <div class="sticky top-0 bg-white px-6 py-4 border-b flex items-center justify-between">



 <div>



  <h2 class="text-[18px] font-bold">{{ editing ? 'Edit: '+(form.nama||'') : 'Tambah Event Baru' }}</h2>



 <p class="mono text-[11px]" style="color:#8a8580">{{ editing ? 'Mode edit - perubahan tercatat audit' : 'Buat event baru - akan pending approval Kepsek' }}</p>



 </div>



 <button @click="closeModal" class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" aria-label="Tutup">x</button>



 </div>



 <div class="p-6">



 <div v-if="formErr.server" class="rounded-xl border px-3 py-2 text-[12px] bg-red-50 text-red-700 mb-3" style="border-color:#fecdd3">{{ formErr.server }}</div>



 <div class="grid sm:grid-cols-[1.4fr_0.6fr] gap-3">



 <label class="block">



 <span class="mono text-[10px] tracking-widest font-semibold">NAMA <span class="text-red-600">*</span></span>



 <input v-model="form.nama" placeholder="Nama event" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" aria-label="Nama event" />



 <span v-if="formErr.nama" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.nama }}</span>



 </label>



 <label class="block">



 <span class="mono text-[10px] tracking-widest font-semibold">TANGGAL <span class="text-red-600">*</span></span>



 <input type="date" v-model="form.tanggal" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" aria-label="Tanggal" />



 <span v-if="formErr.tanggal" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.tanggal }}</span>



 </label>



 </div>



 <div class="grid sm:grid-cols-3 gap-3 mt-3">



 <label class="block">



 <span class="mono text-[10px] tracking-widest font-semibold">JAM MULAI <span class="text-red-600">*</span></span>



 <input type="time" v-model="form.waktu" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" aria-label="Jam mulai" />



 <span v-if="formErr.waktu" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.waktu }}</span>



 </label>



 <label class="block">



 <span class="mono text-[10px] tracking-widest font-semibold">JAM SELESAI</span>



 <input type="time" v-model="form.waktu_selesai" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" aria-label="Jam selesai" />



 <span v-if="formErr.waktu_selesai" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.waktu_selesai }}</span>



 </label>



 <label class="block">



 <span class="mono text-[10px] tracking-widest font-semibold">KUOTA <span class="text-red-600">*</span></span>



 <input type="number" min="1" v-model.number="form.kuota" class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" aria-label="Kuota" />



 <span v-if="formErr.kuota" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.kuota }}</span>



 </label>



 </div>



 <label class="block mt-3">



 <span class="mono text-[10px] tracking-widest font-semibold">LOKASI <span class="text-red-600">*</span></span>



 <input v-model="form.lokasi" placeholder="Aula, Lapangan..." class="mt-1 w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none" aria-label="Lokasi" />



 <span v-if="formErr.lokasi" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.lokasi }}</span>



 </label>



 <!-- Scope: Ekskul / Umum - multi-select -->



 <div class="block mt-3">



 <span class="mono text-[10px] tracking-widest font-semibold">RUANG LINGKUP <span class="text-red-600">*</span></span>



 <div class="mt-1 flex gap-2">



  <button type="button" :class="['flex-1 h-[44px] rounded-xl border text-[13px] font-medium', form.scope==='umum' ? 'bg-[#2F3E46] text-white border-[#2F3E46]' : 'bg-white border-[#E0E5E3] hover:bg-stone-50']" @click="form.scope='umum'; form.ekskul_ids=[]" aria-label="Scope Umum">o Umum</button>



  <button type="button" :class="['flex-1 h-[44px] rounded-xl border text-[13px] font-medium', form.scope==='ekskul' ? 'bg-[#2F3E46] text-white border-[#2F3E46]' : 'bg-white border-[#E0E5E3] hover:bg-stone-50']" @click="openScopeEkskul" aria-label="Scope Ekskul"> Ekskul</button>



 </div>



  <div v-if="form.scope==='ekskul'" class="mt-2">



 <div v-if="form.ekskul_ids && form.ekskul_ids.length" class="flex flex-wrap gap-2 mb-2">



 <span v-for="eid in form.ekskul_ids" :key="eid" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-[12px] font-medium bg-[#eef2ff] border-[#c7d2fe] text-[#4338ca]">



 {{ labelForEkskulId(eid) }}



  <button type="button" class="w-4 h-4 grid place-items-center rounded-full hover:bg-white/60" @click="removeScopeEkskul(eid)" aria-label="Hapus">x</button>



 </span>



 </div>



 <div class="relative">



 <input v-model="scopeSearch" @input="onScopeInput" @focus="onScopeFocus" placeholder="Cari & tambah Ekskul..." class="w-full px-3 h-[44px] rounded-xl border bg-white text-[13px] outline-none pr-8" aria-label="Cari ekskul untuk event" @keydown.escape="clearScopeSearch" />



  <span v-if="scopeLoading" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 border-2 border-stone-300 border-t-zinc-900 rounded-full animate-spin"></span>



 <ul v-if="showScopeList" class="absolute z-10 w-full mt-1 rounded-xl border bg-white shadow-lg overflow-hidden max-h-[200px] overflow-auto" style="border-color:#E0E5E3">



  <li v-for="e in scopeOptions" :key="e.id" class="px-3 py-2 hover:bg-stone-50 cursor-pointer flex justify-between items-center text-[13px]" :class="(form.ekskul_ids||[]).some(x=> String(x)===String(e.id)) ? 'opacity-40' : ''" @click="selectScopeEkskul(e)">



 <span class="font-medium truncate">{{ e.nama }}</span><span class="mono text-[11px] shrink-0 ml-2" style="color:#a8a29e">{{ (form.ekskul_ids||[]).some(x=> String(x)===String(e.id)) ? 'terpilih' : e.status }}</span>



 </li>



 <li v-if="!scopeOptions.length && !scopeLoading" class="px-3 py-2 mono text-[11px]" style="color:#a8a29e">Tidak ada ekskul</li>



 </ul>



 </div>



 <p v-if="form.ekskul_ids && form.ekskul_ids.length" class="mono text-[11px] mt-1" style="color:#6B7C85">{{ form.ekskul_ids.length }} ekskul terpilih</p>



 <p v-else class="mono text-[11px] mt-1 text-amber-600">Pilih minimal 1 ekskul (bisa banyak)</p>



 </div>



  <p v-else-if="form.scope==='umum'" class="mono text-[11px] mt-1" style="color:#a8a29e">Event umum - terlihat semua siswa - tidak terikat ekskul tertentu</p>



 <span v-if="formErr.ekskul_ids" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.ekskul_ids }}</span>



 </div>



 <div class="mt-3">



 <div class="flex items-center justify-between">



 <span class="mono text-[10px] tracking-widest font-semibold">RUNDOWN - jam + kegiatan</span>



 <button type="button" class="mono text-[11px] px-3 py-1 rounded-full border bg-white hover:bg-stone-50" @click="addRundown">+ Tambah baris</button>



 </div>



 <div v-for="(row,i) in rundownList" :key="i" class="mt-2 grid grid-cols-[110px_1fr_auto] gap-2 items-start">



  <input type="time" v-model="row.jam" class="px-3 h-[40px] rounded-xl border bg-white text-[13px] outline-none" :aria-label="'Jam rundown '+(i+1)" />



  <input v-model="row.kegiatan" placeholder="Kegiatan, mis. Registrasi" class="px-3 h-[40px] rounded-xl border bg-white text-[13px] outline-none" :aria-label="'Kegiatan rundown '+(i+1)" />



 <button type="button" class="h-[40px] w-[40px] grid place-items-center rounded-xl border bg-white hover:bg-red-50 text-red-600" @click="removeRundown(i)" :aria-label="`Hapus rundown ${i+1}`">x</button>



 </div>



 <span v-if="formErr.rundown" class="mono text-[11px] text-red-600 mt-1 block">{{ formErr.rundown }}</span>



 </div>



 <label class="block mt-3">



 <span class="mono text-[10px] tracking-widest font-semibold">DESKRIPSI</span>



 <textarea v-model="form.deskripsi" placeholder="Deskripsi event" rows="3" class="mt-1 w-full px-3 py-3 rounded-xl border bg-white text-[13px] outline-none min-h-[72px] resize-none"></textarea>



 </label>



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

<!-- Cover (same as Ekskul): JPG/PNG/WEBP maks 2MB -->
<div class="mt-3 rounded-xl border p-3 bg-slate-50" style="border-color:#E0E5E3">
<div class="mono text-[10px] tracking-widest font-semibold" style="color:#6B7C85">COVER  -  JPG/PNG/WEBP maks 2MB</div>
<div class="flex items-start gap-3 mt-2">
<div class="w-20 h-20 rounded-xl overflow-hidden border bg-slate-100 shrink-0 flex items-center justify-center" style="border-color:#e2e8f0">
<img v-if="coverPreview" :src="coverPreview" alt="preview cover" class="w-full h-full object-cover" />
<span v-else class="text-slate-300 text-[22px]"> - </span>
</div>
<div class="flex-1 min-w-0">
<label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-full border bg-white text-[12px] font-medium cursor-pointer hover:bg-slate-50">Pilih cover<input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onCoverPick" /></label>
<button v-if="coverPreview" type="button" @click="coverPreview=''; pendingCover=null; coverMsg=''" class="ml-2 px-3 py-2 rounded-full border bg-white text-[12px] font-medium text-slate-600 hover:bg-slate-50">Batal</button>
<p v-if="coverMsg" class="text-[11px] mt-2" :class="coverOk?'text-emerald-600':'text-red-600'">{{ coverMsg }}</p>
</div>
</div>
</div>

  </div>



 <div class="sticky bottom-0 bg-white px-6 py-4 border-t flex justify-end gap-2">



 <button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50" @click="closeModal">Batal</button>



 <button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold hover:bg-black disabled:opacity-40" style="background:var(--m-cta,#4A7875)" @click="submitManage" :disabled="!isManageValid">{{ editing?'Update':'Tambah' }}</button>



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



import { useRoute, useRouter } from 'vue-router'



import { useAuth } from '../stores/auth.js'



import { api, apiCover } from '../lib/api.js'
import ApexChart from '../components/ApexChart.vue'



const auth=useAuth(), route=useRoute(), router=useRouter()



const list=ref([]), total=ref(0), pages=ref(1), page=ref(1), limit=20



const search=ref(''), statusFilter=ref(''), sortFilter=ref('tanggal_asc')



const loading=ref(false), msg=ref(''), ok=ref(false)



const openRundown=ref(null)



let debounce=null







// role adaptif: satu layout, badge/CTA berubah per role ( Katalog)



const role=computed(()=> auth.user?.role || 'guest')



const isSiswa=computed(()=> role.value==='siswa')



const isAdmin=computed(()=> role.value==='admin')

// chart analitik: admin + kepsek saja (siswa/pembina/guest disembunyikan)
const canSeeCharts=computed(()=> ['admin','kepsek'].includes(role.value))



// kelola: admin-only (event hanya admin  -  pembina hanya ekskul)



const canManageAny=computed(()=> isAdmin.value)



function canManage(e){



  return isAdmin.value



}







function sisa(e){ const s=(Number(e.kuota)||0)-(Number(e.terisi)||0); return s<0?0:s }



function isPenuh(e){ return Number(e.terisi||0) >= Number(e.kuota||0) }



// kuota state tunggal: label/short/cls/bar/pct ( Katalog)



function kuotaMeta(e){



 const s=sisa(e); const t=Number(e.kuota)||1; const terisi=Number(e.terisi)||0



 const p = t? Math.min(100, Math.round(terisi/t*100)):0



 if(s===0) return {label:'Penuh',short:'Penuh',cls:'red',bar:'bg-red',pct:100}



 if(s<=3) return {label:'Hampir penuh',short:`Sisa ${s}`,cls:'amber',bar:'bg-amber',pct:p}



 return {label:'Tersedia',short:`Sisa ${s}`,cls:'emerald',bar:'bg-emerald',pct:p}



}



// role-adaptive card class: quota state + mine state + pending triage ( Katalog)



function cardCls(e){



 const m=kuotaMeta(e); const c=['q-'+m.cls]



 if(Number(e.is_registered)) c.push('is-mine-ok')



 if(showPendingFilter.value && e.status==='pending') c.push('is-pending')



 if(m.cls==='red') c.push('is-full')



 return c.join(' ')



}



// 1-baris jadwal: TANGGAL JAM - LOKASI ( Katalog schedLine)



function schedLine(e){



 const d=fmtDateShort(e.tanggal); const j=fmtJamRange(e); const l=((e.lokasi||'').trim()||'-').toUpperCase()



 return `${d} ${j} - ${l}`



}



function fmtDateShort(t){



 if(!t) return '-'



 const d=new Date(t+'T00:00:00')



 if(isNaN(d)) return String(t).slice(0,10)



 const months=['JAN','FEB','MAR','APR','MEI','JUN','JUL','AGU','SEP','OKT','NOV','DES']



 return `${String(d.getDate()).padStart(2,'0')} ${months[d.getMonth()]||''}`



}



function fmtJamRange(e){



 const a=(e.waktu||'').slice(0,5), b=(e.waktu_selesai||'').slice(0,5)



 if(!a && !b) return '-'



 return `${a||'-'}-${b||'-'}`



}



function initial(n){



 if(!n) return '?'



 return String(n).trim().slice(0,1).toUpperCase()



}



// allowlist cover: backend hanya keluarkan /api/covers/event/:id numerik ( Katalog safeCover)



function safeCover(e){



 const u=e.cover_url; return (typeof u==='string' && /^\/api\/covers\/event\/\d+$/.test(u)) ? u : null



}



function showDesc(e){ return !isDescEmpty(e) }



function formatPeriode(s,e){



 const hasS=!!(s&&String(s).trim()), hasE=!!(e&&String(e).trim()); const fmt=v=> v? String(v).slice(0,10): ''



 if(!hasS && !hasE) return 'Buka terus'



 if(hasS && !hasE) return `Mulai ${fmt(s)} - Buka terus`



 if(!hasS && hasE) return `s/d ${fmt(e)}`



 return `${fmt(s)} s/d ${fmt(e)}`



}



function isOpen(e){



 const now=Date.now()



 if(e.registration_start && !isNaN(Date.parse(e.registration_start))){



 if(new Date(e.registration_start).getTime() > now) return false



 }



 if(e.registration_end && !isNaN(Date.parse(e.registration_end))){



 if(new Date(e.registration_end).getTime() < now) return false



 }



 return true



}



function canDaftar(e){



 if(auth.user?.role!=='siswa') return false



 if(e.status!=='approved') return false



 if(!isOpen(e)) return false



 if(isPenuh(e)) return false



 if(Number(e.is_registered)) return false



 return true



}



function buttonLabel(e){



 if(Number(e.is_registered)) return 'Sudah Daftar'



 if(e.status!=='approved') return 'Menunggu approval'



 if(!isOpen(e)){



 if(e.registration_start && new Date(e.registration_start).getTime()>Date.now()) return 'Belum buka'



 return 'Tutup'



 }



 if(isPenuh(e)) return 'Penuh'



 return 'Daftar'



}



function disabledHelper(e){



 if(Number(e.is_registered)) return 'sudah terdaftar'



 if(e.status!=='approved') return 'Menunggu approval'



 if(!isOpen(e)){



 if(e.registration_start && new Date(e.registration_start).getTime()>Date.now()) return 'belum dibuka'



 return 'periode tutup'



 }



 if(isPenuh(e)) return 'kuota penuh'



 if(auth.user?.role!=='siswa') return 'siswa only'



 return 'bisa daftar'



}



function isDescEmpty(e){



 const d=String(e.deskripsi||'').trim().replace(/\[{1,2}img:\d+\]{1,2}/g,'').trim()



 return !d || d.length<3 || /^(deskripsi belum diisi|sudah diisi|test|dummy)$/i.test(d) || d.includes('belum diisi')



}



function displayDesc(e){



 let d=String(e.deskripsi||'').trim().replace(/\[{1,2}img:\d+\]{1,2}/g,'').replace(/\s{2,}/g,' ').trim()



 if(!d) return 'Deskripsi belum diisi'



 if(isDescEmpty(e) && d.length<10) return 'Deskripsi belum diisi'



 return d



}



function rundownItems(e){



 const raw=String(e.rundown||'').trim()



 if(!raw) return []



 const lines=raw.split(/\r?\n/).filter(Boolean); return lines.map(line=>{



 const m=line.match(/^(\d{1,2}:\d{2}(?::\d{2})?)\s*[- - - - ]?\s*(.*)$/)



 if(m) return {time:m[1].slice(0,5), text:m[2]||'-'}



 // fallback: try split by space



 const parts=line.trim().split(/\s+/)



 if(/^\d{1,2}:\d{2}/.test(parts[0])) return {time:parts[0].slice(0,5), text:parts.slice(1).join(' ')||'-'}



 return {time:'-', text:line}



 })



}



function toggleRundown(id){ openRundown.value = openRundown.value===id ? null : id }







const showPendingFilter=computed(()=>{



 const r=auth.user?.role



 return r==='admin' || r==='pembina' || r==='kepsek'



})



// role-adaptive header: 1 baris judul + sub berbeda per role, strip hanya bila actionable ( Katalog)



const headTitle=computed(()=>{



 if(role.value==='kepsek') return 'Approval Event'



 if(role.value==='pembina') return 'Event Saya'



 if(role.value==='admin') return 'Semua Event'



 return 'Katalog Event'



})



const headSub=computed(()=>{



 if(role.value==='kepsek') return attention.value.pending ? `${attention.value.pending} menunggu keputusan` : 'Katalog event'



 if(role.value==='admin') return `${total.value} event`



 if(role.value==='pembina') return `${total.value} event`



 if(role.value==='siswa') return `${total.value} event`



 return `${total.value} event`



})



const stripText=computed(()=>{



 if(role.value==='guest') return 'Login untuk mendaftar.'



 if(role.value==='kepsek' && attention.value.pending) return `${attention.value.pending} menunggu keputusan.`



 if(role.value==='admin' && attention.value.pending) return `${attention.value.pending} pending.`



 return 'bisa daftar'



})



const stripCls=computed(()=> (role.value==='kepsek'||role.value==='admin')?'warn':'');



// stats halaman ini (server-side paging: hitung dari list yg tampil)



const stats=computed(()=>{



 let tersedia=0, hampir=0, penuh=0, pending=0



 for(const x of list.value){



 if(x.status==='pending'){ pending++; continue }



 if(x.status!=='approved') continue



 const s=sisa(x)



 if(s===0) penuh++; else if(s<=3) hampir++; else tersedia++



 }



 return {tersedia, hampir, penuh, pending}



})



const myCount=computed(()=>  list.value.filter(x=> Number(x.is_registered)).length)



const attention=computed(()=>{



 let pending=0, penuh=0, nodesc=0



 for(const x of list.value){ if(x.status==='pending') pending++; if(isPenuh(x)) penuh++; if(isDescEmpty(x)) nodesc++ }



 return {pending, penuh, nodesc}



})




const chartList=ref([])
async function loadCharts(){
  if(!canSeeCharts.value){ chartList.value=[]; return }
  try{
    const acc=[]; let page=1
    for(let i=0;i<10;i++){
      const j=await api('/events?page='+page+'&limit=100&sort=tanggal_asc')
      const rows=j.data||[]
      acc.push(...rows)
      const pages=j.meta?.pages||1
      if(page>=pages) break
      page++
    }
    chartList.value=acc
  }catch{ chartList.value=[] }
}
function evPct(e){ const k=Number(e.kuota)||0; if(k<=0) return 0; return Math.min(100, Math.round((Number(e.terisi)||0)/k*100)) }
const trenRows=computed(()=>{
  const m=new Map()
  for(const e of [...chartList.value].sort((a,b)=> String(a.tanggal||'').localeCompare(String(b.tanggal||'')))){
    const t=String(e.tanggal||'').slice(0,10)||'-'
    m.set(t,(m.get(t)||0)+(Number(e.terisi)||0))
  }
  let cum=0
  return [...m.entries()].map(([t,v])=>{ cum+=v; return { t, cum } }).slice(-20)
})
const trenSeries=computed(()=> [{ name:'Kumulatif pendaftar', data: trenRows.value.map(r=> r.cum) }])
// label pendek D/M + tick dibatasi agar tidak bertumpuk
const trenLbl=(t)=> `${Number(String(t).slice(8))}/${Number(String(t).slice(5,7))}`
const trenOpts=computed(()=> ({ colors:['#4A7875'], fill:{ type:'gradient', gradient:{ opacityFrom:.45, opacityTo:.05 } }, stroke:{ curve:'smooth', width:2 }, xaxis:{ categories: trenRows.value.map(r=> trenLbl(r.t)), tickAmount:6, tickPlacement:'on', labels:{ rotate:-45, rotateAlways:false, hideOverlappingLabels:true, trim:true, style:{ fontSize:'10px' } } }, tooltip:{ x:{ formatter:(_, o={})=> trenRows.value[o.dataPointIndex]?.t || '' } }, dataLabels:{ enabled:false }, grid:{ strokeDasharray:3 } }))
const okupansiAvg=computed(()=>{
  if(!chartList.value.length) return 0
  const s=chartList.value.reduce((a,e)=> a+evPct(e),0)
  return Math.round(s/chartList.value.length)
})
const okupansiSeries=computed(()=> [okupansiAvg.value])
const okupansiOpts=computed(()=> ({ colors:['#5EB87E'], plotOptions:{ radialBar:{ hollow:{ size:'60%' }, dataLabels:{ name:{ show:false }, value:{ fontSize:'26px', fontWeight:'700', formatter:v=> v+'%' } } } }, labels:['Okupansi'] }))
const kategoriRows=computed(()=>{
  let umumEv=0, ekskulEv=0, umumPes=0, ekskulPes=0
  for(const e of chartList.value){
    const isEk=(e.ekskul_ids&&e.ekskul_ids.length)||e.ekskul_id
    if(isEk){ ekskulEv++; ekskulPes+=Number(e.terisi)||0 } else { umumEv++; umumPes+=Number(e.terisi)||0 }
  }
  return { umumEv, ekskulEv, umumPes, ekskulPes }
})
const kategoriSeries=computed(()=> [{ name:'Event', data:[kategoriRows.value.umumEv, kategoriRows.value.ekskulEv] }, { name:'Peserta', data:[kategoriRows.value.umumPes, kategoriRows.value.ekskulPes] }])
const kategoriOpts=computed(()=> ({ colors:['#A7C7E7','#4A7875'], plotOptions:{ bar:{ borderRadius:6, columnWidth:'45%' } }, xaxis:{ categories:['Umum','Ekskul'] }, dataLabels:{ enabled:false }, grid:{ strokeDasharray:3 } }))

const filteredList=computed(()=> list.value)



const filteredKey=computed(()=> list.value.map(x=>x.id).join(','))



const rangeText=computed(()=>{



 if(!total.value) return '0'



 const s=(page.value-1)*limit+1, e=Math.min(page.value*limit,total.value)



 return `${s}-${e}`



})



function clearFilters(){ search.value=''; statusFilter.value=''; sortFilter.value='tanggal_asc'; page.value=1 }



function goPage(p){ page.value=p; window.scrollTo({top:0,behavior:'smooth'}) }



function syncUrl(){



 const q={}



 if(search.value) q.search=search.value



 if(statusFilter.value) q.status=statusFilter.value



 if(sortFilter.value && sortFilter.value!=='tanggal_asc') q.sort=sortFilter.value



 if(page.value>1) q.page=page.value



 router.replace({query:q})



}



async function load(){



 loading.value=true; msg.value=''



 try{



 const params=new URLSearchParams()



 params.set('page',page.value); params.set('limit',limit)



 if(search.value) params.set('search',search.value)



 if(statusFilter.value) params.set('status',statusFilter.value)



 params.set('sort',sortFilter.value)



 syncUrl()



 const j=await api('/events?'+params.toString())



 list.value=j.data||[]; total.value=j.meta?.total||0; pages.value=j.meta?.pages||1; loadCharts()



 }catch(ex){ list.value=[]; total.value=0 }



 finally{ loading.value=false }



}



async function daftar(e){



 if(!canDaftar(e)) return



 try{ await api('/events/'+e.id+'/daftar',{method:'POST',body:{}}); ok.value=true; msg.value='Berhasil daftar event'; await load() }catch(ex){ ok.value=false; msg.value=ex.error?.message||ex.message||'Gagal daftar' }



}



async function batal(e){



 if(!confirm(`Batalkan pendaftaran ${e.nama}?`)) return



 try{ await api('/events/'+e.id+'/batal',{method:'POST',body:{}}); ok.value=true; msg.value='Batal berhasil'; await load() }catch(ex){ ok.value=false; msg.value=ex.error?.message||ex.message||'Gagal batal' }



}



// ---- kelola inline (admin-only) + cover ----
const coverPreview=ref(''), coverMsg=ref(''), coverOk=ref(false)
let pendingCover=null
function onCoverPick(e){
  const f=e.target?.files?.[0]; if(!f) return
  if(f.size>2*1024*1024){ coverMsg.value='Maks 2MB'; coverOk.value=false; e.target.value=''; return }
  pendingCover=f; coverPreview.value=URL.createObjectURL(f); coverMsg.value='Siap diunggah saat Simpan'; coverOk.value=true; e.target.value=''
}



const showModal=ref(false), editing=ref(null), saving=ref(false)



const form=ref({nama:'',deskripsi:'',tanggal:'',waktu:'',waktu_selesai:'',lokasi:'',kuota:50,rundown:'',scope:'umum',ekskul_ids:[],registration_start:'',registration_end:''})



const rundownList=ref([{jam:'',kegiatan:''}])



const formErr=ref({})



// scope ekskul multi-select (pilih >1 ekskul)



const scopeSearch=ref(''), scopeOptions=ref([]), scopeLoading=ref(false), showScopeList=ref(false)



const selectedEkskulMap=computed(()=>{ const m=new Map(); for(const o of scopeOptions.value) m.set(String(o.id), o); return m })



function labelForEkskulId(id){ const hit=selectedEkskulMap.value.get(String(id)); return hit? hit.nama : `Ekskul #${id}` }



let scopeDebounce=null



async function fetchScopeEkskul(q){



 scopeLoading.value=true



 try{



 const params=new URLSearchParams({limit:'20'})



 if(q) params.set('search',q)



 const j=await api('/ekskul?'+params.toString())



 scopeOptions.value=j.data||[]



 }catch{ scopeOptions.value=[] }finally{ scopeLoading.value=false }



}



function openScopeEkskul(){ form.value.scope='ekskul'; showScopeList.value=true; fetchScopeEkskul(scopeSearch.value.trim()) }



function onScopeInput(){ showScopeList.value=true; clearTimeout(scopeDebounce); scopeDebounce=setTimeout(()=> fetchScopeEkskul(scopeSearch.value.trim()),300) }



function onScopeFocus(){ showScopeList.value=true; fetchScopeEkskul(scopeSearch.value.trim()) }



function selectScopeEkskul(eu){



 const arr=form.value.ekskul_ids||[]



 if(!arr.includes(eu.id) && !arr.includes(String(eu.id))) arr.push(eu.id)



 form.value.ekskul_ids=arr



 // ensure option in map for chip label



 if(!scopeOptions.value.find(x=> String(x.id)===String(eu.id))) scopeOptions.value=[eu, ...scopeOptions.value]



 scopeSearch.value=''; showScopeList.value=false



}



function removeScopeEkskul(id){ form.value.ekskul_ids=(form.value.ekskul_ids||[]).filter(x=> String(x)!==String(id)) }



function clearScopeSearch(){ scopeSearch.value=''; showScopeList.value=false }



function parseRundown(str){



 const raw=String(str||'').trim()



 if(!raw) return [{jam:'',kegiatan:''}]



 return raw.split(/\r?\n/).filter(Boolean).map(line=>{



 const m=line.match(/^(\d{1,2}:\d{2}(?::\d{2})?)\s*[- - - - ]?\s*(.*)$/)



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



const isManageValid=computed(()=>{



 const f=form.value



 if(!(f.nama||'').trim()||f.nama.trim().length<3) return false



 if(!f.tanggal) return false



 // create: past date blocked; edit: allow keeping past date (fix Simpan disabled when editing)



 if(!editing.value){



 const d=new Date(), today=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`



 if(f.tanggal<today) return false



 }



 if(!f.waktu) return false



 if(!(f.lokasi||'').trim()||f.lokasi.trim().length<3) return false



 if(!f.kuota||Number(f.kuota)<1) return false



 if(f.waktu&&f.waktu_selesai&&f.waktu>=f.waktu_selesai) return false



 if(f.scope==='ekskul' && !(f.ekskul_ids && f.ekskul_ids.length)) return false



 return true



})



function validateManage(){



 const err={}, f=form.value



 const n=(f.nama||'').trim()



 if(!n) err.nama='Nama wajib (min 3)'



 else if(n.length<3) err.nama='Minimal 3 karakter'



 if(!f.tanggal) err.tanggal='Tanggal wajib'



 else if(!editing.value){



 const d=new Date(), today=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`



 if(f.tanggal<today) err.tanggal='Tanggal sudah lewat'



 }



 if(!f.waktu) err.waktu='Jam mulai wajib'



 if(!(f.lokasi||'').trim()) err.lokasi='Lokasi wajib'



 else if(f.lokasi.trim().length<3) err.lokasi='Minimal 3 karakter'



 if(!f.kuota||Number(f.kuota)<1) err.kuota='Kuota harus >0'



 if(f.waktu&&f.waktu_selesai&&f.waktu>=f.waktu_selesai) err.waktu_selesai='Jam selesai harus > jam mulai'



 if(f.scope==='ekskul' && !(f.ekskul_ids && f.ekskul_ids.length)) err.ekskul_ids='Pilih minimal 1 ekskul (bisa banyak)'



 const hasIncomplete=rundownList.value.some(r=>((r.jam||'').trim()&&!(r.kegiatan||'').trim())||(!(r.jam||'').trim()&&(r.kegiatan||'').trim()))



 if(hasIncomplete) err.rundown='Lengkapi jam + kegiatan tiap baris'



 formErr.value=err



 return Object.keys(err).length===0



}



function openCreate(){



  if(!canManageAny.value) return



  editing.value=null; resetManageForm(); coverPreview.value=''; pendingCover=null; showModal.value=true



}



function openEdit(e){



 if(!canManage(e)) return



 editing.value=e.id



 {



 const ids = e.ekskul_ids && e.ekskul_ids.length ? e.ekskul_ids : (e.ekskul_id ? [e.ekskul_id] : []);



 Object.assign(form.value,{nama:e.nama,deskripsi:e.deskripsi||'',tanggal:e.tanggal||'',waktu:(e.waktu||'').slice(0,5),waktu_selesai:(e.waktu_selesai||'').slice(0,5),lokasi:e.lokasi||'',kuota:e.kuota,scope: ids.length ? 'ekskul' : 'umum', ekskul_ids: ids, registration_start:e.registration_start?String(e.registration_start).slice(0,16):'',registration_end:e.registration_end?String(e.registration_end).slice(0,16):''})



 if(ids.length){ scopeSearch.value=''; fetchScopeEkskul('') } else scopeSearch.value=''



 }



  rundownList.value=parseRundown(e.rundown||'')

  // cover preview existing
  try{ coverPreview.value = e.cover_url || '' }catch{ coverPreview.value='' }
  pendingCover=null; coverMsg.value=''; coverOk.value=false

  formErr.value={}



 showModal.value=true



}



function resetManageForm(){ form.value={nama:'',deskripsi:'',tanggal:'',waktu:'',waktu_selesai:'',lokasi:'',kuota:50,rundown:'',scope:'umum',ekskul_ids:[],registration_start:'',registration_end:''}; rundownList.value=[{jam:'',kegiatan:''}]; scopeSearch.value=''; showScopeList.value=false; formErr.value={}; coverPreview.value=''; coverMsg.value=''; coverOk.value=false; pendingCover=null }



function closeModal(){ showModal.value=false; editing.value=null; resetManageForm() }



async function submitManage(){



 if(!validateManage()) return



 saving.value=true



 const payload={...form.value}



 payload.kuota=Number(payload.kuota)



 if(!payload.waktu_selesai) payload.waktu_selesai=null



 if(!payload.deskripsi) payload.deskripsi=null



 payload.rundown=serializeRundown()



 // scope -> ekskul_ids: Umum = [], Ekskul = ids[]



 if(payload.scope==='ekskul') payload.ekskul_ids = payload.ekskul_ids||[]



 else payload.ekskul_ids=[]



 // compat: also send ekskul_id for old calendars



 payload.ekskul_id = (payload.ekskul_ids && payload.ekskul_ids.length) ? payload.ekskul_ids[0] : null



 delete payload.scope



 if(!payload.registration_start) payload.registration_start=null



 if(!payload.registration_end) payload.registration_end=null



  try{



  let eid=editing.value
  if(eid) await api('/events/'+eid,{method:'PATCH',body:payload})
  else { const r=await api('/events',{method:'POST',body:payload}); eid=r?.data?.id||r?.data?.event?.id||r?.id||null }

  if(pendingCover && eid){ try{ await apiCover('event', eid, pendingCover); pendingCover=null }catch(ex){ coverMsg.value=ex?.error?.message||'Event tersimpan, cover gagal'; coverOk.value=false } }



 ok.value=true; msg.value=editing.value?'Update berhasil':'Tambah berhasil'



 closeModal(); await load()



 }catch(e){ ok.value=false; msg.value=e.error?.message||'Gagal'; formErr.value={...formErr.value,server:msg.value} }



 finally{ saving.value=false }



}



async function hapus(e){



 if(!canManage(e)) return



 if(!confirm(`Hapus ${e.nama}? (soft delete)`)) return



 try{ await api('/events/'+e.id,{method:'DELETE',body:{}}); ok.value=true; msg.value='Hapus berhasil'; await load() }catch(err){ ok.value=false; msg.value=err?.error?.message||'Gagal hapus' }



}







watch([search, statusFilter, sortFilter], ()=>{



 clearTimeout(debounce)



 debounce=setTimeout(()=>{ page.value=1; load() },300)



})



watch(page, load)



// migrate non-privileged status di URL (mis. ?status=pending manual) -> reset ke All



watch(showPendingFilter,(v)=>{



 if(!v && ['pending','rejected'].includes(statusFilter.value)) statusFilter.value=''



})



onMounted(()=>{



 const q=route.query



 search.value=q.search||''; sortFilter.value=q.sort||'tanggal_asc'; page.value=parseInt(q.page||'1')||1



 // only restore status if valid for role



 const qs=String(q.status||'')



 if(['pending','approved','rejected'].includes(qs)){



 statusFilter.value = showPendingFilter.value ? qs : (qs==='approved'?'': '')



 } else if(['sudah_daftar','tersedia'].includes(qs)){



 statusFilter.value = showPendingFilter.value ? '' : qs



 } else statusFilter.value=''



 // migrate legacy sort values



 if(q.sort==='terlengkap') sortFilter.value='terisi_desc'



 // deep-link dari Kalender: ?tanggal=YYYY-MM-DD&create=1



 const tgl=String(q.tanggal||'')



 if(/^\d{4}-\d{2}-\d{2}$/.test(tgl) && !isNaN(new Date(tgl+'T00:00:00').getTime())){



 form.value.tanggal=tgl



 if(q.create==='1' && canManageAny.value){



 editing.value=null; resetManageForm(); form.value.tanggal=tgl; showModal.value=true



 // bersihkan query biar reload tidak auto-open lagi



 router.replace({query:{...q, tanggal:undefined, create:undefined}}).catch(()=>{})



 }



 }



 load()



})



</script>



<style scoped>



/* Mindora tokens, scoped - tanpa bocor ke chrome global ( Katalog) */



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



.kat-reset{padding:9px 14px;border:1px solid var(--m-line);background:#fff;border-radius:10px;font-size:12.5px;font-weight:600;color:var(--m-ink);min-height:38px;cursor:pointer;font-family:'Satoshi',system-ui,sans-serif}



.kat-reset:hover{background:var(--m-bg)}



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



 .kat-reset{grid-column:1/-1;min-height:36px;padding:7px 12px;border-radius:9px;font-size:12.5px}



 .kat-clear{width:22px;height:22px}



 .kat-paging{flex-wrap:wrap}



}



.kat-charts{margin-top:12px;display:grid;gap:10px;grid-template-columns:repeat(2,1fr)}
.kat-chart-card:first-child{grid-column:1/-1}
@media(min-width:900px){.kat-charts{grid-template-columns:repeat(3,1fr);gap:12px}.kat-chart-card:first-child{grid-column:auto}}
.kat-chart-card{background:#fff;border:1px solid var(--m-line);border-radius:12px;padding:12px;min-width:0}
.kat-chart-title{margin:0 0 4px;font-size:13px;font-weight:700;font-family:'Satoshi',system-ui,sans-serif}
.kat-chart-sub{font-size:11px;font-weight:400;color:var(--m-muted)}
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



.tag.act{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}



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



.kat-periode{font-size:10.5px;color:#a8a29e}



.kat-rundown{border:1px solid var(--m-line);border-radius:10px;background:var(--m-bg);padding:2px 8px}



.kat-rundown-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;background:none;border:0;cursor:pointer;padding:5px 0;font-family:'Satoshi',system-ui,sans-serif}



.kat-rundown-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:#57534e}



.kat-rundown-right{display:flex;align-items:center;gap:6px}



.kat-rundown-count{font-size:10px;color:#a8a29e}



.kat-rundown-chev{width:20px;height:20px;display:grid;place-items:center;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:10px;color:var(--m-muted)}



.kat-rundown-body{padding:2px 0 8px}



.kat-rundown-preview{padding:2px 0 6px;cursor:pointer}



.kat-rundown-more{font-size:10px;color:var(--m-cta);margin-top:6px;text-align:center;padding:5px 8px;background:#fff;border:1px dashed var(--m-line);border-radius:8px}



.kat-rundown--empty{opacity:.75}



.kat-rundown-empty{padding:6px 10px;border-radius:8px;border:1px dashed var(--m-line);background:#fff;font-size:11px;color:#a8a29e;text-align:center}



.kat-rundown-list{background:#fff;border:1px solid var(--m-line);border-radius:8px;padding:8px;display:flex;flex-direction:column;gap:6px}



.kat-rundown-row{display:flex;gap:8px;align-items:flex-start}



.kat-rundown-time{font-size:11px;font-weight:600;color:var(--m-ink);flex-shrink:0;min-width:40px}



.kat-rundown-text{font-size:12px;line-height:1.5;color:#52525b}



.kat-cta{display:flex;gap:8px;align-items:center;margin-top:2px;flex-wrap:wrap}



.kat-detail{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:999px;background:var(--m-cta);color:#fff;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap;border:1px solid var(--m-cta);line-height:1;transition:background .15s,transform .15s,box-shadow .15s}



.kat-detail:hover{background:#3d6462;transform:translateY(-1px);box-shadow:0 4px 12px rgba(74,120,117,.25);text-decoration:none;color:#fff}

.kat-detail:active{transform:none;box-shadow:none}

.kat-detail-arrow{font-size:13px;line-height:1;transition:transform .15s}

.kat-detail:hover .kat-detail-arrow{transform:translateX(2px)}



.kat-mini{margin-left:auto;padding:5px 10px;border-radius:8px;border:1px solid var(--m-line);background:#fff;font-size:11.5px;font-weight:600;cursor:pointer;white-space:nowrap;color:var(--m-ink);text-decoration:none;display:inline-flex;align-items:center;font-family:'Satoshi',system-ui,sans-serif}



.kat-mini + .kat-mini{margin-left:0}



.kat-mini.neutral:hover{background:var(--m-bg)}



.kat-mini.danger{border-color:#fecaca;color:#991b1b}



.kat-mini.danger:hover{background:#fef2f2}



.kat-mini.primary{background:var(--m-cta);border-color:var(--m-cta);color:#fff}



.kat-mini.primary:hover:not(:disabled){background:var(--m-cta-h)}



.kat-mini.primary:disabled{opacity:.55;cursor:not-allowed}



.kat-helper{margin:0;font-size:10px;color:#a8a29e;text-align:right}



.kat-empty{margin-top:16px;background:#fff;border:1px dashed var(--m-line);border-radius:16px;padding:40px;text-align:center;color:var(--m-muted);font-size:13px}



.kat-paging{margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:13px}



.kat-count{color:var(--m-muted)}



.kat-paging-btns{display:flex;gap:8px;align-items:center}



.kat-page-btn{padding:6px 12px;border-radius:8px;border:1px solid var(--m-line);background:#fff;font-size:13px;min-width:32px;min-height:32px;color:var(--m-ink);cursor:pointer;font-family:'Satoshi',system-ui,sans-serif}



.kat-page-btn:disabled{opacity:.4;cursor:not-allowed}



.kat-page-num{padding:6px 10px;border:1px solid var(--m-line);background:#fff;border-radius:8px;font-size:12px;color:var(--m-ink)}



.skel-line{height:10px;background:#e7eceb;border-radius:6px;margin-bottom:8px}



.skel-line.w40{width:40%}.skel-line.w80{width:80%}.skel-line.w90{width:90%}



.kat-alert{margin-top:12px;padding:10px 12px;border-radius:10px;font-size:13px}



.kat-alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}



.kat-alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}



.kat-detail:focus-visible,.kat-mini:focus-visible,.kat-page-btn:focus-visible,.kat-reset:focus-visible,.kat-clear:focus-visible,.kat-input:focus-visible,.kat-select:focus-visible,.kat-link:focus-visible,.kat-rundown-toggle:focus-visible{outline:2px solid var(--m-green);outline-offset:2px}



</style>




