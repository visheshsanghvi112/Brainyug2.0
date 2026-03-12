<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { CurrencyRupeeIcon, TableCellsIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    ledgers: Object,
    context: String
});
</script>

<template>
    <Head title="Financial Ledger" />
    <div class="h-screen flex flex-col bg-gray-50">
        <header class="bg-gray-900 shadow text-white shrink-0">
            <div class="px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <CurrencyRupeeIcon class="w-8 h-8 text-emerald-400" />
                    <div>
                        <h1 class="text-xl font-bold tracking-tight">Financial Ledger</h1>
                        <p class="text-xs text-gray-400 uppercase tracking-widest">{{ context }}</p>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-6">
            <div class="bg-white shadow rounded-lg overflow-hidden border border-gray-200">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100 uppercase text-xs font-bold text-gray-600">
                            <tr>
                                <th class="px-6 py-4 text-left tracking-wider w-32">Date</th>
                                <th class="px-6 py-4 text-left tracking-wider">Voucher / Type</th>
                                <th class="px-6 py-4 text-left tracking-wider">Narration</th>
                                <th class="px-6 py-4 text-right tracking-wider text-rose-600 w-32">Debit (-)</th>
                                <th class="px-6 py-4 text-right tracking-wider text-emerald-600 w-32">Credit (+)</th>
                                <th class="px-6 py-4 text-right tracking-wider text-blue-700 w-40">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 bg-stripes">
                            <tr v-if="ledgers.data.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500 bg-gray-50">
                                    <TableCellsIcon class="w-12 h-12 mx-auto text-gray-300 mb-2" />
                                    No ledger entries found for this account.
                                </td>
                            </tr>
                            <tr v-for="entry in ledgers.data" :key="entry.id" class="hover:bg-blue-50/30 transition-colors group">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-mono">{{ entry.transaction_date }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-xs font-bold text-gray-900 border border-gray-200 bg-gray-50 rounded px-2 py-0.5 inline-block">{{ entry.voucher_no }}</div>
                                    <div class="text-xs text-indigo-600 font-semibold mt-1">{{ entry.transaction_type }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div v-if="entry.payment_mode" class="text-xs font-semibold text-gray-400 uppercase">{{ entry.payment_mode }}</div>
                                    {{ entry.narration || '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-mono tracking-tight font-bold text-rose-600">
                                    {{ Number(entry.debit) > 0 ? Number(entry.debit).toFixed(2) : '' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-mono tracking-tight font-bold text-emerald-600">
                                    {{ Number(entry.credit) > 0 ? Number(entry.credit).toFixed(2) : '' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-mono tracking-tight font-bold" :class="Number(entry.running_balance) >= 0 ? 'text-blue-700 bg-blue-50/50' : 'text-red-700 bg-red-50/50'">
                                    ₹{{ Number(entry.running_balance).toFixed(2) }}
                                    <span class="text-xs ml-1 text-gray-400 font-sans tracking-normal font-normal">
                                        {{ Number(entry.running_balance) >= 0 ? 'CR' : 'DR' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 flex justify-between items-center" v-if="ledgers.total > 0">
                    <span class="text-sm text-gray-500">Showing {{ ledgers.from }} to {{ ledgers.to }} of {{ ledgers.total }} entries</span>
                    <div class="flex gap-2">
                        <template v-for="(link, index) in (ledgers.links || []).filter(Boolean)" :key="link.label || `ledger-link-${index}`">
                            <Link v-if="link.url" :href="link.url" v-html="link.label" class="px-3 py-1 text-sm rounded border border-gray-300" :class="link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'" />
                            <span v-else v-html="link.label" class="px-3 py-1 text-sm rounded border border-gray-300 text-gray-300" />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
