import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // Server-side rendering. This is Nuxt's default, but it is set explicitly
  // because the dashboard depends on it staying on: switching to `false`
  // would turn the app into a pure SPA and silently change how charts mount.
  ssr: true,

  // ApexCharts touches `window` at import time and cannot run during the
  // server render. Chart components must therefore be `*.client.vue` or sit
  // inside `<ClientOnly>` — see frontend/TASK.md §2 and §6.

  css: ['~/assets/css/main.css'],

  vite: {
    // Tailwind 4 ships as a Vite plugin. The old `@nuxtjs/tailwindcss`
    // module targets Tailwind 3 and is not used here.
    plugins: [tailwindcss()],
  },

  runtimeConfig: {
    public: {
      // Overridden by NUXT_PUBLIC_API_BASE at runtime.
      apiBase: 'http://localhost:8000/api',
    },
  },

  nitro: {
    // Node server output — `npm run build` then `node .output/server/index.mjs`.
    preset: 'node-server',
  },

  devServer: {
    port: 3000,
  },
})
