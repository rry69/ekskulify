<template>

 <!-- E Bertumpuk - Mindora lock: ink + white + blue + green -->

 <svg :width="size" :height="size" viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg" :aria-label="alt" role="img">

  <rect x="4" y="4" width="64" height="64" :rx="variant === 'round' ? 18 : 14" :fill="bg" />

  <rect x="18" y="18" width="8" height="36" rx="4" :fill="variant === 'mono' ? fgMono : '#FFFFFF'" />

  <rect x="18" y="18" width="32" height="8" rx="4" :fill="variant === 'mono' ? fgMono : '#FFFFFF'" />

  <rect x="18" y="32" width="26" height="8" rx="4" :fill="variant === 'mono' ? fgMono : '#A7C7E7'" />

  <rect x="18" y="46" width="32" height="8" rx="4" :fill="variant === 'mono' ? fgMono : '#5EB87E'" />

 </svg>

</template>

<script setup>

defineProps({

 size: { type: [Number, String], default: 32 },

 bg: { type: String, default: '#2F3E46' },

 variant: { type: String, default: 'default' }, // default | mono | round

 alt: { type: String, default: 'Ekskul & Event' }

})

const fgMono = '#FFFFFF'

</script>

