<template>
  <div class="flex flex-col">
    <label v-if="label" :for="id" class="mb-1 font-medium">{{ label }}</label>
    <slot name="input">
      <input :id="id" class="ui-input" v-bind="$attrs" />
    </slot>
    <p v-if="error" class="text-sm text-red-600 mt-1">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  label: { type: String, default: '' },
  error: { type: String, default: '' },
  id: { type: String, default: () => `field-${Math.random().toString(36).slice(2,9)}` },
});
</script>

<style scoped>
</style>
