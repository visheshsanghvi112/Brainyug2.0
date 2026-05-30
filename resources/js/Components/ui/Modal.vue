<template>
  <div v-if="modelValue" class="fixed inset-0 z-50 flex items-center justify-center" @keydown.escape="emitClose" tabindex="-1">
    <div class="absolute inset-0 bg-black/40" @click="emitClose" aria-hidden="true"></div>

    <div class="relative w-full max-w-3xl rounded-lg ui-surface ui-shadow-md p-6" role="dialog" aria-modal="true" :aria-labelledby="titleId">
      <header class="flex items-start justify-between gap-4">
        <h3 :id="titleId" class="text-lg font-semibold"><slot name="title">Modal</slot></h3>
        <button class="ui-btn" @click="emitClose">Close</button>
      </header>

      <section class="mt-4">
        <slot />
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ modelValue: { type: Boolean, required: true } });
const emits = defineEmits(['update:modelValue','close']);

const titleId = `modal-title-${Math.random().toString(36).slice(2,8)}`;

function emitClose() {
  emits('update:modelValue', false);
  emits('close');
}
</script>

<style scoped>
/* small accessible modal; focus trap can be added later */
</style>
