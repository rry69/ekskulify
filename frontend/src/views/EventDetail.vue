<template>
<div class="kat-page">
<div class="kat-inner">

 <div v-if="loadError" class="kat-empty" role="alert">
 <p style="font-weight:600">{{ loadError }}</p>
 <p class="mono" style="font-size:11px;opacity:.7">Event tidak ditemukan atau di luar akses Anda.</p>
 <p style="margin-top:12px"><router-link to="/events" class="kat-back">--> Kembali ke daftar</router-link></p>
 </div>

 <div v-else-if="e">

 <!--  hero editorial -->
 <section class="kat-card kat-ed">
 <div class="kat-media">
 <img v-if="e.cover_url" :src="e.cover_url" alt="" />
 <div v-else class="kat-media-empty" aria-hidden="true"><span>{{ (e.nama||'').slice(0,1).toUpperCase() }}</span></div>
 <div class="kat-media-shade" aria-hidden="true"></div>
 <div class="kat-media-top">
  <span class="badge-kuota" :class="isPenuh(e)?'red':'emerald'">{{ isPenuh(e)?'Penuh':'Tersedia' }}</span>
 <span v-if="Number(e.is_registered)" class="badge-mine diterima">- Sudah daftar</span>
 <span v-else class="badge-status">{{ e.status }}</span>
 </div>
 <div class="kat-media-bottom">
 <h1 class="kat-ed-title">{{ e.nama }}</h1>
 <p class="kat-ed-meta">{{ formatTanggalMeta(e) }} - {{ e.lokasi && String(e.lokasi).trim() ? e.lokasi : 'Belum ditentukan' }} - {{ e.terisi ?? 0 }}/{{ e.kuota }}</p>
 </div>
 </div>
 <div class="kat-card-body">
  <p v-if="role==='guest'" class="kat-strip">Login untuk mendaftar event ini. <router-link to="/login">Login -></router-link></p>
  <p v-else-if="role==='kepsek'" class="kat-strip">Mode baca: keputusan status di halaman Approval.</p>
 <div class="kat-periode mono" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
 <span>Periode: {{ formatPeriode(e.registration_start, e.registration_end) }} - dibuat {{ e.creator || ' - ' }}</span>
 <template v-if="e.ekskul_list && e.ekskul_list.length"><span v-for="ek in e.ekskul_list" :key="ek.id" class="mono" style="font-size:10px;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe"> {{ ek.nama }}</span></template>
 <span v-else-if="e.ekskul_id" class="mono" style="font-size:10px;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe"> {{ e.ekskul_nama || ('Ekskul #'+e.ekskul_id) }}</span>
 <span v-else class="mono" style="font-size:10px;padding:2px 8px;border-radius:999px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0">o Umum</span>
 </div>
  <div class="kat-desc" :class="isDescEmpty ? 'muted' : ''" v-html="descHtml"></div>
 <div class="kat-rundown" :class="{empty:!rundownItems.length}">
  <div class="kat-rundown-head">
    <span class="kat-rundown-title">RUNDOWN</span>
  </div>
  <div v-if="!rundownItems.length" class="kat-rundown-empty"><span class="kat-rundown-empty-ic" aria-hidden="true">◷</span><span>Belum ada rundown</span><span class="mono" style="font-size:10px;color:#a8a29e">Rundown akan tampil di sini setelah diisi</span></div>
  <div v-else class="kat-rundown-list">
    <div v-for="(r,i) in rundownItems" :key="i" class="kat-rundown-row">
      <span class="kat-rundown-dot" aria-hidden="true"></span>
      <span class="kat-rundown-time mono">{{ r.time }}</span>
      <span class="kat-rundown-text">{{ r.text }}</span>
    </div>
  </div>
 </div>
 </div>
 <div class="kat-cta">
  <button v-if="Number(e.is_registered)" class="kat-btn-ghost" @click="batal" :aria-label="'Batal daftar '+e.nama">Batal Daftar</button>
  <button v-else-if="isSiswa" class="kat-btn" :disabled="!canDaftar" :aria-label="canDaftar ? 'Daftar event '+e.nama : label+' event '+e.nama" @click="daftar">{{ label }}</button>
  <router-link v-else-if="role==='guest'" to="/login" class="kat-btn">Login untuk Daftar</router-link>
  <router-link v-else-if="role==='kepsek'" to="/kepsek/approval" class="kat-btn-ghost neutral">Approval</router-link>
 <router-link v-if="canEdit" class="kat-btn-ghost neutral" :to="'/events/'+e.id+'/edit'" aria-label="Edit event">Edit</router-link>
  <router-link v-else-if="role==='admin'" to="/events" class="kat-btn-ghost neutral">--> Events</router-link>
 </div>
 <p v-if="msg" class="kat-helper mono" :class="ok?'ok':'err'" role="status">{{ msg }}</p>
 <p v-else-if="isSiswa && !Number(e.is_registered) && !canDaftar" class="kat-helper mono">{{ disabledHelper }}</p>
 </section>

  <!--  ===== KEHADIRAN TERPADU - Peserta + QR (admin-only, global) ===== -->
  <section v-if="role==='admin'" class="kat-card kat-kehadiran">
    <!--  head -->
    <div class="kat-keh-head">
      <div class="kat-keh-head-l">
        <span class="kat-sec-title">Kehadiran</span>
        <span class="kat-count">{{ peserta.length }}</span>
        <span class="mono keh-sub">Peserta & Absensi QR - global</span>
      </div>
      <div class="kat-keh-head-r"></div>
    </div>

    <!--  KPI + progress global -->
    <div class="keh-kpi">
      <div class="keh-kpi-card">
        <span class="mono keh-kpi-label">TERDAFTAR</span>
        <span class="keh-kpi-val">{{ peserta.length }}</span>
        <span class="mono keh-kpi-hint">{{ e.terisi ?? peserta.length }}/{{ e.kuota }} kuota</span>
      </div>
      <div class="keh-kpi-card ok">
        <span class="mono keh-kpi-label">HADIR (QR)</span>
        <span class="keh-kpi-val">{{ hadirGlobal }}</span>
        <span class="mono keh-kpi-hint">{{ hadirGlobal }} hadir - {{ peserta.length - hadirGlobal }} belum - {{ pctGlobal }}%</span>
      </div>
      <div class="keh-kpi-card muted">
        <span class="mono keh-kpi-label">SESI</span>
        <span class="keh-kpi-val">{{ sessions.length }}</span>
        <span class="mono keh-kpi-hint">{{ aktifCount }} aktif - {{ stoppedCount }} stop</span>
      </div>
    </div>
    <div class="keh-progress">
      <div class="keh-progress-bar"><div class="keh-progress-fill" :style="{width: pctGlobal + '%'}"></div></div>
      <span class="mono keh-progress-txt">{{ pctGlobal }}% hadir global</span>
    </div>

    <!--  Sesi strip + create -->
    <div class="keh-sesi-wrap">
      <div class="keh-sesi-head">
        <span class="mono keh-label">SESI & QR</span>
        <span class="mono keh-hint">{{ sessions.length ? sessions.length + ' sesi - 1 QR per sesi, reusable s/d expiry' : 'Belum ada sesi' }}</span>
      </div>

      <!--  create row -->
      <div class="keh-create">
        <label class="keh-field">
          <span class="mono keh-field-label">NAMA SESI</span>
          <input v-model="sesiForm.nama" placeholder="Sesi Pagi (opsional)" class="keh-input" />
        </label>
        <label class="keh-field sm">
          <span class="mono keh-field-label">DURASI JAM</span>
          <input v-model.number="sesiForm.durasi" type="number" min="1" max="24" class="keh-input" />
        </label>
        <button class="keh-btn keh-btn-create" @click="buatSesi" :disabled="sesiSaving">{{ sesiSaving ? 'Membuat...' : '+ Buat Sesi + QR' }}</button>
      </div>
      <p v-if="sesiErr" class="mono keh-err" role="alert">{{ sesiErr }}</p>

      <!--  sesi chips -->
      <div v-if="sessions.length" class="keh-chips">
        <div v-for="s in sessions" :key="s.id" class="keh-chip" :class="s.status">
          <div class="keh-chip-top">
            <strong class="keh-chip-name">{{ s.nama }}</strong>
            <span class="mono keh-chip-status" :class="s.status"> - * {{ s.status.toUpperCase() }}</span>
          </div>
          <div class="mono keh-chip-meta">s/d {{ fmtWaktu(s.expires_at) }} - {{ s.hadir_count }} hadir</div>
            <div class="keh-chip-actions">
              <button class="keh-btn keh-btn-qr" @click="tampilQR(s)" aria-label="Lihat QR">Lihat QR</button>
              <button class="keh-btn keh-btn-print" @click="cetakQR(s)" aria-label="Cetak QR">Cetak QR</button>
              <button v-if="s.status==='aktif'" class="keh-btn keh-btn-stop" @click="stopSesi(s)">Stop QR</button>
              <button class="keh-btn keh-btn-laporan" @click="unduhLaporan(s,'pdf')">PDF</button>
              <button class="keh-btn keh-btn-laporan" @click="unduhLaporan(s,'xls')">Excel</button>
            </div>
        </div>
      </div>
      <div v-else class="kat-empty-sm">Belum ada sesi. Buat sesi untuk menerbitkan QR absensi.</div>
    </div>

    <!--  peserta toolbar -->
    <div class="keh-toolbar">
      <div class="keh-search-wrap">
        <span class="keh-search-icon"></span>
        <input v-model="pesertaSearch" placeholder="Cari nama peserta..." class="keh-search" aria-label="Cari peserta" />
        <button v-if="pesertaSearch" class="keh-search-clear" @click="pesertaSearch=''" aria-label="Hapus pencarian">x - </button>
      </div>
      <div class="mono keh-toolbar-meta">
        <span>{{ filteredPeserta.length }} peserta</span>
        <span v-if="scanLoading" class="keh-loading"> - memuat QR...</span>
        <span v-else-if="hadirGlobal>0" class="keh-ok"> - {{ hadirGlobal }} sudah scan</span>
      </div>
      <label class="mono keh-manual-sesi">SESI MANUAL:
        <select v-model="manualSid" class="keh-select" aria-label="Pilih sesi untuk absen manual">
          <option value="">-- pilih sesi --</option>
          <option v-for="s in sesiAktif" :key="s.id" :value="s.id">{{ s.nama }} (s/d {{ fmtWaktu(s.expires_at) }})</option>
        </select>
      </label>
    </div>
    <p v-if="manualMsg" class="mono keh-manual-msg" :class="manualOk?'ok':'err'" role="status">{{ manualMsg }}</p>

    <!--  peserta table -->
    <div v-if="!filteredPeserta.length && !peserta.length" class="kat-empty-sm">Belum ada peserta</div>
    <div v-else-if="!filteredPeserta.length" class="kat-empty-sm">Tidak ada hasil untuk "{{ pesertaSearch }}"</div>
    <div v-else class="kat-table-wrap">
      <table class="kat-table">
        <thead><tr><th scope="col" style="width:36px">#</th><th scope="col">NAMA</th><th scope="col">SCAN QR (GLOBAL)</th><th scope="col">SESI DIPILIH</th><th scope="col" class="r">AKSI</th></tr></thead>
        <tbody>
          <tr v-for="(p,idx) in pagedPeserta" :key="p.id">
            <td class="mono" style="color:#78716c;font-size:11px">{{ (pesertaPage - 1)*pesertaLimit + idx + 1 }}</td>
            <td class="nm">
              <div style="font-weight:600">{{ p.nama }}</div>
              <div class="mono" style="font-size:10px;color:#a8a29e">{{ p.kelas || p.email || '' }}</div>
            </td>
            <td>
              <span v-if="scanMap[p.user_id]" class="keh-badge scan">v  {{ scanMap[p.user_id].count }} sesi - {{ scanMap[p.user_id].last }}</span>
              <span v-else class="keh-badge idle"> - belum scan</span>
            </td>
            <td class="mono" style="font-size:11px">{{ manualSid && scanSet[manualSid] && scanSet[manualSid].has(p.user_id) ? 'v hadir ('+namaSesi(manualSid)+')' : ' - ' }}</td>
            <td class="r">
              <button v-if="manualSid && scanSet[manualSid] && scanSet[manualSid].has(p.user_id)" class="keh-btn keh-btn-stop" @click="unmarkManual(p)" :disabled="manualBusy" aria-label="Batalkan absen sesi">Batal</button>
              <button v-else class="keh-btn keh-btn-mark" @click="markManual(p)" :disabled="!manualSid || manualBusy" :title="!manualSid ? 'Pilih sesi manual dulu' : 'Tandai hadir di '+namaSesi(manualSid)" aria-label="Tandai hadir di sesi">Tandai hadir</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!--  pagination 300+ -->
    <div v-if="filteredPeserta.length > pesertaLimit" class="keh-pagination">
      <button class="kat-btn-ghost neutral sm" :disabled="pesertaPage<=1" @click="pesertaPage--">< Prev</button>
      <span class="mono keh-page-info">{{ pesertaPage }} / {{ totalPesertaPages }} - {{ filteredPeserta.length }} peserta</span>
      <button class="kat-btn-ghost neutral sm" :disabled="pesertaPage>=totalPesertaPages" @click="pesertaPage++">Next ></button>
    </div>
  </section>

  <!--  QR Modal (kat theme) -->
  <Teleport to="body">
    <div v-if="qrAktif" class="keh-modal-backdrop" @click.self="qrAktif=null">
      <div class="keh-modal" role="dialog" aria-modal="true" aria-label="QR Absensi">
        <div class="keh-modal-head">
          <div>
            <div class="mono keh-modal-label">QR ABSENSI - KHUSUS SISWA</div>
            <div style="font-weight:700;font-size:15px">{{ e.nama }} - {{ qrAktif.nama }}</div>
            <div class="mono" style="font-size:11px;color:#6B7C85">Berlaku s/d {{ fmtWaktu(qrAktif.expires_at) }} - 1 siswa = 1 scan - reusable s/d expiry</div>
          </div>
          <button class="keh-modal-close" @click="qrAktif=null" aria-label="Tutup">x - </button>
        </div>
         <div class="keh-modal-body">
           <canvas ref="qrCanvas" width="300" height="300" class="keh-qr-canvas"></canvas>
           <p class="mono keh-qr-url" style="word-break:break-all">{{ qrUrl(qrAktif) }}</p>
           <p class="mono keh-qr-text" style="font-size:10px;color:#a8a29e;word-break:break-all">{{ qrAktif.qr_text }}</p>
           <div class="keh-qr-link-wrap">
             <input class="keh-qr-link" :value="qrUrl(qrAktif)" readonly @focus="$event.target.select()" aria-label="Link absensi" />
             <button class="keh-btn keh-btn-copy" @click="copyQrLink(qrAktif)">{{ copyOk ? 'Tersalin!' : 'Copy Link' }}</button>
           </div>
           <p class="mono" style="font-size:11px;color:#6B7C85;margin-top:8px;line-height:1.4">QR berisi URL valid — bisa di-scan pakai Google Lens / kamera HP. Jika belum login akan diarahkan ke Login dulu lalu otomatis absen.</p>
           <div class="keh-modal-actions">
             <button class="keh-btn keh-btn-primary" @click="cetakQR(qrAktif)">Cetak QR</button>
             <button class="keh-btn keh-btn-ghost" @click="qrAktif=null">Tutup</button>
           </div>
         </div>
      </div>
    </div>
  </Teleport>

  </div>
 <div v-else class="kat-empty" aria-busy="true">Memuat event...</div>
</div>
</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuth } from '../stores/auth.js'
import { api } from '../lib/api.js'
import { sanitizeHtml } from '../lib/sanitizeHtml.js'
import QRCode from 'qrcode'

const route=useRoute(), auth=useAuth(), e=ref(null), peserta=ref([]), msg=ref(''), ok=ref(false), loadError=ref('')
const role=computed(()=> auth.user?.role || 'guest')
const isSiswa=computed(()=> role.value==='siswa')
const isAdmin=computed(()=> role.value==='admin')
const canEdit=computed(()=>{ if(!e.value) return false; return isAdmin.value })

function isPenuh(ev){ return Number(ev.terisi||0) >= Number(ev.kuota||0) }
function formatTanggalMeta(ev){
 const t=ev.tanggal||'', w=(ev.waktu||'').slice(0,5), ws=(ev.waktu_selesai||'').slice(0,5)
 if(!t) return w||' - '
 const d=new Date(t+'T00:00:00')
 const months=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']
 const dd=String(d.getDate()).padStart(2,'0')
 const mm=months[d.getMonth()]||''
 const dateStr=`${dd} ${mm}`
 if(w && ws) return `${dateStr} ${w} - ${ws}`
 if(w) return `${dateStr} ${w}`
 return dateStr
}
function formatPeriode(s,ee){
 const hasS=!!(s&&String(s).trim()), hasE=!!(ee&&String(ee).trim())
 if(!hasS && !hasE) return 'Buka terus'
 if(hasS && !hasE) return `Mulai ${String(s).slice(0,10)} - Buka terus`
 if(!hasS && hasE) return `s/d ${String(ee).slice(0,10)}`
 return `${String(s).slice(0,10)} s/d ${String(ee).slice(0,10)}`
}
function isOpen(ev){
 const now=Date.now()
 if(ev.registration_start && new Date(ev.registration_start).getTime() > now) return false
 if(ev.registration_end && new Date(ev.registration_end).getTime() < now) return false
 return true
}
const canDaftar=computed(()=>{
 if(!e.value) return false
  if(auth.user?.role!=='siswa') return false
 if(e.value.status!=='approved') return false
 if(!isOpen(e.value)) return false
 if(isPenuh(e.value)) return false
 if(Number(e.value.is_registered)) return false
 if(e.value.can_register===false) return false
 return true
})
const label=computed(()=>{
 if(!e.value) return 'Daftar'
 if(Number(e.value.is_registered)) return 'Sudah Daftar'
 if(e.value.status!=='approved') return 'Menunggu approval'
 if(!isOpen(e.value)){
  if(e.value.registration_start && new Date(e.value.registration_start).getTime()>Date.now()) return 'Belum buka'
  return 'Tutup'
 }
 if(isPenuh(e.value)) return 'Penuh'
 if(e.value.can_register===false) return 'Khusus Anggota'
 return 'Daftar'
})
const disabledHelper=computed(()=>{
 if(!e.value) return ''
 if(Number(e.value.is_registered)) return 'sudah terdaftar'
 if(e.value.status!=='approved') return 'Menunggu approval'
 if(!isOpen(e.value)){
  if(e.value.registration_start && new Date(e.value.registration_start).getTime()>Date.now()) return 'belum dibuka'
  return 'periode tutup'
 }
 if(isPenuh(e.value)) return 'kuota penuh'
 if(e.value.can_register===false) return e.value.register_block_reason || 'Hanya anggota ekskul terkait'
  if(auth.user?.role!=='siswa') return 'siswa only'
 return ''
})
const isDescEmpty=computed(()=> !String(e.value.deskripsi||'').trim())
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;') }
const descHtml=computed(()=>{
 const raw=String(e.value.deskripsi||'').trim()
 if(!raw) return '<span class="italic" style="color:var( - m - muted)">Deskripsi belum diisi</span>'
 let h=esc(raw)
 h=h.replace(/\[\[img:(\d+)\]\]/g, '<img src="/api/event-images/$1" alt="gambar event" loading="lazy" decoding="async" style="max-width:100%;border-radius:12px;margin:12px auto;display:block;border:1px solid var(--m-line,#E0E5E3)" />')
 h=h.replace(/\n/g,'<br>')
 return sanitizeHtml(h)
})
const rundownItems=computed(()=>{
 const raw=String(e.value?.rundown||'').trim()
 if(!raw) return []
 const lines=raw.split(/\r?\n/).map(s=>s.trim()).filter(Boolean)
 return lines.map(line=>{
  const m=line.match(/^(\d{1,2}[:.]\d{2}(?::\d{2})?)\s*[-–—]\s*(.*)$/)
  if(m) return {time:m[1].replace(/\./g,':').slice(0,5), text:(m[2]||'').trim()||'-'}
  const parts=line.trim().split(/\s+/)
  if(/^\d{1,2}[:.]\d{2}/.test(parts[0])) return {time:parts[0].replace(/\./g,':').slice(0,5), text:parts.slice(1).join(' ').trim()||'-'}
  return {time:'-', text:line.trim()||'-'}
 })
})

async function load(){
 loadError.value=''
 try{
   const j=await api('/events/'+route.params.id)
   e.value=j.data
   try{ const _n=String(j.data.nama||'').trim(); document.title=(_n?_n.slice(0,40):'Event')+' | Eskulify' }catch{}
 }catch(ex){
  e.value=null
   const c=ex?.error?.code||ex?.code
  loadError.value=(c==='NOT_FOUND'||c==='FORBIDDEN')?'Event tidak ditemukan atau di luar akses Anda':'Gagal memuat event'
  return
 }
  try{ const p=await api('/events/'+route.params.id+'/peserta'); peserta.value=p.data||[] }catch{ peserta.value=[] }
  await loadSessions()
  await loadScanGlobal()
}

async function daftar(){ try{ await api('/events/'+route.params.id+'/daftar',{method:'POST',body:{}}); ok.value=true; msg.value='Berhasil daftar'; await load() }catch(ex){ ok.value=false; msg.value=ex?.error?.message||ex?.message||'Gagal' } }
async function batal(){ try{ await api('/events/'+route.params.id+'/batal',{method:'POST',body:{}}); ok.value=true; msg.value='Batal berhasil'; await load() }catch(ex){ ok.value=false; msg.value=ex?.error?.message||ex?.message||'Gagal batal' } }
async function hadir(uid,val){ await api('/events/'+route.params.id+'/hadir',{method:'POST',body:{user_id:uid,hadir:val}}); await load() }
// --- Opsi A: manual absen terikat sesi (dropdown manualSid) + guard duplikat ---
const sessions=ref([]) // deklarasi di atas: dipakai sesiAktif + watch manualSid
const manualSid=ref('')
const manualMsg=ref('')
const manualOk=ref(false)
const manualBusy=ref(false)
const scanSet=ref({}) // sid -> Set(user_id), dibangun dari loadScanGlobal
const sesiAktif=computed(()=> sessions.value.filter(s=>s.status==='aktif'))
function namaSesi(sid){ const s=sessions.value.find(x=>String(x.id)===String(sid)); return s?s.nama:('Sesi #'+sid) }
watch(sessions, ()=>{ if(manualSid.value && !sessions.value.some(s=>String(s.id)===String(manualSid.value) && s.status==='aktif')) manualSid.value='' }, {deep:true})
async function markManual(p){
  manualMsg.value=''; manualOk.value=false
  if(!manualSid.value){ manualMsg.value='Pilih sesi manual dulu sebelum tandai hadir.'; return }
  manualBusy.value=true
  try{
    await api('/event-sessions/'+manualSid.value+'/mark',{method:'POST',body:{user_id:p.user_id}})
    manualOk.value=true; manualMsg.value=p.nama+' tercatat hadir di '+namaSesi(manualSid.value)+'.'
    await loadScanGlobal()
  }catch(ex){
    const c=ex?.error?.code||ex?.code||''
    const raw=ex?.error?.message||ex?.message||'Gagal'
    manualMsg.value=(c==='ALREADY') ? (p.nama+' sudah tercatat absensi di '+namaSesi(manualSid.value)+'.') : raw
  }finally{ manualBusy.value=false }
}
async function unmarkManual(p){
  manualMsg.value=''; manualOk.value=false
  if(!manualSid.value) return
  if(!confirm('Batalkan absen '+p.nama+' di '+namaSesi(manualSid.value)+'?')) return
  manualBusy.value=true
  try{
    await api('/event-sessions/'+manualSid.value+'/unmark',{method:'POST',body:{user_id:p.user_id}})
    manualOk.value=true; manualMsg.value='Absen '+p.nama+' di '+namaSesi(manualSid.value)+' dibatalkan.'
    await loadScanGlobal()
  }catch(ex){ manualMsg.value=ex?.error?.message||ex?.message||'Gagal batal' }
  finally{ manualBusy.value=false }
}

//  - Absensi QR multi - sesi (admin-only) + global aggregation (sessions dideklarasi di atas)  -  -
const sesiForm=ref({ nama:'', durasi:10 })
const sesiSaving=ref(false)
const sesiErr=ref('')
const qrAktif=ref(null)
const qrCanvas=ref(null)
const copyOk=ref(false)
function qrTokenOf(s){
  const raw=String(s?.qr_text||'')
  const m=raw.match(/EVENT:\d+:([a-f0-9]{32})/i)
  if(m) return m[1].toLowerCase()
  if(/^[a-f0-9]{32}$/i.test(raw)) return raw.toLowerCase()
  return raw
}
function qrUrl(s){
  const token=qrTokenOf(s)
  const base=window.location.origin
  return `${base}/events/${s.event_id || e.value?.id || route.params.id}/attendance/${s.id}?t=${encodeURIComponent(token)}`
}
async function copyQrLink(s){
  const url=qrUrl(s)
  try{ await navigator.clipboard.writeText(url); copyOk.value=true; setTimeout(()=>copyOk.value=false,1500) }
  catch{ const ta=document.createElement('textarea'); ta.value=url; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy')}catch{}; ta.remove(); copyOk.value=true; setTimeout(()=>copyOk.value=false,1500) }
}
const pesertaSearch=ref('')
const pesertaPage=ref(1)
const pesertaLimit=25
const scanMap=ref({})
const scanLoading=ref(false)
let pollTimer=null

function escH(s){ return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) }
function fmtWaktu(v){ if(!v) return '-'; try{ return new Date(v).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}) }catch{ return v } }

const hadirGlobal=computed(()=> Object.keys(scanMap.value).length )
const pctGlobal=computed(()=> peserta.value.length ? Math.round(hadirGlobal.value / peserta.value.length * 100) : 0)
const aktifCount=computed(()=> sessions.value.filter(s=>s.status==='aktif').length )
const stoppedCount=computed(()=> sessions.value.filter(s=>s.status==='stopped').length )
const filteredPeserta=computed(()=>{
  const q=pesertaSearch.value.trim().toLowerCase()
  if(!q) return peserta.value
  return peserta.value.filter(p=> String(p.nama||'').toLowerCase().includes(q) || String(p.email||'').toLowerCase().includes(q) || String(p.kelas||'').toLowerCase().includes(q))
})
const totalPesertaPages=computed(()=> Math.max(1, Math.ceil(filteredPeserta.value.length / pesertaLimit)))
const pagedPeserta=computed(()=>{
  const start=(pesertaPage.value - 1)*pesertaLimit
  return filteredPeserta.value.slice(start, start+pesertaLimit)
})
watch(pesertaSearch, ()=> pesertaPage.value=1)
watch(filteredPeserta, ()=>{ if(pesertaPage.value>totalPesertaPages.value) pesertaPage.value=totalPesertaPages.value })

async function loadSessions(){
  if(auth.user?.role!=='admin'||!e.value?.id) return
  try{ const r=await api('/events/'+e.value.id+'/sessions'); sessions.value=r.data.sessions || r.data || [] }catch{ sessions.value=[] }
}
async function loadScanGlobal(){
  if(auth.user?.role!=='admin'||!e.value?.id) return
  if(!sessions.value.length){ scanMap.value={}; return }
  scanLoading.value=true
  const map={}
  const perSesi={}
  try{
    // fetch laporan per sesi, aggregate globally: user_id --> {count, last} + per-sesi Set
    const results=await Promise.all(sessions.value.map(s=> api('/events/'+e.value.id+'/sessions/'+s.id+'/laporan').then(r=>({sid:s.id,r})).catch(()=>null)))
    results.forEach(item=>{
      if(!item || !item.r?.data?.hadir) return
      const sid=item.sid
      if(!perSesi[sid]) perSesi[sid]=new Set()
      item.r.data.hadir.forEach(h=>{
        const uid=h.id || h.user_id
        if(!uid) return
        perSesi[sid].add(uid)
        if(!map[uid]) map[uid]={count:0, last: h.scanned_at||''}
        map[uid].count++
        if(h.scanned_at && (!map[uid].last || h.scanned_at > map[uid].last)) map[uid].last=h.scanned_at.slice(11,16) || h.scanned_at
      })
    })
    // if no per - session hadir, fallback: try aggregated keep map
    scanMap.value=map
    scanSet.value=perSesi
  }catch{ scanMap.value=map }
  scanLoading.value=false
}

async function buatSesi(){
  sesiErr.value=''
  const d=Number(sesiForm.value.durasi)
  if(!Number.isFinite(d)||d<1||d>24){ sesiErr.value='Durasi wajib diisi 1 - 24 jam.'; return }
  sesiSaving.value=true
  try{
    const r=await api('/events/'+e.value.id+'/sessions',{method:'POST',body:{nama:sesiForm.value.nama,durasi_jam:d}})
    sesiForm.value.nama=''
    await loadSessions()
    await loadScanGlobal()
    const sess=r.data.session || r.data
    if(sess) await tampilQR(sess)
  }catch(ex){ sesiErr.value=ex?.error?.message||ex?.message||'Gagal membuat sesi.' }
  finally{ sesiSaving.value=false }
}

async function stopSesi(s){
  if(!confirm('Stop QR "'+s.nama+'" Siswa tidak bisa scan lagi.')) return
  try{ await api('/event-sessions/'+s.id+'/stop',{method:'POST',body:{}}); await loadSessions(); await loadScanGlobal() }catch(ex){ sesiErr.value=ex.error?.message||ex.message||'Gagal stop sesi.' }
}

async function tampilQR(s){
  qrAktif.value=s
  await nextTick()
  try{ if(qrCanvas.value) await QRCode.toCanvas(qrCanvas.value,qrUrl(s),{width:300,margin:1,errorCorrectionLevel:'M'}) }catch{ sesiErr.value='Gagal menggambar QR.' }
}

async function cetakQR(s){
  try{
    const url=qrUrl(s)
    const c=document.createElement('canvas')
    await QRCode.toCanvas(c,url,{width:512,margin:2,errorCorrectionLevel:'M'})
    const w=window.open('','_blank','width=680,height=760')
    if(!w){ sesiErr.value='Popup diblokir — izinkan popup untuk cetak.'; return }
    const escUrl=escH(url)
    w.document.write(`<html><head><meta charset="utf-8"><title>Cetak QR - ${escH(s.nama)}</title><style>
      *{box-sizing:border-box} body{font-family:'Satoshi',system-ui,sans-serif;text-align:center;padding:28px 20px;color:#2F3E46}
      .badge{display:inline-block;padding:4px 10px;border-radius:999px;background:#2F3E46;color:#fff;font-size:11px;letter-spacing:.06em}
      h2{margin:10px 0 4px;font-size:15px;letter-spacing:.06em}
      h3{margin:0 0 14px;font-size:16px;line-height:1.25}
      .qr{width:420px;height:420px;border:1px solid #E0E5E3;border-radius:16px;display:block;margin:0 auto}
      .url{font-size:10px;color:#6B7C85;word-break:break-all;margin:10px auto 0;max-width:520px}
      .meta{font-size:11px;color:#6B7C85;margin:8px 0 0}
      .note{font-size:11px;color:#57534e;margin-top:8px}
      .actions{margin-top:18px;display:flex;gap:10px;justify-content:center}
      .btn{height:42px;padding:0 18px;border-radius:999px;border:1px solid #2F3E46;background:#2F3E46;color:#fff;font-weight:700;cursor:pointer}
      .btn-ghost{height:42px;padding:0 18px;border-radius:999px;border:1px solid #E0E5E3;background:#fff;color:#2F3E46;font-weight:600;cursor:pointer}
      @media print{ .actions{display:none} body{padding:16px} }
    </style></head><body>
      <span class="badge">KHUSUS SISWA</span>
      <h2>${escH(e.value.nama)} — ${escH(s.nama)}</h2>
      <img class="qr" src="${c.toDataURL('image/png')}" alt="QR Absensi"/>
      <div class="url">${escUrl}</div>
      <div class="meta">Scan QR untuk absen — Berlaku s/d ${escH(fmtWaktu(s.expires_at))}</div>
      <div class="note">1 siswa = 1 scan — reusable s/d expiry — jika belum login akan diarahkan ke Login lalu otomatis absen.</div>
      <div class="actions"><button class="btn" onclick="window.print()">Cetak</button><button class="btn-ghost" onclick="window.close()">Tutup</button></div>
    </body></html>`)
    w.document.close(); w.focus()
  }catch{ sesiErr.value='Gagal menyiapkan cetakan.' }
}

async function unduhLaporan(s,kind){
  sesiErr.value=''
  try{
    const r=await api('/events/'+e.value.id+'/sessions/'+s.id+'/laporan')
    const d=r.data
    const rows=a=>(a||[]).map((p,i)=>'<tr><td>'+(i+1)+'</td><td>'+escH(p.nama)+'</td><td>'+escH(p.nis||p.email||'')+'</td><td>'+escH(p.kelas||'')+'</td><td>'+escH(p.scanned_at||' --')+'</td></tr>').join('')
    const judul='Laporan '+e.value.nama+' --'+s.nama
    if(kind==='xls'){
      const html='<html><head><meta charset="utf --8"></head><body><h3>HADIR (scan QR) --'+escH(judul)+'</h3><table border="1"><tr><th>No</th><th>Nama</th><th>NIS</th><th>Kelas</th><th>Waktu Scan</th></tr>'+rows(d.hadir)+'</table><h3>TERDAFTAR TAPI TIDAK ABSEN</h3><table border="1"><tr><th>No</th><th>Nama</th><th>NIS</th><th>Kelas</th><th>Waktu Scan</th></tr>'+rows(d.tidak_absen)+'</table></body></html>'
      const a=document.createElement('a')
      a.href=URL.createObjectURL(new Blob([''+html],{type:'application/vnd.ms-excel'}))
      a.download='laporan-event-sesi-'+s.id+'.xls'
      a.click()
      setTimeout(()=>URL.revokeObjectURL(a.href),5000)
    }else{
      const w=window.open('','_blank','width=820,height=900')
      w.document.write('<html><head><title>'+escH(judul)+'</title><style>body{font-family:sans-serif;padding:24px}table{border-collapse:collapse;width:100%;margin:12px 0}th,td{border:1px solid #999;padding:6px 8px;font-size:12px;text-align:left}@media print{button{display:none}}</style></head><body><h2>'+escH(judul)+'</h2><p>Hadir: '+d.counts.hadir+' - Terdaftar tapi tidak absen: '+d.counts.tidak_absen+'</p><h3>HADIR (scan QR saja)</h3><table><tr><th>No</th><th>Nama</th><th>NIS</th><th>Kelas</th><th>Waktu Scan</th></tr>'+rows(d.hadir)+'</table><h3>TERDAFTAR TAPI TIDAK ABSEN</h3><table><tr><th>No</th><th>Nama</th><th>NIS</th><th>Kelas</th><th>Waktu Scan</th></tr>'+rows(d.tidak_absen)+'</table><button onclick="window.print()">Cetak / Simpan PDF</button></body></html>')
      w.document.close()
    }
  }catch(ex){ sesiErr.value=ex?.error?.message||ex?.message||'Gagal memuat laporan.' }
}

onMounted(()=>{
  load()
  // reload saat pindah event tanpa remount (events/:id reuse komponen)
  watch(()=>route.params.id, ()=>{ e.value=null; peserta.value=[]; sessions.value=[]; scanMap.value={}; load() })
  // auto refresh hadir global every 15s for 300+ live feel (admin only)
  pollTimer=setInterval(()=>{ if(auth.user?.role==='admin' && e.value?.id) { loadSessions().then(()=> loadScanGlobal()) } },15000)
})
onBeforeUnmount(()=>{ if(pollTimer) clearInterval(pollTimer) })
</script>
<style scoped>



.kat-page{



  --m-green:#5EB87E;  --m-blue:#A7C7E7;  --m-ink:#2F3E46;  --m-bg:#F1F5F4;  --m-pink:#E8AEB3;



  --m-cta:#4A7875;  --m-cta-h:#5A908C;  --m-line:#E0E5E3;  --m-muted:#6B7C85;



  --page:linear-gradient(165deg,#E9F3EC 0%,#F1F5F4 36%,#EAF1F7 70%,#F8EBED 100%);



 background:var(--page);color:var(--m-ink);



 margin: --24px  --16px 0;padding:24px 16px 24px;



 overflow-x:clip;



}



.kat-inner{max-width:1120px;margin:0 auto}



.kat-crumb{margin-bottom:12px}



.kat-back{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:8px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-ink);text-decoration:none}



.kat-back:hover{background:var(--m-bg)}



.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:20px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06)}



.kat-ed .kat-media{position:relative;height:320px;overflow:hidden;background:linear-gradient(140deg,#dcebe2,#eef4f1 55%,#f3ece7)}



.kat-ed .kat-media img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}



.kat-media-empty{position:absolute;inset:0;display:grid;place-items:center}



.kat-media-empty span{font-family:'Satoshi',system-ui,sans-serif;font-style:italic;font-size:88px;line-height:1;color:#fff;text-shadow:0 2px 18px rgba(47,62,70,.28)}



.kat-media-shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(26,26,24,.34) 0%,rgba(26,26,24,0) 34%,rgba(26,26,24,.06) 55%,rgba(26,26,24,.62) 100%)}



.kat-media-top{position:absolute;top:10px;left:10px;right:10px;display:flex;flex-wrap:wrap;gap:6px}



.kat-media-top .badge-kuota,.kat-media-top .badge-mine,.kat-media-top .badge-status{backdrop-filter:blur(6px)}



.kat-media-bottom{position:absolute;left:12px;right:12px;bottom:10px}



.kat-ed-title{font-family:'Satoshi',system-ui,sans-serif;font-style:italic;font-size:30px;line-height:1.05;color:#fff;margin:0;text-shadow:0 2px 14px rgba(26,26,24,.45)}



.kat-ed-meta{font-family:'Satoshi',system-ui,sans-serif;font-size:12px;color:rgba(255,255,255,.9);margin:4px 0 0}



.badge-kuota{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid;font-weight:500}



.badge-kuota.emerald{background:#ecfdf5;color:#047857;border-color:#a7f3d0}



.badge-kuota.red{background:#fef2f2;color:#dc2626;border-color:#fecaca}



.badge-mine{font-size:11px;padding:4px 8px;border-radius:999px;font-weight:500;border:1px solid transparent}



.badge-mine.diterima{background:var(--m-ink);color:#fff}



.badge-status{font-size:11px;padding:4px 8px;border-radius:999px;background:var(--m-ink);color:#fff;font-weight:500}



.kat-card-body{padding:16px}



.kat-strip{margin:0 0 10px;font-size:13px;background:var(--m-bg);border:1px solid var(--m-line);border-radius:12px;padding:10px 12px}



.kat-strip a{color:var(--m-cta);font-weight:600}



.kat-periode{font-size:11px;color:var(--m-muted);margin-bottom:8px}



.mono{font-family:'Satoshi',system-ui,sans-serif}



.kat-desc{font-family:'Satoshi',system-ui,sans-serif;font-size:14px;line-height:1.65;margin:0;white-space:normal;word-break:break-word}



.kat-desc.muted{color:var(--m-muted)}



.kat-desc :deep(img){max-width:100%;height:auto;max-height:320px;object-fit:contain;border-radius:12px;display:block;margin:12px auto}



.kat-rundown{margin-top:14px;border:1px solid var(--m-line);border-radius:16px;background:#fff;overflow:hidden}
.kat-rundown-head{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--m-line);background:#fafaf9;flex-wrap:wrap}
.kat-rundown-title{font-family:'Satoshi',system-ui,sans-serif;font-size:11px;font-weight:700;letter-spacing:.1em;color:var(--m-ink)}
.kat-rundown-badge{font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px;background:var(--m-ink);color:#fff}
.kat-rundown-hint{font-size:10px;color:var(--m-muted);margin-left:auto}
.kat-rundown-empty{margin:12px;padding:22px 14px;border:1px dashed var(--m-line);border-radius:12px;background:var(--m-bg);display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;color:var(--m-muted);font-size:12px}
.kat-rundown-empty-ic{width:32px;height:32px;border-radius:50%;background:#fff;border:1px solid var(--m-line);display:grid;place-items:center;font-size:14px;color:var(--m-muted)}
.kat-rundown-list{position:relative;padding:12px 14px 12px 36px;display:flex;flex-direction:column;gap:10px;max-height:360px;overflow:auto;scrollbar-width:thin}
.kat-rundown-list::before{content:"";position:absolute;left:19px;top:14px;bottom:14px;width:1px;background:var(--m-line)}
.kat-rundown-row{position:relative;display:flex;gap:10px;align-items:center;background:#fff;border:1px solid var(--m-line);border-radius:12px;padding:8px 10px;transition:border-color .15s,background .15s}
.kat-rundown-row:hover{border-color:#d6d3d1;background:#fafaf9}
.kat-rundown-dot{position:absolute;left:-23px;top:50%;transform:translateY(-50%);width:9px;height:9px;border-radius:50%;background:var(--m-ink);border:2px solid #fff;box-shadow:0 0 0 2px var(--m-line);flex-shrink:0}
.kat-rundown-time{font-size:11px;font-weight:700;letter-spacing:.04em;background:var(--m-ink);color:#fff;padding:3px 8px;border-radius:999px;flex-shrink:0;min-width:44px;text-align:center;line-height:1.3}
.kat-rundown-text{font-size:13px;line-height:1.5;color:#44403c;flex:1;min-width:0;word-break:break-word}
@media(max-width:480px){ .kat-rundown-list{padding-left:30px} .kat-rundown-list::before{left:15px} .kat-rundown-dot{left:-19px} }



.kat-cta{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--m-line);flex-wrap:wrap}



.kat-cta .kat-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border:0;border-radius:12px;background:var(--m-cta);color:#fff;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;min-height:40px}



.kat-cta .kat-btn:hover:not(:disabled){background:var(--m-cta-h)}



.kat-cta .kat-btn:disabled{opacity:.55;cursor:not-allowed}



.kat-btn-ghost{padding:10px 12px;border-radius:12px;border:1px solid #fecaca;background:#fff;color:#991b1b;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;min-height:40px}



.kat-btn-ghost:hover{background:#fef2f2}



.kat-btn-ghost.neutral{border-color:var(--m-line);color:var(--m-ink)}



.kat-btn-ghost.neutral:hover{background:var(--m-bg)}



.kat-btn-ghost.sm{min-height:32px;padding:6px 12px;font-size:12px}



.kat-btn-ghost:disabled{opacity:.4;cursor:not-allowed}



.kat-helper{font-size:11px;color:var(--m-muted);text-align:center;margin:0 16px 12px}



.kat-helper.ok{color:#047857}



.kat-helper.err{color:#dc2626}



.kat-peserta{margin-top:12px}



.kat-peserta-head{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid var(--m-line)}



.kat-sec-title{font-family:'Satoshi',system-ui,sans-serif;font-size:14px;font-weight:600}



.kat-count{font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--m-ink);color:#fff}



.kat-peserta-head .kat-btn-ghost{margin-left:auto}



.kat-empty-sm{padding:32px 16px;text-align:center;font-size:12px;color:var(--m-muted)}



.kat-table-wrap{overflow-x:auto}



.kat-table{width:100%;min-width:520px;border-collapse:collapse}



.kat-table thead th{font-family:'Satoshi',system-ui,sans-serif;font-size:10px;letter-spacing:.08em;font-weight:600;color:var(--m-muted);background:var(--m-bg);text-align:left;padding:10px 16px;border-bottom:1px solid var(--m-line)}



.kat-table tbody td{padding:10px 16px;font-size:13px;border-bottom:1px solid var(--m-bg)}



.kat-table tbody tr:last-child td{border-bottom:0}



.kat-table tbody tr:hover{background:var(--m-bg)}



.kat-table .nm{font-weight:500}



.kat-table .r{text-align:right}



.kat-empty{background:#fff;border:1px dashed var(--m-line);border-radius:16px;padding:40px;text-align:center;color:var(--m-muted);font-size:13px}



.kat-back:focus-visible,.kat-btn:focus-visible,.kat-btn-ghost:focus-visible{outline:2px solid var(--m-green);outline-offset:2px}



@media(max-width:639px){



 .kat-page{margin: --24px  --16px 0;padding:20px 16px 24px}



 .kat-ed-title{font-size:26px}



}



.kat-page img,.kat-page canvas,.kat-page table{max-width:100%}



.kat-page section,.kat-page article,.kat-page div{min-width:0}



/* ===== Kehadiran terpadu ===== */
.kat-kehadiran{margin-top:14px}
.kat-keh-head{display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid var(--m-line);flex-wrap:wrap}
.kat-keh-head-l{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.keh-sub{font-size:10px;letter-spacing:.06em;color:#8a8580}
.kat-keh-head-r{margin-left:auto;display:flex;gap:8px}
.keh-kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:12px 16px}
@media(max-width:640px){ .keh-kpi{grid-template-columns:1fr} }
.keh-kpi-card{border:1px solid var(--m-line);border-radius:14px;padding:12px;background:#fff}
.keh-kpi-card.ok{background:#ecfdf5;border-color:#a7f3d0}
.keh-kpi-card.muted{background:#fafaf9}
.keh-kpi-label{font-size:9px;letter-spacing:.08em;color:#6B7C85}
.keh-kpi-val{font-size:22px;font-weight:800;line-height:1;margin-top:4px;display:block}
.keh-kpi-hint{font-size:10px;color:#6B7C85;margin-top:2px;display:block}
.keh-progress{padding:0 16px 12px}
.keh-progress-bar{height:8px;border-radius:999px;background:#f1f5f4;overflow:hidden}
.keh-progress-fill{height:100%;background:var(--m-cta);border-radius:999px;transition:width .4s}
.keh-progress-txt{font-size:10px;color:#6B7C85;margin-top:6px;display:block}
.keh-sesi-wrap{padding:12px 16px;border-top:1px solid var(--m-line);background:#fafaf9}
.keh-sesi-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.keh-label{font-size:10px;letter-spacing:.08em;font-weight:700;color:var(--m-ink)}
.keh-hint{font-size:11px;color:#6B7C85}
.keh-create{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
.keh-field{display:flex;flex-direction:column;gap:4px;flex:1;min-width:160px}
.keh-field.sm{max-width:140px;flex:0 0 140px}
.keh-field-label{font-size:9px;letter-spacing:.08em;font-weight:700;color:#6B7C85}
.keh-input{height:40px;padding:0 12px;border:1px solid var(--m-line);border-radius:12px;background:#fff;font-size:13px;outline:none}
.keh-input:focus{border-color:var(--m-cta);box-shadow:0 0 0 2px rgba(74,120,117,.12)}
.keh-btn-create{height:40px;padding:0 18px;background:var(--m-cta) !important;color:#fff !important;border-color:var(--m-cta) !important;font-weight:800;box-shadow:0 2px 8px rgba(74,120,117,.18)}
.keh-btn-create:hover{filter:brightness(.96)}
.keh-btn-create:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.keh-err{font-size:11px;color:#dc2626;margin-top:6px}
.keh-chips{display:flex;gap:10px;overflow-x:auto;padding-top:10px;padding-bottom:2px;scrollbar-width:thin}
.keh-chip{min-width:220px;max-width:260px;flex-shrink:0;border:1px solid var(--m-line);border-radius:14px;padding:10px;background:#fff}
.keh-chip.aktif{border-color:#a7f3d0}
.keh-chip.stopped{opacity:.7}
.keh-chip.expired{opacity:.55}
.keh-chip-top{display:flex;gap:6px;align-items:center;justify-content:space-between}
.keh-chip-name{font-size:13px;font-weight:700;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.keh-chip-status{font-size:9px;padding:2px 6px;border-radius:999px;border:1px solid}
.keh-chip-status.aktif{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
.keh-chip-status.stopped{background:#f1f5f9;color:#475569;border-color:#cbd5e1}
.keh-chip-status.expired{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.keh-chip-meta{font-size:10px;color:#6B7C85;margin-top:4px}
.keh-chip-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.kat-btn-ghost.xs{min-height:28px;padding:4px 10px;font-size:11px;border-radius:999px}
.kat-btn-ghost.xs.danger{color:#b91c1c;border-color:#fecaca}
.kat-btn-ghost.xs.danger:hover{background:#fef2f2}
.keh-toolbar{padding:12px 16px;border-top:1px solid var(--m-line);display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff}
.keh-search-wrap{position:relative;flex:1;min-width:200px;max-width:420px;display:flex;align-items:center}
.keh-search-icon{position:absolute;left:10px;color:#a8a29e;font-size:12px}
.keh-search{width:100%;height:38px;padding:0 32px 0 28px;border:1px solid var(--m-line);border-radius:12px;background:#fff;font-size:13px;outline:none}
.keh-search:focus{border-color:var(--m-cta);box-shadow:0 0 0 2px rgba(74,120,117,.12)}
.keh-search --clear{position:absolute;right:6px;width:24px;height:24px;border-radius:999px;border:1px solid var(--m-line);background:#fff;display:grid;place-items:center;cursor:pointer;font-size:12px}
.keh-toolbar-meta{font-size:11px;color:#6B7C85;display:flex;gap:6px}
.keh-loading{color:var(--m-cta)}
.keh-ok{color:#065f46;font-weight:600}
.keh-badge{font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid}
.keh-badge.scan{background:#ecfdf5;color:#065f46;border-color:#a7f3d0;font-weight:600}
.keh-badge.idle{background:#fafaf9;color:#78716c;border-color:var(--m-line)}
.keh-pagination{padding:12px 16px;border-top:1px solid var(--m-line);display:flex;gap:8px;align-items:center;justify-content:space-between;background:#fff}
.keh-page-info{font-size:11px;color:#57534e}
.keh-pagination --info{padding:10px 16px;text-align:center;font-size:11px;color:#6B7C85;border-top:1px solid var(--m-line);background:#fff}
.keh-btn{height:34px;padding:0 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-ink);font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;white-space:nowrap;line-height:1}
.keh-btn:hover{filter:brightness(.98);transform:translateY(-1px)}
.keh-btn:active{transform:none}
.keh-btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.keh-btn-qr{background:var(--m-ink);color:#fff;border-color:var(--m-ink);min-width:92px}
.keh-btn-print{background:#fff;border-color:var(--m-ink);color:var(--m-ink);min-width:92px}
.keh-btn-stop{background:#fff;color:#991b1b;border-color:#fecaca;min-width:84px}
.keh-btn-stop:hover{background:#fef2f2}
.keh-btn-laporan{background:#fafaf9;min-width:70px}
.keh-btn-primary{background:var(--m-cta);color:#fff;border-color:var(--m-cta);height:40px;padding:0 18px}
.keh-btn-mark{background:var(--m-cta);color:#fff;border-color:var(--m-cta)}
.keh-btn-mark:hover:not(:disabled){filter:brightness(1.08)}
.keh-manual-sesi{font-size:10px;font-weight:700;color:var(--m-ink);display:flex;gap:8px;align-items:center;margin-left:auto}
.keh-select{height:36px;padding:0 10px;border:1px solid var(--m-line);border-radius:10px;background:#fff;font-size:12px;font-weight:600;color:var(--m-ink);outline:none;max-width:280px}
.keh-select:focus{border-color:var(--m-cta);box-shadow:0 0 0 2px rgba(74,120,117,.12)}
.keh-manual-msg{font-size:11px;padding:8px 16px;margin:0;border-top:1px solid var(--m-line);background:#fff}
.keh-manual-msg.ok{color:#065f46}
.keh-manual-msg.err{color:#991b1b}
.keh-btn-ghost{background:#fff}
.keh-btn-copy{height:36px}
.keh-qr-link-wrap{display:flex;gap:8px;align-items:center;margin-top:10px}
.keh-qr-link{flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--m-line);border-radius:10px;background:#fafaf9;font-size:11px;color:#2F3E46;outline:none}
.keh-qr-link:focus{border-color:var(--m-cta);box-shadow:0 0 0 2px rgba(74,120,117,.12)}
.keh-qr-url{font-size:11px;color:#2F3E46;font-weight:600;margin-top:8px}
.keh-qr-text{font-size:10px;word-break:break-all;color:#6B7C85;margin-top:6px}
/* QR modal */
.keh-modal-backdrop{position:fixed;inset:0;background:rgba(47,62,70,.38);backdrop-filter:blur(6px);display:grid;place-items:center;z-index:60;padding:16px}
.keh-modal{background:#fff;border:1px solid var(--m-line);border-radius:20px;overflow:hidden;max-width:560px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.18)}
.keh-modal-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:16px;border-bottom:1px solid var(--m-line)}
.keh-modal-label{font-size:9px;letter-spacing:.08em;color:#6B7C85;font-weight:700}
.keh-modal-close{width:32px;height:32px;border-radius:999px;border:1px solid var(--m-line);background:#fff;display:grid;place-items:center;cursor:pointer;font-size:18px;line-height:1}
.keh-modal-body{padding:16px;text-align:center}
.keh-qr-canvas{display:block;margin:0 auto;border:1px solid var(--m-line);border-radius:16px}
.keh-modal-actions{display:flex;gap:8px;justify-content:center;margin-top:12px;flex-wrap:wrap}
</style>





