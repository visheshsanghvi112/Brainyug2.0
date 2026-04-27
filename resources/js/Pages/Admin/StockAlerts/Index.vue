<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    alerts: Object,
    filters: Object,
    statusOptions: Array,
    levelOptions: Array,
    typeOptions: Array,
    summary: Object,
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || 'pending');
const alertLevel = ref(props.filters?.alert_level || '');
const alertType = ref(props.filters?.alert_type || '');
const location = ref(props.filters?.location || '');
const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');

const updateForm = useForm({
    status: 'acknowledged',
    action_taken: '',
});

function applyFilters() {
    router.get(route('admin.stock-alerts.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        alert_level: alertLevel.value || undefined,
        alert_type: alertType.value || undefined,
        location: location.value || undefined,
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
    status.value = 'pending';
    alertLevel.value = '';
    alertType.value = '';
    location.value = '';
    from.value = '';
    to.value = '';
    applyFilters();
}

function updateAlert(alertId, nextStatus) {
    updateForm.status = nextStatus;
    updateForm.post(route('admin.stock-alerts.acknowledge', alertId), {
        preserveScroll: true,
        onSuccess: () => {
            updateForm.reset('action_taken');
        },
    });
}

function toneClass(level) {
    if (level === 'critical') {
        return 'text-rose-700 bg-rose-50 border-rose-200 dark:text-rose-200 dark:bg-rose-900/30 dark:border-rose-800';
    }
    if (level === 'warning') {
        return 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-200 dark:bg-amber-900/30 dark:border-amber-800';
    }
    return 'text-sky-700 bg-sky-50 border-sky-200 dark:text-sky-200 dark:bg-sky-900/30 dark:border-sky-800';
}

function locationLabel(alert) {
    if (!alert.franchisee) {
        return 'HO Warehouse';
    }

    return `${alert.franchisee.shop_name} (${alert.franchisee.shop_code || 'N/A'})`;
}
</script>

<template>
    <Head title="Stock Alerts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">Stock Alerts</h2>
            </div>
        </template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-gray-500">Total Alerts</p>
                    <p class="mt-2 text-2xl font-black text-gray-900 dark:text-white">{{ summary.total }}</p>
                </div>
                <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-amber-700 dark:text-amber-300">Pending</p>
                    <p class="mt-2 text-2xl font-black text-amber-900 dark:text-amber-100">{{ summary.pending }}</p>
                </div>
                <div class="rounded-xl border border-rose-200 dark:border-rose-800 bg-rose-50 dark:bg-rose-900/20 p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-rose-700 dark:text-rose-300">Critical Pending</p>
                    <p class="mt-2 text-2xl font-black text-rose-900 dark:text-rose-100">{{ summary.critical_pending }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-8">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search product, batch, trigger, franchisee"
                    class="md:col-span-2 rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    @keyup.enter="applyFilters"
                />

                <select v-model="status" class="rounded-lg border-gray-300 bg-white text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" @change="applyFilters">
                    <option value="">All Status</option>
                    <option v-for="item in statusOptions" :key="item" :value="item">{{ item }}</option>
                </select>

                <select v-model="alertLevel" class="rounded-lg border-gray-300 bg-white text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" @change="applyFilters">
                    <option value="">All Levels</option>
                    <option v-for="item in levelOptions" :key="item" :value="item">{{ item }}</option>
                </select>

                <select v-model="alertType" class="rounded-lg border-gray-300 bg-white text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" @change="applyFilters">
                    <option value="">All Types</option>
                    <option v-for="item in typeOptions" :key="item" :value="item">{{ item }}</option>
                </select>

                <select v-model="location" class="rounded-lg border-gray-300 bg-white text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" @change="applyFilters">
                    <option value="">All Locations</option>
                    <option value="ho">HO only</option>
                    <option value="franchisee">Franchisee only</option>
                </select>

                <input v-model="from" type="date" class="rounded-lg border-gray-300 bg-white text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" @change="applyFilters" />
                <input v-model="to" type="date" class="rounded-lg border-gray-300 bg-white text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" @change="applyFilters" />
            </div>

            <div class="flex items-center gap-2">
                <button type="button" @click="applyFilters" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                    Apply
                </button>
                <button type="button" @click="clearFilters" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Reset
                </button>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">When</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Product</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Location</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Stock Snapshot</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Level</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60 text-sm">
                            <tr v-for="row in alerts.data" :key="row.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    <div class="font-semibold">{{ row.triggered_at }}</div>
                                    <div class="text-xs text-gray-500">{{ row.trigger_source || '-' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ row.product?.product_name || '-' }}</div>
                                    <div class="text-xs text-gray-500">SKU: {{ row.product?.sku || '-' }}</div>
                                    <div class="text-xs text-gray-500">Batch: {{ row.batch_no || '-' }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ locationLabel(row) }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    <div>Current: <span class="font-semibold">{{ row.current_qty }}</span></div>
                                    <div>Threshold: <span class="font-semibold">{{ row.threshold_qty }}</span></div>
                                    <div class="text-xs text-gray-500" v-if="row.expiry_date">Expiry: {{ row.expiry_date }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['inline-flex rounded-full border px-2.5 py-1 text-xs font-bold uppercase', toneClass(row.alert_level)]">
                                        {{ row.alert_level }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    <div class="font-semibold uppercase text-xs">{{ row.status }}</div>
                                    <div class="text-xs text-gray-500" v-if="row.acknowledged_by">By: {{ row.acknowledged_by.name }}</div>
                                    <div class="text-xs text-gray-500" v-if="row.acknowledged_at">At: {{ row.acknowledged_at }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2" v-if="row.status === 'pending'">
                                        <button type="button" @click="updateAlert(row.id, 'acknowledged')" class="rounded-md bg-sky-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-sky-500">Acknowledge</button>
                                        <button type="button" @click="updateAlert(row.id, 'resolved')" class="rounded-md bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">Resolve</button>
                                        <button type="button" @click="updateAlert(row.id, 'false_alarm')" class="rounded-md bg-slate-700 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-600">False Alarm</button>
                                    </div>
                                    <div v-else class="text-xs text-gray-500">No action required</div>
                                </td>
                            </tr>

                            <tr v-if="!alerts.data?.length">
                                <td colspan="7" class="px-6 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
                                    No stock alerts found for selected filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination :data="alerts" />
        </div>
    </AuthenticatedLayout>
</template>
