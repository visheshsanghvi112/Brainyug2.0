<template>
  <AuthenticatedLayout>
    <Head title="Purchase Orders" />

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <div>
                <h1 class="text-3xl font-bold text-gray-900">Purchase Orders</h1>
                <p class="mt-1 text-sm text-gray-600">Manage procurement requests sent to suppliers</p>
              </div>
              <Link href="/admin/purchase-orders/create">
                <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                  + New Purchase Order
                </button>
              </Link>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search by Order/Supplier</label>
                <input
                  v-model="filters.search"
                  type="text"
                  placeholder="Search..."
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                  @keyup="applyFilters"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select
                  v-model="filters.status"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                  @change="applyFilters"
                >
                  <option value="">All Statuses</option>
                  <option v-for="status in statuses" :key="status" :value="status">
                    {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                  </option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Supplier</label>
                <select
                  v-model="filters.supplier_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                  @change="applyFilters"
                >
                  <option value="">All Suppliers</option>
                  <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                    {{ supplier.name }}
                  </option>
                </select>
              </div>
              <div>
                <button
                  @click="clearFilters"
                  class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 mt-6"
                >
                  Clear Filters
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Table -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order Number</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order Date</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="po in purchaseOrders.data" :key="po.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm font-medium text-blue-600">
                  <Link :href="`/admin/purchase-orders/${po.id}`">
                    {{ po.order_number }}
                  </Link>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ po.supplier.name }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ formatDate(po.order_date) }}</td>
                <td class="px-6 py-4 text-sm text-gray-900 font-semibold">₹{{ formatCurrency(po.total_amount) }}</td>
                <td class="px-6 py-4 text-sm">
                  <span :class="getStatusClass(po.status)" class="px-3 py-1 rounded-full text-xs font-semibold">
                    {{ po.status.charAt(0).toUpperCase() + po.status.slice(1) }}
                  </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ po.created_by?.name || 'System' }}</td>
                <td class="px-6 py-4 text-sm space-x-2">
                  <Link :href="`/admin/purchase-orders/${po.id}`">
                    <button class="text-blue-600 hover:text-blue-800 underline">View</button>
                  </Link>
                  <Link v-if="po.status === 'draft'" :href="`/admin/purchase-orders/${po.id}/edit`">
                    <button class="text-yellow-600 hover:text-yellow-800 underline">Edit</button>
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="mt-6 flex justify-center">
          <nav class="flex space-x-2">
            <Link v-if="purchaseOrders.prev_page_url" :href="purchaseOrders.prev_page_url">
              <button class="px-3 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">Prev</button>
            </Link>
            <span class="px-3 py-2">Page {{ purchaseOrders.current_page }} of {{ purchaseOrders.last_page }}</span>
            <Link v-if="purchaseOrders.next_page_url" :href="purchaseOrders.next_page_url">
              <button class="px-3 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">Next</button>
            </Link>
          </nav>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
  purchaseOrders: Object,
  suppliers: Array,
  statuses: Array,
  filters: Object,
});

const filters = ref(props.filters || {});

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('en-IN');
};

const formatCurrency = (amount) => {
  return parseFloat(amount).toLocaleString('en-IN', { maximumFractionDigits: 2 });
};

const getStatusClass = (status) => {
  const classes = {
    draft: 'bg-gray-100 text-gray-800',
    approved: 'bg-blue-100 text-blue-800',
    sent: 'bg-indigo-100 text-indigo-800',
    received: 'bg-yellow-100 text-yellow-800',
    invoiced: 'bg-green-100 text-green-800',
    cancelled: 'bg-red-100 text-red-800',
  };
  return classes[status] || 'bg-gray-100 text-gray-800';
};

const applyFilters = () => {
  // Typically handled through router query
  window.location.href = buildFilterUrl();
};

const buildFilterUrl = () => {
  const params = new URLSearchParams();
  if (filters.value.search) params.append('search', filters.value.search);
  if (filters.value.status) params.append('status', filters.value.status);
  if (filters.value.supplier_id) params.append('supplier_id', filters.value.supplier_id);
  return `/admin/purchase-orders?${params.toString()}`;
};

const clearFilters = () => {
  filters.value = {};
  window.location.href = '/admin/purchase-orders';
};
</script>
