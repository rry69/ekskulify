<template>
  <div ref="el" class="apex-wrap" :style="{ minHeight: height + 'px' }"></div>
</template>
<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import ApexCharts from 'apexcharts'
const props = defineProps({
  type: { type: String, required: true },
  height: { type: Number, default: 260 },
  options: { type: Object, required: true },
  series: { type: [Array, Object], required: true },
  events: { type: Object, default: undefined },
})
const el = ref(null)
let chart = null
const MINDORA_FONT = "'Satoshi',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif"
function render() {
  if (!el.value) return
  try { chart?.destroy() } catch {}
  chart = null
  // Mindora: SVG ApexCharts tak warisi font body — paksa Satoshi eksplisit (1 titik, bukan per-options)
  const baseChart = { type: props.type, height: props.height, toolbar: { show: false }, fontFamily: MINDORA_FONT, ...(props.options.chart || {}) }
  baseChart.fontFamily = baseChart.fontFamily || MINDORA_FONT
  if (baseChart.fontFamily === 'inherit') baseChart.fontFamily = MINDORA_FONT
  if (props.events) baseChart.events = { ...(baseChart.events || {}), ...props.events }
  const opts = { ...props.options, chart: baseChart, series: props.series }
  chart = new ApexCharts(el.value, opts)
  chart.render().catch(() => {})
}
onMounted(render)
watch(() => [props.type, props.height, props.options, props.series, props.events], render, { deep: true })
onBeforeUnmount(() => { try { chart?.destroy() } catch {} })
</script>
<style scoped>
.apex-wrap { width: 100%; overflow: hidden; }
</style>
