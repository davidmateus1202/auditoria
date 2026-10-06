<script setup lang="ts">
/**
 * Set propio de iconos de línea, minimalista a propósito: evita una
 * dependencia externa solo para un puñado de glifos. Todos comparten el
 * mismo viewBox y grosor de trazo para que se vean como una sola familia.
 */
withDefaults(
  defineProps<{
    name: string
    size?: number
  }>(),
  { size: 20 },
)
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 20 20"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <template v-if="name === 'upload'">
      <path d="M10 13V3M6 7l4-4 4 4M4 14v2a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-2" />
    </template>
    <template v-else-if="name === 'download'">
      <path d="M10 3v10M6 9l4 4 4-4M4 14v2a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-2" />
    </template>
    <template v-else-if="name === 'refresh'">
      <path d="M4 10a6 6 0 0 1 10.2-4.2M16 10a6 6 0 0 1-10.2 4.2" />
      <path d="M14 3v3h-3M6 17v-3h3" />
    </template>
    <template v-else-if="name === 'search'">
      <circle cx="8.5" cy="8.5" r="5" />
      <path d="M16 16l-3.2-3.2" />
    </template>
    <template v-else-if="name === 'close'">
      <path d="M5 5l10 10M15 5L5 15" />
    </template>
    <template v-else-if="name === 'check'">
      <path d="M4 10.5l4 4 8-9" />
    </template>
    <template v-else-if="name === 'check-circle'">
      <circle cx="10" cy="10" r="7" />
      <path d="M7 10.3l2.2 2.2L13.5 8" />
    </template>
    <template v-else-if="name === 'chevron-right'">
      <path d="M7.5 4.5l6 5.5-6 5.5" />
    </template>
    <template v-else-if="name === 'chevron-down'">
      <path d="M4.5 7.5l5.5 6 5.5-6" />
    </template>
    <template v-else-if="name === 'arrow-left'">
      <path d="M16 10H4M8.5 4.5L4 10l4.5 5.5" />
    </template>
    <template v-else-if="name === 'edit'">
      <path d="M12.5 3.5l4 4-9 9-4.3.8.8-4.3 8.5-9.5z" />
    </template>
    <template v-else-if="name === 'lock'">
      <rect x="4.5" y="9" width="11" height="7.5" rx="1.2" />
      <path d="M6.5 9V6.5a3.5 3.5 0 0 1 7 0V9" />
    </template>
    <template v-else-if="name === 'lock-open'">
      <rect x="4.5" y="9" width="11" height="7.5" rx="1.2" />
      <path d="M6.5 9V6.5a3.5 3.5 0 0 1 6.6-1.6" />
    </template>
    <template v-else-if="name === 'calendar'">
      <rect x="3" y="4.5" width="14" height="12" rx="1.3" />
      <path d="M3 8.3h14M7 3v3M13 3v3" />
    </template>
    <template v-else-if="name === 'calendar-edit'">
      <rect x="3" y="4.5" width="14" height="12" rx="1.3" />
      <path d="M3 8.3h14M7 3v3M13 3v3" />
      <path d="M9 14.5l0.5-2 4-4 1.5 1.5-4 4z" />
    </template>
    <template v-else-if="name === 'calendar-check'">
      <rect x="3" y="4.5" width="14" height="12" rx="1.3" />
      <path d="M3 8.3h14M7 3v3M13 3v3" />
      <path d="M7 12.2l1.8 1.8L13.5 10" />
    </template>
    <template v-else-if="name === 'trash'">
      <path d="M4.5 6h11M8 6V4.5h4V6M6 6l.6 10a1 1 0 0 0 1 .9h4.8a1 1 0 0 0 1-.9L14 6" />
    </template>
    <template v-else-if="name === 'warning'">
      <path d="M10 3.5l7 12.3H3z" />
      <path d="M10 8.3v3M10 14h.01" />
    </template>
    <template v-else-if="name === 'info'">
      <circle cx="10" cy="10" r="7" />
      <path d="M10 9v4.3M10 6.7h.01" />
    </template>
    <template v-else-if="name === 'error-circle'">
      <circle cx="10" cy="10" r="7" />
      <path d="M10 6.7v4M10 13.3h.01" />
    </template>
    <template v-else-if="name === 'block'">
      <circle cx="10" cy="10" r="7" />
      <path d="M5.5 5.5l9 9" />
    </template>
    <template v-else-if="name === 'folder'">
      <path d="M3 6.2a1 1 0 0 1 1-1h3.4l1.4 1.8H16a1 1 0 0 1 1 1V15a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" />
    </template>
    <template v-else-if="name === 'folder-off'">
      <path d="M3 6.2a1 1 0 0 1 1-1h3.4l1.4 1.8H16a1 1 0 0 1 1 1V15a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" opacity=".5" />
      <path d="M4 4l12 12" />
    </template>
    <template v-else-if="name === 'inbox'">
      <path d="M4 10.5h3.2l1.1 2h3.4l1.1-2H16" />
      <path d="M4.5 5.5h11l1.5 5v4a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-4z" />
    </template>
    <template v-else-if="name === 'menu'">
      <path d="M3.5 6h13M3.5 10h13M3.5 14h13" />
    </template>
    <template v-else-if="name === 'logout'">
      <path d="M8 3.5H4.5a1 1 0 0 0-1 1V15a1 1 0 0 0 1 1H8" />
      <path d="M12.5 13.5l3.5-3.5-3.5-3.5M16 10H7.5" />
    </template>
    <template v-else-if="name === 'eye'">
      <path d="M2.5 10S5.5 4.5 10 4.5 17.5 10 17.5 10 14.5 15.5 10 15.5 2.5 10 2.5 10z" />
      <circle cx="10" cy="10" r="2.3" />
    </template>
    <template v-else-if="name === 'eye-off'">
      <path d="M2.5 10S5.5 4.5 10 4.5 17.5 10 17.5 10 14.5 15.5 10 15.5 2.5 10 2.5 10z" />
      <path d="M3.5 3.5l13 13" />
    </template>
    <template v-else-if="name === 'mail'">
      <rect x="3" y="5" width="14" height="10" rx="1.3" />
      <path d="M3.5 5.8l6.5 5 6.5-5" />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M10 4.5v11M4.5 10h11" />
    </template>
    <template v-else-if="name === 'table'">
      <rect x="3" y="4" width="14" height="12" rx="1.2" />
      <path d="M3 8.5h14M8.2 4v12" />
    </template>
    <template v-else-if="name === 'chart-line'">
      <path d="M3.5 16V4M3.5 16h13" />
      <path d="M5.5 13l3-3.5 2.5 2 3.5-5" />
    </template>
    <template v-else-if="name === 'send'">
      <path d="M17 3L3 9.3l5.2 2 2 5.2z" />
      <path d="M17 3L8.2 11.3" />
    </template>
    <template v-else-if="name === 'file-table'">
      <path d="M5.5 3h6l3 3v10.5a1 1 0 0 1-1 1h-8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" />
      <path d="M11 3v3h3M7 11h6M7 14h6" />
    </template>
    <template v-else-if="name === 'home'">
      <path d="M4 9.5L10 4l6 5.5" />
      <path d="M5.5 8.5V16h9V8.5" />
    </template>
    <template v-else-if="name === 'pending'">
      <circle cx="10" cy="10" r="7" />
      <path d="M10 6.5V10l2.5 1.5" />
    </template>
    <template v-else-if="name === 'location-plus'">
      <path d="M10 17s5-4.8 5-8.8A5 5 0 0 0 5 8.2C5 12.2 10 17 10 17z" />
      <path d="M10 6v4.4M7.8 8.2h4.4" />
    </template>
    <template v-else-if="name === 'merge'">
      <path d="M5 4v5a3 3 0 0 0 3 3h4M5 4L3 6M5 4l2 2" />
      <path d="M15 16v-5a3 3 0 0 0-3-3h-1" />
      <path d="M15 16l-2-2M15 16l2-2" />
    </template>
    <template v-else-if="name === 'bulb'">
      <path d="M7 13.5a4.5 4.5 0 1 1 6 0c-.5.45-.9 1-.9 1.7V16h-4.2v-.8c0-.7-.4-1.25-.9-1.7z" />
      <path d="M8.5 18h3" />
    </template>
    <template v-else-if="name === 'timeline'">
      <circle cx="5" cy="5" r="1.6" />
      <circle cx="10" cy="10" r="1.6" />
      <circle cx="15" cy="15" r="1.6" />
      <path d="M5 6.6V13a1 1 0 0 0 1 1h2.4M11.6 10H13a1 1 0 0 1 1 1v2.4" />
    </template>
    <template v-else-if="name === 'file'">
      <path d="M5.5 3h6l3 3v10.5a1 1 0 0 1-1 1h-8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" />
      <path d="M11 3v3h3" />
    </template>
    <template v-else-if="name === 'image'">
      <rect x="3" y="4" width="14" height="12" rx="1.5" />
      <circle cx="7.5" cy="8.5" r="1.4" />
      <path d="M3.5 14.5l4.5-4 3.5 3 2-1.8 3 2.8" />
    </template>
    <template v-else-if="name === 'chevron-left'">
      <path d="M12.5 4.5l-6 5.5 6 5.5" />
    </template>
  </svg>
</template>
