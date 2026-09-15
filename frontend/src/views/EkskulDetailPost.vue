<template>

<div class="max-w-[720px] mx-auto px-4 py-5">

  <router-link :to="'/ekskul/'+$route.params.id" class="text-xs px-3 py-1.5 rounded-full border inline-flex gap-1"><- Kembali ke ekskul</router-link>

 <div v-if="loading" class="mt-6 bg-white rounded-2xl border p-6 animate-pulse h-40"></div>

 <div v-else-if="post" class="mt-6 bg-white rounded-[20px] border border-slate-200 p-5">

 <div class="flex items-center gap-2">

  <router-link :to="'/u/'+post.user_id"><UserAvatar :user-id="post.user_id" :name="post.author_nama" size="w-8 h-8" /></router-link>

  <router-link :to="'/u/'+post.user_id" class="text-sm font-semibold hover:underline">{{ post.author_nama }}</router-link>

 <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-900 text-white">{{ post.tipe }}</span>

 <span class="ml-auto text-[11px] text-slate-400">{{ timeAgo(post.created_at) }}</span>

 </div>

 <h2 v-if="post.judul" class="font-bold text-[16px] mt-3">{{ post.judul }}</h2>

 <p class="text-[13px] text-slate-600 mt-2 whitespace-pre-wrap break-words">{{ post.isi }}</p>

 <div v-if="post.poll" class="mt-3 p-3 bg-[#F8FAF9] border border-slate-200 rounded-xl">
  <div class="text-xs font-semibold">{{ post.poll.question }}<span v-if="post.poll.is_closed||post.poll.closed_at" class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-slate-100">closed</span><span class="ml-2 text-[11px] text-slate-400">{{ post.poll.total_votes }} suara</span><button v-if="canClosePoll && !(post.poll.is_closed||post.poll.closed_at)" @click="closeDetailPoll" class="ml-2 text-[11px] text-amber-700 underline">Tutup</button></div>
  <div class="mt-2 space-y-1">
   <label v-for="o in post.poll.options" :key="o.id" class="flex items-center gap-2 text-xs" :class="((post.poll.is_closed||post.poll.closed_at)||post.poll.my_vote)?'opacity-70 cursor-default':'cursor-pointer'">
    <input type="radio" :name="'poll-detail-'+post.poll.id" :checked="post.poll.my_vote===o.id" :disabled="!!(post.poll.is_closed||post.poll.closed_at||post.poll.my_vote)" @change="voteDetailPoll(o.id)"/> {{ o.label }} <span class="ml-auto text-[11px] text-slate-500">{{ o.votes }} ({{ o.percent }}%)</span>
   </label>
  </div>
  <div v-for="o in post.poll.options" :key="'bar-'+o.id" class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1"><div class="h-full bg-[#4A7875]" :style="{width:o.percent+'%'}"></div></div>
 </div>

  <div v-if="post.uploads?.length" class="grid gap-2 mt-3" :class="post.uploads.length===1?'grid-cols-1':'grid-cols-2'">

  <a v-for="u in post.uploads" :key="u.id" :href="u.url" target="_blank" class="block"><img :src="u.url" class="w-full h-32 object-cover rounded-xl border cursor-pointer"/><div class="text-[11px] text-slate-500 mt-1">{{ u.original_name }} - {{ (u.size/1024).toFixed(1) }} KB - klik untuk unduh</div></a>

 </div>

 <div class="flex gap-2 mt-4">

  <button @click="toggleLike" :class="post.is_liked?'bg-rose-50 border-rose-200 text-rose-600':''" class="px-3 py-1.5 rounded-full border text-xs">{{ post.is_liked?'♥':'♡' }} {{ post.likes }}</button>

 <span class="text-xs text-slate-400 self-center">{{ post.comments_count }} komentar</span>

 </div>

 <!-- comments realtime -->

 <div class="mt-6 space-y-3">

 <h3 class="font-bold text-sm">Komentar ({{ comments.length }})</h3>

 <div class="flex gap-2 relative">

  <input v-model="newComment" @input="e=>onNewInput(e)" @keydown="onNewKeydown" placeholder="Tulis komentar... bisa @mention" class="flex-1 px-3 py-2 rounded-full border bg-slate-50 text-sm focus:bg-white focus:border-slate-900 outline-none"/>

   <button @click="sendComment" title="Kirim komentar" aria-label="Kirim komentar" class="px-4 py-2 rounded-full bg-[#4A7875] text-white text-sm font-semibold inline-flex items-center gap-1.5 shadow-sm hover:bg-[#3d6562] active:scale-95 transition-colors"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg> Kirim</button>

  <div v-if="mentionOpen && mentionTarget?.kind==='new' && mentionFiltered.length" class="absolute left-0 right-20 bottom-[44px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-40 overflow-auto z-10">

  <button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx ? 'bg-slate-900 text-white':'hover:bg-slate-50'" class="w-full text-left px-3 py-2 text-xs flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}</button>

 </div>

 </div>

 <div v-for="c in comments" :key="c.id" class="border rounded-2xl p-3">

  <div class="flex items-center gap-2"><router-link :to="'/u/'+c.user_id" class="text-xs font-semibold hover:underline">{{ c.author_nama }}</router-link><span class="text-[11px] text-slate-400">{{ timeAgo(c.created_at) }}</span>

  <button @click="likeComment(c)" :class="c.is_liked?'text-rose-600':''" class="ml-auto text-xs px-2 py-1 rounded-full border">{{ c.is_liked?'♥':'♡' }} {{ c.likes }}</button>

 <button v-if="canDelete(c)" @click="delComment(c)" class="text-xs text-red-600">Hapus</button>

 </div>

 <p class="text-xs text-slate-600 mt-1 whitespace-pre-wrap">{{ c.isi }}</p>

 <div v-if="c.uploads?.length" class="flex gap-2 mt-2"><a v-for="u in c.uploads" :key="u.id" :href="u.url" target="_blank" class="text-[11px] underline">{{ u.original_name }}</a></div>

 <div v-if="c.replies?.length" class="mt-2 ml-4 pl-3 border-l-2 border-slate-100 space-y-2">

 <div v-for="r in c.replies" :key="r.id" class="bg-slate-50 rounded-xl p-2">

  <div class="flex gap-2"><router-link :to="'/u/'+r.user_id" class="text-xs font-semibold">{{ r.author_nama }}</router-link><span class="text-[11px] text-slate-400">{{ timeAgo(r.created_at) }}</span><button @click="likeComment(r)" class="ml-auto text-xs">{{ r.is_liked?'♥':'♡' }} {{ r.likes }}</button></div>

 <p class="text-xs text-slate-600">{{ r.isi }}</p>

 </div>

 </div>

   <div class="flex gap-2 mt-2 relative"><input v-model="c.replyInput" @input="e=>onReplyInput(e,c)" @keydown="e=>onReplyKeydown(e,c)" placeholder="Balas @mention..." class="flex-1 px-3 py-1.5 rounded-full border border-slate-200 text-xs focus:bg-white focus:border-[#4A7875] outline-none"/><button @click="replyTo(c)" title="Kirim balasan" aria-label="Kirim balasan" class="px-3.5 py-1.5 rounded-full bg-[#4A7875] text-white text-xs font-semibold inline-flex items-center gap-1.5 shadow-sm hover:bg-[#3d6562] active:scale-95 transition-colors"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg> Balas</button>

  <div v-if="mentionOpen && mentionTarget?.kind==='reply' && mentionTarget?.c===c && mentionFiltered.length" class="absolute left-0 right-16 bottom-[36px] bg-white border border-slate-200 rounded-xl shadow-lg max-h-32 overflow-auto z-10"><button v-for="(u,i) in mentionFiltered" :key="u.user_id" @click="selectMention(u)" :class="i===mentionIdx ? 'bg-slate-900 text-white':'hover:bg-slate-50'" class="w-full text-left px-3 py-1.5 text-xs flex items-center gap-1.5"><span class="w-5 h-5 rounded-full bg-slate-200 flex items-center justify-center text-[10px]">{{ u.nama[0] }}</span>{{ u.nama }}</button></div>

 </div>

 </div>

 </div>

 </div>

 <div v-else class="mt-6 text-center text-sm text-slate-400">Post tidak ditemukan</div>

</div>

</template>

<script setup>

import { ref, computed, onMounted, nextTick } from 'vue'

import { useRoute } from 'vue-router'

import { useAuth } from '../stores/auth.js'

import { api } from '../lib/api.js'
import UserAvatar from '../components/UserAvatar.vue'

const route=useRoute(), auth=useAuth()

const post=ref(null), comments=ref([]), loading=ref(true), newComment=ref('')

const anggota=ref([]), mentionCache=ref([]), mentionOpen=ref(false), mentionQuery=ref(''), mentionIdx=ref(0), mentionTarget=ref(null), newMentionIds=ref([])

const mentionFiltered=computed(()=>{

 const q=mentionQuery.value.toLowerCase()

 const base=(anggota.value.length?anggota.value:mentionCache.value).slice(0,20)

 if(!q) return base.slice(0,8)

 return base.filter(u=> u.nama.toLowerCase().includes(q)).slice(0,8)

})

async function fetchMentionCache(q=''){

  try{ const j=await api('/ekskul/'+route.params.id+'/members?limit=20'+(q?'&q='+encodeURIComponent(q):'')); const rows=j.data||[]; if(!anggota.value.length) mentionCache.value=rows; return rows }catch{ return [] }

}

function openMentionFor(val,pos,target){

  const m=val.slice(0,pos).match(/@([A-Za-z0-9_ ]{0,20})$/)

  if(m){ mentionQuery.value=m[1]||''; mentionOpen.value=true; mentionIdx.value=0; mentionTarget.value=target

 if(!anggota.value.length) fetchMentionCache(mentionQuery.value).catch(()=>{})

 else if(mentionQuery.value) fetchMentionCache(mentionQuery.value).then(r=>{ if(r.length) mentionCache.value=r }).catch(()=>{})

 } else { mentionOpen.value=false; mentionTarget.value=null }

}

function onNewInput(e){ openMentionFor(newComment.value, e.target.selectionStart||0, {kind:'new', el:e.target}) }

function onNewKeydown(e){

 if(!mentionOpen.value||mentionTarget.value?.kind!=='new') return

 if(e.key==='ArrowDown'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value+1)%mentionFiltered.value.length }

 else if(e.key==='ArrowUp'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value-1+mentionFiltered.value.length)%mentionFiltered.value.length }

 else if(e.key==='Enter' && mentionFiltered.value[mentionIdx.value]){ e.preventDefault(); selectMention(mentionFiltered.value[mentionIdx.value]) }

 else if(e.key==='Escape'){ mentionOpen.value=false; mentionTarget.value=null }

}

function onReplyInput(e,c){ openMentionFor(c.replyInput||'', e.target.selectionStart||0, {kind:'reply', el:e.target, c}) }

function onReplyKeydown(e,c){

 if(!mentionOpen.value||mentionTarget.value?.kind!=='reply'||mentionTarget.value?.c!==c) return

 if(e.key==='ArrowDown'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value+1)%mentionFiltered.value.length }

 else if(e.key==='ArrowUp'){ e.preventDefault(); mentionIdx.value=(mentionIdx.value-1+mentionFiltered.value.length)%mentionFiltered.value.length }

 else if(e.key==='Enter' && mentionFiltered.value[mentionIdx.value]){ e.preventDefault(); selectMention(mentionFiltered.value[mentionIdx.value]) }

 else if(e.key==='Escape'){ mentionOpen.value=false; mentionTarget.value=null }

}

function selectMention(u){

 const t=mentionTarget.value; if(!t) return

 if(t.kind==='new'){

 const el=t.el, pos=el.selectionStart||0, val=newComment.value, m=val.slice(0,pos).match(/@([A-Za-z0-9_ ]{0,20})$/); if(!m) return

 const start=pos-m[0].length, ins='@'+u.nama+' '; newComment.value=val.slice(0,start)+ins+val.slice(pos)

 if(!newMentionIds.value.includes(u.user_id)) newMentionIds.value.push(u.user_id)

 mentionOpen.value=false; mentionTarget.value=null; nextTick(()=>{ el.focus(); el.selectionStart=el.selectionEnd=start+ins.length })

 } else {

 const el=t.el, c=t.c, pos=el.selectionStart||0, val=c.replyInput||'', m=val.slice(0,pos).match(/@([A-Za-z0-9_ ]{0,20})$/); if(!m) return

 const start=pos-m[0].length, ins='@'+u.nama+' '; c.replyInput=val.slice(0,start)+ins+val.slice(pos)

 c._replyMentionIds=c._replyMentionIds||[]; if(!c._replyMentionIds.includes(u.user_id)) c._replyMentionIds.push(u.user_id)

 mentionOpen.value=false; mentionTarget.value=null; nextTick(()=>{ el.focus(); el.selectionStart=el.selectionEnd=start+ins.length })

 }

}

function timeAgo(s){ if(!s) return ''; const d=new Date(s), diff=(Date.now()-d.getTime())/1000; if(diff<60) return 'baru saja'; if(diff<3600) return Math.floor(diff/60)+'m lalu'; if(diff<86400) return Math.floor(diff/3600)+' jam lalu'; return Math.floor(diff/86400)+' hari lalu' }

function canDelete(c){ return auth.user && (auth.user.id===c.user_id || auth.user.role==='admin') }

const canClosePoll=computed(()=> post.value?.poll && auth.user && (auth.user.role==='admin' || auth.user.id===post.value.user_id))
function toast2(msg){ try{ alert(msg) }catch{} }
async function voteDetailPoll(optId){
 const pl=post.value?.poll; if(!pl) return
 if(pl.is_closed||pl.closed_at){ toast2('Poll sudah ditutup'); return }
 if(pl.my_vote){ toast2('Sudah vote, tidak bisa diubah'); return }
 const prev={ options: JSON.parse(JSON.stringify(pl.options)), total_votes: pl.total_votes, my_vote: pl.my_vote }
 pl.my_vote=optId
 try{
   const j=await api('/polls/'+pl.id+'/vote',{method:'POST',body:{option_id:optId}})
   pl.options=j.data.options; pl.total_votes=j.data.total ?? j.data.total_votes; pl.my_vote=j.data.my_vote
   if(j.data?.is_closed) pl.is_closed=true
 }catch(ex){
   pl.options=prev.options; pl.total_votes=prev.total_votes; pl.my_vote=prev.my_vote
   const code=ex.error?.code
   if(code==='CLOSED'){ pl.is_closed=true; pl.closed_at=new Date().toISOString() }
   toast2(ex.error?.message||'Gagal vote')
 }
}
async function closeDetailPoll(){
 const pl=post.value?.poll; if(!pl) return
 if(!confirm('Tutup polling ini?')) return
 try{ await api('/polls/'+pl.id+'/close',{method:'POST',body:{}}); pl.is_closed=true; pl.closed_at=new Date().toISOString() }
 catch(ex){ toast2(ex.error?.message||'Gagal tutup poll') }
}

async function load(){

 loading.value=true; try{ const j=await api('/ekskul/'+route.params.id+'/posts/'+route.params.postId)

  post.value=j.data; comments.value=(j.data.comments||[]).map(c=>({...c, replyInput:c.replyInput||'', _replyMentionIds:[]}))
  try{ const _n=String(j.data.judul||j.data.isi||'').trim(); document.title=(_n?_n.slice(0,40):'Postingan')+' | Eskulify' }catch{}

 // fetch anggota for @

 try{ const a=await api('/ekskul/'+route.params.id+'/anggota'); anggota.value=a.data }catch{ try{ const m=await api('/ekskul/'+route.params.id+'/members?limit=20'); anggota.value=m.data; mentionCache.value=m.data }catch{ anggota.value=[] } }

 }catch{} finally{ loading.value=false }

}

async function toggleLike(){ const j=await api('/ekskul/'+route.params.id+'/posts/'+route.params.postId+'/like',{method:'POST',body:{}}); post.value.is_liked=j.data.liked; post.value.likes=j.data.likes }

async function sendComment(){

 const v=newComment.value.trim(); if(!v) return

 await api('/ekskul/'+route.params.id+'/posts/'+route.params.postId+'/comments',{method:'POST',body:{isi:v, mention_ids:newMentionIds.value}})

 newComment.value=''; newMentionIds.value=[]; mentionOpen.value=false; mentionTarget.value=null; await load()

}

async function replyTo(c){

 const v=(c.replyInput||'').trim(); if(!v) return

 const mids=c._replyMentionIds||[]

 await api('/ekskul/'+route.params.id+'/posts/'+post.value.id+'/comments',{method:'POST',body:{isi:v, parent_id:c.id, mention_ids:mids}})

 c.replyInput=''; c._replyMentionIds=[]; mentionOpen.value=false; mentionTarget.value=null; await load()

}

async function likeComment(c){ const j=await api('/comments/'+c.id+'/like',{method:'POST',body:{}}); c.is_liked=j.data.liked; c.likes=j.data.likes }

async function delComment(c){ await api('/comments/'+c.id,{method:'DELETE',body:{}}); await load() }

onMounted(load)

</script>

