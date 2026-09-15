<template>
<div class="kat-page">
  <div class="kat-inner" style="max-width:640px">
    <!-- header -->
    <div style="margin-bottom:12px">
      <router-link :to="`/events/${eid}`" class="kat-back">← Kembali ke event</router-link>
    </div>

    <section class="kat-card" style="overflow:hidden">
      <div style="padding:18px 16px 14px;border-bottom:1px solid var(--m-line);display:flex;gap:10px;align-items:center">
        <span style="width:36px;height:36px;border-radius:12px;display:grid;place-items:center;background:var(--m-ink);color:#fff;font-size:16px;flex-shrink:0">◉</span>
        <div style="min-width:0">
          <div class="mono" style="font-size:10px;letter-spacing:.08em;color:#6B7C85;font-weight:700">ABSENSI QR</div>
          <div style="font-weight:800;font-size:15px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ eventNama || ('Event #'+eid) }} — {{ sessionNama || ('Sesi #'+sid) }}</div>
          <div class="mono" style="font-size:11px;color:#6B7C85;margin-top:2px">{{ statusLabel }}</div>
        </div>
      </div>

      <div style="padding:16px">
        <!-- redirecting to login -->
        <div v-if="needLogin" class="att-state">
          <div class="att-icon warn">!</div>
          <div style="font-weight:700">Login diperlukan</div>
          <div class="mono" style="font-size:12px;color:#6B7C85;margin-top:4px;line-height:1.5">Kamu belum login. Mengarahkan ke halaman login... Setelah login, absensi akan otomatis diproses — tidak perlu scan ulang.</div>
          <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap">
            <button class="kat-btn" style="height:40px;padding:0 18px;border-radius:999px;background:var(--m-cta);color:#fff;border:none;font-weight:600;cursor:pointer" @click="goLogin">Ke halaman Login</button>
          </div>
          <div class="mono" style="font-size:10px;color:#a8a29e;margin-top:8px;word-break:break-all">Tujuan: {{ intendedUrl }}</div>
        </div>

        <!-- loading -->
        <div v-else-if="loading" class="att-state">
          <div class="att-spin"></div>
          <div style="font-weight:600;margin-top:10px">Memproses absensi...</div>
          <div class="mono" style="font-size:11px;color:#6B7C85;margin-top:4px">Mohon tunggu sebentar</div>
        </div>

        <!-- success -->
        <div v-else-if="ok" class="att-state ok">
          <div class="att-icon ok">✓</div>
          <div style="font-weight:800;color:#065f46">Hadir tercatat!</div>
          <div class="mono" style="font-size:12px;color:#065f46;margin-top:4px;line-height:1.5">{{ msg }}</div>
          <div class="mono" style="font-size:11px;color:#6B7C85;margin-top:6px">{{ sessionNama ? 'Sesi: '+sessionNama : '' }}</div>
          <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap">
            <router-link :to="`/events/${eid}`" class="kat-btn" style="height:40px;padding:0 18px;border-radius:999px;background:var(--m-cta);color:#fff;text-decoration:none;display:inline-flex;align-items:center;font-weight:600">Lihat Event</router-link>
            <router-link to="/saya" class="kat-btn-ghost neutral" style="height:40px;padding:0 14px;border-radius:999px">Riwayat Saya</router-link>
          </div>
        </div>

        <!-- error -->
        <div v-else-if="err" class="att-state err">
          <div class="att-icon err">!</div>
          <div style="font-weight:800;color:#991b1b">{{ errTitle }}</div>
          <div class="mono" style="font-size:12px;color:#7f1d1d;margin-top:4px;line-height:1.5">{{ errMsg }}</div>
          <div v-if="errCode" class="mono" style="font-size:10px;color:#a8a29e;margin-top:6px">Kode: {{ errCode }}</div>
          <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap">
            <router-link :to="`/events/${eid}`" class="kat-btn-ghost neutral" style="height:40px;padding:0 14px;border-radius:999px;display:inline-flex;align-items:center;text-decoration:none">Kembali</router-link>
            <button v-if="canRetry" class="kat-btn" style="height:40px;padding:0 18px;border-radius:999px;background:var(--m-cta);color:#fff;border:none;font-weight:600;cursor:pointer" @click="doScan">Coba lagi</button>
          </div>
        </div>

        <!-- idle fallback -->
        <div v-else class="att-state">
          <div class="mono" style="font-size:12px;color:#6B7C85">Siap memproses absensi...</div>
          <button class="kat-btn" style="margin-top:12px;height:40px;padding:0 18px;border-radius:999px;background:var(--m-cta);color:#fff;border:none;font-weight:600;cursor:pointer" @click="doScan">Proses Absensi</button>
        </div>
      </div>
    </section>

    <p class="mono" style="font-size:11px;color:#a8a29e;text-align:center;margin-top:10px">Scan sekali saja — sistem menangani login otomatis dan mencegah absensi ganda.</p>
  </div>
</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '../stores/auth.js'
import { api } from '../lib/api.js'

const route = useRoute()
const router = useRouter()
const auth = useAuth()

const eid = computed(()=> String(route.params.id || ''))
const sid = computed(()=> String(route.params.sid || ''))

const loading = ref(false)
const ok = ref(false)
const msg = ref('')
const err = ref(false)
const errTitle = ref('')
const errMsg = ref('')
const errCode = ref('')
const canRetry = ref(false)
const needLogin = ref(false)
const intendedUrl = computed(()=> route.fullPath)
const eventNama = ref('')
const sessionNama = ref('')
const statusLabel = ref('Memverifikasi sesi...')

let scannedOnce = false

function goLogin(){
  router.replace({ path:'/login', query:{ redirect: route.fullPath } })
}

function getToken(){
  // priority: ?t=  ?token=  ?code=  (URL QR) — fallback: EVENT:sid:hex constructed if we can
  const q = route.query
  let t = (q.t || q.token || q.code || '').toString().trim()
  if(t) return t
  // if no query, try to extract from hash? not needed
  return ''
}

function mapError(code, raw){
  const c = String(code||'').toUpperCase()
  if(c==='ALREADY') return { title:'Sudah absen', msg:'Kamu sudah melakukan absensi untuk sesi ini. Tidak perlu scan lagi.', retry:false }
  if(c==='STOPPED') return { title:'Sesi dihentikan', msg:'QR sesi sudah dihentikan panitia. Hubungi admin untuk sesi baru.', retry:false }
  if(c==='EXPIRED') return { title:'QR kedaluwarsa', msg:'QR sesi sudah kedaluwarsa. Minta QR baru dari admin.', retry:false }
  if(c==='NOT_REGISTERED') return { title:'Belum terdaftar', msg:'Kamu belum terdaftar di event ini — daftar dulu ya, baru bisa absen.', retry:false }
  if(c==='INVALID') return { title:'QR tidak valid', msg: raw || 'QR tidak valid — pastikan scan QR absensi event yang benar.', retry:false }
  if(c==='NOT_FOUND') return { title:'Sesi tidak ditemukan', msg: raw || 'Sesi tidak ditemukan.', retry:false }
  if(c==='RATE_LIMIT') return { title:'Terlalu sering', msg:'Terlalu sering scan — tunggu 1 menit lalu coba lagi.', retry:true }
  if(c==='FORBIDDEN') return { title:'Akses ditolak', msg: raw || 'Hanya siswa yang bisa absen.', retry:false }
  if(c==='UNAUTHORIZED') return { title:'Belum login', msg:'Sesi login habis — silakan login kembali.', retry:true }
  return { title:'Gagal absen', msg: raw || 'Terjadi kesalahan. Coba lagi.', retry:true }
}

async function doScan(){
  if(scannedOnce) return
  // if still not logged in, redirect to login preserving URL
  if(auth.loading) await auth.me()
  if(!auth.user){
    needLogin.value = true
    statusLabel.value = 'Perlu login — mengalihkan...'
    // auto redirect after short delay to allow user reading
    setTimeout(()=> goLogin(), 900)
    return
  }
  // role guard: only siswa can scan, but let backend decide and show friendly message
  const token = getToken()
  if(!token){
    err.value = true
    errTitle.value = 'QR tidak valid'
    errMsg.value = 'Token tidak ditemukan di URL. Pastikan QR berisi link absensi yang benar (berakhiran ?t=...).'
    errCode.value = 'INVALID'
    canRetry.value = false
    statusLabel.value = 'Token kosong'
    return
  }
  // build token to send: if token is raw 32hex, backend expects either EVENT:sid:hex or hex — we send as-is plus also try EVENT form for robustness
  // Prefer EVENT:sid:hex when sid available and token is hex
  let sendToken = token
  if(/^[a-f0-9]{32}$/i.test(token) && sid.value){
    sendToken = `EVENT:${sid.value}:${token.toLowerCase()}`
  }
  // if token already URL-encoded EVENT:..., it will be decoded by router query — keep as is
  // if token is full URL pasted (e.g. https://.../attendance/19?t=abc), extract ?t=
  if(/^https?:\/\//i.test(token)){
    try{
      const u = new URL(token)
      const inner = u.searchParams.get('t') || u.searchParams.get('token') || ''
      if(inner) sendToken = inner
      // if inner is hex, wrap as EVENT
      if(/^[a-f0-9]{32}$/i.test(inner) && sid.value) sendToken = `EVENT:${sid.value}:${inner.toLowerCase()}`
    }catch{}
  }

  loading.value = true
  err.value = false
  ok.value = false
  statusLabel.value = 'Memproses...'
  try{
    const j = await api('/event-attendance/scan', { method:'POST', body:{ token: sendToken } })
    scannedOnce = true
    ok.value = true
    const sname = j?.data?.session_nama || j?.data?.session?.nama || sessionNama.value || ('Sesi #'+(j?.data?.session_id||sid.value))
    sessionNama.value = sname
    msg.value = `Kehadiran untuk ${sname} berhasil dicatat.`
    statusLabel.value = 'Berhasil — hadir tercatat'
    loading.value = false
  }catch(e){
    loading.value = false
    const code = e?.error?.code || e?.code || ''
    const raw = e?.error?.message || e?.message || 'Gagal'
    // if 401 unauth after expiry, treat as needLogin
    if(code==='UNAUTHORIZED' || /belum login/i.test(raw)){
      needLogin.value = true
      statusLabel.value = 'Sesi habis — perlu login ulang'
      setTimeout(()=> goLogin(), 900)
      return
    }
    const m = mapError(code, raw)
    err.value = true
    errTitle.value = m.title
    errMsg.value = m.msg
    errCode.value = code || 'ERROR'
    canRetry.value = m.retry
    statusLabel.value = m.title
    // ALREADY should not allow retry but show as success-ish? keep as error with clear message per spec
    if(code==='ALREADY') scannedOnce = true
  }
}

onMounted(async()=>{
  // fetch event/session names for header (best-effort, no auth required for fetch may fail — keep fallback)
  try{
    const j = await api('/events/'+eid.value)
    if(j?.data?.nama) eventNama.value = j.data.nama
  }catch{}
  // try to get session nama via sessions list if admin? for siswa we may not have access — parse from query fallback
  if(auth.user?.role==='admin'){
    try{
      const r = await api('/events/'+eid.value+'/sessions')
      const list = r.data || r.data.sessions || []
      const s = Array.isArray(list) ? list.find(x=> String(x.id)===String(sid.value)) : null
      if(s?.nama) sessionNama.value = s.nama
    }catch{}
  }

  if(auth.loading) await auth.me()
  if(!auth.user){
    needLogin.value = true
    statusLabel.value = 'Belum login — mengalihkan ke login'
    // keep URL intact, auto redirect shortly
    setTimeout(()=> goLogin(), 900)
    return
  }
  needLogin.value = false
  // auto process once
  await doScan()
})
</script>

<style scoped>
.kat-page{ --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3; --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85; background:linear-gradient(165deg,#E9F3EC 0%,#F1F5F4 36%,#EAF1F7 70%,#F8EBED 100%); color:var(--m-ink); margin:-24px -16px 0; padding:24px 16px; min-height:calc(100vh - 56px) }
.kat-inner{margin:0 auto}
.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:20px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.kat-back{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:8px 14px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-ink);text-decoration:none}
.mono{font-family:'Satoshi',system-ui,sans-serif}
.att-state{display:flex;flex-direction:column;align-items:center;text-align:center;padding:12px 8px}
.att-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:18px;border:1px solid}
.att-icon.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
.att-icon.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.att-icon.warn{background:#fffbeb;color:#92400e;border-color:#fde68a}
.att-spin{width:28px;height:28px;border:3px solid #E0E5E3;border-top-color:var(--m-cta);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.kat-btn{font-family:'Satoshi',system-ui,sans-serif}
.kat-btn-ghost{font-family:'Satoshi',system-ui,sans-serif;padding:10px 12px;border-radius:12px;border:1px solid var(--m-line);background:#fff;color:var(--m-ink);font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.kat-btn-ghost.neutral{border-color:var(--m-line);color:var(--m-ink)}
</style>
