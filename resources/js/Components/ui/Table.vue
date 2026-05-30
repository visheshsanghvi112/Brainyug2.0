<template>
  <div class="overflow-x-auto ui-surface ui-shadow-sm rounded-lg">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-700">
      <thead class="bg-white dark:bg-zinc-800">
        <tr>
          <th v-for="col in columns" :key="col.key" class="px-4 py-2 text-left text-sm text-muted">{{ col.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id" class="border-t border-gray-100 dark:border-zinc-700">
          <td v-for="col in columns" :key="col.key" class="px-4 py-3 text-sm">{{ row[col.key] }}</td>
        </tr>
        <tr v-if="rows.length === 0">
          <td :colspan="columns.length" class="p-6 text-center text-sm text-muted">No records</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
const props = defineProps({
  columns: { type: Array, default: () => [] },
  rows: { type: Array, default: () => [] },
});
</script>

<style scoped>
.text-muted { color: rgb(var(--color-muted)); }
</style>
