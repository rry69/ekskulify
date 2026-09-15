<template>
  <div class="err-page">
    <div class="err-card">
      <div class="err-circle" :style="{ background: circleBg }"><Icon :icon="iconName" width="48" height="48" color="#fff" /></div>
      <div class="err-code">{{ code }}</div>
      <h1 class="err-title">{{ title }}</h1>
      <p class="err-msg">{{ message }}</p>
      <div class="err-actions">
        <button class="btn-ghost" @click="goBack">Kembali</button>
        <router-link class="btn-cta" :to="ctaTo">{{ ctaLabel }}</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useAuth } from '../stores/auth.js'

const props = defineProps({
  code: { type: String, default: '404' },
  title: { type: String, default: 'Halaman Tidak Ditemukan' },
  message: { type: String, default: 'URL yang kamu tuju tidak ada atau sudah dipindahkan.' },
  icon: { type: String, default: '' },
})

const defaults = { 404: 'mingcute:search-line', 403: 'mingcute:lock-line', 500: 'mingcute:alert-line' }
const circles = { 404: '#A7C7E7', 403: '#E8AEB3', 500: '#5EB87E' }
const iconName = computed(() => props.icon || defaults[props.code] || 'mingcute:search-line')
const circleBg = computed(() => circles[props.code] || '#A7C7E7')

const auth = useAuth()
const isGuest = computed(() => !auth.user)
const ctaTo = computed(() => (isGuest.value ? '/login' : '/'))
const ctaLabel = computed(() => (isGuest.value ? 'Ke Login' : 'Ke Dashboard'))

function goBack(){ window.history.back() }
</script>

<style scoped>
.err-page{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg,#F1F5F4);padding:24px 16px}
.err-card{display:flex;flex-direction:column;align-items:center;text-align:center;max-width:440px;width:100%;background:var(--surface,#fff);border:1px solid var(--line,#E0E5E3);border-radius:24px;padding:40px 28px}
.err-circle{width:120px;height:120px;border-radius:999px;display:grid;place-items:center;margin-bottom:20px}
.err-code{font-size:56px;font-weight:800;line-height:1;color:var(--ink,#2F3E46)}
.err-title{font-size:20px;font-weight:700;color:var(--ink,#2F3E46);margin:10px 0 6px}
.err-msg{font-size:13px;color:var(--muted,#6B7C85);line-height:1.6;margin-bottom:24px}
.err-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
.btn-ghost{padding:11px 22px;border-radius:999px;border:1px solid var(--line,#E0E5E3);background:#fff;color:var(--ink,#2F3E46);font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-ghost:hover{background:var(--bg,#F1F5F4)}
.btn-cta{padding:11px 22px;border-radius:999px;background:var(--m-cta,#4A7875);color:#fff;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center}
.btn-cta:hover{background:var(--m-cta-h,#5A908C)}
@media(max-width:480px){ .err-code{font-size:44px} .err-card{padding:32px 20px} }
</style>
