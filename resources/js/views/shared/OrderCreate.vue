<template>
  <div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold mb-4">Nouvelle course</h1>
    <OrderForm @saved="saved" />
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import OrderForm from '../../components/OrderForm.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

function saved(order) {
  toasts.success(`Course ${order.tracking_code} enregistrée.`)
  router.push(`${auth.homeRoute}/courses/${order.id}`)
}
</script>
