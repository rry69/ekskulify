<template>

<div class="max-w-[1280px] mx-auto">

 <h1 class="font-bold text-[18px]">File Manager</h1>

 <div class="mt-4 bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-2 items-end">

 <label class="text-xs">Ekskul ID <input v-model.number="f.ekskul_id" type="number" placeholder="semua" class="ml-1 px-2 py-1.5 rounded-lg border text-xs" w-24/></label>

 <label class="text-xs">Min size (bytes) <input v-model.number="f.min_size" type="number" placeholder="0" class="ml-1 px-2 py-1.5 rounded-lg border text-xs w-28"/></label>

 <label class="text-xs">Max size <input v-model.number="f.max_size" type="number" placeholder="" class="ml-1 px-2 py-1.5 rounded-lg border text-xs w-28"/></label>

 <label class="text-xs">Older than (hari) <input v-model.number="f.older_than_days" type="number" placeholder="0" class="ml-1 px-2 py-1.5 rounded-lg border text-xs w-20"/></label>

 <button @click="load" class="px-4 py-2 rounded-full bg-slate-900 text-white text-xs font-semibold">Filter</button>

 <span class="text-xs text-slate-500 ml-auto">Total: {{ meta.total }} - {{ fmtSize(meta.total_size) }}</span>

 </div>

 <div class="mt-3 flex gap-2">

 <button @click="bulkDelete" :disabled="!selected.length" class="px-4 py-2 rounded-full bg-red-600 text-white text-xs font-bold disabled:opacity-40">Hapus {{ selected.length }} terpilih</button>

 <span class="text-xs text-slate-400 self-center">Klik Unduh untuk preview/download (inline image)</span>

 </div>

 <div class="mt-3 bg-white rounded-2xl border border-slate-200 overflow-auto">

 <table class="w-full text-xs">

 <thead class="bg-slate-50 text-[11px] tracking-widest text-slate-400"><tr><th class="px-3 py-2"><input type="checkbox" :checked="allChecked" @change="toggleAll"></th><th class="text-left px-3 py-2">FILE</th><th class="text-left px-3 py-2">SIZE</th><th class="text-left px-3 py-2">TANGGAL</th><th class="text-right px-3 py-2">AKSI</th></tr></thead>

 <tbody class="divide-y divide-slate-100">

 <tr v-for="r in rows" :key="r.id">

 <td class="px-3 py-2"><input type="checkbox" :value="r.id" v-model="selected"></td>

 <td class="px-3 py-2"><div class="font-medium">{{ r.original_name }}</div><div class="text-[11px] text-slate-400">ekskul {{ r.ekskul_id }} - {{ r.mime }}</div></td>

 <td class="px-3 py-2">{{ fmtSize(r.size) }}</td>

 <td class="px-3 py-2">{{ r.created_at }}</td>

 <td class="px-3 py-2 text-right"><a :href="`/api/uploads/${r.id}`">Unduh</a></td>

 </tr>

 </tbody>

 </table>

 <div v-if="!rows.length" class="p-6 text-center text-xs text-slate-400">Tidak ada file</div>

 </div>

</div>

</template>

<script setup>

import { ref, computed } from 'vue'

import { api } from '../lib/api.js'

const rows=ref([]), meta=ref({total:0,total_size:0}), selected=ref([]), f=ref({ekskul_id:'',min_size:'',max_size:'',older_than_days:''})

const allChecked=computed(()=> rows.value.length && selected.value.length===rows.value.length)

function fmtSize(b){ if(b==null) return '-'; if(b<1024) return b+' B'; if(b<1024*1024) return (b/1024).toFixed(1)+' KB'; return (b/1024/1024).toFixed(2)+' MB' }

function toggleAll(e){ selected.value = e.target.checked ? rows.value.map(r=>r.id) : [] }

async function load(){

 const q=new URLSearchParams()

 if(f.value.ekskul_id) q.set('ekskul_id', f.value.ekskul_id)

 if(f.value.min_size) q.set('min_size', f.value.min_size)

 if(f.value.max_size) q.set('max_size', f.value.max_size)

 if(f.value.older_than_days) q.set('older_than_days', f.value.older_than_days)

 const j=await api('/admin/uploads?'+q.toString())

 rows.value=j.data; meta.value=j.meta; selected.value=[]

}

async function bulkDelete(){

 if(!selected.value.length) return

 if(!confirm('Hapus '+selected.value.length+' file?')) return

 await api('/admin/uploads/bulk-delete',{method:'POST', body:{ids:selected.value}})

 await load()

}

load()

</script>

