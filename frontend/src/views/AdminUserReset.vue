<template>
<div class="kat-page">
<div class="kat-inner">

<div class="kat-head">
<div class="kat-head-l">
<h1 class="kat-title">Reset Password User</h1>
<p class="kat-sub mono">PERMINTAAN RESET DARI NOTIFIKASI</p>
</div>
<div class="kat-head-r">
<router-link to="/admin/users" class="kat-link">← Kembali ke Users</router-link>
</div>
</div>

<div class="kat-strip">🔑 Permintaan reset dari notifikasi — generate sesuai metode aktif di Pengaturan, lalu salin &amp; sampaikan manual ke user.</div>

<div v-if="loading" class="kat-card" style="padding:16px"><div class="skeleton"></div></div>
<div v-else-if="notFound" class="kat-card kat-empty">
<p class="kat-empty-title">User tidak ditemukan</p>
<p class="muted">ID {{ id }} tidak ada atau sudah dihapus.</p>
<div style="margin-top:12px"><router-link to="/admin/users" class="kat-link">Kembali ke Users</router-link></div>
</div>
<div v-else class="reset-grid">

<!-- kartu user -->
<section class="kat-card reset-user" aria-label="Data user">
<div class="mono reset-label">DATA USER PEMINTA</div>
<div class="reset-id">
<span class="reset-avatar" aria-hidden="true">{{ (user.nama||'?').trim().charAt(0).toUpperCase() }}</span>
<div class="reset-id-t">
<div class="reset-name">{{ user.nama }}</div>
<div class="reset-email mono">{{ user.email }}</div>
</div>
</div>
<div class="reset-meta">
<span class="kat-chip small static">Role: {{ user.role }}</span>
<span class="kat-chip small static" :class="user.status==='aktif' ? 'ok' : 'warn'">Status: {{ user.status }}</span>
<span v-if="user.kelas" class="kat-chip small static">Kelas: {{ user.kelas }}</span>
</div>
<div class="reset-method">
<span class="mono reset-label">METODE AKTIF</span>
<span v-if="method==='link'" class="kat-chip small static active">Link Reset</span>
<span v-else class="kat-chip small static active">Password Sementara</span>
<router-link to="/pengaturan" class="kat-link reset-change">Ubah di Pengaturan</router-link>
</div>
<p class="muted reset-hint">{{ methodHint }}</p>
</section>

<!-- kartu aksi -->
<section class="kat-card reset-act" aria-label="Aksi reset">
<div class="mono reset-label">GENERATE SESUAI METODE</div>
<div v-if="err" class="field-error" role="alert">{{ err }}</div>
<div v-if="result" class="gen-box">
<code class="mono">{{ resultText }}</code>
<button type="button" class="kat-mini neutral" @click="copyResult" :class="{'is-ok': copied}">{{ copied ? '✓ Tercopy' : 'Copy' }}</button>
</div>
<div v-if="result?.method==='link'" class="muted reset-expiry">Kadaluarsa: {{ result.expires_at }} (30 menit, sekali pakai)</div>
<div v-else-if="!result" class="muted reset-idle">Belum ada hasil — klik tombol generate di bawah.</div>
<div class="reset-btns">
<button class="kat-btn-primary" @click="doReset" :disabled="busy">{{ busy ? 'Memproses…' : (method==='link' ? 'Generate Link Reset' : 'Generate Password Sementara') }}</button>
</div>

<div class="reset-divider" aria-hidden="true"></div>
<div class="mono reset-label">RESET MANUAL (OPSIONAL)</div>
<div class="reset-manual">
<input :type="show?'text':'password'" v-model="manual" placeholder="Password baru min 8 karakter" class="kat-field-input" aria-label="Password baru manual" />
<button type="button" class="kat-mini neutral" @click="show=!show">{{ show ? 'Sembunyi' : 'Lihat' }}</button>
</div>
<div v-if="manual && manual.length<8" class="field-error">Minimal 8 karakter</div>
<div class="reset-btns">
<button class="kat-btn-ghost" @click="doManual" :disabled="busy || manual.length<8">Reset Manual</button>
</div>
<div v-if="toast" class="reset-toast" :class="{ok: toastOk}" role="status">{{ toast }}</div>
</section>

</div>
</div>
</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../lib/api.js'

const route = useRoute(), router = useRouter()
const id = computed(()=> parseInt(String(route.params.id||''),10))
const user = ref(null), loading = ref(true), notFound = ref(false)
const method = ref('temp'), err = ref(''), result = ref(null), copied = ref(false), busy = ref(false)
const manual = ref(''), show = ref(false), toast = ref(''), toastOk = ref(true)

const methodHint = computed(()=> method.value==='link'
  ? 'Link sekali pakai, kadaluarsa 30 menit. Salin lalu kirim ke user manual.'
  : 'Password sementara langsung aktif. Salin lalu sampaikan ke user manual.')
const resultText = computed(()=>{
  const d = result.value; if(!d) return ''
  if(d.method==='temp') return 'Password sementara untuk '+(d.nama||'')+' <'+(d.email||'')+'>:\n'+d.temp_password
  if(d.method==='link') return 'Link reset sekali pakai (30 menit) untuk '+(d.nama||'')+' <'+(d.email||'')+'>:\n'+d.reset_link
  return ''
})

async function loadUser(){
  loading.value = true; notFound.value = false
  try{
    const j = await api('/users/'+id.value)
    user.value = j.data
  }catch{ notFound.value = true }
  finally{ loading.value = false }
}
async function refreshMethod(){
  try{
    const j = await api('/settings')
    const m = j?.data?.forgot_password_method
    if(m==='temp'||m==='link') method.value = m
  }catch{}
}
async function copyText(t, flag){
  try{ await navigator.clipboard.writeText(t) }
  catch{ const ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove() }
  flag.value = true; setTimeout(()=> flag.value=false, 1800)
}
function copyResult(){ if(resultText.value) copyText(resultText.value, copied) }
async function doReset(){
  err.value=''; result.value=null; busy.value=true
  try{
    await refreshMethod()
    const j = await api('/users/'+id.value+'/forgot-reset',{method:'POST',body:{}})
    result.value = j.data||null
    if(result.value?.method) method.value = result.value.method
  }catch(e){ err.value = e?.error?.message || e?.message || 'Gagal generate reset' }
  finally{ busy.value=false }
}
async function doManual(){
  err.value=''; toast.value=''
  try{
    await api('/users/'+id.value+'/reset-password',{method:'POST',body:{password: manual.value}})
    toast.value='Password manual berhasil diset untuk '+(user.value?.nama||'user'); toastOk.value=true; manual.value=''
  }catch(e){ toast.value = e?.error?.message || 'Gagal reset manual'; toastOk.value=false }
  setTimeout(()=> toast.value='', 2600)
}
function guardAdmin(){
  // route meta sudah role:admin, ini fallback bila guard dilewati
  try{
    const raw = localStorage.getItem('auth')||''
    const role = (JSON.parse(raw)?.user?.role)||''
    if(role && role!=='admin') router.replace('/403')
  }catch{}
}
onMounted(async ()=>{ guardAdmin(); if(!id.value||isNaN(id.value)) router.replace('/admin/users'); else { await loadUser(); await refreshMethod() } })
</script>

<style scoped>
/* Mindora tokens — scoped di halaman ini (kat-* tidak global, sumber: Katalog/Events) */
.kat-page{
  --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3;
  --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85;
  background:var(--m-bg); color:var(--m-ink);
  margin:-24px calc(50% - 50vw) 0; padding:20px max(16px,calc(50vw - 680px)) 24px;
  min-height:60vh;
}
.kat-inner{max-width:1360px;margin:0 auto}
.mono{font-family:'JetBrains Mono',ui-monospace,monospace}
.muted{color:var(--m-muted)}

/* flat tool head */
.kat-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:2px 0 10px;flex-wrap:wrap}
.kat-title{font-size:20px;font-weight:800;letter-spacing:-.01em;color:var(--m-ink);margin:0}
.kat-sub{font-size:11.5px;color:var(--m-muted);margin:2px 0 0;letter-spacing:.06em}
.kat-head-r{display:flex;gap:8px;align-items:center}
.kat-link{color:var(--m-cta);font-size:12.5px;font-weight:600;text-decoration:none}
.kat-link:hover{color:var(--m-cta-h);text-decoration:underline}

/* scope strip */
.kat-strip{background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);
  border-radius:12px;padding:10px 14px;font-size:12.5px;color:var(--m-ink);margin-bottom:12px}

/* cards */
.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px}
.kat-empty{padding:24px;text-align:center}
.kat-empty-title{font-weight:800;font-size:15px;margin:0 0 4px}
.kat-empty .muted{font-size:12.5px}

/* grid */
.reset-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:12px}
@media(max-width:900px){.reset-grid{grid-template-columns:1fr}}
.reset-user,.reset-act{padding:18px}

/* labels */
.reset-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

/* user identity */
.reset-id{display:flex;gap:12px;align-items:center;margin-top:10px}
.reset-avatar{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;flex-shrink:0;
  background:var(--m-ink);color:#fff;font-size:18px;font-weight:800}
.reset-name{font-size:17px;font-weight:800;color:var(--m-ink);line-height:1.25}
.reset-email{font-size:12px;color:var(--m-muted);margin-top:2px;word-break:break-all}

/* chips */
.reset-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.kat-chip{padding:7px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;
  font-size:12px;font-weight:600;color:var(--m-ink);cursor:default}
.kat-chip.small{padding:5px 11px;font-size:11.5px}
.kat-chip.active{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}
.kat-chip.ok{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.kat-chip.warn{background:#fef2f2;color:#991b1b;border-color:#fecaca}

/* method */
.reset-method{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:14px}
.reset-change{margin-left:2px}
.reset-hint{font-size:12px;line-height:1.6;margin:8px 0 0}

/* result */
.gen-box{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid var(--m-line);
  background:#F8FAF9;border-radius:12px;margin-top:10px}
.gen-box code{flex:1;font-size:12px;letter-spacing:.02em;word-break:break-all;color:var(--m-ink);white-space:pre-wrap}
.kat-mini{border:1px solid var(--m-line);background:#fff;border-radius:999px;padding:7px 14px;
  font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0;color:var(--m-ink)}
.kat-mini:hover{background:var(--m-bg)}
.kat-mini.is-ok{background:var(--m-cta);border-color:var(--m-cta);color:#fff}
.reset-expiry{font-size:11px;margin-top:6px}
.reset-idle{font-size:12px;margin-top:10px}

/* buttons */
.reset-btns{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
.kat-btn-primary{background:var(--m-cta);color:#fff;border:none;border-radius:999px;
  padding:11px 20px;font-size:13px;font-weight:700;cursor:pointer}
.kat-btn-primary:hover:not(:disabled){background:var(--m-cta-h)}
.kat-btn-primary:disabled{opacity:.5;cursor:not-allowed}
.kat-btn-ghost{background:#fff;color:var(--m-ink);border:1px solid var(--m-line);border-radius:999px;
  padding:11px 20px;font-size:13px;font-weight:600;cursor:pointer}
.kat-btn-ghost:hover:not(:disabled){background:var(--m-bg)}
.kat-btn-ghost:disabled{opacity:.5;cursor:not-allowed}

/* manual */
.reset-divider{border-top:1px solid var(--m-line);margin:18px 0 12px}
.reset-manual{display:flex;gap:8px;margin-top:10px}
.kat-field-input{flex:1;min-width:0;height:44px;border:1px solid var(--m-line);border-radius:12px;
  padding:0 14px;font-size:13px;color:var(--m-ink);background:#fff}
.kat-field-input:focus{outline:2px solid var(--m-cta);outline-offset:-1px;border-color:var(--m-cta)}
.field-error{font-size:12px;color:#991b1b;margin-top:8px}

/* toast */
.reset-toast{margin-top:12px;padding:10px 12px;border-radius:10px;font-size:12.5px;
  background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.reset-toast.ok{background:#f0fdf4;color:#166534;border-color:#bbf7d0}

/* skeleton */
.skeleton{height:120px;border-radius:12px;background:linear-gradient(90deg,#eef2f1 25%,#f7faf9 50%,#eef2f1 75%);
  background-size:200% 100%;animation:shimmer 1.2s infinite}
@keyframes shimmer{to{background-position:-200% 0}}
@media (prefers-reduced-motion: reduce){.skeleton{animation:none}}
</style>
