<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    audits: Object,
    filters: Object,
    actionOptions: Array,
    notice: {
        type: String,
        default: null,
    },
});

const search = ref(props.filters?.search || '');
const action = ref(props.filters?.action || '');
const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');

function applyFilters() {
    router.get(route('admin.support-access.audits'), {
        search: search.value || undefined,
        action: action.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    search.value = '';
    action.value = '';
    from.value = '';
    to.value = '';
    applyFilters();
}

function display(v) {
    return v || '-';
}
</script>

<template>
    <Head title="Support Access Audit" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">Support Access Audit</h2>
            </div>
        </template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">
            <div v-if="notice" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">
                {{ notice }}
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-5">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search admin/user/email/ip/path"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    @keyup.enter="applyFilters"
                />

                <select
                    v-model="action"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    @change="applyFilters"
                >
                    <option value="">All Actions</option>
                    <option v-for="item in actionOptions" :key="item" :value="item">{{ item }}</option>
                </select>

                <input
                    v-model="from"
                    type="date"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    @change="applyFilters"
                />

                <input
                    v-model="to"
                    type="date"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    @change="applyFilters"
                />

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="applyFilters"
                        class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                    >
                        Apply
                    </button>
                    <button
                        type="button"
                        @click="clearFilters"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        Reset
                    </button>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">When</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Admin</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Accessed User</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Support Flag</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Action</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Request</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60 text-sm">
                            <tr v-for="row in audits.data" :key="row.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.created_at }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ display(row.admin_user_name) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ display(row.admin_user_email) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">ID: {{ row.admin_user_id }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ display(row.accessed_user_name) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ display(row.accessed_user_email) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">ID: {{ row.impersonated_user_id }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/50">true</span>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ row.action }}</td>
                                <td class="px-4 py-3">
                                    <div class="text-gray-700 dark:text-gray-300">{{ display(row.method) }} {{ display(row.path) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Status: {{ display(row.response_status) }}</div>
                                    <div v-if="row.reason" class="text-xs text-gray-500 dark:text-gray-400">Reason: {{ row.reason }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ display(row.ip_address) }}</td>
                            </tr>

                            <tr v-if="!audits.data?.length">
                                <td colspan="7" class="px-6 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
                                    No support access audit records found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination :data="audits" />
        </div>
    </AuthenticatedLayout>
</template>
