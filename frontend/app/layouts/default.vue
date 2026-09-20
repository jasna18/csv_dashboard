<script setup lang="ts">
const nav = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Import', to: '/' },
  // { label: 'Report View', to: '/report' },
]

const mobileOpen = ref(false)
const route = useRoute()

// Close the mobile menu on navigation, or it stays open over the new page.
watch(() => route.fullPath, () => (mobileOpen.value = false))
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40">
      <nav class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" aria-label="Main">
        <div class="flex h-16 items-center justify-between">
          <!-- Brand -->
          <NuxtLink to="/" class="flex items-center gap-2.5 shrink-0">
            <span
              class="grid h-9 w-9 place-items-center rounded-lg bg-brand-600 text-white font-bold text-sm"
              aria-hidden="true"
            >
              CD
            </span>
            <span class="font-semibold text-slate-900 tracking-tight">
              CSV Dashboard
            </span>
          </NuxtLink>

          <!-- Desktop nav -->
          <ul class="hidden sm:flex items-center gap-1">
            <li v-for="item in nav" :key="item.to">
              <NuxtLink
                :to="item.to"
                class="px-3 py-2 rounded-md text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                active-class="!bg-brand-50 !text-brand-700"
              >
                {{ item.label }}
              </NuxtLink>
            </li>
            <!-- Last, and set apart by a divider: it is an action, not a
                 destination, and a destructive one. -->
            <li class="ml-2 border-l border-slate-200 pl-2">
              <ClearDataButton />
            </li>
          </ul>

          <!-- Mobile toggle -->
          <button
            type="button"
            class="sm:hidden inline-flex items-center justify-center rounded-md p-2 text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
            :aria-expanded="mobileOpen"
            aria-controls="mobile-nav"
            @click="mobileOpen = !mobileOpen"
          >
            <span class="sr-only">
              {{ mobileOpen ? 'Close main menu' : 'Open main menu' }}
            </span>
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path v-if="!mobileOpen" stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Mobile nav -->
        <ul v-show="mobileOpen" id="mobile-nav" class="sm:hidden pb-3 space-y-1">
          <li v-for="item in nav" :key="item.to">
            <NuxtLink
              :to="item.to"
              class="block px-3 py-2 rounded-md text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
              active-class="!bg-brand-50 !text-brand-700"
            >
              {{ item.label }}
            </NuxtLink>
          </li>
          <li class="mt-2 border-t border-slate-200 pt-2">
            <ClearDataButton />
          </li>
        </ul>
      </nav>
    </header>

    <main class="flex-1">
      <slot />
    </main>

    <footer class="border-t border-slate-200 bg-white">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-5">
        <p class="text-xs text-slate-500">
          CSV Dashboard — upload a CSV, get a dashboard.
        </p>
      </div>
    </footer>
  </div>
</template>
