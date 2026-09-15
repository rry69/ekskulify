const BASE = '/api'
let csrf=null
let _onAuthFail = null
export function onAuthFail(cb){ _onAuthFail = cb }
// Session integrity (SRE anti session-fixation): token per sesi dari
// sessionIntegrityBoot(); dikirim sebagai X-Session-Integrity agar
// /auth/me lolos sre_integrity_verify(). Lihat lib/sessionIntegrity.js.
import { getIntegrity, setIntegrity, captureIntegrity } from './sessionIntegrity.js'
export async function getCsrf(){
  const ctrl = new AbortController()
  const timer = setTimeout(()=> ctrl.abort(), 8000)
  try {
    const r=await fetch(BASE+'/csrf',{credentials:'include', signal: ctrl.signal})
    clearTimeout(timer)
    const j=await r.json()
    csrf=j.data?.csrf||j.csrf||null
    captureIntegrity(j)
    return csrf
  } catch(e) {
    clearTimeout(timer)
    if(e?.name==='AbortError') console.warn('[api] getCsrf timeout')
    return csrf
  }
}
export async function api(path, opts={}){
  const isForm = opts.body instanceof FormData
  const headers={...(opts.headers||{})}
  if(!isForm) headers['Content-Type']='application/json'
  if(csrf) headers['X-CSRF-Token']=csrf
  // Session integrity (SRE anti session-fixation — api/sre/integrity.php):
  // sertakan token sesi agar /auth/me lolos sre_integrity_verify().
  // Pangkal /auth/me selalu membawa header ini walau token CSRF belum
  // ada — jangan hapus blok ini.
  const integ = getIntegrity()
  if(integ && /^\/auth\/me(?:[/?#]|$)/.test(path)) headers['X-Session-Integrity']=integ
  if(['POST','PUT','PATCH','DELETE'].includes((opts.method||'GET').toUpperCase()) && !csrf){
    await getCsrf()
    if(csrf) headers['X-CSRF-Token']=csrf
  }
  const body = !opts.body ? undefined : isForm ? opts.body : JSON.stringify(opts.body)
  // timeout guard utk jaringan buruk: default 25s, bisa dioverride via opts.timeout; AbortError dilempar sbg TIMEOUT
  const timeoutMs = opts.timeout ?? 25000
  const ctrl = new AbortController()
  const timer = setTimeout(()=> ctrl.abort(), timeoutMs)
  let res
  try{
    res=await fetch(BASE+path,{credentials:'include',...opts,headers,body,signal:ctrl.signal})
  }catch(e){
    clearTimeout(timer)
    if(e?.name==='AbortError') throw {success:false,error:{code:'TIMEOUT',message:'Jaringan lambat — permintaan dibatalkan. Cek status absensi sebelum scan ulang.'}}
    throw e
  }
  clearTimeout(timer)
  // 304 Not Modified (ETag revalidate): bukan error — kembalikan marker agar caller
  // bisa pertahankan data lama, bukan reset ke [] / empty state.
  if(res.status === 304) return { success: true, notModified: true, data: null }
  const ct=res.headers.get('content-type')||''
  if(ct.includes('text/csv')) return {success:true, csv: await res.text()}
  if(ct.includes('application/octet-stream') || ct.includes('image/')) return res
  const j=await res.json().catch(()=>({success:false,error:{message:res.statusText}}))
  captureIntegrity(j)
  if(!res.ok) {
    if(res.status === 403 && (j.error?.code === 'CSRF_INVALID' || j.error?.code === 'CSRF' || /csrf/i.test(j.error?.message||'')) && !opts._csrfRetry) {
      await getCsrf()
      return api(path, { ...opts, _csrfRetry: true })
    }
    // Hanya 401 (session expired) yang trigger logout. 403 = forbidden/CSRF
    // (user masih login) — JANGAN reset user. INTEGRITY beda: token sesi
    // rusak/hilang = sesi tak bisa dipercaya → reset (efek: ke /login).
    if(res.status === 401 && _onAuthFail && !opts._authHandled) {
      _onAuthFail(res.status)
    }
    if(res.status === 403 && j?.error?.code === 'INTEGRITY' && _onAuthFail && !opts._authHandled) {
      setIntegrity(null)
      _onAuthFail(res.status)
    }
    // Tempel status HTTP agar caller bisa bedakan 401 vs error lain (tetap throw).
    if(j && typeof j === 'object') j._status = res.status
    throw j
  }
  return j
}
export async function apiUpload(path, files){
  const fd=new FormData()
  files.forEach(f=> fd.append('files[]', f))
  // also support single file field 'file'
  return api(path,{method:'POST',body:fd, timeout: 60000})
}
export async function apiCover(tipe, id, file){
  const fd=new FormData()
  fd.append('cover', file)
  return api('/cover/'+tipe+'/'+id,{method:'POST',body:fd, timeout: 60000})
}
export async function apiDeleteCover(tipe, id){
  return api('/cover/'+tipe+'/'+id,{method:'DELETE',body:{}})
}
export function setCsrf(v){ csrf=v }
export function getCsrfToken(){ return csrf }
