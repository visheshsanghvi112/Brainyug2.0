<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { ArrowUturnLeftIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    returns: Object
});
</script>

<template>
    <Head title="Sales Returns" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-indigo-500 pl-4">Sales Returns</h2>
                <Link :href="route('pos.returns.create')" class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    <PlusIcon class="h-5 w-5" /> New Return
                </Link>
            </div>
        </template>

        <div class="bg-white shadow rounded-lg overflow-hidden border border-gray-200">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date / ID</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Reason</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Items</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Refund (₹)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-if="returns.data.length === 0">
                        <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                            <ArrowUturnLeftIcon class="h-12 w-12 mx-auto mb-2 text-gray-300" />
                            No sales returns recorded yet.
                        </td>
                    </tr>
                    <tr v-for="ret in returns.data" :key="ret.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-gray-900">{{ ret.return_no }}</div>
                            <div class="text-xs text-gray-500">{{ ret.return_date }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ ret.customer?.name || 'Walk-in' }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                            {{ ret.reason || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            {{ ret.items.length }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-mono font-bold text-rose-600">
                            ₹{{ Number(ret.total_refund_amount).toFixed(2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
            <Pagination :data="returns" class="mt-4" />
        </div>
    </AuthenticatedLayout>
</template>
