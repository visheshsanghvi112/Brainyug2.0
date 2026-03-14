<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline';
import { ref } from 'vue';

const props = defineProps({
    visits: Object,
    canCreate: Boolean,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');

function applyFilters() {
    window.location.href = route('shop-visits.index', { search: search.value, status: status.value });
}

function scoreColor(score) {
    if (score >= 80) return 'text-emerald-600 font-semibold';
    if (score >= 60) return 'text-amber-600 font-semibold';
    return 'text-red-600 font-semibold';
}
</script>

<template>
    <Head title="Shop Visit Audits" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Shop Visit Audits</h2>
                <Link v-if="canCreate" :href="route('shop-visits.create')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    + New Audit
                </Link>
            </div>
        </template>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-6">
            <input v-model="search" @keyup.enter="applyFilters" type="text"
                placeholder="Search shop…"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100 w-60" />
            <select v-model="status" @change="applyFilters"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="completed">Completed</option>
            </select>
        </div>

        <!-- Table -->
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Shop</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Visit Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Auditor</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Score</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-if="!visits.data.length">
                        <td colspan="6" class="text-center py-12 text-gray-400">No audits found.</td>
                    </tr>
                    <tr v-for="v in visits.data" :key="v.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ v.franchisee?.shop_name }}</p>
                            <p class="text-xs text-gray-500">{{ v.franchisee?.district }}</p>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                            {{ new Date(v.visit_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ v.auditor?.name }}</td>
                        <td class="px-4 py-3 text-center">
                            <span :class="['text-sm', scoreColor(v.inspection_score)]">{{ v.inspection_score }}/100</span>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                v.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700']">
                                {{ v.status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('shop-visits.show', v.id)"
                                class="text-indigo-600 hover:text-indigo-500 text-sm font-medium">
                                View
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>

        <Pagination :data="visits" class="mt-6" />
    </AuthenticatedLayout>
</template>
