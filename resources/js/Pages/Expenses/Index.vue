<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { CreditCardIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    expenses: Object
});

const formatCurrency = (val) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(val);
</script>

<template>
    <Head title="Operational Expenses" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-rose-500 pl-4">Expense Tracker</h2>
                <Link :href="route('expenses.create')" class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-md font-bold flex items-center gap-2 shadow-lg shadow-rose-100">
                    <PlusIcon class="h-5 w-5" /> Log Expense
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div class="bg-white shadow rounded-xl border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 text-xs font-bold uppercase text-gray-500">
                        <tr>
                            <th class="px-6 py-4 text-left">Date / Voucher</th>
                            <th class="px-6 py-4 text-left">Category</th>
                            <th class="px-6 py-4 text-left">Vendor/Details</th>
                            <th class="px-6 py-4 text-center">Mode</th>
                            <th class="px-6 py-4 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-if="expenses.data.length === 0">
                            <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                                <CreditCardIcon class="h-12 w-12 mx-auto mb-2 text-gray-200" />
                                No expenses recorded. Start logging your daily operational costs.
                            </td>
                        </tr>
                        <tr v-for="exp in expenses.data" :key="exp.id" class="hover:bg-rose-50/30 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-sm font-bold text-gray-900">{{ exp.voucher_number }}</div>
                                <div class="text-xs text-gray-500">{{ exp.expense_date }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-bold uppercase">{{ exp.expense_category?.name }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-700">{{ exp.vendor_name || 'N/A' }}</div>
                                <div class="text-xs text-gray-400 italic">{{ exp.narration }}</div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="capitalize text-xs font-semibold text-gray-500">{{ exp.payment_mode }}</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="text-sm font-bold text-gray-900">{{ formatCurrency(exp.total_amount) }}</div>
                                <div class="text-[10px] text-gray-400">GST: {{ formatCurrency(exp.gst_amount) }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->
                
                <Pagination :data="expenses" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
