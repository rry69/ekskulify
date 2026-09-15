<script setup>
import { ref, computed } from 'vue'
// ponytail: probe /api/avatar/:id, fallback lingkaran inisial bila 404. Upgrade path: srcset retina bila butuh.
const props = defineProps({
  userId: { type: [Number, String], required: true },
  name: { type: String, default: '?' },
  size: { type: String, default: 'w-11 h-11' },
})
const failed = ref(false)
const src = computed(() => '/api/avatar/' + props.userId)
const initial = computed(() => (props.name || '?').trim().charAt(0).toUpperCase())
</script>
<template>
  <img
    v-if="!failed"
    :src="src"
    @error="failed = true"
    :class="[size, 'rounded-full object-cover shrink-0']"
    loading="lazy"
    alt=""
  />
  <span
    v-else
    :class="[size, 'rounded-full grid place-items-center font-bold text-white shrink-0']"
    style="background:#2F3E46"
    aria-hidden="true"
  >{{ initial }}</span>
</template>
