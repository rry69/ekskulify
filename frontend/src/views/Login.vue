<template>

 <div class="login-aura">

 <div class="login-center">

 <div class="login-shell">

 <!-- HERO: desktop kanan, mobile atas - carousel multi-foto dari admin -->

  <div class="login-hero" :class="{ 'has-media': slides.length > 0 }">

  <div v-if="slides.length" class="hero-slides" aria-hidden="true" @mouseenter="pause" @mouseleave="resume">

  <template v-for="(s,i) in slides" :key="s.url">

  <a v-if="s.link" :href="s.link" target="_blank" rel="noopener" class="hero-slide-link" :class="{ active: i === cur }" :aria-label="s.alt || ('Foto hero ' + (i + 1))">

  <img :src="s.url" :alt="s.alt || ''" loading="eager" decoding="async" class="hero-slide" :class="{ active: i === cur }" @error="onImgErr(s.url)" />

  </a>

  <img v-else :src="s.url" :alt="s.alt || ''" loading="eager" decoding="async"

  class="hero-slide" :class="{ active: i === cur }" @error="onImgErr(s.url)" />

 </template>

 </div>

 <div class="hero-shade" :style="{opacity: overlay}" aria-hidden="true"></div>

 <div class="hero-body">

 <span class="hero-tag" v-if="hero.tag" v-text="hero.tag"></span>

 <h2 class="hero-title" v-text="hero.title"></h2>

 <p class="hero-desc" v-text="hero.desc"></p>

 <div class="hero-badges">

 <span class="badge" v-for="b in hero.badges" :key="b" v-text="b"></span>

 </div>

  <div v-if="slides.length > 1" class="hero-dots" role="tablist" aria-label="Foto hero">

  <button v-for="(s,i) in slides" :key="'d' + s.url" class="dot" :class="{ active: i === cur }"

  :aria-label="'Foto ' + (i + 1) + (s.alt ? ' - ' + s.alt : '')" @click="go(i)" @mouseenter="pause" @mouseleave="resume"></button>

 </div>

 </div>

 </div>



 <!-- FORM: desktop kiri, mobile bawah -->

 <div class="login-form">

 <h1 class="greet" v-text="hero.greet"></h1>

 <p class="caption" style="margin-top:6px;line-height:1.6">

 <span v-text="hero.sub"></span><br>

 <span class="seed">Kredensial demo: admin / budi / andi / kepsek @sekolah.test - kata sandi <b>password123</b></span>

 </p>



  <div class="fields">

  <div v-if="route.query.reason==='auth'" class="auth-banner" role="alert"><Icon icon="mingcute:lock-line" width="18" height="18" /><span>Silakan login dulu untuk mengakses halaman tersebut.</span></div>

  <div class="field">

  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>

 <input class="inp" v-model="email" placeholder="Surel - cth. andi@sekolah.test" autocomplete="email" inputmode="email" aria-label="Email" />

 </div>

 <div class="field">

  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15" r="1.5"/></svg>

 <input class="inp" type="password" v-model="password" placeholder="Kata Sandi" autocomplete="current-password" aria-label="Password" @keyup.enter="doLogin" />

 </div>



 <label class="remember">

 <input type="checkbox" v-model="remember" />

 <span>Ingat saya (30 hari)</span>

  <a href="#" class="link" style="margin-left:auto" @click.prevent="showForgot=true; forgotMsg=''; forgotErr=''"> Lupa kata sandi?</a>

 </label>



 <button class="btn btn-primary" @click="doLogin" :disabled="loading" :aria-busy="loading">

 {{ loading ? 'Memproses...' : 'Masuk' }}

 </button>



 <div v-if="err" class="err" role="alert">{{ err }}</div>



 <p class="caption" style="text-align:center;margin-top:10px">Dengan masuk, Anda menyetujui tata tertib ekstrakurikuler yang berlaku.</p>



 <div class="form-foot">

 <span class="caption">Belum memiliki akun? Silakan hubungi pembina ekstrakurikuler.</span>

 </div>

 <div class="verify-row">

 <router-link to="/verify" class="link-verify">Verifikasi sertifikat</router-link>

 </div>

 <!-- LUPA PASSWORD: modal permintaan reset (tanpa SMTP) -->
 <Teleport to="body">
 <div v-if="showForgot" class="fixed inset-0 z-50">
 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showForgot=false"></div>
 <div class="absolute inset-0 grid place-items-center p-4">
 <div class="w-full max-w-[420px] rounded-[20px] bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden" style="border:1px solid #E0E5E3">
 <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid #E0E5E3">
 <h2 class="text-[16px] font-bold" style="color:#2F3E46">Lupa Kata Sandi</h2>
 <button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showForgot=false" aria-label="Tutup">x</button>
 </div>
 <div class="p-6">
 <div v-if="!forgotMsg">
 <p class="caption" style="line-height:1.7">Tenang, hal seperti ini biasa terjadi. Masukkan surel akunmu — permintaanmu akan diteruskan ke admin, dan admin akan membantu mereset password-mu.</p>
 <div class="field" style="margin-top:12px">
 <input class="inp" v-model="forgotEmail" placeholder="Surel - cth. andi@sekolah.test" autocomplete="email" inputmode="email" aria-label="Email untuk reset" @keyup.enter="doForgot" />
 </div>
 <div v-if="forgotErr" class="err" role="alert">{{ forgotErr }}</div>
 <button class="btn btn-primary" @click="doForgot" :disabled="forgotLoading" :aria-busy="forgotLoading" style="margin-top:12px">{{ forgotLoading ? 'Mengirim...' : 'Kirim Permintaan ke Admin' }}</button>
 </div>
 <div v-else class="forgot-ok" role="status">
 <p class="caption" style="line-height:1.7">{{ forgotMsg }}</p>
 <button class="btn btn-primary" @click="showForgot=false" style="margin-top:12px">Tutup</button>
 </div>
 </div>
 </div>
 </div>
 </div>
 </Teleport>

 </div>

 </div>

 </div>



 <p class="caption" style="text-align:center;padding:10px 0 6px">Navigasi keyboard: gunakan tombol <b style="color:var(--ink)">Tab</b> - Verifikasi sertifikat dapat diakses tanpa masuk</p>

 </div>

 </div>

</template>



<script setup>

import { reactive, ref, onMounted, onUnmounted } from 'vue'

import { useAuth } from '../stores/auth.js'

import { useRouter, useRoute } from 'vue-router'

import { Icon } from '@iconify/vue'

import { api } from '../lib/api.js'

// ponytail: hero carousel multi-foto (max 5, fade, interval admin 2-10s, overlay 0-0.8, alt+link per slide) via /login-settings publik; aura gradient tokenized; fallback default bila fetch gagal

const FALLBACK={tag:'',title:'Belajar, Berkarya, dan Bertumbuh Bersama.',desc:'Sistem informasi ekstrakurikuler terpadu - presensi QR, kalender kegiatan terpusat, dan persetujuan berjenjang. Tertib, transparan, dan terdata.',badges:['Kuota Tersedia - 18/20','Kalender Terpusat','Presensi QR'],greet:'Selamat Datang',sub:'Silakan masuk untuk mengakses layanan ekstrakurikuler dan kegiatan sekolah.'}

const hero=reactive({...FALLBACK})

const slides=ref([]) // [{url, alt, link}]

const cur=ref(0)

const intervalSec=ref(4)

const overlay=ref(0.42)

let timer=null, paused=false

function startTimer(){

 stopTimer()

 if(slides.value.length<2) return

  timer=setInterval(()=>{ if(!paused){ cur.value=(cur.value+1)%slides.value.length } }, intervalSec.value*1000)

}

function stopTimer(){ if(timer){ clearInterval(timer); timer=null } }

function go(i){ cur.value=i; startTimer() }

function pause(){ paused=true }

function resume(){ paused=false }

function onImgErr(url){ slides.value=slides.value.filter(s=>s.url!==url); if(cur.value>=slides.value.length) cur.value=0; startTimer() }

onMounted(async()=>{

 try{

 const r=await fetch('/api/login-settings',{credentials:'omit'})

 if(!r.ok) return

 const j=await r.json().catch(()=>null)

 const d=j?.data

 if(!d) return

 const s=(v,f)=>{ v=String(v??f); return v.length>500?v.slice(0,500):v }

 hero.tag=s(d.tag,FALLBACK.tag).slice(0,200)

 hero.title=s(d.title,FALLBACK.title).slice(0,200)

 hero.desc=s(d.desc,FALLBACK.desc)

 hero.greet=s(d.greet,FALLBACK.greet).slice(0,200)

 hero.sub=s(d.sub,FALLBACK.sub)

 if(Array.isArray(d.badges)) hero.badges=d.badges.map(x=>String(x).slice(0,60)).filter(Boolean).slice(0,6)

 // Opsi B: d.slides [{url,alt,link}] preferred; fallback media_urls legacy

 let list=[]

 if(Array.isArray(d.slides) && d.slides.length){

  list=d.slides.map(x=>({url:String(x.url||''), alt:String(x.alt||'').slice(0,120), link:String(x.link||'')})).filter(x=>x.url.startsWith('/api/login-hero'))

 } else {

 let urls=[]

 if(Array.isArray(d.media_urls)) urls=d.media_urls

 else if(d.has_media && typeof d.media_url==='string' && d.media_url.startsWith('/api/login-hero')) urls=[d.media_url]

 const meta=d.meta||{}

 list=urls.filter(u=>typeof u==='string' && u.startsWith('/api/login-hero')).slice(0,5).map(u=>{

  const base=decodeURIComponent((u.split('f=')[1]||'').split('&')[0])

 const m=meta[base]||{}

  return {url:u, alt:String(m.alt||'').slice(0,120), link:String(m.link||'')}

 })

 }

 slides.value=list.slice(0,5)

 const iv=parseInt(d.interval,10)

  if(iv>=2 && iv<=10) intervalSec.value=iv
  const ov=parseFloat(d.overlay)
  if(!isNaN(ov) && ov>=0 && ov<=0.8) overlay.value=Math.round(ov*100)/100
  startTimer()
  }catch{}
  })
   onUnmounted(stopTimer)
   const email=ref('admin@sekolah.test'), password=ref('password123'), err=ref(''), loading=ref(false), remember=ref(true)
   const auth=useAuth(), router=useRouter()
   const route = useRoute()
   function safeRedirect(v){
     const s = String(v||'').trim()
     if(!s) return '/'
     if(!s.startsWith('/')) return '/'
     if(s.startsWith('//')) return '/'
     if(s.includes('://')) return '/'
     if(s.includes('\\')) return '/'
     return s
   }
   async function doLogin(){ err.value=''; loading.value=true; try{ await auth.login(email.value,password.value,remember.value); const dest = safeRedirect(route.query.redirect || route.query.next || route.query.returnTo || ''); router.push(dest || '/') }catch(e){ err.value=e.error?.message||'Gagal login' } finally{ loading.value=false } }

   // LUPA PASSWORD (tanpa SMTP): siswa kirim email -> BE notify admin; respons generik anti-enumerasi
   const showForgot=ref(false), forgotEmail=ref(''), forgotLoading=ref(false), forgotErr=ref(''), forgotMsg=ref('')
   async function doForgot(){
     forgotErr.value=''; forgotMsg.value=''
     const em=String(forgotEmail.value||'').trim()
     if(!em || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)){ forgotErr.value='Masukkan surel yang valid.'; return }
     forgotLoading.value=true
     try{
       const j=await api('/auth/forgot-password',{method:'POST',body:{email:em}})
       forgotMsg.value=j?.message||'Tenang, permintaanmu sudah diteruskan ke admin. Admin akan segera membantu mereset password-mu.'
     }catch(e){ forgotErr.value=e?.error?.message||e?.message||'Gagal mengirim permintaan' }
     finally{ forgotLoading.value=false }
   }

</script>



<style scoped>

/* aura gradient tokenized - layered blend modes ala auragradients, palet DESIGN-SYSTEM saja (sand/green/blue/pink), tanpa blob div, tanpa neon */

.login-aura{

 min-height:calc(100vh - 0px);

 background:

 radial-gradient(120% 90% at 15% 10%, rgba(167,199,231,.35), transparent 55%),

 radial-gradient(110% 85% at 85% 20%, rgba(201,127,139,.28), transparent 55%),

 radial-gradient(130% 100% at 50% 100%, rgba(91,136,112,.30), transparent 60%),

 var(--bg,#F4F1EC);

 background-blend-mode:multiply,multiply,multiply,normal;

}

/* center vertikal+horizontal desktop/tablet; mobile tetap atas (stacked) */

.login-center{max-width:1280px;margin:0 auto;padding:24px 16px;min-height:100vh;display:flex;flex-direction:column;justify-content:center}

.login-shell{

 display:grid;grid-template-columns:420px 1fr;

 border:1px solid var(--hair,#D8CCB8);

 background:var(--bg,#F4F1EC);

 overflow:hidden;

 min-height:520px;

}

.login-hero{

 position:relative;display:flex;flex-direction:column;justify-content:flex-end;

 padding:22px 20px;gap:10px;

 background:linear-gradient(180deg,#CBD8CF 0%, #9DB5A3 100%);

 order:2; /* desktop kanan */

 overflow:hidden;

}

.hero-slides{position:absolute;inset:0}

.hero-slide{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .8s ease}

.hero-slide.active{opacity:1}

.hero-slide-link{position:absolute;inset:0;display:block;opacity:0;transition:opacity .8s ease}

.hero-slide-link.active{opacity:1}

.hero-slide-link .hero-slide{opacity:1}

.hero-shade{position:absolute;inset:0;background:rgba(47,62,70,1)}

.hero-dots{display:flex;gap:6px;margin-top:4px;position:relative}

.dot{width:22px;height:4px;border-radius:999px;border:none;background:rgba(255,255,255,.45);cursor:pointer;padding:0}

.dot.active{background:#fff}

.has-media .hero-body{position:relative}

.has-media .hero-tag{background:rgba(47,62,70,.9)}

.has-media .hero-title{color:#fff;text-shadow:0 1px 12px rgba(0,0,0,.35)}

.has-media .hero-desc{color:#fff;opacity:.92}

.hero-body{display:flex;flex-direction:column;gap:10px;position:relative}

.hero-tag{font-family:'Satoshi',system-ui,sans-serif;font-size:11px;font-weight:600;background:var(--ink,#2F3E46);color:var(--paper,#F9F5F4);padding:7px 12px;border-radius:999px;align-self:flex-start}

.hero-title{font-family:'Satoshi',system-ui,sans-serif;font-style:italic;font-size:32px;line-height:1.05;white-space:pre-line;color:var(--ink,#2F3E46)}

.hero-desc{font-size:12px;color:#2f3e46;opacity:.72;line-height:1.6;max-width:36ch}

.hero-badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:2px}

.badge{font-family:'Satoshi',system-ui,sans-serif;font-size:11px;font-weight:600;padding:6px 10px;border-radius:999px;border:1px solid var(--hair,#D8CCB8);background:rgba(249,245,244,.92);color:var(--ink,#2F3E46)}

.has-media .badge{background:rgba(255,255,255,.95);color:#2F3E46;border-color:rgba(255,255,255,.92);box-shadow:0 1px 10px rgba(0,0,0,.18);backdrop-filter:blur(4px)}



.login-form{

 padding:28px 24px;background:var(--bg,#F4F1EC);

 display:flex;flex-direction:column;justify-content:center;

 order:1; /* desktop kiri */

 border-right:1px solid var(--hair,#D8CCB8);

}

.greet{font-family:'Satoshi',system-ui,sans-serif;font-style:italic;font-size:28px;line-height:1;color:var(--ink)}

.caption{font-family:'Satoshi',system-ui,sans-serif;font-size:11px;color:var(--muted,#758586)}

.link{font-family:'Satoshi',system-ui,sans-serif;font-size:11px;color:var(--muted);text-decoration:underline;text-underline-offset:2px}

.seed b{color:var(--ink);font-weight:600}

.fields{margin-top:16px}

.auth-banner{display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:10px 12px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;font-family:'Satoshi',system-ui,sans-serif;font-size:12px;border-radius:12px}

.field{position:relative;margin-bottom:12px}

.field svg{position:absolute;left:13px;top:15px;opacity:.45}

.inp{height:48px;border:none;border-bottom:1.5px solid var(--hair,#D8CCB8);padding:0 14px 0 40px;font-family:'Satoshi',system-ui,sans-serif;font-size:13px;width:100%;background:transparent;color:var(--ink);outline:none}

.inp:focus{border-bottom-color:var(--green,#5B8870)}

.remember{display:flex;align-items:center;gap:8px;margin:6px 0 16px;font-family:'Satoshi',system-ui,sans-serif;font-size:12px;color:var(--ink)}

.remember input{accent-color:var(--ink)}

.btn{height:48px;border-radius:999px;font-family:'Satoshi',system-ui,sans-serif;font-weight:600;font-size:14px;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:100%}

.btn:disabled{opacity:.6;cursor:not-allowed}

.btn-primary{background:var(--ink,#2F3E46);color:var(--paper,#F9F5F4)}

.btn-primary:active{transform:scale(.98)}

.err{margin-top:10px;padding:10px 12px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-family:'Satoshi',system-ui,sans-serif;font-size:12px;border-radius:12px}

.form-foot{margin-top:14px;padding-top:12px;border-top:1px solid var(--hair,#D8CCB8)}

.verify-row{margin-top:10px;text-align:center}

.link-verify{font-family:'Satoshi',system-ui,sans-serif;font-size:12px;color:var(--ink,#2F3E46);text-decoration:underline;text-underline-offset:3px}

.link-verify:hover{color:var(--green,#5B8870)}



/* MOBILE / TABLET: split atas-bawah (hero atas, form bawah) - center hanya desktop/tablet landscape */

@media(max-width:900px){

 .login-center{min-height:auto;justify-content:flex-start;padding:0}

 .login-aura{background:var(--bg,#F4F1EC)}

 .login-shell{grid-template-columns:1fr;border-left:none;border-right:none}

 .login-hero{order:1;min-height:168px;border-bottom:1px solid var(--hair)}

 .has-media.login-hero{min-height:220px}

 .login-form{order:2;border-right:none}

 .hero-title{font-size:26px}

}

@media(max-width:390px){

 .login-form{padding:20px 16px}

}

@media(prefers-reduced-motion:reduce){

 .hero-slide,.hero-slide-link{transition:none}

}

</style>

