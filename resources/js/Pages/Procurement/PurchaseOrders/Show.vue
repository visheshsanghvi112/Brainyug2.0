<template>
  <AuthenticatedLayout>
    <Head title="Purchase Order Detail" />

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center mb-4">
              <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ purchaseOrder.order_number }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ purchaseOrder.supplier.name }}</p>
              </div>
              <div>
                <span :class="getStatusClass(purchaseOrder.status)" class="px-4 py-2 rounded-full text-lg font-semibold">
                  {{ purchaseOrder.status.charAt(0).toUpperCase() + purchaseOrder.status.slice(1) }}
                </span>
              </div>
            </div>
            <div class="grid grid-cols-4 gap-4 mt-6">
              <div>
                <label class="text-sm text-gray-600">Order Date</label>
                <p class="text-lg font-semibold">{{ formatDate(purchaseOrder.order_date) }}</p>
              </div>
              <div>
                <label class="text-sm text-gray-600">Required Date</label>
                <p class="text-lg font-semibold">{{ formatDate(purchaseOrder.expected_delivery_date) }}</p>
              </div>
              <div>
                <label class="text-sm text-gray-600">Total Amount</label>
                <p class="text-lg font-semibold text-green-600">₹{{ formatCurrency(purchaseOrder.total_amount) }}</p>
              </div>
              <div>
                <label class="text-sm text-gray-600">Financial Year</label>
                <p class="text-lg font-semibold">{{ purchaseOrder.financial_year }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Order Items</h2>
            <table class="w-full">
              <thead class="bg-gray-50 border-b">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-600">Product</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-600">Unit</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-600">Qty Ordered</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-600">Qty Received</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-600">Rate</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-600">Amount</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-600">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="item in purchaseOrder.items" :key="item.id" class="hover:bg-gray-50">
                  <td class="px-4 py-3 text-sm text-gray-900">
                    <div class="font-medium">{{ item.product?.product_name || 'Unknown Product' }}</div>
                    <div class="text-xs text-gray-600">Batch: {{ item.batch_no || 'N/A' }}</div>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900">{{ item.unit }}</td>
                  <td class="px-4 py-3 text-sm text-right font-medium">{{ item.qty_ordered }}</td>
                  <td class="px-4 py-3 text-sm text-right font-medium">{{ item.qty_received }}</td>
                  <td class="px-4 py-3 text-sm text-right">₹{{ formatCurrency(item.rate) }}</td>
                  <td class="px-4 py-3 text-sm text-right">₹{{ formatCurrency(item.line_amount) }}</td>
                  <td class="px-4 py-3 text-sm text-right font-semibold">₹{{ formatCurrency(item.line_total) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Summary -->
          <div class="p-6 bg-gray-50">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
              <div>
                <label class="text-sm text-gray-600">Subtotal</label>
                <p class="text-lg font-semibold">₹{{ formatCurrency(purchaseOrder.subtotal) }}</p>
              </div>
              <div>
                <label class="text-sm text-gray-600">Tax</label>
                <p class="text-lg font-semibold">₹{{ formatCurrency(purchaseOrder.sgst_amount + purchaseOrder.cgst_amount + purchaseOrder.igst_amount) }}</p>
              </div>
              <div>
                <label class="text-sm text-gray-600">Discount</label>
                <p class="text-lg font-semibold">₹{{ formatCurrency(purchaseOrder.discount_amount) }}</p>
              </div>
              <div class="bg-white p-4 rounded">
                <label class="text-sm text-gray-600">Total Amount</label>
                <p class="text-2xl font-bold text-green-600">₹{{ formatCurrency(purchaseOrder.total_amount) }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Actions</h2>
            <div class="flex space-x-3">
              <Link v-if="purchaseOrder.status === 'draft'" :href="`/admin/purchase-orders/${purchaseOrder.id}/edit`">
                <button class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">Edit</button>
              </Link>
              <button
                v-if="purchaseOrder.status === 'draft'"
                @click="approve"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
              >
                Approve
              </button>
              <button
                v-if="purchaseOrder.status === 'approved'"
                @click="send"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
              >
                Send to Supplier
              </button>
              <button
                v-if="['sent', 'approved'].includes(purchaseOrder.status)"
                @click="openReceiveModal"
                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
              >
                Mark as Received
              </button>
              <button
                v-if="purchaseOrder.status === 'received'"
                @click="openConvertModal"
                class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
              >
                Convert to Invoice
              </button>
              <button
                v-if="['draft', 'approved'].includes(purchaseOrder.status)"
                @click="cancelPO"
                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
              >
                Cancel
              </button>
              <Link :href="route('admin.purchase-orders.index')">
                <button class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">Back to List</button>
              </Link>
            </div>
          </div>
        </div>

        <!-- Notes -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Notes</h2>
            <p class="text-gray-600 whitespace-pre-line">{{ purchaseOrder.notes || 'No notes' }}</p>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
  purchaseOrder: Object,
});

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

const approve = async () => {
  if (confirm('Approve this purchase order?')) {
    router.post(route('admin.purchase-orders.approve', props.purchaseOrder.id));
  }
};

const send = async () => {
  if (confirm('Send this purchase order to supplier?')) {
    router.post(route('admin.purchase-orders.send', props.purchaseOrder.id), {
      notes: props.purchaseOrder.notes || null,
    });
  }
};

const openReceiveModal = () => {
  if (!confirm('Mark all line quantities as received?')) {
    return;
  }

  router.post(route('admin.purchase-orders.receive', props.purchaseOrder.id), {
    received_date: new Date().toISOString().split('T')[0],
    transporter: '',
    lr_number: '',
    transport_cost: 0,
    items: (props.purchaseOrder.items || []).map((item) => ({
      id: item.id,
      qty_received: Number(item.qty_ordered || 0),
      qty_rejected: 0,
    })),
  });
};

const openConvertModal = () => {
  const supplierInvoiceNo = prompt('Enter supplier invoice number:');
  if (!supplierInvoiceNo) {
    return;
  }

  const today = new Date().toISOString().split('T')[0];
  router.post(route('admin.purchase-orders.convert-to-invoice', props.purchaseOrder.id), {
    supplier_invoice_no: supplierInvoiceNo,
    invoice_date: today,
    received_date: today,
  });
};

const cancelPO = async () => {
  if (confirm('Cancel this purchase order? This action cannot be undone.')) {
    const reason = prompt('Cancellation reason:');
    if (!reason) {
      return;
    }

    router.post(route('admin.purchase-orders.cancel', props.purchaseOrder.id), {
      cancellation_reason: reason,
    });
  }
};
</script>
