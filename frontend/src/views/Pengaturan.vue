<template>
<div class="kat-page">
<div class="kat-inner">

  <div class="kat-head">
    <div class="kat-head-l">
      <h1 class="kat-title">Pengaturan</h1>
      <p class="kat-sub">Ganti password &amp; foto profil — {{ userNama }}</p>
    </div>
    <div class="kat-head-actions">
      <router-link to="/saya" class="kat-link small">← Kembali</router-link>
    </div>
  </div>

  <!-- toast -->
  <div v-if="toast" class="kat-toast" :class="toastOk?'ok':'err'" role="status" aria-live="polite">
    <span>{{ toast }}</span>
    <button class="toast-x" @click="toast=''" aria-label="Tutup">x</button>
  </div>

  <!-- profil ringkas -->
  <div class="kat-card kat-profile">
    <div class="prof-left">
      <img v-if="safeFoto(fotoUrl)" :src="safeFoto(fotoUrl)" class="prof-avatar-img" alt="Foto profil" />
      <div v-else class="prof-avatar" :style="avatarStyle(userNama)" aria-hidden="true">{{ initials(userNama) }}</div>
      <div class="prof-meta">
        <div class="prof-name-row"><span class="prof-name">{{ userNama }}</span></div>
        <div class="prof-email mono">{{ userEmail }}</div>
      </div>
    </div>
  </div>

  <!-- GANTI PASSWORD -->
  <div class="kat-card kat-sec">
    <div class="kat-card-head">
      <div class="kat-card-head-l">
        <h2 class="kat-card-title">Ganti Password</h2>
        <span class="kat-card-sub">Min 8 karakter, beda dari lama</span>
      </div>
    </div>
    <form class="kat-body" @submit.prevent="gantiPassword">
      <label class="fld"><span>Password lama</span>
        <input v-model="pwLama" type="password" autocomplete="current-password" :disabled="pwLoading" placeholder="••••••••" />
      </label>
      <label class="fld"><span>Password baru (min 8)</span>
        <input v-model="pwBaru" type="password" autocomplete="new-password" :disabled="pwLoading" placeholder="••••••••" />
      </label>
      <label class="fld"><span>Konfirmasi password baru</span>
        <input v-model="pwKonfirmasi" type="password" autocomplete="new-password" :disabled="pwLoading" placeholder="••••••••" />
      </label>
      <p v-if="pwError" class="fld-err" role="alert">{{ pwError }}</p>
      <div class="fld-actions">
        <button type="submit" class="kat-cta small" :disabled="pwLoading">{{ pwLoading ? 'Menyimpan…' : 'Simpan Password' }}</button>
      </div>
    </form>
  </div>

  <!-- GANTI FOTO -->
  <div class="kat-card kat-sec">
    <div class="kat-card-head">
      <div class="kat-card-head-l">
        <h2 class="kat-card-title">Foto Profil</h2>
        <span class="kat-card-sub">JPG / JPEG / PNG / WEBP, maks 2MB</span>
      </div>
    </div>
    <div class="kat-body">
      <div class="foto-row">
        <img v-if="previewUrl || safeFoto(fotoUrl)" :src="previewUrl || safeFoto(fotoUrl)" class="foto-preview" alt="Preview foto profil" />
        <div v-else class="prof-avatar big" :style="avatarStyle(userNama)" aria-hidden="true">{{ initials(userNama) }}</div>
        <div class="foto-meta">
          <input ref="fileEl" type="file" class="file-hidden" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" :disabled="fotoLoading" @change="onFile" tabindex="-1" aria-hidden="true" />
          <button type="button" class="file-pick" :disabled="fotoLoading" @click="triggerFile">
            <span class="file-pick-icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.5-4.5L6 21"/></svg>
            </span>
            <span class="file-pick-text">
              <span class="file-pick-label">{{ pickedFile ? pickedFile.name : 'Pilih foto…' }}</span>
              <span class="mono file-pick-sub">{{ pickedFile ? fmtSize(pickedFile.size) + ' — klik untuk ganti' : 'JPG / JPEG / PNG / WEBP · maks 2MB' }}</span>
            </span>
            <span class="file-pick-btn">Pilih File</span>
          </button>
          <p v-if="fotoError" class="fld-err" role="alert">{{ fotoError }}</p>
          <p v-if="previewUrl" class="foto-hint">Preview — klik Simpan untuk mengunggah.</p>
        </div>
      </div>
      <div class="fld-actions">
        <button class="kat-cta small" :disabled="fotoLoading || !pickedFile" @click="simpanFoto">{{ fotoLoading ? 'Mengunggah…' : 'Simpan Foto' }}</button>
        <button class="kat-cta small ghost" :disabled="fotoLoading || !safeFoto(fotoUrl)" @click="hapusFoto">Hapus Foto</button>
      </div>
    </div>
  </div>

  <!-- METODE LUPA PASSWORD (admin saja) -->
  <div v-if="isAdmin" class="kat-card kat-sec">
    <div class="kat-card-head">
      <div class="kat-card-head-l">
        <h2 class="kat-card-title">Metode Lupa Password</h2>
        <span class="kat-card-sub">Pilih cara admin mereset password siswa</span>
      </div>
    </div>
    <div class="kat-body">
      <label class="fld radio-row">
        <input type="radio" value="temp" v-model="forgotMethod" :disabled="forgotLoading" />
        <span><b>Password Sementara</b> — admin reset, sistem generate password yang bisa disalin, lalu admin memberitahu siswa manual.</span>
      </label>
      <label class="fld radio-row">
        <input type="radio" value="link" v-model="forgotMethod" :disabled="forgotLoading" />
        <span><b>Link Reset Sekali Pakai</b> — admin reset, sistem generate link (kadaluarsa 30 menit, sekali pakai) yang bisa disalin; siswa buka link lalu buat password baru.</span>
      </label>
      <p v-if="forgotError" class="fld-err" role="alert">{{ forgotError }}</p>
      <div class="fld-actions">
        <button class="kat-cta small" :disabled="forgotLoading" @click="simpanForgotMethod">{{ forgotLoading ? 'Menyimpan…' : 'Simpan Metode' }}</button>
      </div>
    </div>
  </div>

</div>
</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { api } from '../lib/api.js'
import { useAuth } from '../stores/auth.js'

const auth = useAuth()

const toast = ref('')
const toastOk = ref(true)
function showToast(msg, ok=true){ toast.value=msg; toastOk.value=ok; setTimeout(()=>{ toast.value='' }, 3200) }

function initials(s){ return (s||'?').slice(0,2).toUpperCase() }
function avatarStyle(n){
  const h = ((n||'')).charCodeAt(0) % 3
  if(h===0) return 'background:#ecfdf5;color:#047857;border-color:#a7f3d0'
  if(h===1) return 'background:#eff6ff;color:#0f5b9b;border-color:#bfdbfe'
  return 'background:#F1F5F4;color:#57534e;border-color:#E0E5E3'
}
// allowlist: cegah javascript:/data: XSS — hanya /api/…, /uploads/…, atau http(s)
function safeFoto(u){
  if(typeof u !== 'string') return null
  const s = u.trim()
  if(/^\/api\//.test(s) || /^\/uploads\//.test(s)) return s
  if(/^https?:\/\/[^\s"'<>]+$/.test(s)) return s
  return null
}

const userNama = computed(()=> auth.user?.nama || '-')
const userEmail = computed(()=> auth.user?.email || '-')
const fotoUrl = ref(auth.user?.foto_url || '')
const isAdmin = computed(()=> auth.user?.role === 'admin')

// ---- METODE LUPA PASSWORD (admin) ----
const forgotMethod = ref('temp'), forgotLoading = ref(false), forgotError = ref('')
async function loadForgotMethod(){
  try{
    const j = await api('/settings')
    const m = j?.data?.forgot_password_method
    if(m === 'temp' || m === 'link') forgotMethod.value = m
  }catch{}
}
async function simpanForgotMethod(){
  forgotError.value = ''
  forgotLoading.value = true
  try{
    const v = forgotMethod.value === 'link' ? 'link' : 'temp'
    const j = await api('/settings',{method:'PUT',body:{forgot_password_method:v}})
    const ok = j?.ok ?? j?.success ?? false
    if(ok === false) throw j
    showToast('Metode lupa password: ' + (v === 'link' ? 'Link Reset Sekali Pakai' : 'Password Sementara'), true)
  }catch(e){
    forgotError.value = e?.error?.message || e?.message || 'Gagal menyimpan metode'
    showToast(forgotError.value, false)
  }finally{ forgotLoading.value = false }
}

// ---- GANTI PASSWORD ----
const pwLama = ref(''), pwBaru = ref(''), pwKonfirmasi = ref('')
const pwLoading = ref(false), pwError = ref('')
function validPw(){
  const lama = String(pwLama.value||''), baru = String(pwBaru.value||''), konf = String(pwKonfirmasi.value||'')
  if(!lama || !baru || !konf) return 'Lengkapi ketiga kolom password.'
  if(baru.length < 8) return 'Password baru minimal 8 karakter.'
  if(baru !== konf) return 'Konfirmasi tidak cocok dengan password baru.'
  if(baru === lama) return 'Password baru harus beda dari password lama.'
  return ''
}
async function gantiPassword(){
  pwError.value = validPw()
  if(pwError.value) return
  pwLoading.value = true
  try{
    const j = await api('/auth/change-password',{method:'POST',body:{
      password_lama: String(pwLama.value||''),
      password_baru: String(pwBaru.value||''),
      konfirmasi: String(pwKonfirmasi.value||'')
    }})
    const ok = j?.ok ?? j?.success ?? false
    if(ok === false) throw j
    pwLama.value = pwBaru.value = pwKonfirmasi.value = ''
    pwError.value = ''
    // tetap login (session regenerate) — tanpa auto-logout paksa, UX aman
    showToast(j?.message || 'Password diganti', true)
  }catch(e){
    const msg = e?.error?.message || e?.message || 'Gagal ganti password'
    pwError.value = msg
    showToast(msg, false)
  }finally{ pwLoading.value = false }
}

// ---- FOTO PROFIL ----
const ALLOWED = ['image/jpeg','image/png','image/webp']
const MAX = 2*1024*1024
const fileEl = ref(null)
const pickedFile = ref(null)
const previewUrl = ref('')
const fotoLoading = ref(false), fotoError = ref('')
function triggerFile(){ if(!fotoLoading.value) fileEl.value?.click() }
function fmtSize(n){ n=Number(n||0); if(n>=1048576) return (n/1048576).toFixed(1)+' MB'; if(n>=1024) return Math.round(n/1024)+' KB'; return n+' B' }
function onFile(){
  fotoError.value = ''
  const f = fileEl.value?.files?.[0]
  if(!f){ pickedFile.value = null; previewUrl.value = ''; return }
  const extOk = /\.(jpe?g|png|webp)$/i.test(f.name||'')
  if(!ALLOWED.includes(f.type) && !extOk){ fotoError.value = 'Format harus JPG/JPEG/PNG/WEBP.'; pickedFile.value = null; return }
  if(f.size > MAX){ fotoError.value = 'Ukuran maksimal 2MB.'; pickedFile.value = null; return }
  pickedFile.value = f
  if(previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = URL.createObjectURL(f)
}
async function simpanFoto(){
  if(!pickedFile.value) return
  fotoLoading.value = true
  try{
    const fd = new FormData()
    fd.append('avatar', pickedFile.value)
    const j = await api('/me/avatar',{method:'POST', body: fd, timeout: 60000})
    const ok = j?.ok ?? j?.success ?? false
    if(ok === false) throw j
    const url = j?.foto_url ?? j?.data?.foto_url ?? null
    if(url){ fotoUrl.value = url; if(auth.user) auth.user.foto_url = url }
    else { try{ const m = await api('/auth/me'); fotoUrl.value = m?.data?.user?.foto_url || ''; if(auth.user && m?.data?.user) auth.user.foto_url = fotoUrl.value }catch{} }
    pickedFile.value = null
    if(previewUrl.value){ URL.revokeObjectURL(previewUrl.value); previewUrl.value = '' }
    if(fileEl.value) fileEl.value.value = ''
    fotoError.value = ''
    showToast(j?.message || 'Foto profil diganti', true)
  }catch(e){
    showToast(e?.error?.message || e?.message || 'Gagal unggah foto', false)
  }finally{ fotoLoading.value = false }
}
async function hapusFoto(){
  fotoLoading.value = true
  try{
    const j = await api('/me/avatar',{method:'DELETE', body:{}})
    const ok = j?.ok ?? j?.success ?? false
    if(ok === false) throw j
    fotoUrl.value = ''
    if(auth.user) auth.user.foto_url = null
    if(fileEl.value) fileEl.value.value = ''
    showToast(j?.message || 'Foto profil dihapus', true)
  }catch(e){
    showToast(e?.error?.message || e?.message || 'Gagal hapus foto', false)
  }finally{ fotoLoading.value = false }
}

onMounted(async ()=>{
  try{
    const m = await api('/auth/me')
    const u = m?.data?.user
    if(u){ fotoUrl.value = u.foto_url || ''; if(auth.user) auth.user.foto_url = u.foto_url ?? auth.user.foto_url }
  }catch{}
  if(isAdmin.value) loadForgotMethod()
})
onBeforeUnmount(()=>{ if(previewUrl.value) URL.revokeObjectURL(previewUrl.value) })
</script>

<style scoped>
.kat-page{ --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3; --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85; background:var(--m-bg); color:var(--m-ink); margin:-24px calc(50% - 50vw) 0; padding:20px max(16px,calc(50vw - 680px)) 24px; }
.kat-inner{max-width:1360px;margin:0 auto}
.mono{font-family:'Satoshi',system-ui,sans-serif}
.kat-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:2px 0 10px;flex-wrap:wrap}
.kat-title{margin:0;font-family:'Satoshi',system-ui,sans-serif;font-size:20px;font-weight:800;letter-spacing:-.01em}
.kat-sub{margin:2px 0 0;font-size:11.5px;color:var(--m-muted);font-family:'Satoshi',system-ui,sans-serif}
.kat-head-actions{display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}
.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px}
.kat-link.small{padding:7px 12px;font-size:12.5px}
.kat-link:hover{border-color:#d4d4d8}
.kat-toast{margin:0 0 12px;background:var(--m-ink);color:#fff;padding:10px 14px;border-radius:10px;font-size:12.5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border:1px solid #26343c}
.kat-toast.ok{background:#065f46;border-color:#047857}
.kat-toast.err{background:#7f1d1d;border-color:#991b1b}
.toast-x{margin-left:auto;width:24px;height:24px;border-radius:999px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;cursor:pointer;display:grid;place-items:center}
.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}
.kat-profile{display:flex;justify-content:space-between;gap:16px;padding:16px;align-items:center;flex-wrap:wrap}
.prof-left{display:flex;gap:14px;align-items:center;flex:1;min-width:260px}
.prof-avatar{width:44px;height:44px;border-radius:999px;display:grid;place-items:center;font-weight:800;font-size:14px;color:#fff;background:var(--m-ink);flex-shrink:0;border:1px solid var(--m-line)}
.prof-avatar.big{width:64px;height:64px;font-size:18px}
.prof-avatar-img{width:44px;height:44px;border-radius:999px;object-fit:cover;flex-shrink:0;border:1px solid var(--m-line)}
.prof-name{font-size:16px;font-weight:800;letter-spacing:-.01em}
.prof-email{font-size:11px;color:var(--m-muted);margin-top:2px}
.kat-sec{margin-top:12px}
.kat-card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--m-line);background:#fff;flex-wrap:wrap}
.kat-card-title{margin:0;font-size:13px;font-weight:700;letter-spacing:-.01em}
.kat-card-sub{font-size:11px;color:var(--m-muted)}
.kat-body{padding:16px;display:flex;flex-direction:column;gap:10px}
.fld{display:flex;flex-direction:column;gap:6px;font-size:12.5px;font-weight:600;max-width:420px}
.fld.radio-row{flex-direction:row;align-items:flex-start;gap:10px;max-width:640px;padding:10px 12px;border:1px solid var(--m-line);border-radius:10px;font-weight:400;cursor:pointer}
.fld.radio-row input{margin-top:3px}
.fld.radio-row span{font-size:12.5px;line-height:1.6}
.fld input{padding:10px 12px;border:1px solid var(--m-line);border-radius:10px;font-size:13px;font-weight:400}
.fld input:focus{outline:none;border-color:var(--m-cta)}
.fld-err{font-size:12px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;margin:0;max-width:420px}
.fld-actions{display:flex;gap:8px;flex-wrap:wrap}
.kat-cta{padding:10px 18px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:13px;font-weight:700;border:1px solid var(--m-ink);cursor:pointer}
.kat-cta.small{padding:8px 14px;font-size:12.5px}
.kat-cta:disabled{opacity:.55;cursor:not-allowed}
.kat-cta.ghost{background:#fff;color:var(--m-muted);border-color:var(--m-line)}
.foto-row{display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap}
.foto-preview{width:64px;height:64px;border-radius:999px;object-fit:cover;border:1px solid var(--m-line)}
.foto-meta{flex:1;min-width:220px;display:flex;flex-direction:column;gap:8px;font-size:12.5px}
.foto-hint{font-size:11.5px;color:var(--m-muted);margin:0}
.file-hidden{display:none}
.file-pick{display:flex;align-items:center;gap:12px;width:100%;max-width:420px;padding:12px 14px;background:#fff;border:1.5px dashed var(--m-line);border-radius:12px;cursor:pointer;text-align:left;color:var(--m-ink)}
.file-pick:hover:not(:disabled){border-color:var(--m-cta);background:#fafaf9}
.file-pick:disabled{opacity:.6;cursor:not-allowed}
.file-pick-icon{width:36px;height:36px;border-radius:10px;background:var(--m-bg);border:1px solid var(--m-line);display:grid;place-items:center;color:var(--m-cta);flex-shrink:0}
.file-pick-text{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.file-pick-label{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.file-pick-sub{font-size:10.5px;color:var(--m-muted)}
.file-pick-btn{flex-shrink:0;padding:8px 14px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:12px;font-weight:700}
@media(max-width:639px){ .kat-page{padding:20px 16px 24px} .kat-head{flex-direction:column;align-items:flex-start} }
</style>
