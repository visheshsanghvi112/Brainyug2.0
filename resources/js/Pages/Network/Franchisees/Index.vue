<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import {
    MagnifyingGlassIcon, PlusIcon, PencilSquareIcon, EyeIcon,
    FunnelIcon, BuildingStorefrontIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    franchisees: Object,
    filters: Object,
    states: Array,
    statusOptions: Array,
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const stateFilter = ref(props.filters?.state_id || '');

function applyFilters() {
    router.get(route('admin.franchisees.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        state_id: stateFilter.value || undefined,
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

watch([statusFilter, stateFilter], applyFilters);

const statusColors = {
    enquiry: 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300',
    registered: 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    approved: 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    rejected: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    active: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    suspended: 'bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
    banned: 'bg-gray-50 text-gray-700 dark:bg-gray-900/30 dark:text-gray-300',
};
</script>

<template>
    <Head title="Franchise Network" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <BuildingStorefrontIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        Franchise Network
                    </h2>
                </div>
                <Link
                    :href="route('admin.franchisees.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-all duration-200"
                >
                    <PlusIcon class="h-5 w-5" />
                    Register Franchisee
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
                            placeholder="Search by name, mobile, shop code, email..."
                            class="w-full rounded-xl border-0 bg-white py-3 pl-10 pr-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                        />
                    </div>
                    <select
                        v-model="statusFilter"
                        class="rounded-xl border-0 bg-white py-3 px-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <option value="">All Status</option>
                        <option v-for="s in statusOptions" :key="s" :value="s" class="capitalize">
                            {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                        </option>
                    </select>
                    <select
                        v-model="stateFilter"
                        class="rounded-xl border-0 bg-white py-3 px-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <option value="">All States</option>
                        <option v-for="state in states" :key="state.id" :value="state.id">
                            {{ state.name }}
                        </option>
                    </select>
                </div>

                <!-- Table -->
                <div class="overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Shop / Owner</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Code</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Location</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Contact</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Registered</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr
                                v-for="f in franchisees.data"
                                :key="f.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30"
                            >
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ f.shop_name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ f.owner_name }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-300">
                                    {{ f.shop_code || '—' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm text-gray-900 dark:text-white">{{ f.district?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ f.state?.name || '—' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm text-gray-900 dark:text-white">{{ f.mobile }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ f.email || '—' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <span
                                        :class="statusColors[f.status] || 'bg-gray-100 text-gray-600'"
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                                    >
                                        {{ f.status }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ f.created_at }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <Link
                                            :href="route('admin.franchisees.show', f.id)"
                                            class="inline-flex items-center rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-700 dark:hover:text-blue-400 transition"
                                            title="View Profile"
                                        >
                                            <EyeIcon class="h-5 w-5" />
                                        </Link>
                                        <Link
                                            :href="route('admin.franchisees.edit', f.id)"
                                            class="inline-flex items-center rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-indigo-600 dark:hover:bg-gray-700 dark:hover:text-indigo-400 transition"
                                            title="Edit"
                                        >
                                            <PencilSquareIcon class="h-5 w-5" />
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!franchisees.data?.length">
                                <td colspan="7" class="px-6 py-12 text-center text-gray-400 dark:text-gray-500">
                                    <BuildingStorefrontIcon class="mx-auto h-12 w-12 mb-3 text-gray-300 dark:text-gray-600" />
                                    No franchisees found. Register your first franchisee to get started.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div><!-- overflow-x-auto -->

                    <Pagination :data="franchisees" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
