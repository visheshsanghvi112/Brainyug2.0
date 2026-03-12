<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Universal Pagination Component
 * Handles null URLs safely — Inertia's <Link> crashes on href=null (first page prev / last page next).
 * Usage: <Pagination :data="products" />
 * `data` must be a Laravel paginator object with `links` array.
 */
const props = defineProps({
    data: {
        type: Object,
        required: true,
    },
    preserveState: {
        type: Boolean,
        default: true,
    },
});

const paginationLinks = computed(() => {
    const rawLinks = props.data?.links ?? props.data?.meta?.links ?? [];

    if (!Array.isArray(rawLinks)) {
        return [];
    }

    return rawLinks
        .filter((link) => link && typeof link === 'object')
        .map((link, index) => ({
            key: `${link.label ?? 'page'}-${index}`,
            label: link.label ?? '',
            url: link.url ?? null,
            active: Boolean(link.active),
        }));
});

const shouldRender = computed(() => paginationLinks.value.length > 3);
</script>

<template>
    <div v-if="shouldRender" class="flex flex-wrap items-center justify-between gap-3 mt-4">

        <!-- Metadata -->
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Showing
            <span class="font-semibold text-gray-700 dark:text-gray-200">{{ data.from ?? data.meta?.from ?? 0 }}</span>
            –
            <span class="font-semibold text-gray-700 dark:text-gray-200">{{ data.to ?? data.meta?.to ?? 0 }}</span>
            of
            <span class="font-semibold text-gray-700 dark:text-gray-200">{{ data.total ?? data.meta?.total }}</span>
            results
        </p>

        <!-- Page Buttons -->
        <nav class="flex flex-wrap gap-1">
            <template v-for="link in paginationLinks" :key="link.key">
                <!-- Disabled: null URL (prev on first page, next on last page) -->
                <span
                    v-if="!link.url"
                    class="px-3 py-1.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 text-gray-300 dark:text-gray-600 cursor-not-allowed select-none"
                    v-html="link.label"
                />
                <!-- Active page -->
                <span
                    v-else-if="link.active"
                    class="px-3 py-1.5 rounded-lg text-sm font-bold bg-indigo-600 text-white border border-indigo-600 select-none"
                    v-html="link.label"
                />
                <!-- Navigable page -->
                <Link
                    v-else
                    :href="link.url"
                    :preserve-state="preserveState"
                    preserve-scroll
                    class="px-3 py-1.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-indigo-50 dark:hover:bg-gray-700 hover:text-indigo-600 dark:hover:text-indigo-400 hover:border-indigo-200 transition-colors"
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
