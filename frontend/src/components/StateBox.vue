<script setup>
import Icon from './Icon.vue'
defineProps({ icon: { type: String, default: 'doc' }, title: String, text: String, action: String })
defineEmits(['action'])
</script>

<template>
  <div class="state">
    <div class="big"><Icon :name="icon" :size="32" /></div>
    <h3>{{ title }}</h3>
    <p>{{ text }}</p>
    <button v-if="action" class="btn btn-ghost" @click="$emit('action')">{{ action }}</button>
  </div>
</template>
