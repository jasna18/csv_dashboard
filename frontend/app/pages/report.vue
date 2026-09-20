<script setup lang="ts">
useHead({ title: 'Report View — CSV Dashboard' })
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Report View</h1>
    <p class="mt-2 text-slate-600">
      KPI cards, charts and the data table will render here once a CSV or Excel file has been imported.
    </p>

    <div class="mt-8 rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
      <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
      </svg>
      <p class="mt-4 text-sm font-medium text-slate-900">No data yet</p>
      <p class="mt-1 text-sm text-slate-500">Upload a CSV or Excel file to generate a report.</p>
      <NuxtLink
        to="/"
        class="mt-6 inline-flex items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
      >
        Upload a file
      </NuxtLink>
    </div>
  </div>
</template>
