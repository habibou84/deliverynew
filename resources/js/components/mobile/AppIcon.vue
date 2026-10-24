<template>
  <!-- Icône au trait de l'application marchand, de la couleur du texte qui l'entoure -->
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="stroke" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path v-for="(d, i) in ICONS[name] || ICONS.box" :key="i" :d="d" />
  </svg>
</template>

<script setup>
defineProps({
  name: { type: String, required: true },
  stroke: { type: [Number, String], default: 1.9 },
})

const circle = (cx, cy, r) => `M${cx - r} ${cy}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0`
const ICONS = {
  home: ['M3 11l9-8 9 8v9a2 2 0 0 1-2 2h-4v-7H9v7H5a2 2 0 0 1-2-2z'],
  box: ['M11 21.7a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7z', 'M12 22V12M3.3 7 12 12l8.7-5'],
  wallet: ['M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v2H5a2 2 0 0 1-2-2z', 'M3 7v11a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5', circle(16.5, 14.5, 1)],
  user: [circle(12, 8, 4), 'M4 21a8 8 0 0 1 16 0'],
  plus: ['M12 5v14M5 12h14'],
  bell: ['M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9', 'M10.3 21a1.94 1.94 0 0 0 3.4 0'],
  search: [circle(11, 11, 7), 'm20 20-3.5-3.5'],
  left: ['M15 5l-7 7 7 7'],
  right: ['M9 6l6 6-6 6'],
  phone: ['M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z'],
  chat: ['M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z'],
  moto: [circle(5, 17, 3.5), circle(19, 17, 3.5), 'M8.5 17h5.5l3-7M14 5h3l2.2 8.5M3 11h7l2.5 3.5'],
  check: ['M20 6 9 17l-5-5'],
  alert: [circle(12, 12, 9), 'M12 8v5M12 16.5h.01'],
  calendar: ['M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM16 2v4M8 2v4M3 10h18'],
  clock: [circle(12, 12, 9), 'M12 7v5l3 2'],
  store: ['M3 9l1.5-5h15L21 9zM5 9v12h14V9M10 21v-5h4v5'],
  pin: ['M12 22s7-7 7-12a7 7 0 1 0-14 0c0 5 7 12 7 12z', circle(12, 10, 2.5)],
  edit: ['M4 20h4L19 9l-4-4L4 16z'],
  back: ['M9 14 4 9l5-5', 'M4 9h10.5a5.5 5.5 0 0 1 0 11H11'],
  refresh: ['M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4', 'M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4'],
  download: ['M12 4v12M7 11l5 5 5-5M4 20h16'],
  upload: ['M12 16V4M7 9l5-5 5 5M4 20h16'],
  dots: [circle(5, 12, 0.6), circle(12, 12, 0.6), circle(19, 12, 0.6)],
  x: ['M18 6 6 18M6 6l12 12'],
  clip: ['M9 2h6v4H9z', 'M15 4h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3'],
  link: ['M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1'],
  chart: ['M4 20V10M10 20V4M16 20v-7M22 20H2'],
  send: ['M22 2 11 13M22 2l-7 20-4-9-9-4z'],
  copy: ['M8 8h12v12H8z', 'M16 8V4H4v12h4'],
  ban: [circle(12, 12, 9), 'm5.6 5.6 12.8 12.8'],
}
</script>
