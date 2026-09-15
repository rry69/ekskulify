<template>
  <div class="max-w-[720px] mx-auto px-4 py-6">
    <button
      class="text-xs px-3 py-1.5 rounded-full border inline-flex items-center gap-1"
      @click="$router.back()"
    >
      &lt;- Kembali
    </button>

    <div v-if="loading" class="mt-6 bg-white rounded-[20px] border p-6 animate-pulse h-32"></div>

    <div v-else-if="user" class="mt-6 bg-white rounded-[20px] border border-slate-200 overflow-hidden">
      <div class="p-6 flex gap-4 items-center">
        <img
          v-if="safeFoto(user.foto_url)"
          :src="safeFoto(user.foto_url)"
          class="w-16 h-16 rounded-full object-cover border border-slate-200"
          alt="avatar"
        />
        <div
          v-else
          class="w-16 h-16 rounded-full grid place-items-center font-bold text-white shrink-0"
          style="background:#2F3E46"
          aria-hidden="true"
        >{{ initials(user.nama) }}</div>
        <div>
          <h1 class="font-bold text-[18px]">{{ user.nama }}</h1>
          <p class="text-xs text-slate-500">{{ user.email }} - {{ user.role }}</p>
          <p class="text-xs text-slate-400 mt-1">{{ user.ekskul_count }} ekskul - {{ user.posts_count }} postingan</p>
          <span class="inline-flex mt-2 text-[11px] px-2 py-1 rounded-full bg-slate-900 text-white">{{ user.role }}</span>
          <router-link v-if="auth.user?.id === user.id" to="/pengaturan" class="inline-flex ml-2 mt-2 text-[11px] px-2 py-1 rounded-full border border-slate-300 hover:bg-slate-50">Pengaturan</router-link>
        </div>
      </div>

      <div class="px-6 pb-6 text-xs text-slate-500">
        <div>Bergabung: {{ user.created_at }}</div>
        <div v-if="user.kelas">Kelas: {{ user.kelas }}</div>
        <div v-if="user.nip">NIP: {{ user.nip }}</div>
      </div>
    </div>

    <div v-else class="mt-6 text-center text-sm text-slate-400">User tidak ditemukan</div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../lib/api.js'
import { useAuth } from '../stores/auth.js'

const auth = useAuth()

const route = useRoute()
const user = ref(null)
const loading = ref(true)

function initials(s){ return (s||'?').slice(0,2).toUpperCase() }
// allowlist: cegah javascript:/data: XSS — hanya /api/…, /uploads/…, atau http(s)
function safeFoto(u){
  if(typeof u !== 'string') return null
  const s = u.trim()
  if(/^\/api\//.test(s) || /^\/uploads\//.test(s)) return s
  if(/^https?:\/\/[^\s"'<>]+$/.test(s)) return s
  return null
}

onMounted(async () => {
  try {
    const j = await api('/u/' + route.params.id)
    user.value = j.data
    try{ const _n=String(j.data.nama||'').trim(); document.title=(_n?_n.slice(0,40):'Profil')+' | Eskulify' }catch{}
  } catch {
    // ignore
  } finally {
    loading.value = false
  }
})
</script>
