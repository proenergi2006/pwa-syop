<script setup>
import { useAuth } from '../stores/auth'
import { inisial } from '../utils/format'

// Isi avatar: foto profil bila ada, jika tidak inisial nama. Bentuk/ukuran diatur oleh wadah induknya.
const auth = useAuth()
</script>

<template>
  <img v-if="auth.photoUrl" :src="auth.photoUrl" alt="" class="ua-img" draggable="false" />
  <template v-else>{{ inisial(auth.user?.fullname) }}</template>
</template>

<style>
.ua-img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; display: block; }
</style>
