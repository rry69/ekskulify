<template>
  <div class="sesi-page">
    <!-- Header -->
    <div class="sesi-head-card">
      <button class="md-back-btn" @click="router.push('/admin/sertifikat')">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 12L6 8L10 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Kembali ke Sertifikat
      </button>
      <div class="sesi-title-row">
        <h1>Sesi Cetak: {{ targetNama || 'Event' }}</h1>
        <span class="md-badge md-badge-blue">Event</span>
      </div>
      <div class="sesi-sub">Tipe: event · ID: {{ targetId }}</div>
      <!-- event-only: route /ekskul/* tidak didukung sertifikat -->
      <div v-if="tipe !== 'event'" class="md-alert md-alert-err" style="margin-top:10px">Modul sertifikat hanya mendukung event. Tipe "{{ tipe }}" tidak didukung.</div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="md-loading-wrap">
      <div class="md-skeleton md-skeleton-title"></div>
      <div class="md-skeleton md-skeleton-line"></div>
      <div class="md-skeleton-grid">
        <div class="md-skeleton md-skeleton-card"></div>
        <div class="md-skeleton md-skeleton-card sm"></div>
      </div>
    </div>

    <div v-else class="sesi-body">
      <!-- FONT SELECTOR: semua 5 field × 13 fonts (sinkron AdminSertifikat) -->
      <div class="cert-fontbar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;background:#f8fafb;border:1px solid var(--m-line,#E0E5E3);border-radius:12px;padding:10px 12px">
        <div style="display:flex;gap:6px;align-items:center;color:#2F3E46;font-weight:800;font-size:11px;letter-spacing:.06em">✎ FONT TEKS</div>
        <label class="fontpick" style="display:flex;flex-direction:column;gap:2px"><span class="lbl mono" style="font-size:10px;font-weight:700;letter-spacing:.06em;color:#2F3E46">Nama <span :style="{color: isPdfAvailable(certFonts.nama)?'#166534':'#991b1b', fontSize:'9px'}">{{ isPdfAvailable(certFonts.nama) ? '● PDF' : '⚠ Preview' }}</span></span><select v-model="certFonts.nama" style="font-size:12px;padding:7px 10px;min-height:34px;border-radius:8px;border:1px solid #E0E5E3;background:#fff"><option v-for="o in FONT_OPTIONS.nama" :key="'fn-'+o.value" :value="o.value">{{ o.label }}</option></select></label>
        <label class="fontpick" style="display:flex;flex-direction:column;gap:2px"><span class="lbl mono" style="font-size:10px;font-weight:700;letter-spacing:.06em;color:#2F3E46">Label <span :style="{color: isPdfAvailable(certFonts.label)?'#166534':'#991b1b', fontSize:'9px'}">{{ isPdfAvailable(certFonts.label) ? '● PDF' : '⚠ Preview' }}</span></span><select v-model="certFonts.label" style="font-size:12px;padding:7px 10px;min-height:34px;border-radius:8px;border:1px solid #E0E5E3;background:#fff"><option v-for="o in FONT_OPTIONS.label" :key="'fl-'+o.value" :value="o.value">{{ o.label }}</option></select></label>
        <label class="fontpick" style="display:flex;flex-direction:column;gap:2px"><span class="lbl mono" style="font-size:10px;font-weight:700;letter-spacing:.06em;color:#2F3E46">Deskripsi <span :style="{color: isPdfAvailable(certFonts.deskripsi)?'#166534':'#991b1b', fontSize:'9px'}">{{ isPdfAvailable(certFonts.deskripsi) ? '● PDF' : '⚠ Preview' }}</span></span><select v-model="certFonts.deskripsi" style="font-size:12px;padding:7px 10px;min-height:34px;border-radius:8px;border:1px solid #E0E5E3;background:#fff"><option v-for="o in FONT_OPTIONS.deskripsi" :key="'fd-'+o.value" :value="o.value">{{ o.label }}</option></select></label>
        <label class="fontpick" style="display:flex;flex-direction:column;gap:2px"><span class="lbl mono" style="font-size:10px;font-weight:700;letter-spacing:.06em;color:#2F3E46">Nomor <span :style="{color: isPdfAvailable(certFonts.nomor)?'#166534':'#991b1b', fontSize:'9px'}">{{ isPdfAvailable(certFonts.nomor) ? '● PDF' : '⚠ Preview' }}</span></span><select v-model="certFonts.nomor" style="font-size:12px;padding:7px 10px;min-height:34px;border-radius:8px;border:1px solid #E0E5E3;background:#fff"><option v-for="o in FONT_OPTIONS.nomor" :key="'fno-'+o.value" :value="o.value">{{ o.label }}</option></select></label>
        <label class="fontpick" style="display:flex;flex-direction:column;gap:2px"><span class="lbl mono" style="font-size:10px;font-weight:700;letter-spacing:.06em;color:#2F3E46">TTD Nama <span :style="{color: isPdfAvailable(certFonts.ttd_nama)?'#166534':'#991b1b', fontSize:'9px'}">{{ isPdfAvailable(certFonts.ttd_nama) ? '● PDF' : '⚠ Preview' }}</span></span><select v-model="certFonts.ttd_nama" style="font-size:12px;padding:7px 10px;min-height:34px;border-radius:8px;border:1px solid #E0E5E3;background:#fff"><option v-for="o in FONT_OPTIONS.ttd_nama" :key="'ft-'+o.value" :value="o.value">{{ o.label }}</option></select></label>
      </div>
      <!-- FULL-WIDTH: Preview -->
      <div class="md-card md-card-preview">
        <div class="md-preview-head">
          <span class="md-preview-title">Preview Sertifikat · A4 Landscape 297×210</span>
          <span class="md-badge md-badge-mint md-badge-live"><span class="md-dot"></span>Live</span>
        </div>
        <div class="md-preview-body">
          <CertEditor
            ref="editorRef"
            :layout="layout"
            :bg-src="bgSrc"
            :bg-cover-style="bgCoverStyle"
            :display-nama="'Nama Peserta'"
            :display-label="displayLabel"
            :display-deskripsi="displayDeskripsi"
            :display-ttd-img="ttdPreview"
            :kepsek-nama="kepsekNama"
            :kepsek-nip="kepsekNip"
            :display-nomor="'####/SERT-EVT/##/#####'"
            :display-hash="''"
            :today-label="todayLabel"
            :fonts="certFonts"
            :font-options="FONT_OPTIONS"
            @save="saveTemplate"
            @update:layout="onEditorLayoutUpdate"
            @update:fonts="onEditorFontsUpdate"
          />
        </div>
      </div>

      <div class="sesi-grid">
      <!-- LEFT: Template editor -->
      <div class="sesi-editor">
        <div class="md-card">
          <div class="md-card-head">
            <h2>Template &amp; Layout</h2>
            <p>Atur label, deskripsi, dan background sertifikat sebelum cetak massal.</p>
          </div>
          <div class="md-form">
            <label class="md-field">
              <span class="md-label">Label Default</span>
              <input v-model="labelDefault" placeholder="PESERTA" class="md-input" />
            </label>
            <label class="md-field">
              <span class="md-label">Deskripsi Override</span>
              <textarea v-model="deskripsiOverride" rows="2" :placeholder="'atas partisipasi aktif pada {{target_nama}} sesuai kriteria kehadiran'" class="md-input md-textarea"></textarea>
              <span class="md-hint">Gunakan &#123;&#123;target_nama&#125;&#125; untuk nama event dinamis.</span>
            </label>
            <label class="md-field">
              <span class="md-label">Sumber Layout</span>
              <select v-model="layoutSource" class="md-input" @change="switchLayoutSource">
                <option value="global">Global (Default)</option>
                <option value="custom">Custom (Event ini)</option>
              </select>
              <span class="md-hint">Global = layout default dari Settings. Custom = layout khusus event ini.</span>
            </label>
            <label class="md-field">
              <span class="md-label">Cert BG URL <em>(opsional, kosongkan = global)</em></span>
              <div class="md-input-row">
                <input v-model="certBgUrl" placeholder="api/uploads/cert_bg/event_123.png" class="md-input" />
                <span v-if="certBgUrl" class="md-input-icon-ok" title="Custom BG aktif">✓</span>
              </div>
            </label>
          </div>
          <button class="md-btn-primary" @click="saveTemplate">
            Simpan Template
          </button>
          <div v-if="tplMsg" class="md-alert" :class="tplOk ? 'md-alert-ok' : 'md-alert-err'">{{ tplMsg }}</div>
        </div>
      </div>

      <!-- RIGHT: Students sidebar -->
      <aside class="sesi-sidebar">
        <div class="md-sidebar-head">
          <h2>Siswa Eligible</h2>
          <div class="md-pills">
            <span class="md-pill md-pill-total">Total: {{ students.length }}</span>
            <span class="md-pill md-pill-eligible">Eligible: {{ eligibleCount }}</span>
            <span class="md-pill md-pill-hascert">Sudah: {{ hascertCount }}</span>
          </div>
        </div>

        <div class="md-search-wrap">
          <label class="md-search" :class="{ focused: searchFocused }">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="6" cy="6" r="4.2" stroke="#6B7C85" stroke-width="1.3"/><path d="M9.2 9.2L12 12" stroke="#6B7C85" stroke-width="1.3" stroke-linecap="round"/></svg>
            <input v-model="searchQuery" placeholder="Cari nama / kelas..." @focus="searchFocused=true" @blur="searchFocused=false" />
            <button v-if="searchQuery" class="md-search-clear" @click="searchQuery=''" aria-label="Hapus pencarian">×</button>
          </label>
        </div>

        <div class="md-chips">
          <div class="md-segment">
            <button :class="{ active: filterStatus==='all' }" @click="filterStatus='all'">Semua</button>
            <button :class="{ active: filterStatus==='ready' }" @click="filterStatus='ready'">Siap</button>
            <button :class="{ active: filterStatus==='hascert' }" @click="filterStatus='hascert'">Sudah</button>
            <button :class="{ active: filterStatus==='ineligible' }" @click="filterStatus='ineligible'">Tidak</button>
          </div>
        </div>

        <div class="md-actions">
          <button class="md-btn-secondary md-btn-sm" @click="selectAllEligible">Pilih semua</button>
          <button class="md-btn-tertiary md-btn-sm" @click="selectNone">Kosongkan</button>
          <span class="md-actions-count">{{ selectedCount }} dipilih</span>
        </div>

        <div class="student-list">
          <div v-for="s in filteredStudents" :key="s.user_id" class="md-student-row" :class="{ ineligible: !s.eligible, hascert: s.has_cert, selected: selectedIds.has(s.user_id) }">
            <label class="md-student-check">
              <span class="md-checkbox-wrap">
                <input type="checkbox" :checked="selectedIds.has(s.user_id)" :disabled="!s.eligible || s.has_cert" @change="toggleSelect(s.user_id)" />
                <span class="md-checkbox-box"><svg v-if="selectedIds.has(s.user_id)" width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M2.5 5L4.3 6.8L7.5 3.2" stroke="white" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
              </span>
              <div class="md-student-info">
                <div class="md-student-nama">{{ s.nama }}</div>
                <div class="md-student-kelas">Kelas {{ s.kelas || '-' }} <span v-if="hadirLabel(s)" class="md-hadir-badge">{{ hadirLabel(s) }}</span></div>
                <div v-if="s.has_cert" class="md-status md-status-hascert"><span class="md-status-dot hascert"></span>Sudah ada: {{ s.existing_nomor }} <button class="md-del-btn" :disabled="deletingId===s.existing_id" @click.stop="hapusSertifikat(s)" :title="'Hapus sertifikat ' + s.existing_nomor">{{ deletingId===s.existing_id ? '…' : 'Hapus' }}</button></div>
                <div v-else-if="!s.eligible" class="md-status md-status-ineligible"><span class="md-status-dot ineligible"></span>{{ s.reason }}</div>
                <div v-else class="md-status md-status-ready"><span class="md-status-dot ready"></span>Siap dicetak</div>
              </div>
            </label>
          </div>
        </div>

        <div v-if="students.length && !filteredStudents.length" class="md-empty">
          <div class="md-empty-icon">∅</div>
          <div class="md-empty-title">Tidak ada hasil</div>
          <div class="md-empty-desc">Coba ubah kata kunci atau filter status.</div>
        </div>
        <div v-if="!students.length" class="md-empty">
          <div class="md-empty-icon">◯</div>
          <div class="md-empty-title">Belum ada siswa</div>
          <div class="md-empty-desc">Data eligible akan tampil di sini setelah dimuat.</div>
        </div>

        <div class="sesi-footer-sticky">
          <button class="md-btn-primary md-btn-block" :disabled="batchRunning || selectedCount === 0" @click="cetakSemua">
            <span v-if="batchRunning" class="md-spinner" aria-hidden="true"></span>
            {{ batchRunning ? 'Memproses...' : `Cetak Semua (${selectedCount})` }}
          </button>
          <div v-if="batchMsg && !batchResults" class="md-alert md-alert-err" style="margin-top:10px">{{ batchMsg }}</div>
          <div v-if="batchMsg && batchResults" class="md-alert md-alert-ok" style="margin-top:10px">{{ batchMsg }}</div>
        </div>
      </aside>
      </div> <!-- /sesi-grid -->
    </div> <!-- /sesi-body -->

    <!-- Batch results modal -->
    <div v-if="batchResults" class="batch-modal-overlay" @click.self="batchResults = null">
      <div class="batch-modal">
        <div class="batch-modal-head">
          <h2>Hasil Cetak Batch</h2>
          <button class="md-icon-btn" @click="batchResults=null" aria-label="Tutup">×</button>
        </div>
        <div class="batch-stats">
          <span class="md-pill md-pill-eligible">Dibuat: {{ batchResults.created }}</span>
          <span class="md-pill md-pill-hascert">Sudah ada: {{ batchResults.skipped }}</span>
          <span class="md-pill md-pill-err">Gagal: {{ batchResults.failed }}</span>
          <span class="md-pill md-pill-total">Total: {{ batchResults.total }}</span>
        </div>
        <div class="batch-results">
          <div v-for="r in batchResults.results" :key="r.user_id" class="batch-row" :class="{ ok: r.ok, err: !r.ok }">
            <span class="batch-nama">{{ r.nama || ('UID ' + r.user_id) }}</span>
            <span v-if="r.ok && r.exists" class="mono" style="color:#16a34a;font-size:11px">Sudah ada: {{ r.nomor }}</span>
            <span v-else-if="r.ok" class="mono" style="color:#2563eb;font-size:11px">Dibuat: {{ r.nomor }}</span>
            <span v-else class="mono" style="color:#dc2626;font-size:11px">{{ r.error }}</span>
          </div>
        </div>
        <button class="md-btn-primary" style="margin-top:14px;width:100%;justify-content:center" @click="batchResults = null">Tutup</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../lib/api.js'
import CertEditor from '../components/CertEditor.vue'

const route = useRoute()
const router = useRouter()
const tipe = computed(() => route.params.tipe)
const targetId = computed(() => parseInt(route.params.id, 10))

const loading = ref(true)
const targetNama = ref('')
const template = ref(null)
const layout = ref({})
const useCustomLayout = ref(0)
const labelDefault = ref('')
const deskripsiOverride = ref('')
const certBgUrl = ref('')
const tplMsg = ref('')
const tplOk = ref(true)

// eligible students
const students = ref([])
const selectedIds = ref(new Set())
const batchRunning = ref(false)
const batchMsg = ref('')
const batchResults = ref(null)
const deletingId = ref(null)
// Hadir map dari /laporan/rekap?event_id=&session_id=all (rows: sesi_hadir/total_sesi)
const hadirMap = ref({})
function hadirLabel(s) {
  const h = hadirMap.value[s.user_id]
  if (!h) return ''
  return `· Hadir ${h.sesi_hadir}/${h.total_sesi}`
}
async function loadHadir() {
  if (tipe.value !== 'event') return
  const eid = targetId.value
  try {
    const m = {}
    let pg = 1
    for (;;) {
      const j = await api(`/laporan/rekap?event_id=${eid}&session_id=all&limit=100&page=${pg}&from=1970-01-01`)
      if (eid !== targetId.value) return
      const rows = j.data?.event?.rows || []
      for (const r of rows) m[r.id] = { sesi_hadir: r.sesi_hadir ?? 0, total_sesi: r.total_sesi ?? 0 }
      const total = j.data?.event?.total ?? rows.length
      if (rows.length < 100 || Object.keys(m).length >= total || pg >= 20) break
      pg++
    }
    if (eid !== targetId.value) return
    hadirMap.value = m
  } catch { if (eid === targetId.value) hadirMap.value = {} }
}

// tambahan Mindora: search & filter
const searchQuery = ref('')
const filterStatus = ref('all') // all | ready | hascert | ineligible
const searchFocused = ref(false)

// ttd + bg
const ttdPreview = ref('')

// TTD via /api/ttd (bytes + ETag) — /settings.ttd_kepsek deprecated (null).
// Pakai helper api() (BASE + credentials) agar auth sama seperti request /settings.
// 404 (belum dipasang) -> throw oleh api() -> caller fallback '' (img disembunyikan).
async function ttdDataUri(){
  const res=await api('/ttd')
  const blob=await res.blob()
  return await new Promise((ok,bad)=>{ const fr=new FileReader(); fr.onload=()=>ok(String(fr.result||'')); fr.onerror=bad; fr.readAsDataURL(blob) })
}
const kepsekNama = ref('Kepala Sekolah')
const kepsekNip = ref('NIP.')
const bgSrc = ref('/api/uploads/cert_bg/cert_bg.png')
const bgCoverStyle = computed(() => ({
  backgroundImage: `url(${bgSrc.value})`,
  backgroundSize: 'cover',
  backgroundPosition: 'center',
  backgroundRepeat: 'no-repeat',
}))
const todayLabel = computed(() => new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }))
// share font pilihan dari /admin/sertifikat (localStorage key certFonts); default DejaVu agar PDF aman
// MAJOR persist server: fonts_json di certificate_templates — prioritas server(template) > localStorage > default.
// Semua 13 family TTF tersedia untuk SEMUA field (WYSIWYG penuh) — sinkron dengan AdminSertifikat ALL_FONTS.
const ALL_FONTS = [
  { value: 'DejaVu Sans', label: 'DejaVu Sans (default PDF)' },
  { value: 'Great Vibes', label: 'Great Vibes 400 ★ script' },
  { value: 'Allura', label: 'Allura 400 ★ script' },
  { value: 'Alex Brush', label: 'Alex Brush 400 ★ script' },
  { value: 'Cormorant Garamond', label: 'Cormorant Garamond 700' },
  { value: 'Playfair Display', label: 'Playfair Display 700/800' },
  { value: 'Cinzel', label: 'Cinzel 700' },
  { value: 'DM Serif Display', label: 'DM Serif Display 400' },
  { value: 'Inter', label: 'Inter 400/500/700' },
  { value: 'DM Sans', label: 'DM Sans 400/700' },
  { value: 'Jost', label: 'Jost 500/600' },
  { value: 'Source Serif 4', label: 'Source Serif 4 400' },
  { value: 'JetBrains Mono', label: 'JetBrains Mono 400 mono' },
  { value: 'IBM Plex Mono', label: 'IBM Plex Mono 400 mono' },
]
const FONT_OPTIONS = { nama:[...ALL_FONTS], label:[...ALL_FONTS], deskripsi:[...ALL_FONTS], nomor:[...ALL_FONTS], ttd_nama:[...ALL_FONTS] }
const FONT_GF_SLUG = {
  'Cormorant Garamond': 'Cormorant+Garamond:wght@600;700',
  'Playfair Display': 'Playfair+Display:wght@700;800',
  'Cinzel': 'Cinzel:wght@600;700',
  'DM Serif Display': 'DM+Serif+Display:ital,wght@0,400;1,400',
  'Inter': 'Inter:wght@400;500;600;700;800',
  'DM Sans': 'DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700',
  'Jost': 'Jost:wght@500;600',
  'Source Serif 4': 'Source+Serif+4:opsz,wght@8..60,400;8..60,600',
  'JetBrains Mono': 'JetBrains+Mono:wght@400;500',
  'IBM Plex Mono': 'IBM+Plex+Mono:wght@400;500',
  'Great Vibes': 'Great+Vibes',
  'Allura': 'Allura',
  'Alex Brush': 'Alex+Brush',
}
const PDF_AVAILABLE = new Set(['DejaVu Sans','Arial','Georgia','Times New Roman','Cormorant Garamond','Inter','Source Serif 4','JetBrains Mono','Great Vibes','Alex Brush','Playfair Display','Cinzel','DM Serif Display','DM Sans','Jost','IBM Plex Mono','Allura'])
function isPdfAvailable(fam){ return PDF_AVAILABLE.has(String(fam||'')) }
const DEFAULT_CERT_FONTS = { nama: 'Great Vibes', label: 'DejaVu Sans', deskripsi: 'DejaVu Sans', nomor: 'DejaVu Sans', ttd_nama: 'DejaVu Sans' }
const certFonts = ref({ ...DEFAULT_CERT_FONTS })
function applyServerFonts(srv){
  if(!srv || typeof srv !== 'object') return false
  const allow = new Set(['DejaVu Sans','Arial','Georgia','Times New Roman', ...Object.keys(FONT_GF_SLUG)])
  let hit = false
  const next = { ...(certFonts.value || DEFAULT_CERT_FONTS) }
  for(const k of Object.keys(DEFAULT_CERT_FONTS)){
    const v = typeof srv[k] === 'string' ? srv[k].trim() : ''
    if(v && allow.has(v)){ next[k] = v; hit = true }
  }
  if(hit) certFonts.value = next
  return hit
}
function reloadCertFonts(){ try{ const raw = localStorage.getItem('certFonts'); if(raw) certFonts.value = { ...certFonts.value, ...JSON.parse(raw) } }catch{} }
function onCertFontsStorage(e){ if(!e.key || e.key==='certFonts') reloadCertFonts() }
reloadCertFonts(); window.addEventListener('storage', onCertFontsStorage)
onBeforeUnmount(()=>{ window.removeEventListener('storage', onCertFontsStorage) }) // same-tab sync via mount ulang; storage event hanya cross-tab
function onEditorFontsUpdate(nextFonts){
  if(!nextFonts || typeof nextFonts !== 'object') return
  certFonts.value = { ...certFonts.value, ...nextFonts }
  try{ localStorage.setItem('certFonts', JSON.stringify(certFonts.value)) }catch{}
}

const editorRef = ref(null)
const DEFAULT_BG = '/api/uploads/cert_bg/cert_bg.png'

// Dropdown Global vs Custom (event-only, Opsi B): 'global' | 'custom'.
// useCustomLayout dipertahankan ('0'/'1' string) untuk kompatibilitas save.
const layoutSource = ref('global')
const globalSnap = ref({})
const customSnap = ref({})
const globalBg = ref(DEFAULT_BG)

function deepCopy(v) {
  try { return JSON.parse(JSON.stringify(v ?? {})) } catch { return {} }
}
function isNonEmptyLayout(o) {
  return !!o && typeof o === 'object' && Object.keys(o).length > 0
}
function readEditorUnsaved() {
  try {
    const u = editorRef.value?.hasUnsaved
    if (u && typeof u === 'object' && 'value' in u) return !!u.value
    if (typeof editorRef.value?.hasUnsaved === 'function') return !!editorRef.value.hasUnsaved()
    return !!u
  } catch { return false }
}
// Amankan snapshot sumber sebelum switch agar tidak hilang diam-diam.
function salvageActiveSnap() {
  try {
    if (!readEditorUnsaved()) return
    const cur = editorRef.value?.getLayout?.()
    if (cur && typeof cur === 'object') {
      if (layoutSource.value === 'custom') customSnap.value = deepCopy(cur)
      else globalSnap.value = deepCopy(cur)
    }
  } catch { /* ignore */ }
}
function applySourceToView() {
  if (layoutSource.value === 'custom') {
    layout.value = deepCopy(customSnap.value)
    bgSrc.value = certBgUrl.value ? certBgUrl.value : DEFAULT_BG
  } else {
    layout.value = deepCopy(globalSnap.value)
    bgSrc.value = globalBg.value || DEFAULT_BG
  }
  useCustomLayout.value = layoutSource.value === 'custom' ? '1' : '0'
}
function switchLayoutSource() {
  salvageActiveSnap()
  applySourceToView()
}

// Editor emit update:layout -> update snapshot yang sedang aktif saja + layout.
function onEditorLayoutUpdate(snap) {
  if (snap && typeof snap === 'object') {
    layout.value = deepCopy(snap)
    if (layoutSource.value === 'custom') customSnap.value = deepCopy(snap)
    else globalSnap.value = deepCopy(snap)
  }
  useCustomLayout.value = layoutSource.value === 'custom' ? '1' : '0'
}

const displayDeskripsi = computed(() => {
  if (deskripsiOverride.value) return deskripsiOverride.value.replace(/{{target_nama}}/g, targetNama.value).replace(/{{tanggal}}/g, '')
  return `atas partisipasi aktif pada <b>${targetNama.value || 'Event'}</b> sesuai kriteria kehadiran yang ditetapkan sekolah.`
})
const displayLabel = computed(() => labelDefault.value || 'PESERTA')

const selectedCount = computed(() => selectedIds.value.size)
const eligibleCount = computed(() => students.value.filter(s => s.eligible && !s.has_cert).length)
const hascertCount = computed(() => students.value.filter(s => s.has_cert).length)

const filteredStudents = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  let list = students.value
  if (filterStatus.value === 'ready') list = list.filter(s => s.eligible && !s.has_cert)
  else if (filterStatus.value === 'hascert') list = list.filter(s => s.has_cert)
  else if (filterStatus.value === 'ineligible') list = list.filter(s => !s.eligible)
  if (!q) return list
  return list.filter(s => {
    const nama = (s.nama || '').toLowerCase()
    const kelas = (s.kelas || '').toLowerCase()
    const reason = (s.reason || '').toLowerCase()
    const nomor = (s.existing_nomor || '').toLowerCase()
    return nama.includes(q) || kelas.includes(q) || reason.includes(q) || nomor.includes(q)
  })
})

async function loadGlobal() {
  try {
    const j = await api('/settings/cert_layout')
    const parsed = j?.data?.parsed ?? j?.parsed ?? j?.data?.cert_layout_parsed ?? null
    // global fonts server (app_settings.cert_fonts) → sinkron sebelum template (template menang bila ada)
    try{
      let gsrv = j?.data?.fonts_parsed || null
      if(!gsrv && typeof j?.data?.cert_fonts === 'string' && j.data.cert_fonts.trim()){
        try{ gsrv = JSON.parse(j.data.cert_fonts) }catch{}
      }
      if(gsrv) applyServerFonts(gsrv)
    }catch{}
    if (parsed && typeof parsed === 'object') {
      globalSnap.value = deepCopy(parsed)
    } else if (typeof j?.data?.cert_layout === 'string' && j.data.cert_layout.trim()) {
      try { globalSnap.value = deepCopy(JSON.parse(j.data.cert_layout)) } catch { globalSnap.value = {} }
    } else {
      globalSnap.value = {}
    }
    globalBg.value = j?.data?.cert_bg_path || j?.data?.cert_bg_url || j?.cert_bg_path || DEFAULT_BG
  } catch {
    try {
      const k = await api('/settings')
      const p = k?.data?.cert_layout_parsed ?? null
      if (p && typeof p === 'object') globalSnap.value = deepCopy(p)
      else if (typeof k?.data?.cert_layout === 'string' && k.data.cert_layout.trim()) {
        try { globalSnap.value = deepCopy(JSON.parse(k.data.cert_layout)) } catch { globalSnap.value = {} }
      } else globalSnap.value = {}
      globalBg.value = k?.data?.cert_bg_path || k?.data?.cert_bg_url || DEFAULT_BG
    } catch { globalSnap.value = {}; globalBg.value = DEFAULT_BG }
  }
}

async function loadTemplate() {
  loading.value = true
  // event-only: tolak tipe selain event sebelum fetch
  if (tipe.value !== 'event') { tplMsg.value = 'Modul sertifikat hanya mendukung event.'; tplOk.value = false; loading.value = false; return }
  try {
    const j = await api(`/sertifikat/template/${tipe.value}/${targetId.value}`)
    targetNama.value = j.data?.target_nama || ''
    template.value = j.data?.template || null
    const serverCustom = String(j.data?.use_custom_layout ?? 0) === '1' ? '1' : '0'
    useCustomLayout.value = serverCustom
    labelDefault.value = j.data?.label_default || ''
    deskripsiOverride.value = j.data?.deskripsi_override || ''
    certBgUrl.value = j.data?.cert_bg_url || ''
    // fonts: prioritas server (fonts_parsed/fonts_json/template) > localStorage > default
    try{
      let srv = j.data?.fonts_parsed || null
      if(!srv && typeof j.data?.fonts_json === 'string' && j.data.fonts_json.trim()){
        try{ srv = JSON.parse(j.data.fonts_json) }catch{}
      }
      if(!srv){
        const t = j.data?.template || null
        if(t && typeof t.fonts_json === 'string' && t.fonts_json.trim()){
          try{ srv = JSON.parse(t.fonts_json) }catch{}
        }
      }
      if(!applyServerFonts(srv)) reloadCertFonts()
      else { try{ localStorage.setItem('certFonts', JSON.stringify(certFonts.value)) }catch{} }
    }catch{ reloadCertFonts() }
    const parsed = j.data?.layout_parsed || {}
    customSnap.value = deepCopy(parsed && typeof parsed === 'object' ? parsed : {})
    // Keputusan tampilan awal (setelah loadGlobal selesai dipanggil berurutan):
    // custom=1 + layout non-empty -> custom, selain itu -> global.
    if (serverCustom === '1' && isNonEmptyLayout(customSnap.value)) {
      layoutSource.value = 'custom'
    } else {
      layoutSource.value = 'global'
      useCustomLayout.value = '0'
    }
    applySourceToView()
    // bgSrc sinkron ulang untuk custom (cert_bg_url server, fallback DEFAULT_BG),
    // global sudah di-set applySourceToView dari globalBg.
    if (layoutSource.value === 'custom') bgSrc.value = certBgUrl.value ? certBgUrl.value : DEFAULT_BG
  } catch (e) {
    tplMsg.value = 'Gagal load template: ' + (e?.error?.message || e?.message || '')
    tplOk.value = false
  } finally {
    loading.value = false
  }
}

async function loadEligible() {
  // event-only: tidak load untuk tipe selain event
  if (tipe.value !== 'event') return
  try {
    const j = await api(`/sertifikat/eligible?tipe=${encodeURIComponent(tipe.value)}&target_id=${targetId.value}`)
    students.value = j.data || []
    selectedIds.value = new Set(students.value.filter(s => s.eligible && !s.has_cert).map(s => s.user_id))
    await loadHadir()
  } catch (e) {
    tplMsg.value = 'Gagal load siswa: ' + (e?.error?.message || e?.message || '')
    tplOk.value = false
  }
}

async function loadTtd() {
  try {
    const j = await api('/settings')
    if(j.data?.has_ttd){ try{ ttdPreview.value = await ttdDataUri() }catch{ ttdPreview.value = '' } }
    else ttdPreview.value = ''
    kepsekNama.value = j.data?.kepsek_nama || kepsekNama.value
    kepsekNip.value = j.data?.kepsek_nip || kepsekNip.value
  } catch { /* ignore */ }
}

async function saveTemplate() {
  tplMsg.value = ''
  tplOk.value = true
  try {
    // Save konsisten dari dropdown (bukan checkbox lama).
    const isCustom = layoutSource.value === 'custom'
    useCustomLayout.value = isCustom ? '1' : '0'
    const payload = {
      label_default: labelDefault.value,
      deskripsi_override: deskripsiOverride.value,
      use_custom_layout: isCustom ? 1 : 0,
      // MAJOR persist server: kirim fonts_json aktif agar PDF konsisten cross-browser
      fonts_json: JSON.stringify(certFonts.value || DEFAULT_CERT_FONTS),
      // Global: kirim null agar backend preserve custom lama di DB (tidak wipe).
      // Custom: selalu kirim layout nyata dari editor.
      layout_json: isCustom
        ? JSON.stringify(editorRef.value?.getLayout?.() || layout.value)
        : null,
      cert_bg_url: certBgUrl.value,
    }
    const j = await api(`/sertifikat/template/${tipe.value}/${targetId.value}`, {
      method: 'POST',
      body: payload,
    })
    template.value = j.data?.template || template.value
    // Post-save: customSnap = deep copy getLayout() bila custom; sync dropdown + bg; markSaved.
    const savedLayout = deepCopy(editorRef.value?.getLayout?.() || layout.value)
    if (isCustom) {
      customSnap.value = deepCopy(savedLayout)
      layout.value = deepCopy(savedLayout)
    }
    editorRef.value?.markSaved?.()
    if (j.data?.use_custom_layout != null) {
      const srv = String(j.data.use_custom_layout) === '1' ? '1' : '0'
      useCustomLayout.value = srv
      layoutSource.value = srv === '1' ? 'custom' : 'global'
      if (srv === '1' && isNonEmptyLayout(savedLayout)) customSnap.value = deepCopy(savedLayout)
      applySourceToView()
      if (layoutSource.value === 'custom') bgSrc.value = certBgUrl.value ? certBgUrl.value : DEFAULT_BG
    }
    if (j.data?.cert_bg_url != null) {
      certBgUrl.value = j.data.cert_bg_url || ''
      if (layoutSource.value === 'custom') bgSrc.value = j.data.cert_bg_url || DEFAULT_BG
    }
    tplMsg.value = j.data?.warning ? ('Template tersimpan ✓ (' + j.data.warning + ')') : 'Template tersimpan ✓'
    tplOk.value = true
    setTimeout(() => { tplMsg.value = '' }, 2500)
  } catch (err) {
    tplMsg.value = 'Gagal simpan: ' + (err?.error?.message || err?.message || '')
    tplOk.value = false
  }
}

async function cetakSemua() {
  batchRunning.value = true
  batchMsg.value = ''
  batchResults.value = null
  const ids = Array.from(selectedIds.value)
  if (ids.length === 0) {
    batchMsg.value = 'Pilih minimal 1 siswa'
    batchRunning.value = false
    return
  }
  try {
    const j = await api('/sertifikat/generate-batch', {
      method: 'POST',
      body: { tipe: tipe.value, target_id: targetId.value, user_ids: ids, label: labelDefault.value || 'PESERTA' },
    })
    // pseudo-queue: 202 {job_id} -> poll /jobs/:id 2 detik
    if(j?.queued && j?.data?.job_id){
      const jobId=j.data.job_id
      batchMsg.value=`Diproses di background (job #${jobId})...`
      for(let i=0;i<30;i++){
        await new Promise(r=>setTimeout(r,2000))
        let st=null
        try{ st=await api('/jobs/'+jobId) }catch(e){ break }
        const s=st?.data?.status
        if(s==='done'){
          const res=st?.data?.meta||st?.data
          batchMsg.value=`Selesai via worker: job #${jobId} done`
          try{ batchResults.value=res?.results||res }catch(e){}
          await loadEligible()
          return
        }
        if(s==='failed'){ batchMsg.value='Job gagal: '+(st?.data?.error||'worker error'); return }
      }
      batchMsg.value=`Job #${jobId} masih jalan — refresh manual`
      return
    }
    batchResults.value = j.data
    batchMsg.value = `Selesai: ${j.data.created} dibuat, ${j.data.skipped} sudah ada, ${j.data.failed} gagal`
    await loadEligible()
  } catch (err) {
    batchMsg.value = 'Gagal: ' + (err?.error?.message || err?.message || '')
  } finally {
    batchRunning.value = false
  }
}

async function hapusSertifikat(s) {
  if (!s.existing_id) return
  if (!confirm(`Hapus sertifikat ${s.existing_nomor} (${s.nama})? Siswa kembali ke status Siap.`)) return
  deletingId.value = s.existing_id
  try {
    await api(`/sertifikat/${s.existing_id}`, { method: 'DELETE', body: {} })
    await loadEligible()
  } catch (err) {
    batchMsg.value = ''
    tplMsg.value = 'Gagal hapus: ' + (err?.error?.message || err?.message || '')
    tplOk.value = false
  } finally {
    deletingId.value = null
  }
}

function toggleSelect(id) {
  const s = new Set(selectedIds.value)
  if (s.has(id)) s.delete(id); else s.add(id)
  selectedIds.value = s
}
function selectAllEligible() {
  selectedIds.value = new Set(students.value.filter(s => s.eligible && !s.has_cert).map(s => s.user_id))
}
function selectNone() { selectedIds.value = new Set() }

function resetSnaps() {
  globalSnap.value = {}
  customSnap.value = {}
  globalBg.value = DEFAULT_BG
  layoutSource.value = 'global'
}

onMounted(async () => {
  loading.value = true
  await loadGlobal()
  await loadTemplate()
  await loadEligible()
  await loadTtd()
})

watch(() => [route.params.tipe, route.params.id], () => { resetSnaps(); loadGlobal().then(() => loadTemplate()); loadEligible(); })
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500&family=Cormorant:ital,wght@1,400&display=swap');

/* Page */
.sesi-page{
  max-width:1360px;
  margin:0 auto;
  padding:24px;
  display:flex;
  flex-direction:column;
  gap:24px;
  background:var(--bg,#F1F5F4);
  min-height:100%;
  font-family:'DM Sans',system-ui,sans-serif;
  color:var(--ink,#2F3E46);
}

/* Header card */
.sesi-head-card{
  background:var(--surface,#fff);
  border:1px solid var(--line,#E0E5E3);
  border-radius:16px;
  box-shadow:0 4px 20px rgba(0,0,0,.06);
  padding:18px 20px 16px;
  display:flex;
  flex-direction:column;
  gap:10px;
}
.md-back-btn{
  align-self:flex-start;
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-family:'Inter',system-ui,sans-serif;
  font-size:13px;
  font-weight:500;
  color:var(--m-cta,#4A7875);
  background:transparent;
  border:0;
  border-radius:999px;
  padding:6px 10px 6px 6px;
  cursor:pointer;
  transition:background .15s;
}
.md-back-btn:hover{ background:rgba(74,120,117,.08); }
.md-back-btn:active{ transform:scale(.98); }
.sesi-title-row{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.sesi-title-row h1{
  font-family:'Inter',system-ui,sans-serif;
  font-size:24px;
  font-weight:500;
  letter-spacing:-.02em;
  color:var(--ink,#2F3E46);
  margin:0;
  line-height:1.2;
}
.sesi-sub{
  font-family:'DM Sans',system-ui,sans-serif;
  font-size:12px;
  color:var(--muted,#6B7C85);
  letter-spacing:.01em;
}

/* Badges */
.md-badge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  border-radius:999px;
  padding:4px 10px;
  font-family:'Inter',system-ui,sans-serif;
  font-size:11px;
  font-weight:600;
  line-height:1;
  border:1px solid transparent;
  white-space:nowrap;
}
.md-badge-blue{ background:var(--m-blue,#A7C7E7); color:#2F3E46; border-color:rgba(167,199,231,.6); }
.md-badge-mint{ background:var(--m-green,#5EB87E); color:#fff; border-color:rgba(94,184,126,.3); }
.md-badge-live{ background:rgba(94,184,126,.14); color:#166534; border-color:rgba(94,184,126,.22); }
.md-dot{ width:6px; height:6px; border-radius:999px; background:#16a34a; display:inline-block; animation:mdPulse 1.6s infinite; }
@keyframes mdPulse{ 0%,100%{opacity:1} 50%{opacity:.45} }

/* Pills */
.md-pills{ display:flex; flex-wrap:wrap; gap:6px; }
.md-pill{
  display:inline-flex;
  align-items:center;
  border-radius:999px;
  padding:4px 8px;
  font-family:'Inter',system-ui,sans-serif;
  font-size:11px;
  font-weight:600;
  line-height:1;
  border:1px solid var(--line,#E0E5E3);
}
.md-pill-total{ background:#F1F5F4; color:#2F3E46; }
.md-pill-eligible{ background:rgba(94,184,126,.14); color:#166534; border-color:rgba(94,184,126,.2); }
.md-pill-hascert{ background:rgba(167,199,231,.2); color:#1e40af; border-color:rgba(167,199,231,.3); }
.md-pill-err{ background:#fef2f2; color:#991b1b; border-color:#fecaca; }

/* Body: preview full-width di atas, grid form+sidebar di bawah */
.sesi-body{
  display:flex;
  flex-direction:column;
  gap:24px;
  min-width:0;
}

/* Grid */
.sesi-grid{
  display:grid;
  grid-template-columns:1fr 380px;
  gap:24px;
  align-items:start;
}
@media(max-width:1100px){
  .sesi-grid{ grid-template-columns:1fr; }
}

/* Left column */
.sesi-editor{
  display:flex;
  flex-direction:column;
  gap:20px;
  min-width:0;
}

/* Cards */
.md-card{
  background:var(--surface,#fff);
  border:1px solid var(--line,#E0E5E3);
  border-radius:16px;
  box-shadow:0 4px 20px rgba(0,0,0,.06);
  padding:20px 24px;
}
.md-card-head h2{
  font-family:'Inter',system-ui,sans-serif;
  font-size:16px;
  font-weight:600;
  color:var(--ink,#2F3E46);
  margin:0 0 4px;
  letter-spacing:-.01em;
}
.md-card-head p{
  font-size:13px;
  color:var(--muted,#6B7C85);
  margin:0 0 16px;
  line-height:1.5;
}
.md-form{ display:flex; flex-direction:column; gap:14px; margin-bottom:16px; }
.md-field{ display:flex; flex-direction:column; gap:6px; }
.md-label{
  font-family:'Inter',system-ui,sans-serif;
  font-size:10px;
  font-weight:700;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:var(--muted,#6B7C85);
}
.md-label em{ font-style:normal; font-weight:500; letter-spacing:0; text-transform:none; color:#9aa8ad; }
.md-input{
  width:100%;
  border:1px solid var(--line,#E0E5E3);
  border-radius:12px;
  padding:10px 12px;
  font-family:'DM Sans',system-ui,sans-serif;
  font-size:14px;
  color:var(--ink,#2F3E46);
  background:#fff;
  outline:0;
  transition:border-color .15s, box-shadow .15s, background .15s;
}
.md-input::placeholder{ color:#B0B8B5; }
.md-input:focus{ border-color:var(--m-cta,#4A7875); box-shadow:0 0 0 3px rgba(74,120,117,.12); }
.md-textarea{ resize:vertical; min-height:64px; line-height:1.5; }
.md-hint{ font-size:11px; color:#9aa8ad; }
.md-input-row{ position:relative; display:flex; align-items:center; }
.md-input-icon-ok{
  position:absolute; right:10px;
  width:22px; height:22px; border-radius:999px;
  background:rgba(94,184,126,.14); color:#16a34a;
  display:grid; place-items:center; font-size:11px; font-weight:700;
  pointer-events:none;
}

/* Checkbox Mindora */
.md-check-row{
  display:flex; align-items:center; gap:10px;
  cursor:pointer; user-select:none;
  padding:2px 0;
}
.md-checkbox-wrap{
  position:relative; width:18px; height:18px; flex:none; display:inline-block;
}
.md-checkbox-wrap input{
  position:absolute; inset:0; opacity:0; cursor:pointer; margin:0; z-index:1;
}
.md-checkbox-box{
  position:absolute; inset:0;
  border:1.5px solid var(--line,#E0E5E3);
  border-radius:6px;
  background:#fff;
  display:grid; place-items:center;
  transition:background .15s, border-color .15s;
}
.md-checkbox-wrap input:checked + .md-checkbox-box{
  background:var(--m-green,#5EB87E);
  border-color:var(--m-green,#5EB87E);
}
.md-checkbox-wrap input:disabled + .md-checkbox-box{ opacity:.45; cursor:not-allowed; }
.md-checkbox-wrap input:focus-visible + .md-checkbox-box{ box-shadow:0 0 0 3px rgba(94,184,126,.2); }
.md-check-label{ font-size:13px; color:var(--ink,#2F3E46); }

/* Buttons */
.md-btn-primary{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  background:var(--m-cta,#4A7875); color:#fff;
  border:1px solid var(--m-cta,#4A7875);
  border-radius:12px;
  padding:10px 16px;
  font-family:'Inter',system-ui,sans-serif;
  font-size:13px; font-weight:600;
  cursor:pointer;
  box-shadow:0 4px 20px rgba(0,0,0,.06);
  transition:background .15s, transform .08s, opacity .15s;
}
.md-btn-primary:hover{ background:var(--m-cta-h,#5A908C); border-color:var(--m-cta-h,#5A908C); }
.md-btn-primary:active{ transform:scale(.98); }
.md-btn-primary:disabled{ background:#F5F7F6; border-color:#E0E5E3; color:#B0B8B5; cursor:not-allowed; box-shadow:none; transform:none; }
.md-btn-secondary{
  display:inline-flex; align-items:center; justify-content:center; gap:6px;
  background:#fff; color:var(--ink,#2F3E46);
  border:1px solid var(--line,#E0E5E3);
  border-radius:12px;
  padding:8px 12px;
  font-family:'Inter',system-ui,sans-serif;
  font-size:12px; font-weight:600;
  cursor:pointer; transition:background .15s;
}
.md-btn-secondary:hover{ background:#F1F5F4; }
.md-btn-tertiary{
  display:inline-flex; align-items:center; justify-content:center;
  background:transparent; color:var(--muted,#6B7C85);
  border:1px solid transparent;
  border-radius:999px;
  padding:8px 12px;
  font-family:'Inter',system-ui,sans-serif;
  font-size:12px; font-weight:600;
  cursor:pointer; transition:background .15s, color .15s;
}
.md-btn-tertiary:hover{ background:rgba(74,120,117,.08); color:var(--m-cta,#4A7875); }
.md-btn-sm{ padding:7px 10px; font-size:11px; border-radius:999px; }
.md-btn-block{ width:100%; height:44px; border-radius:12px; }

/* Alerts */
.md-alert{
  border-radius:12px;
  padding:10px 12px;
  font-family:'DM Sans',system-ui,sans-serif;
  font-size:12px;
  line-height:1.5;
  border:1px solid;
}
.md-alert-ok{ background:#f0fdf4; border-color:#a7f3d0; color:#166534; }
.md-alert-err{ background:#fef2f2; border-color:#fecaca; color:#991b1b; }

/* Preview card — full-width, padding tunggal 16px, body tanpa jepitan */
.md-card-preview{ padding:16px; overflow:visible; }
.md-preview-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  padding:2px 2px 12px;
  border-bottom:1px solid var(--line,#E0E5E3);
  margin-bottom:12px;
}
.md-preview-title{
  font-family:'Inter',system-ui,sans-serif;
  font-size:12px; font-weight:600; color:var(--muted,#6B7C85);
  letter-spacing:.02em;
}
.md-preview-body{
  border:0;
  background:transparent;
  min-width:0;
}

/* Sidebar */
.sesi-sidebar{
  background:var(--surface,#fff);
  border:1px solid var(--line,#E0E5E3);
  border-radius:20px;
  box-shadow:0 4px 20px rgba(0,0,0,.06);
  overflow:hidden;
  display:flex;
  flex-direction:column;
  position:sticky;
  top:16px;
  max-height:calc(100vh - 32px);
}
@media(max-width:1100px){
  .sesi-sidebar{ position:relative; top:auto; max-height:none; }
}
.md-sidebar-head{
  padding:16px 16px 12px;
  border-bottom:1px solid var(--line,#E0E5E3);
  display:flex; flex-direction:column; gap:8px;
  background:#fff;
}
.md-sidebar-head h2{
  font-family:'Inter',system-ui,sans-serif;
  font-size:16px; font-weight:600; color:var(--ink,#2F3E46);
  margin:0; letter-spacing:-.01em;
}
.md-search-wrap{ padding:12px 16px 0; }
.md-search{
  display:flex; align-items:center; gap:8px;
  background:#F1F5F4;
  border:1px solid var(--line,#E0E5E3);
  border-radius:999px;
  padding:8px 12px;
  transition:background .15s, border-color .15s, box-shadow .15s;
}
.md-search:focus-within{ background:#fff; border-color:var(--m-cta,#4A7875); box-shadow:0 0 0 3px rgba(74,120,117,.1); }
.md-search input{
  flex:1; border:0; background:transparent; outline:0;
  font-family:'DM Sans',system-ui,sans-serif; font-size:13px; color:var(--ink,#2F3E46);
  min-width:0;
}
.md-search input::placeholder{ color:#B0B8B5; }
.md-search-clear{
  width:20px; height:20px; border-radius:999px; border:0;
  background:var(--line,#E0E5E3); color:var(--muted,#6B7C85);
  display:grid; place-items:center; cursor:pointer; font-size:12px; line-height:1;
}
.md-chips{ padding:10px 16px 0; }
.md-segment{
  display:flex; gap:2px;
  background:#F1F5F4;
  border-radius:999px;
  padding:3px;
}
.md-segment button{
  flex:1;
  border:0; border-radius:999px;
  padding:6px 8px;
  font-family:'Inter',system-ui,sans-serif;
  font-size:11px; font-weight:600;
  color:var(--muted,#6B7C85);
  background:transparent;
  cursor:pointer;
  transition:background .15s, color .15s, box-shadow .15s;
}
.md-segment button.active{
  background:#fff; color:var(--ink,#2F3E46);
  box-shadow:0 4px 20px rgba(0,0,0,.06);
}
.md-actions{
  display:flex; align-items:center; gap:8px;
  padding:10px 16px 12px;
  border-bottom:1px solid var(--line,#E0E5E3);
}
.md-actions-count{
  margin-left:auto;
  font-size:11px; font-weight:600; color:var(--muted,#6B7C85);
}
.student-list{
  display:flex; flex-direction:column; gap:8px;
  overflow-y:auto; flex:1; min-height:0;
  padding:12px;
}
.md-student-row{
  background:#fff;
  border:1px solid var(--line,#E0E5E3);
  border-radius:12px;
  padding:10px 12px;
  transition:border-color .15s, background .15s, box-shadow .15s;
}
.md-student-row:hover{ border-color:var(--m-blue,#A7C7E7); background:#f8fafc; }
.md-student-row.selected{ background:rgba(94,184,126,.08); border-color:var(--m-green,#5EB87E); box-shadow:0 2px 8px rgba(94,184,126,.12); }
.md-student-row.ineligible{ background:#F5F7F6; border-style:dashed; border-color:var(--line,#E0E5E3); opacity:.95; }
.md-student-row.hascert{ background:#f8fafc; border-style:dashed; border-color:#cbd5e1; }
.md-student-check{ display:flex; gap:10px; align-items:flex-start; cursor:pointer; }
.md-student-info{ display:flex; flex-direction:column; gap:2px; min-width:0; flex:1; }
.md-student-nama{
  font-family:'Inter',system-ui,sans-serif;
  font-size:13px; font-weight:600; color:var(--ink,#2F3E46);
  word-break:break-word; line-height:1.3;
}
.md-student-kelas{ font-size:11px; color:var(--muted,#6B7C85); }
.md-status{
  display:inline-flex; align-items:center; gap:6px;
  font-size:11px; line-height:1.4; word-break:break-word;
}
.md-status-ready{ color:#2563eb; }
.md-status-hascert{ color:#16a34a; }
.md-status-ineligible{ color:#dc2626; }
.md-status-dot{ width:6px; height:6px; border-radius:999px; display:inline-block; flex:none; }
.md-status-dot.ready{ background:#2563eb; }
.md-status-dot.hascert{ background:#16a34a; }
.md-status-dot.ineligible{ background:#dc2626; }
.md-hadir-badge{
  display:inline-block; margin-left:4px; padding:1px 7px; border-radius:999px;
  background:rgba(94,184,126,.14); color:#166534; border:1px solid rgba(94,184,126,.25);
  font-size:10px; font-weight:700; white-space:nowrap;
}
.md-del-btn{
  margin-left:8px; padding:2px 9px; border-radius:999px; cursor:pointer;
  background:#fff; color:#991b1b; border:1px solid #fecaca;
  font-size:10px; font-weight:700;
}
.md-del-btn:hover{ background:#fef2f2; }
.md-del-btn:disabled{ opacity:.5; cursor:wait; }

.md-empty{
  padding:24px 16px;
  text-align:center;
  display:flex; flex-direction:column; align-items:center; gap:6px;
  color:var(--muted,#6B7C85);
}
.md-empty-icon{
  width:36px; height:36px; border-radius:999px;
  background:#F1F5F4; border:1px solid var(--line,#E0E5E3);
  display:grid; place-items:center; font-size:16px; color:var(--muted,#6B7C85);
}
.md-empty-title{ font-family:'Inter',system-ui,sans-serif; font-size:13px; font-weight:600; color:var(--ink,#2F3E46); }
.md-empty-desc{ font-size:11px; color:var(--muted,#6B7C85); max-width:220px; line-height:1.5; }

.sesi-footer-sticky{
  position:sticky; bottom:0;
  background:rgba(255,255,255,.92);
  backdrop-filter:blur(12px);
  border-top:1px solid var(--line,#E0E5E3);
  padding:12px 16px;
  margin-top:auto;
}

/* Modal */
.batch-modal-overlay{
  position:fixed; inset:0;
  background:rgba(47,62,70,.32);
  backdrop-filter:blur(12px);
  display:grid; place-items:center;
  z-index:80; padding:16px;
}
.batch-modal{
  background:#fff;
  border:1px solid var(--line,#E0E5E3);
  border-radius:24px;
  padding:20px;
  max-width:560px; width:100%; max-height:86vh;
  display:flex; flex-direction:column;
  box-shadow:0 16px 40px rgba(0,0,0,.12);
  overflow:hidden;
}
.batch-modal-head{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:8px; }
.batch-modal-head h2{
  font-family:'Inter',system-ui,sans-serif;
  font-size:18px; font-weight:600; color:var(--ink,#2F3E46);
  margin:0; letter-spacing:-.01em;
}
.md-icon-btn{
  width:32px; height:32px; border-radius:999px;
  border:1px solid var(--line,#E0E5E3); background:#fff;
  display:grid; place-items:center; cursor:pointer; font-size:18px; color:var(--muted,#6B7C85);
}
.batch-stats{ display:flex; flex-wrap:wrap; gap:6px; margin:6px 0 10px; }
.batch-results{ display:flex; flex-direction:column; gap:6px; overflow-y:auto; min-height:0; padding-right:2px; }
.batch-row{
  display:flex; align-items:center; justify-content:space-between; gap:8px;
  padding:8px 10px; border-radius:12px;
  background:#f9fafb; border:1px solid var(--line,#E0E5E3);
}
.batch-row.ok{ border-color:#a7f3d0; background:#f0fdf4; }
.batch-row.err{ border-color:#fecaca; background:#fef2f2; }
.batch-nama{ font-weight:700; font-size:12px; word-break:break-word; color:var(--ink,#2F3E46); }
.mono{ font-family:'DM Sans',system-ui,sans-serif; }

/* Loading skeleton */
.md-loading-wrap{ display:flex; flex-direction:column; gap:12px; }
.md-skeleton{ background:linear-gradient(90deg,#eef2f0 25%,#f8fafc 37%,#eef2f0 63%); background-size:400% 100%; animation:mdShimmer 1.2s infinite; border-radius:12px; }
@keyframes mdShimmer{ 0%{background-position:100% 0} 100%{background-position:0 0} }
.md-skeleton-title{ height:24px; width:42%; }
.md-skeleton-line{ height:14px; width:28%; }
.md-skeleton-grid{ display:grid; grid-template-columns:1fr 380px; gap:24px; }
@media(max-width:1100px){ .md-skeleton-grid{ grid-template-columns:1fr; } }
.md-skeleton-card{ height:280px; border-radius:16px; }
.md-skeleton-card.sm{ height:420px; border-radius:20px; }

/* Spinner */
.md-spinner{
  width:14px; height:14px; border-radius:999px;
  border:2px solid rgba(255,255,255,.4); border-top-color:#fff;
  display:inline-block; animation:mdSpin .7s linear infinite; flex:none;
}
@keyframes mdSpin{ to{ transform:rotate(360deg); } }
</style>
