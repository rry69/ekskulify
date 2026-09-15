<template>
  <!-- Reusable cert layout editor: drag+resize+sidebar. Emits 'save'/'reset'/'update:layout'/'select'. -->
  <div class="cert-live-preview">
    <div class="md-toolbar">
      <div class="md-toolbar-label">LIVE PREVIEW — identik PDF 297×210 mm (coords % dari layout)</div>
      <div class="md-toolbar-actions">
        <button :class="editMode ? 'md-btn-primary' : 'md-btn-secondary'" @click="toggleEditMode" :title="editMode?'Keluar mode edit':'Masuk mode edit interaktif'">
          <span v-if="editMode" class="md-dot"></span>{{ editMode ? 'Mode Edit: ON' : '✎ Edit Layout' }}
        </button>
        <button v-if="editMode" class="md-btn-tertiary" @click="onResetAll">Reset semua</button>
        <button v-if="editMode" class="md-btn-primary" :disabled="!hasUnsaved" @click="onSave">{{ hasUnsaved ? 'Simpan Layout' : 'Tersimpan' }}</button>
      </div>
    </div>
    <div v-if="editMode" class="md-hint-bar">
      <span class="md-hint-icon">i</span>
      <span>Drag teks untuk geser · tarik handle sudut untuk memperbesar · klik field untuk pilih · atur angka presisi di panel kanan · <b>Simpan Layout</b> untuk pakai di PDF selanjutnya.</span>
    </div>
    <div class="cert-editor-grid" :class="{'is-edit':editMode}">
      <div ref="certStageRef" class="preview-cert" :style="bgCoverStyleProp" :class="{'edit-stage':editMode}" @click="editMode && !dragState && (selectedField=null)">
        <div v-for="k in FIELD_KEYS" :key="'pc-'+k" class="pc-field" :class="['pc-'+k, {selected: selectedField===k && editMode, locked: lockedFields.has(k), dragging: dragState?.key===k}]" :style="fieldStyle(k)" @pointerdown="onFieldPointerDown($event, k)" @click.stop="onFieldClick(k)">
          <template v-if="k==='nama'">{{ displayNama }}</template>
          <template v-else-if="k==='label'">{{ displayLabel }}</template>
          <template v-else-if="k==='deskripsi'">
            <div v-if="displayDeskripsi" v-html="safeDeskripsi"></div>
            <template v-else>atas partisipasi aktif pada <b>{{ targetNamaFallback }}</b> sesuai kriteria kehadiran yang ditetapkan sekolah.</template>
          </template>
          <template v-else-if="k==='ttd'">
            <div class="pc-ttd-date">Jakarta, {{ todayLabel }}</div>
            <div class="pc-ttd-img"><img v-if="displayTtdImg" :src="displayTtdImg" alt="TTD" /><div v-else style="height:18mm;opacity:.2">--</div></div>
            <div class="pc-ttd-name" :style="{ fontFamily: fontCss((props.fonts||{}).ttd_nama) }">{{ kepsekNama }}</div>
            <div class="pc-ttd-nip">NIP. {{ kepsekNip }}</div>
          </template>
          <template v-else-if="k==='qr'">
            <div class="pc-qr-box">QR</div>
            <div class="pc-qr-url">/verify/{{ displayHash ? displayHash.slice(0,12)+'...' : '&lt;hash&gt;' }}</div>
          </template>
          <template v-else-if="k==='nomor'">Nomor: {{ displayNomor }} · Hash ... · {{ todayLabel }}</template>
          <template v-if="editMode && selectedField===k">
            <span class="rz-handle nw" @pointerdown="onResizePointerDown($event,k,'nw')"></span>
            <span class="rz-handle ne" @pointerdown="onResizePointerDown($event,k,'ne')"></span>
            <span class="rz-handle sw" @pointerdown="onResizePointerDown($event,k,'sw')"></span>
            <span class="rz-handle se" @pointerdown="onResizePointerDown($event,k,'se')"></span>
            <span class="rz-handle n"  @pointerdown="onResizePointerDown($event,k,'n')"></span>
            <span class="rz-handle s"  @pointerdown="onResizePointerDown($event,k,'s')"></span>
            <span class="rz-handle e"  @pointerdown="onResizePointerDown($event,k,'e')"></span>
            <span class="rz-handle w"  @pointerdown="onResizePointerDown($event,k,'w')"></span>
            <span class="rz-badge">{{ FIELD_LABELS[k] }}</span>
          </template>
        </div>
      </div>
      <div v-if="editMode" class="cert-props">
        <div class="md-props-title">PROPERTI FIELD</div>
        <div v-if="!selectedField" class="md-props-empty">Klik salah satu field di canvas untuk edit posisinya.</div>
        <template v-else>
          <div class="md-props-field-head">
            <div class="md-props-field-name">{{ FIELD_LABELS[selectedField] }} <span class="md-props-field-key">({{ selectedField }})</span></div>
            <div class="md-props-field-actions">
              <button class="md-icon-btn" @click="toggleLock(selectedField)" :title="lockedFields.has(selectedField)?'Buka kunci':'Kunci'">{{ lockedFields.has(selectedField)?'🔒':'🔓' }}</button>
              <button class="md-btn-tertiary md-btn-xs" @click="resetField(selectedField)">Reset</button>
            </div>
          </div>
          <div class="props-grid">
            <label class="prop"><span>X ({{ ['ttd','qr'].includes(selectedField) ? 'left %' : 'center %' }})</span><input type="number" step="0.5" :value="cssPct(selectedField,'x')" @change="onSidebarPctChange(selectedField,'x',$event.target.value)" class="md-input" /></label>
            <label class="prop"><span>Y (top %)</span><input type="number" step="0.5" :value="cssPct(selectedField,'y')" @change="onSidebarPctChange(selectedField,'y',$event.target.value)" class="md-input" /></label>
            <label class="prop"><span>W (%)</span><input type="number" step="0.5" :value="cssPct(selectedField,'w')" @change="onSidebarPctChange(selectedField,'w',$event.target.value)" class="md-input" /></label>
            <label v-if="certLayout[selectedField]?.h!=null || selectedField==='qr'" class="prop"><span>H (%)</span><input type="number" step="0.5" :value="cssPct(selectedField,'h')" @change="onSidebarPctChange(selectedField,'h',$event.target.value)" class="md-input" /></label>
            <label v-if="certLayout[selectedField]?.font_pt!=null" class="prop"><span>Font (pt)</span><input type="number" step="1" min="4" max="80" :value="certLayout[selectedField].font_pt" @change="onSidebarFontChange(selectedField,$event.target.value)" class="md-input" /></label>
          </div>
          <label v-if="optionsForSelectedField().length" class="prop" style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
            <span>Jenis Font</span>
            <select class="md-input" :value="currentFontForField(selectedField)" @change="onFontChangeForSelected">
              <option v-for="o in optionsForSelectedField()" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <span class="md-hint" style="font-size:10px;color:#6B7C85">Berlaku untuk semua teks {{ FIELD_LABELS[selectedField] }} — sinkron ke PDF.</span>
          </label>
          <div class="md-props-note">X = center-X untuk nama/label/deskripsi/nomor; left-edge untuk TTD &amp; QR. Simpan untuk sinkron ke PDF (mm).</div>
          <button class="md-btn-primary md-btn-block" style="margin-top:10px" @click="onSave">Simpan Layout</button>
        </template>
        <div class="md-props-footer">
          <div class="md-props-footer-label">SEMUA FIELD</div>
          <div class="md-chip-group">
            <button v-for="k in FIELD_KEYS" :key="'chip-'+k" :class="['md-chip', selectedField===k ? 'md-chip-active' : '']" @click="selectField(k)">{{ FIELD_LABELS[k] }}</button>
          </div>
          <div class="md-props-footer-actions">
            <button class="md-btn-tertiary md-btn-sm" @click="onResetAll">Reset semua ke default</button>
            <button class="md-btn-primary md-btn-sm" :disabled="!hasUnsaved" @click="onSave">Simpan</button>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { sanitizeHtml } from '../lib/sanitizeHtml.js'

const props = defineProps({
  layout: { type: Object, required: true },
  bgSrc: { type: String, default: '' },
  bgCoverStyle: { type: Object, default: () => ({}) },
  displayNama: { type: String, default: 'Nama Peserta' },

  displayLabel: { type: String, default: 'PESERTA' },
  displayDeskripsi: { type: String, default: '' },
  displayTtdImg: { type: String, default: '' },
  kepsekNama: { type: String, default: 'Kepala Sekolah' },
  kepsekNip: { type: String, default: 'NIP.' },
  displayNomor: { type: String, default: '####/SERT-EVT/##/#####' },
  displayHash: { type: String, default: '' },
  todayLabel: { type: String, default: '' },
  // selectable fonts (shared via props dari Admin/Sesi; default DejaVu agar PDF aman)
  fonts: { type: Object, default: () => ({ nama: 'DejaVu Sans', label: 'DejaVu Sans', deskripsi: 'DejaVu Sans', nomor: 'DejaVu Sans', ttd_nama: 'DejaVu Sans' }) },
  // All-fonts options untuk panel font picker (optional — fallback ke 4 default)
  fontOptions: { type: Object, default: null },
})
const emit = defineEmits(['update:layout', 'save', 'reset', 'select', 'update:fonts'])

const FIELD_KEYS = ['nama','label','deskripsi','ttd','qr','nomor']
const FIELD_LABELS = { nama:'Nama Peserta', label:'Label', deskripsi:'Deskripsi', ttd:'Tanda Tangan', qr:'QR Code', nomor:'Nomor Sertifikat' }
const DEFAULT_LAYOUT = {
  nama: { x:148.5, y:88, w:180, font_pt:28 },
  label: { x:148.5, y:135, w:180, font_pt:18 },
  deskripsi:{ x:148.5, y:150, w:200, font_pt:11 },
  ttd: { x:55, y:175, w:60, font_pt:10 },
  qr:  { x:242, y:175, w:30, h:30 },
  nomor:{ x:148.5, y:195, w:180, font_pt:7 },
}
function cloneLayout(o){ return JSON.parse(JSON.stringify(o)) }

const certLayout = ref(cloneLayout(DEFAULT_LAYOUT))
const selectedField = ref(null)
const editMode = ref(false)
const certStageRef = ref(null)
const dragState = ref(null)
const resizeState = ref(null)
const lockedFields = ref(new Set())
const hasUnsaved = ref(false)

const targetNamaFallback = computed(() => 'Ekskul/Event')

const safeDeskripsi = computed(() => sanitizeHtml(props.displayDeskripsi))

// ---- font field mapping + options for panel picker ----
const FONT_FIELD_KEYS = { nama: 'nama', label: 'label', deskripsi: 'deskripsi', nomor: 'nomor', ttd: 'ttd_nama', qr: 'nomor' }
function fontFieldForKey(k){ return FONT_FIELD_KEYS[k] || null }
function optionsForSelectedField(){
  if (!selectedField.value) return []
  const fk = fontFieldForKey(selectedField.value)
  if (!fk) return []
  if (props.fontOptions && Array.isArray(props.fontOptions[fk])) return props.fontOptions[fk]
  // fallback: build from fonts prop keys (generic)
  return []
}
function currentFontForField(k){
  const fk = fontFieldForKey(k)
  if (!fk) return ''
  return String((props.fonts || {})[fk] || '')
}
function onFontChangeForSelected(e){
  const fk = fontFieldForKey(selectedField.value)
  if (!fk) return
  const v = String(e.target?.value || '')
  emit('update:fonts', { [fk]: v })
}

// ---- selectable fonts: Google Fonts <link> + font-family per field ----
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
function fontCss(v){
  const fam = String(v || 'DejaVu Sans').replace(/'/g, '')
  if (fam === 'DejaVu Sans') return "'DejaVu Sans', Arial, sans-serif"
  return `'${fam}', 'DejaVu Sans', Arial, sans-serif`
}
function fontForField(key){
  const f = props.fonts || {}
  if (key === 'nama') return fontCss(f.nama)
  if (key === 'label') return fontCss(f.label)
  if (key === 'deskripsi') return fontCss(f.deskripsi)
  if (key === 'nomor' || key === 'qr') return fontCss(f.nomor)
  if (key === 'ttd') return fontCss(f.ttd_nama)
  return "'DejaVu Sans', Arial, sans-serif"
}
const fontsHref = computed(() => {
  const vals = [...new Set(Object.values(props.fonts || {}))].filter(v => v && v !== 'DejaVu Sans' && FONT_GF_SLUG[v])
  if (!vals.length) return ''
  return 'https://fonts.googleapis.com/css2?' + vals.map(v => 'family=' + FONT_GF_SLUG[v]).join('&') + '&display=swap'
})
watch(fontsHref, (href) => {
  try{
    let el = document.getElementById('cert-editor-gf')
    if (!href){ if (el) el.remove(); return }
    if (!el){ el = document.createElement('link'); el.id = 'cert-editor-gf'; el.rel = 'stylesheet'; document.head.appendChild(el) }
    if (el.getAttribute('href') !== href) el.setAttribute('href', href)
  }catch{}
}, { immediate: true })

const bgCoverStyleProp = computed(() => {
  if (props.bgCoverStyle && Object.keys(props.bgCoverStyle).length) return props.bgCoverStyle
  if (props.bgSrc) {
    return { backgroundImage: `url(${props.bgSrc})`, backgroundSize: 'cover', backgroundPosition: 'center', backgroundRepeat: 'no-repeat' }
  }
  return {}
})

// Sync incoming layout prop into internal certLayout (deep merge over defaults).
watch(() => props.layout, (incoming) => {
  if (!incoming || typeof incoming !== 'object' || !Object.keys(incoming).length) {
    // empty -> reset to defaults but keep any incoming
    return
  }
  const next = cloneLayout(DEFAULT_LAYOUT)
  for (const k of FIELD_KEYS) {
    if (incoming[k] && typeof incoming[k] === 'object') next[k] = { ...next[k], ...incoming[k] }
  }
  // preserve bg_* keys if present
  for (const bk of ['bg_w','bg_h','bg_mime','bg_aspect','bg_updated_at']) {
    if (incoming[bk] != null) next[bk] = incoming[bk]
  }
  certLayout.value = next
}, { immediate: true, deep: false })

function emitUpdate(){
  // emit a shallow-cloned snapshot of just the FIELD_KEYS layout
  const snap = {}
  for (const k of FIELD_KEYS) snap[k] = { ...certLayout.value[k] }
  for (const bk of ['bg_w','bg_h','bg_mime','bg_aspect','bg_updated_at']) {
    if (certLayout.value[bk] != null) snap[bk] = certLayout.value[bk]
  }
  emit('update:layout', snap)
}

function mmToPct(layout){
  const out={}
  for(const k of FIELD_KEYS){
    const v=layout[k]||DEFAULT_LAYOUT[k]
    const isCentered = !['ttd','qr'].includes(k)
    const w = v.w ?? DEFAULT_LAYOUT[k].w ?? 60
    const x = v.x ?? DEFAULT_LAYOUT[k].x ?? 148.5
    const y = v.y ?? DEFAULT_LAYOUT[k].y ?? 100
    const leftPct = isCentered ? ((x - w/2)/297*100) : (x/297*100)
    const topPct = y/210*100
    const wPct = w/297*100
    const hPct = v.h!=null ? (v.h/210*100) : null
    const fontPt = v.font_pt ?? DEFAULT_LAYOUT[k].font_pt ?? 14
    const fontCqi = +(fontPt * 0.1188).toFixed(2)
    out[k]={ leftPct, topPct, wPct, hPct, fontCqi, fontPt, raw:v }
  }
  return out
}
const certPct = computed(() => mmToPct(certLayout.value))
function fieldStyle(key){
  const p = certPct.value[key]; if(!p) return {}
  const s = { left: p.leftPct+'%', top: p.topPct+'%', width: p.wPct+'%' }
  if(p.hPct!=null) s.height = p.hPct+'%'
  if(p.fontCqi) s.fontSize = p.fontCqi+'cqi'
  s.fontFamily = fontForField(key)
  if(key==='nama'){
    const fn=(props.fonts||{}).nama||'Great Vibes'
    s.fontWeight = ['Great Vibes','Allura','Alex Brush'].includes(fn) ? '400' : '800'
  }
  return s
}
function cssPct(key, prop){
  const p = certPct.value[key]; if(!p) return 0
  const isCentered = !['ttd','qr'].includes(key)
  if(prop==='x') return isCentered ? +(p.leftPct + p.wPct/2).toFixed(2) : +p.leftPct.toFixed(2)
  if(prop==='y') return +p.topPct.toFixed(2)
  if(prop==='w') return +p.wPct.toFixed(2)
  if(prop==='h') return p.hPct!=null ? +p.hPct.toFixed(2) : 0
  return 0
}

function toggleEditMode(){
  editMode.value = !editMode.value
  if (editMode.value && !selectedField.value) selectedField.value = 'nama'
}
function selectField(k){
  selectedField.value = k
  emit('select', k)
}
function onFieldClick(k){
  if (editMode.value) { selectedField.value = k; emit('select', k) }
}

function resetField(key){
  certLayout.value[key] = cloneLayout(DEFAULT_LAYOUT)[key]
  hasUnsaved.value = true
  emitUpdate()
}
function onResetAll(){
  resetAllLayout()
  emit('reset')
}
function resetAllLayout(){
  certLayout.value = cloneLayout(DEFAULT_LAYOUT)
  hasUnsaved.value = true
  selectedField.value = null
  emitUpdate()
}
function toggleLock(key){
  const s = new Set(lockedFields.value)
  if(s.has(key)) s.delete(key); else s.add(key)
  lockedFields.value = s
}
function onSidebarPctChange(key, prop, val){
  const num = parseFloat(val); if(isNaN(num)) return
  const cur = certLayout.value[key] || {}
  if(prop==='x'){
    // X = center-X untuk centered, left-edge untuk ttd/qr (konsisten dgn cssPct)
    cur.x = clampPctInput(num, 0, 100)/100*297
  } else if(prop==='y'){
    cur.y = clampPctInput(num, 0, 100)/100*210
  } else if(prop==='w'){
    cur.w = clampPctInput(num, 1, 100)/100*297
  } else if(prop==='h'){
    cur.h = clampPctInput(num, 1, 100)/100*210
  }
  hasUnsaved.value = true
  emitUpdate()
}
function onSidebarFontChange(key, val){
  const pt = parseFloat(val); if(isNaN(pt)||pt<4||pt>80) return
  if(!certLayout.value[key]) certLayout.value[key] = {}
  certLayout.value[key].font_pt = pt
  hasUnsaved.value = true
  emitUpdate()
}
function onFieldPointerDown(e, key){
  if(!editMode.value) return
  if(lockedFields.value.has(key)) return
  if(e.target.closest('.rz-handle')) return
  e.preventDefault()
  selectedField.value = key
  const rect = certStageRef.value?.getBoundingClientRect(); if(!rect) return
  const cur = certLayout.value[key]; if(!cur) return
  dragState.value = { key, startX:e.clientX, startY:e.clientY, initX:cur.x, initY:cur.y }
  const onMove = (ev) => {
    if(!dragState.value) return
    const dx = (ev.clientX - dragState.value.startX)/rect.width*297
    const dy = (ev.clientY - dragState.value.startY)/rect.height*210
    const isCentered = !['ttd','qr'].includes(key)
    let nx = dragState.value.initX + dx
    let ny = dragState.value.initY + dy
    const wMm = cur.w ?? DEFAULT_LAYOUT[key].w ?? 60
    const minX = isCentered ? wMm/2 : 0
    const maxX = isCentered ? 297-wMm/2 : 297-wMm
    nx = Math.max(minX, Math.min(maxX, nx))
    ny = Math.max(4, Math.min(206, ny))
    certLayout.value[key].x = +nx.toFixed(1)
    certLayout.value[key].y = +ny.toFixed(1)
    hasUnsaved.value = true
  }
  const onUp = () => {
    dragState.value = null
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
    emitUpdate()
  }
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
}
function onResizePointerDown(e, key, dir){
  if(lockedFields.value.has(key)) return
  e.preventDefault(); e.stopPropagation()
  selectedField.value = key
  const rect = certStageRef.value?.getBoundingClientRect(); if(!rect) return
  const cur = certLayout.value[key]; if(!cur) return
  const isCentered = !['ttd','qr'].includes(key)
  const startX = e.clientX, startY = e.clientY
  const initX = cur.x, initY = cur.y, initW = cur.w ?? DEFAULT_LAYOUT[key].w ?? 60, initH = cur.h ?? 4
  resizeState.value = { key, dir, startX, startY, initX, initY, initW, initH }
  const onMove = (ev) => {
    const dx = (ev.clientX - startX)/rect.width*297
    const dy = (ev.clientY - startY)/rect.height*210
    const c = certLayout.value[key]; if(!c) return
    let nw = initW, nh = initH, nx = initX, ny = initY
    if(dir.includes('e')) nw = Math.max(10, initW + dx)
    if(dir.includes('w')){ nw = Math.max(10, initW - dx); if(!isCentered) nx = initX + dx; else nx = initX + dx/2 }
    if(dir.includes('s')){ if(c.h!=null || key==='qr') nh = Math.max(6, initH + dy) }
    if(dir.includes('n')){ if(c.h!=null || key==='qr'){ nh = Math.max(6, initH - dy); ny = initY + dy } }
    nw = Math.min(280, nw)
    if(nh) nh = Math.min(190, nh)
    c.w = +nw.toFixed(1)
    if(c.h!=null || key==='qr') c.h = +nh.toFixed(1)
    if(isCentered) c.x = +nx.toFixed(1)
    if(dir.includes('n') && (c.h!=null || key==='qr')) c.y = +ny.toFixed(1)
    hasUnsaved.value = true
  }
  const onUp = () => {
    resizeState.value = null
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
    emitUpdate()
  }
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
}
function clampPctInput(v, min, max){ const n = parseFloat(v); if(isNaN(n)) return min; return Math.max(min, Math.min(max, n)) }

function onSave(){
  emit('save')
}
function markSaved(){ hasUnsaved.value = false }
function getLayout(){ return JSON.parse(JSON.stringify(certLayout.value)) }

defineExpose({ markSaved, getLayout, resetAllLayout, hasUnsaved })
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500&display=swap');
.cert-live-preview{--m-cta:#4A7875;--m-cta-h:#5A908C;--m-cta-active:#3D6663;--m-ink:#2F3E46;--m-muted:#6B7C85;--m-line:#E0E5E3;--m-bg:#F1F5F4;--m-blue:#A7C7E7;--m-green:#5EB87E;--m-disabled-bg:#F5F7F6;--m-disabled-text:#B0B8B5;font-family:'DM Sans',system-ui,sans-serif;color:var(--m-ink);margin-top:4px}
/* toolbar */
.md-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:12px 0 8px}
.md-toolbar-label{font-family:'Inter',system-ui,sans-serif;font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);text-transform:uppercase;line-height:1.4}
.md-toolbar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
/* buttons */
.md-btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:var(--m-cta);color:#fff;border:1px solid var(--m-cta);border-radius:12px;padding:9px 14px;font-family:'Inter',system-ui,sans-serif;font-size:13px;font-weight:600;line-height:1;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,.06);transition:background .15s,border-color .15s,transform .08s,opacity .15s;white-space:nowrap}
.md-btn-primary:hover{background:var(--m-cta-h);border-color:var(--m-cta-h)}
.md-btn-primary:active{background:var(--m-cta-active);transform:scale(.98)}
.md-btn-primary:disabled{background:var(--m-disabled-bg);border-color:var(--m-line);color:var(--m-disabled-text);cursor:not-allowed;box-shadow:none;transform:none;opacity:1}
.md-btn-secondary{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#fff;color:var(--m-ink);border:1px solid var(--m-line);border-radius:12px;padding:8px 12px;font-family:'Inter',system-ui,sans-serif;font-size:12px;font-weight:600;line-height:1;cursor:pointer;transition:background .15s,border-color .15s;white-space:nowrap}
.md-btn-secondary:hover{background:var(--m-bg)}
.md-btn-tertiary{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:transparent;color:var(--m-muted);border:1px solid transparent;border-radius:999px;padding:8px 12px;font-family:'Inter',system-ui,sans-serif;font-size:12px;font-weight:600;line-height:1;cursor:pointer;transition:background .15s,color .15s;white-space:nowrap}
.md-btn-tertiary:hover{background:rgba(74,120,117,.08);color:var(--m-cta)}
.md-btn-xs{padding:6px 10px;font-size:11px}
.md-btn-sm{padding:7px 12px;font-size:11px;border-radius:999px}
.md-btn-block{width:100%}
.md-dot{width:6px;height:6px;border-radius:999px;background:#fff;display:inline-block;animation:mdPulse 1.6s infinite;flex:none}
@keyframes mdPulse{0%,100%{opacity:1}50%{opacity:.45}}
/* hint bar */
.md-hint-bar{display:flex;align-items:flex-start;gap:10px;background:var(--m-bg);border:1px solid var(--m-line);border-radius:12px;padding:10px 12px;margin-bottom:8px;font-family:'DM Sans',system-ui,sans-serif;font-size:11px;line-height:1.5;color:var(--m-ink)}
.md-hint-icon{width:16px;height:16px;min-width:16px;border-radius:999px;background:var(--m-blue);color:var(--m-ink);display:grid;place-items:center;font-family:'Inter',system-ui,sans-serif;font-size:10px;font-weight:700;line-height:1}
/* grid */
.cert-editor-grid{display:block}
.cert-editor-grid.is-edit{display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start}
@media(max-width:1100px){.cert-editor-grid.is-edit{grid-template-columns:1fr}}
.preview-cert{width:100%;aspect-ratio:297/210;position:relative;background:#f8fafc center/cover no-repeat;overflow:hidden;border:1.5px solid var(--m-line);border-radius:16px;container-type:inline-size;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.preview-cert::before{content:"";position:absolute;inset:0;background:linear-gradient(transparent 96%,rgba(0,0,0,.04) 100%);pointer-events:none}
.pc-field{position:absolute;z-index:1;text-align:center;overflow:visible;line-height:1.2;font-family:'DejaVu Sans',Arial,sans-serif;user-select:none}
.pc-nama{font-weight:400;color:#0E1442;letter-spacing:-.01em}
.pc-label{font-weight:800;color:#b8860b;letter-spacing:.08em;text-transform:uppercase}
.pc-deskripsi{color:#444444;line-height:1.5;font-family:'DejaVu Sans',Arial,sans-serif}
.pc-ttd{text-align:center;color:#18181b}
.pc-ttd-date{font-size:.82cqi;color:#52525b}
.pc-ttd-img{height:6cqi;display:grid;place-items:center;margin:1cqi auto}
.pc-ttd-img img{max-height:6cqi;max-width:18cqi;object-fit:contain}
.pc-ttd-name{font-weight:700;border-top:.35cqi solid #18181b;padding-top:.6cqi;font-size:.95cqi}
.pc-ttd-nip{color:#71717a;font-size:.78cqi}
.pc-qr{text-align:center}
.pc-qr-box{width:100%;aspect-ratio:1;border:.35cqi solid #e4e4e7;display:grid;place-items:center;background:#fff;font-size:1.4cqi;font-weight:700;color:#71717a}
.pc-qr-url{font-size:.62cqi;color:#71717a;margin-top:.6cqi;word-break:break-all;line-height:1.2}
.pc-nomor{color:#666666}
.preview-cert.edit-stage{cursor:crosshair}
.pc-field.selected{outline:2px dashed #4A7875;outline-offset:2px;background:rgba(74,120,117,.06);cursor:move}
.pc-field.locked{opacity:.6;cursor:not-allowed;outline-color:#94a3b8}
.pc-field.dragging{opacity:.85;z-index:5}
.rz-handle{position:absolute;width:10px;height:10px;background:#fff;border:1.5px solid #4A7875;border-radius:3px;z-index:3}
.rz-handle.nw{left:-5px;top:-5px;cursor:nw-resize}
.rz-handle.ne{right:-5px;top:-5px;cursor:ne-resize}
.rz-handle.sw{left:-5px;bottom:-5px;cursor:sw-resize}
.rz-handle.se{right:-5px;bottom:-5px;cursor:se-resize}
.rz-handle.n{left:50%;top:-5px;transform:translateX(-50%);cursor:n-resize}
.rz-handle.s{left:50%;bottom:-5px;transform:translateX(-50%);cursor:s-resize}
.rz-handle.e{right:-5px;top:50%;transform:translateY(-50%);cursor:e-resize}
.rz-handle.w{left:-5px;top:50%;transform:translateY(-50%);cursor:w-resize}
.rz-badge{position:absolute;left:50%;top:-18px;transform:translateX(-50%);background:#2F3E46;color:#fff;font-size:8px;font-weight:700;letter-spacing:.06em;padding:2px 6px;border-radius:999px;white-space:nowrap;pointer-events:none;font-family:'Inter',system-ui,sans-serif}
/* props panel */
.cert-props{background:#fff;border:1px solid var(--m-line);border-radius:16px;padding:14px;position:sticky;top:12px;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.md-props-title{font-family:'Inter',system-ui,sans-serif;font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-ink);margin-bottom:8px}
.md-props-empty{font-family:'DM Sans',system-ui,sans-serif;font-size:12px;color:var(--m-muted);padding:14px 0;text-align:center}
.md-props-field-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
.md-props-field-name{font-family:'Inter',system-ui,sans-serif;font-weight:700;font-size:13px;color:var(--m-ink)}
.md-props-field-key{font-family:'DM Sans',system-ui,sans-serif;font-size:11px;font-weight:500;color:var(--m-muted)}
.md-props-field-actions{display:flex;gap:6px;align-items:center}
.md-icon-btn{width:32px;height:32px;min-width:32px;border-radius:10px;border:1px solid var(--m-line);background:#fff;display:grid;place-items:center;cursor:pointer;font-size:13px;color:var(--m-ink);transition:background .15s,border-color .15s}
.md-icon-btn:hover{background:var(--m-bg);border-color:var(--m-line)}
.props-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.prop{display:flex;flex-direction:column;gap:4px}
.prop span{font-family:'Inter',system-ui,sans-serif;font-size:9px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);text-transform:uppercase}
.md-input{width:100%;border:1px solid var(--m-line);border-radius:10px;padding:7px 9px;font-family:'DM Sans',system-ui,sans-serif;font-size:12px;color:var(--m-ink);background:#fff;outline:0;transition:border-color .15s,box-shadow .15s}
.md-input::placeholder{color:#B0B8B5}
.md-input:focus{border-color:var(--m-cta);box-shadow:0 0 0 3px rgba(74,120,117,.12)}
.md-props-note{font-family:'DM Sans',system-ui,sans-serif;font-size:10px;color:var(--m-muted);margin-top:8px;line-height:1.5}
.md-props-footer{margin-top:14px;border-top:1px solid var(--m-line);padding-top:10px}
.md-props-footer-label{font-family:'Inter',system-ui,sans-serif;font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-bottom:6px}
.md-chip-group{display:flex;flex-wrap:wrap;gap:6px}
.md-chip{display:inline-flex;align-items:center;justify-content:center;background:var(--m-bg);color:var(--m-muted);border:1px solid var(--m-line);border-radius:999px;padding:5px 10px;font-family:'Inter',system-ui,sans-serif;font-size:11px;font-weight:600;line-height:1;cursor:pointer;transition:background .15s,color .15s,border-color .15s;white-space:nowrap}
.md-chip:hover{background:#e8eeec}
.md-chip-active{background:var(--m-cta);color:#fff;border-color:var(--m-cta)}
.md-chip-active:hover{background:var(--m-cta-h);border-color:var(--m-cta-h)}
.md-props-footer-actions{display:flex;gap:6px;margin-top:10px}
.md-props-footer-actions .md-btn-tertiary,.md-props-footer-actions .md-btn-primary{flex:1;justify-content:center}
.md-footnote{font-family:'DM Sans',system-ui,sans-serif;font-size:10px;color:var(--m-muted);margin-top:6px;word-break:break-all;line-height:1.5}
.md-footnote code{font-size:10px;background:var(--m-bg);padding:2px 4px;border-radius:6px;border:1px solid var(--m-line)}
</style>
