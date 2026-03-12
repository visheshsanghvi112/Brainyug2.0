<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { UsersIcon, MagnifyingGlassIcon, PencilIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    customers: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const showForm = ref(false);
const editing = ref(null); // customer being edited

const form = ref({ name: '', mobile: '', address: '' });

function applyFilters() {
    router.get(route('customers.index'), { search: search.value || undefined }, { preserveState: true, preserveScroll: true });
}
let timer;
watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 300); });

function openCreate() {
    editing.value = null;
    form.value = { name: '', mobile: '', address: '' };
    showForm.value = true;
}

function openEdit(customer) {
    editing.value = customer;
    form.value = { name: customer.name, mobile: customer.mobile, address: customer.address ?? '' };
    showForm.value = true;
}

function submitForm() {
    if (editing.value) {
        router.patch(route('customers.update', editing.value.id), form.value, {
            onSuccess: () => { showForm.value = false; },
        });
    } else {
        router.post(route('customers.store'), form.value, {
            onSuccess: () => { showForm.value = false; form.value = { name: '', mobile: '', address: '' }; },
        });
    }
}

function deleteCustomer(customer) {
    if (!confirm(`Remove customer "${customer.name}"?`)) return;
    router.delete(route('customers.destroy', customer.id));
}

function fmt(val) {
    return Number(val || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Customers" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <UsersIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Customer Directory</h2>
                </div>
                <button @click="openCreate"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-all">
                    <PlusIcon class="h-5 w-5" /> Add Customer
                </button>
            </div>
        </template>

        <!-- Add/Edit Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white dark:bg-gray-800 p-6 shadow-2xl">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    {{ editing ? 'Edit Customer' : 'Add Customer' }}
                </h3>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Name</label>
                        <input v-model="form.name" type="text" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mobile</label>
                        <input v-model="form.mobile" type="tel" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address (optional)</label>
                        <textarea v-model="form.address" rows="2"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showForm = false"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                        <button type="submit"
                            class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                            {{ editing ? 'Save Changes' : 'Add Customer' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="space-y-5">
            <!-- Search -->
            <div class="relative max-w-sm">
                <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                <input v-model="search" type="text" placeholder="Search by name or mobile…"
                    class="w-full rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 pl-9 pr-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-xl bg-white dark:bg-gray-800 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Name</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Mobile</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Address</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Bills</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Total Spend</th>
                            <th class="px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                        <tr v-if="customers.data.length === 0">
                            <td colspan="6" class="py-16 text-center text-gray-400">No customers found.</td>
                        </tr>
                        <tr v-for="c in customers.data" :key="c.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                            <td class="px-5 py-4 font-medium text-gray-900 dark:text-white">{{ c.name }}</td>
                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">{{ c.mobile }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate">{{ c.address ?? '—' }}</td>
                            <td class="px-5 py-4 text-right text-sm text-gray-900 dark:text-white">{{ c.sales_invoices_count ?? 0 }}</td>
                            <td class="px-5 py-4 text-right text-sm font-semibold text-indigo-600 dark:text-indigo-400">₹{{ fmt(c.total_spend) }}</td>
                            <td class="px-5 py-4 text-center">
                                <div class="inline-flex gap-2">
                                    <button @click="openEdit(c)"
                                        class="rounded p-1.5 text-gray-500 hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-900/30 transition-colors">
                                        <PencilIcon class="h-4 w-4" />
                                    </button>
                                    <button @click="deleteCustomer(c)"
                                        class="rounded p-1.5 text-gray-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30 transition-colors">
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->

                <Pagination :data="customers" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
