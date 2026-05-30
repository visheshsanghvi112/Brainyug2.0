<template>
  <input
    :class="['ui-input', $attrs.class]"
    v-bind="$attrs"
  />
</template>

<script setup>
// basic input wrapper - props can be extended later
</script>

<style scoped>
input { outline: none; }
</style>
