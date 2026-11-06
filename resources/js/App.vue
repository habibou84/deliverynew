<template>
  <router-view />
  <InstallPrompt />
  <AppDialog />
</template>

<script setup>
import InstallPrompt from './components/InstallPrompt.vue'
import AppDialog from './components/ui/AppDialog.vue'
</script>
