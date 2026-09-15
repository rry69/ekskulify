<template>
<div class="login-aura">
<div class="login-center">
<div class="login-shell" style="grid-template-columns:1fr;max-width:480px;margin:0 auto;width:100%">
<div class="login-form" style="border-right:none">
<h1 class="greet">Buat Password Baru</h1>
<p class="caption" style="margin-top:6px;line-height:1.6">Link ini hanya bisa dipakai sekali dan kadaluarsa 30 menit. Tenang, kamu hampir selesai.</p>
<div class="fields">
<div v-if="checking" class="caption">Memeriksa link...</div>
<div v-else-if="invalid" class="err" role="alert">{{ invalid }}<div style="margin-top:10px"><router-link to="/login" class="link-verify">Kembali ke login</router-link></div></div>
<div v-else>
<div class="field">
<input class="inp" type="password" v-model="p1" placeholder="Password baru (min 8)" autocomplete="new-password" aria-label="Password baru" />
</div>
<div class="field">
<input class="inp" type="password" v-model="p2" placeholder="Konfirmasi password baru" autocomplete="new-password" aria-label="Konfirmasi password" @keyup.enter="doConfirm" />
</div>
<div v-if="err" class="err" role="alert">{{ err }}</div>
<button class="btn btn-primary" @click="doConfirm" :disabled="loading" :aria-busy="loading">{{ loading ? 'Menyimpan...' : 'Simpan Password Baru' }}</button>
<div v-if="done" class="forgot-ok" role="status" style="margin-top:12px">
<p class="caption" style="line-height:1.7">{{ done }}</p>
<div style="margin-top:10px"><router-link to="/login" class="link-verify">Masuk sekarang</router-link></div>
</div>
</div>
</div>
</div>
</div>
</div>
</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../lib/api.js'

const route = useRoute()
const token = String(route.query.token || '').trim()
const checking = ref(true), invalid = ref(''), err = ref(''), done = ref(''), loading = ref(false)
const p1 = ref(''), p2 = ref('')

onMounted(async ()=>{
  if(!token || !/^[a-f0-9]{64}$/i.test(token)){ checking.value=false; invalid.value='Link tidak valid. Minta link baru ke admin.'; return }
  try{
    await api('/auth/reset-info?token='+encodeURIComponent(token))
    checking.value=false
  }catch(e){
    checking.value=false
    invalid.value=e?.error?.message||'Link kadaluarsa atau sudah dipakai. Minta link baru ke admin.'
  }
})

async function doConfirm(){
  err.value=''; done.value=''
  if(String(p1.value||'').length<8){ err.value='Password minimal 8 karakter.'; return }
  if(p1.value!==p2.value){ err.value='Konfirmasi tidak cocok.'; return }
  loading.value=true
  try{
    const j=await api('/auth/reset-confirm',{method:'POST',body:{token, password:p1.value, konfirmasi:p2.value}})
    done.value=j?.message||'Password berhasil diganti, silakan login.'
    p1.value=''; p2.value=''
  }catch(e){ err.value=e?.error?.message||e?.message||'Gagal menyimpan password' }
  finally{ loading.value=false }
}
</script>

<style scoped>
.login-aura{min-height:100vh;background:radial-gradient(120% 90% at 15% 10%, rgba(167,199,231,.35), transparent 55%),radial-gradient(110% 85% at 85% 20%, rgba(201,127,139,.28), transparent 55%),radial-gradient(130% 100% at 50% 100%, rgba(91,136,112,.30), transparent 60%),var(--bg,#F4F1EC);background-blend-mode:multiply,multiply,multiply,normal}
.login-center{max-width:1280px;margin:0 auto;padding:24px 16px;min-height:100vh;display:flex;flex-direction:column;justify-content:center}
.login-shell{display:grid;border:1px solid var(--hair,#D8CCB8);background:var(--bg,#F4F1EC);overflow:hidden;min-height:320px}
.login-form{padding:28px 24px;background:var(--bg,#F4F1EC);display:flex;flex-direction:column;justify-content:center}
.greet{font-family:'Satoshi',system-ui,sans-serif;font-style:italic;font-size:28px;line-height:1;color:var(--ink)}
.caption{font-family:'Satoshi',system-ui,sans-serif;font-size:11px;color:var(--muted,#758586)}
.fields{margin-top:16px}
.field{position:relative;margin-bottom:12px}
.inp{height:48px;border:none;border-bottom:1.5px solid var(--hair,#D8CCB8);padding:0 14px;font-family:'Satoshi',system-ui,sans-serif;font-size:13px;width:100%;background:transparent;color:var(--ink);outline:none}
.inp:focus{border-bottom-color:var(--green,#5B8870)}
.btn{height:48px;border-radius:999px;font-family:'Satoshi',system-ui,sans-serif;font-weight:600;font-size:14px;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:100%}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn-primary{background:var(--ink,#2F3E46);color:var(--paper,#F9F5F4)}
.err{margin-top:10px;padding:10px 12px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-family:'Satoshi',system-ui,sans-serif;font-size:12px;border-radius:12px}
.link-verify{font-family:'Satoshi',system-ui,sans-serif;font-size:12px;color:var(--ink,#2F3E46);text-decoration:underline;text-underline-offset:3px}
.forgot-ok p{margin:0}
</style>
