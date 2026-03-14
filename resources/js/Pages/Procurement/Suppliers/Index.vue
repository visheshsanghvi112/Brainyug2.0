<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    MagnifyingGlassIcon, PlusIcon, PencilSquareIcon, EyeIcon,
    TruckIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    suppliers: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.active ?? '');

function applyFilters() {
    router.get(route('admin.suppliers.index'), {
        search: search.value || undefined,
        active: statusFilter.value !== '' ? statusFilter.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

let debounceTimer;
watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

watch(statusFilter, applyFilters);
</script>

<template>
    <Head title="Vendors & Suppliers" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <TruckIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        Vendors & Suppliers
                    </h2>
                </div>
                <Link
                    :href="route('admin.suppliers.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-all duration-200"
                >
                    <PlusIcon class="h-5 w-5" />
                    Add Supplier
                </Link>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Filters Row -->
                <div class="mb-6 flex flex-col gap-4 sm:flex-row">
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search by name, code, GST, phone..."
                            class="w-full rounded-xl border-0 bg-white py-3 pl-10 pr-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                        />
                    </div>
                    <select
                        v-model="statusFilter"
                        class="rounded-xl border-0 bg-white py-3 px-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <option value="">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <!-- Table -->
                <div class="overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Supplier / Code</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Location</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Contact / GST</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr
                                v-for="s in suppliers.data"
                                :key="s.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30"
                            >
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ s.name }}</div>
                                    <div class="text-xs text-blue-500 font-mono">{{ s.code || '—' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm text-gray-900 dark:text-white">{{ s.district?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ s.state?.name || '—' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm text-gray-900 dark:text-white">{{ s.phone || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase font-mono">{{ s.gst_number || '—' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <span v-if="s.is_active" class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 border border-emerald-200">Active</span>
                                    <span v-else class="inline-flex items-center rounded-full bg-gray-50 px-2.5 py-0.5 text-xs font-medium text-gray-600 border border-gray-200">Inactive</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-1">
                                        <Link
                                            :href="route('admin.suppliers.show', s.id)"
                                            class="inline-flex items-center rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-sky-600 dark:hover:bg-gray-700 dark:hover:text-sky-400 transition"
                                        >
                                            <EyeIcon class="h-5 w-5" />
                                        </Link>
                                        <Link
                                            :href="route('admin.suppliers.edit', s.id)"
                                            class="inline-flex items-center rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-indigo-600 dark:hover:bg-gray-700 dark:hover:text-indigo-400 transition"
                                        >
                                            <PencilSquareIcon class="h-5 w-5" />
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!suppliers.data?.length">
                                <td colspan="5" class="px-6 py-12 text-center text-gray-400 dark:text-gray-500">
                                    <TruckIcon class="mx-auto h-12 w-12 mb-3 text-gray-300 dark:text-gray-600" />
                                    No suppliers found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div><!-- overflow-x-auto -->
                    <Pagination :data="suppliers" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
