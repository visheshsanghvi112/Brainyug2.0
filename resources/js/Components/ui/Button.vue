<template>
  <button
    :class="['ui-btn', variantClass, $attrs.class]"
    v-bind="$attrs"
  >
    <slot />
  </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  variant: { type: String, default: 'primary' },
});

const variantClass = computed(() => {
  return props.variant === 'primary' ? 'ui-btn-primary' : '';
});
</script>

<style scoped>
button { border: none; cursor: pointer; }
</style>
