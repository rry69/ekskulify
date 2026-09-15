import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '../stores/auth.js'
import { sessionIntegrityBoot } from '../lib/sessionIntegrity.js'

const FALLBACK_TITLE='Eskulify - Manajemen Ekskul & Event'
const routes=[
  {path:'/login', component:()=>import('../views/Login.vue'), meta:{title:'Masuk'}},
  {path:'/', component:()=>import('../views/Dashboard.vue'), meta:{auth:true,title:'Dashboard'}},
  {path:'/ekskul', component:()=>import('../views/Katalog.vue'), meta:{auth:true,title:'Ekskul'}},
  {path:'/katalog', redirect:'/ekskul', meta:{title:'Ekskul'}},
  {path:'/ekskul/:id', component:()=>import('../views/EkskulDetail.vue'), meta:{auth:true,title:'Ekskul'}},
  {path:'/kalender', component:()=>import('../views/Kalender.vue'), meta:{auth:true,title:'Kalender'}},
  {path:'/events', component:()=>import('../views/Events.vue'), meta:{auth:true,title:'Event'}},
  {path:'/events/:id/edit', component:()=>import('../views/EventEdit.vue'), meta:{auth:true,role:['admin'],title:'Edit Event'}},
  {path:'/events/:id', component:()=>import('../views/EventDetail.vue'), meta:{auth:true,title:'Event'}},
  {path:'/ekskul/:id/d/:postId', component:()=>import('../views/EkskulDetailPost.vue'), meta:{auth:true,title:'Postingan'}},
  {path:'/ekskul/:id/t/:postId', component:()=>import('../views/EkskulDetailPost.vue'), meta:{auth:true,title:'Postingan'}},
  {path:'/ekskul/:id/p/:postId', component:()=>import('../views/EkskulDetailPost.vue'), meta:{auth:true,title:'Postingan'}},
  {path:'/u/:id', component:()=>import('../views/Profile.vue'), meta:{auth:true,title:'Profil'}},
  {path:'/admin/files', component:()=>import('../views/AdminFiles.vue'), meta:{auth:true,role:['admin'],title:'File'}},
  {path:'/saya', component:()=>import('../views/Saya.vue'), meta:{auth:true,role:['siswa'],title:'Saya'}},
  {path:'/pengaturan', component:()=>import('../views/Pengaturan.vue'), meta:{auth:true,title:'Pengaturan'}},
  {path:'/scan', component:()=>import('../views/ScanQR.vue'), meta:{auth:true,role:['siswa'],title:'Scan'}},
  // legacy kelola → gabung ke katalog/events
  {path:'/admin/ekskul', redirect:'/ekskul', meta:{title:'Ekskul'}},
  {path:'/admin/events', redirect:'/events', meta:{title:'Event'}},
  // laporan per-role (requirement: tiap role url berbeda)
  {path:'/admin/laporan', component:()=>import('../views/Laporan.vue'), meta:{auth:true,role:['admin'],title:'Laporan'}},
  {path:'/kepsek/laporan', component:()=>import('../views/Laporan.vue'), meta:{auth:true,role:['kepsek'],title:'Laporan'}},
  {path:'/pembina/laporan', component:()=>import('../views/Laporan.vue'), meta:{auth:true,role:['pembina'],title:'Laporan'}},
  // legacy universal → arahkan ke url per-role sesuai login; preserve query
  {path:'/laporan', redirect: to=> {
    try{
      const auth = useAuth()
      const r = auth.user?.role
      if(r==='kepsek') return {path:'/kepsek/laporan', query: to.query}
      if(r==='pembina') return {path:'/pembina/laporan', query: to.query}
      if(r==='admin') return {path:'/admin/laporan', query: to.query}
    }catch{}
    return {path:'/admin/laporan', query: to.query}
  }, meta:{title:'Laporan'}},
  {path:'/admin/users', component:()=>import('../views/AdminUsers.vue'), meta:{auth:true,role:['admin'],title:'Pengguna'}},
  {path:'/admin/users/:id/reset', component:()=>import('../views/AdminUserReset.vue'), meta:{auth:true,role:['admin'],title:'Reset Password'}},
  {path:'/admin/sertifikat', component:()=>import('../views/AdminSertifikat.vue'), meta:{auth:true,role:['admin'],title:'Sertifikat'}},
  {path:'/admin/sertifikat/sesi/:tipe/:id', component:()=>import('../views/SesiCetak.vue'), meta:{auth:true,role:['admin'],title:'Cetak'}},
  {path:'/admin/login-hero', component:()=>import('../views/AdminLoginHero.vue'), meta:{auth:true,role:['admin'],title:'Banner'}},
  {path:'/verify/:hash?', component:()=>import('../views/Verify.vue'), meta:{title:'Verifikasi'}},
  {path:'/reset-password', component:()=>import('../views/ResetPassword.vue'), meta:{title:'Reset Password'}},
  {path:'/kepsek/approval', component:()=>import('../views/Approval.vue'), meta:{auth:true,role:['kepsek'],title:'Persetujuan'}},
  // QR absensi deep-link: scan dari luar -> deep link valid URL, handle belum login di komponen
  {path:'/events/:id/attendance/:sid', component:()=>import('../views/EventAttendance.vue'), meta:{auth:true,title:'Absensi'}},
  // error pages (tanpa meta.auth: tampil untuk guest juga)
  {path:'/403', component:()=>import('../views/ErrorPage.vue'), props:{code:'403',title:'Akses Ditolak',message:'Role kamu tidak punya izin untuk membuka halaman ini.'}, meta:{error:true,title:'Tidak Ditemukan'}},
  {path:'/500', component:()=>import('../views/ErrorPage.vue'), props:{code:'500',title:'Terjadi Kesalahan',message:'Ada error fatal di aplikasi. Coba muat ulang atau kembali nanti.'}, meta:{error:true,title:'Tidak Ditemukan'}},
  {path:'/:pathMatch(.*)*', component:()=>import('../views/ErrorPage.vue'), props:{code:'404',title:'Halaman Tidak Ditemukan',message:'URL yang kamu tuju tidak ada atau sudah dipindahkan.'}, meta:{error:true,title:'Tidak Ditemukan'}},
]

const router=createRouter({history:createWebHistory(),routes})
router.beforeEach(async(to)=>{
  const auth=useAuth()
  // Session integrity (SRE anti session-fixation): token sesi harus siap
  // sebelum /auth/me — tanpanya balas 403 INTEGRITY. Jangan hapus await ini.
  if(to.meta.auth && auth.loading) { try{ await sessionIntegrityBoot() }catch{} await auth.me() }
  if(to.meta.auth && !auth.user) return { path:'/login', query:{ redirect: to.fullPath, reason:'auth' } }
  // role mismatch -> 403 (menggantikan perilaku lama: redirect '/' atau laporan per-role)
  if(to.meta.role && !to.meta.role.includes(auth.user?.role)) return { path:'/403' }
})
router.afterEach((to)=>{
  const t=to.meta?.title
  document.title=t?`${t} | Eskulify`:FALLBACK_TITLE
})
export default router
