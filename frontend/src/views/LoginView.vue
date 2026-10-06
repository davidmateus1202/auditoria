<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/core/http/apiClient'
import { useSessionStore } from '@/stores/session.store'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import Icon from '@/components/ui/Icon.vue'

/** Mirror de PantallaIngreso (app/lib/pantallas/ingreso.dart). */
const session = useSessionStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const clave = ref('')
const ocultarClave = ref(true)
const enviando = ref(false)
const error = ref<string | null>(null)

const emailInvalido = ref(false)
const claveInvalida = ref(false)

async function ingresar() {
  emailInvalido.value = !email.value.includes('@')
  claveInvalida.value = clave.value.length === 0
  if (emailInvalido.value || claveInvalida.value) return

  enviando.value = true
  error.value = null

  try {
    await session.ingresar(email.value.trim(), clave.value)
    const destino = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    router.push(destino)
  } catch (e) {
    error.value = e instanceof ApiError ? e.mensaje : `Fallo inesperado: ${e}`
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-(--color-papel) px-6 py-12">
    <form class="w-full max-w-sm" @submit.prevent="ingresar">
      <div class="rounded-sm border border-(--color-regla) bg-(--color-superficie) p-3.5">
        <p class="text-[10px] font-bold tracking-wider text-(--color-apagado) uppercase">
          E.S.E. Municipal · Villavicencio, Meta
        </p>
        <p class="mt-1 text-[12px] text-(--color-tinta-2)">Resolución 3100 de 2019</p>
      </div>

      <h1 class="mt-7 text-[34px] leading-[1.05] font-bold tracking-tight">Auditoría SUH</h1>
      <p class="mt-1.5 text-[14px] leading-snug text-(--color-tinta-2)">
        Sistema Único de Habilitación · puestos de salud municipales
      </p>

      <div class="mt-8 flex flex-col gap-3.5">
        <AppInput
          v-model="email"
          label="Correo"
          type="email"
          icono="mail"
          autocomplete="email"
          :error="emailInvalido ? 'Escriba un correo válido' : undefined"
        />
        <AppInput
          v-model="clave"
          label="Contraseña"
          :type="ocultarClave ? 'password' : 'text'"
          icono="lock"
          autocomplete="current-password"
          :error="claveInvalida ? 'Escriba su contraseña' : undefined"
        >
          <template #suffix>
            <button
              type="button"
              class="text-(--color-apagado) hover:text-(--color-tinta)"
              @click="ocultarClave = !ocultarClave"
            >
              <Icon :name="ocultarClave ? 'eye' : 'eye-off'" :size="19" />
            </button>
          </template>
        </AppInput>

        <div
          v-if="error"
          class="rounded-[2px] p-3"
          :style="{ backgroundColor: 'var(--color-critico-fondo)', borderLeft: '3px solid var(--color-critico)' }"
        >
          <p class="text-[13px] leading-relaxed" style="color: var(--color-critico)">{{ error }}</p>
        </div>

        <AppButton type="submit" :cargando="enviando" block class="mt-2.5">Ingresar</AppButton>
      </div>
    </form>
  </div>
</template>
