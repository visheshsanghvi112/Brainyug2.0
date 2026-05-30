<template>
  <div class="min-h-screen ui-bg text-black dark:text-white">
    <Topbar />

    <div class="flex">
      <Sidebar />

      <main class="flex-1 p-6">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import Topbar from './Topbar.vue';
import Sidebar from './Sidebar.vue';
</script>

<style scoped>
</style>
