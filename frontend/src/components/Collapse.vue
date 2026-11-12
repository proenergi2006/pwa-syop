<script setup>
import { ref } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({ title: String, count: [Number, String], open: Boolean, tone: String })
const isOpen = ref(props.open)
const uid = `c${Math.random().toString(36).slice(2, 8)}`
</script>

<template>
  <section class="card col">
    <button class="col-h" :aria-expanded="isOpen" :aria-controls="uid" @click="isOpen = !isOpen">
      <span class="col-t">{{ title }}</span>
      <span v-if="count !== undefined && count !== null" class="col-n">{{ count }}</span>
      <Icon name="chevron" :size="18" class="col-i" :style="{ transform: isOpen ? 'rotate(90deg)' : 'none' }" />
    </button>
    <div :id="uid" class="col-b" :class="{ on: isOpen }">
      <div class="col-in"><slot /></div>
    </div>
  </section>
</template>

<style scoped>
.col { padding: 0; overflow: hidden; }
.col-h { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 50px; padding: 0 16px; background: none; border: 0; text-align: left; }
.col-t { flex: 1; font-weight: 700; font-size: 15px; }
.col-n { min-width: 24px; height: 22px; padding: 0 8px; border-radius: 11px; background: var(--blue-50); color: var(--accent); font-size: 12px; font-weight: 700; display: grid; place-items: center; }
.col-i { color: var(--muted); transition: transform 0.25s ease; }
/* grid-rows 0fr -> 1fr = tinggi mengikuti isi tanpa JavaScript */
.col-b { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.3s ease; }
.col-b.on { grid-template-rows: 1fr; }
.col-in { overflow: hidden; min-height: 0; padding: 0 16px; }
.col-b.on .col-in { padding-bottom: 14px; }
</style>
