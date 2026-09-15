import { defineStore } from 'pinia'
import { api, setCsrf, getCsrf, onAuthFail } from '../lib/api.js'

let _authFailRegistered = false

export const useAuth=defineStore('auth',{
  state:()=>({ user:null, loading:true, _mePromise:null }),
  actions:{
    me(){
      // single-flight: request in-progress → return promise yang sama
      if(this._mePromise) return this._mePromise
      this._mePromise = (async()=>{
        try{ const j=await api('/auth/me'); this.user=j.data.user; setCsrf(j.data.csrf) }
        catch{ this.user=null }
        finally{ this.loading=false; this._mePromise=null }
        return this.user
      })()
      return this._mePromise
    },
    async login(email,password,remember=false){
      await getCsrf()
      const j=await api('/auth/login',{method:'POST',body:{email,password,remember}})
      this.user=j.data.user; setCsrf(j.data.csrf)
    },
    async logout(){
      try{ await api('/auth/logout',{method:'POST',body:{}}) }catch{}
      this.user=null; setCsrf(null)
    },
    reset(){ this.user=null; setCsrf(null) }
  }
})

if(!_authFailRegistered){
  _authFailRegistered = true
  onAuthFail(()=>{
    const auth = useAuth()
    auth.reset()
  })
}
