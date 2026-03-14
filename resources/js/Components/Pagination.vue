<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    data: {
        type: Object,
        required: true,
    },
    preserveState: {
        type: Boolean,
        default: true,
    },
    windowSize: {
        type: Number,
        default: 2,
    },
});

function metaValue(key, fallback = null) {
    if (props.data?.[key] !== undefined && props.data?.[key] !== null) {
        return props.data[key];
    }

    if (props.data?.meta?.[key] !== undefined && props.data?.meta?.[key] !== null) {
        return props.data.meta[key];
    }

    return fallback;
}

const currentPage = computed(() => Number(metaValue('current_page', 1)));
const lastPage = computed(() => Number(metaValue('last_page', 1)));
const from = computed(() => Number(metaValue('from', 0)));
const to = computed(() => Number(metaValue('to', 0)));
const total = computed(() => Number(metaValue('total', 0)));

const rawLinks = computed(() => {
    const topLevelLinks = props.data?.links;
    const metaLinks = props.data?.meta?.links;

    if (Array.isArray(topLevelLinks)) {
        return topLevelLinks;
    }

    if (Array.isArray(metaLinks)) {
        return metaLinks;
    }

    return [];
});

const previousLink = computed(() => rawLinks.value[0]?.url ?? props.data?.prev_page_url ?? props.data?.meta?.prev_page_url ?? null);
const nextLink = computed(() => rawLinks.value[rawLinks.value.length - 1]?.url ?? props.data?.next_page_url ?? props.data?.meta?.next_page_url ?? null);

function pageUrl(page) {
    const exactLink = rawLinks.value.find((link) => Number(link?.label) === page && link?.url);
    if (exactLink) {
        return exactLink.url;
    }

    const path = props.data?.path ?? props.data?.meta?.path;
    if (!path) {
        return null;
    }

    const query = new URLSearchParams(props.data?.meta?.query ?? {});
    query.set('page', String(page));
    const queryString = query.toString();
    return queryString ? `${path}?${queryString}` : `${path}?page=${page}`;
}

const pageItems = computed(() => {
    const totalPages = lastPage.value;
    const page = currentPage.value;

    if (totalPages <= 1) {
        return [];
    }

    const pages = new Set([1, totalPages]);
    const start = Math.max(1, page - props.windowSize);
    const end = Math.min(totalPages, page + props.windowSize);

    for (let index = start; index <= end; index += 1) {
        pages.add(index);
    }

    const orderedPages = [...pages].sort((left, right) => left - right);
    const items = [];

    orderedPages.forEach((pageNumber, index) => {
        if (index > 0 && pageNumber - orderedPages[index - 1] > 1) {
            items.push({
                key: `ellipsis-${orderedPages[index - 1]}-${pageNumber}`,
                type: 'ellipsis',
            });
        }

        items.push({
            key: `page-${pageNumber}`,
            type: 'page',
            page: pageNumber,
            active: pageNumber === page,
            url: pageUrl(pageNumber),
        });
    });

    return items;
});

const shouldRender = computed(() => lastPage.value > 1);
</script>

<template>
    <div v-if="shouldRender" class="mt-4 border-t border-gray-100 px-4 py-4 dark:border-gray-700 sm:px-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Showing
                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ from }}</span>
                –
                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ to }}</span>
                of
                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ total }}</span>
                results
            </p>

            <nav class="max-w-full overflow-x-auto pb-1">
                <div class="flex min-w-max items-center gap-1">
                    <span
                        v-if="!previousLink"
                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-300 dark:border-gray-700 dark:text-gray-600"
                    >
                        &laquo; Previous
                    </span>
                    <Link
                        v-else
                        :href="previousLink"
                        :preserve-state="preserveState"
                        preserve-scroll
                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 transition-colors hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-600 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-indigo-400"
                    >
                        &laquo; Previous
                    </Link>

                    <template v-for="item in pageItems" :key="item.key">
                        <span
                            v-if="item.type === 'ellipsis'"
                            class="rounded-lg border border-transparent px-3 py-1.5 text-sm text-gray-400 dark:text-gray-500"
                        >
                            ...
                        </span>

                        <span
                            v-else-if="item.active"
                            class="rounded-lg border border-indigo-600 bg-indigo-600 px-3 py-1.5 text-sm font-bold text-white"
                        >
                            {{ item.page }}
                        </span>

                        <span
                            v-else-if="!item.url"
                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-300 dark:border-gray-700 dark:text-gray-600"
                        >
                            {{ item.page }}
                        </span>

                        <Link
                            v-else
                            :href="item.url"
                            :preserve-state="preserveState"
                            preserve-scroll
                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 transition-colors hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-600 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-indigo-400"
                        >
                            {{ item.page }}
                        </Link>
                    </template>

                    <span
                        v-if="!nextLink"
                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-300 dark:border-gray-700 dark:text-gray-600"
                    >
                        Next &raquo;
                    </span>
                    <Link
                        v-else
                        :href="nextLink"
                        :preserve-state="preserveState"
                        preserve-scroll
                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 transition-colors hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-600 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-indigo-400"
                    >
                        Next &raquo;
                    </Link>
                </div>
            </nav>
        </div>
    </div>
</template>
