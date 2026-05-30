<template>
  <header class="flex items-center justify-between gap-4 px-4 py-3 ui-surface ui-shadow-sm">
    <div class="flex items-center gap-3">
      <div class="text-lg font-semibold">Brainyug ERP</div>
      <div class="text-sm text-muted">Admin</div>
    </div>

    <div class="flex items-center gap-2">
      <button class="ui-btn" @click="toggleTheme">
        Toggle Theme
      </button>
    </div>
  </header>
</template>

<script setup>
function toggleTheme() {
  document.documentElement.classList.toggle('dark');
}
</script>

<style scoped>
</style>
