import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router/index.js'
import App from './App.vue'
import { sessionIntegrityBoot } from './lib/sessionIntegrity.js'
const app = createApp(App)
// error fatal -> /500 sekali saja (hindari loop jika sudah di /500)
app.config.errorHandler = () => { if (router.currentRoute.value.path !== '/500') router.push('/500') }
// Session integrity (SRE anti session-fixation): token per sesi HARUS
// dijemput sebelum auth.me() pertama (router guard + App onMounted).
// Tanpa ini /auth/me balas 403 INTEGRITY dan seluruh user login mental
// ke /login. Jangan hapus baris boot berikut.
sessionIntegrityBoot().catch(()=>{})
app.use(createPinia()).use(router).mount('#app')
