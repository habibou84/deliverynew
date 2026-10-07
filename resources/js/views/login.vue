<template>
  <div>
    <input v-model="email" placeholder="Email">
    <input type="password" v-model="password" placeholder="Mot de passe">
    <button @click="login">Connexion</button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const email = ref('')
const password = ref('')
const auth = useAuthStore()

const login = async () => {
  await auth.login({ email: email.value, password: password.value })
}
</script>
