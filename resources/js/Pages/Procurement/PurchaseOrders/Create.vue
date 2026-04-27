<template>
  <AuthenticatedLayout>
    <Head title="Create Purchase Order" />

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <h1 class="text-3xl font-bold text-gray-900">Create Purchase Order</h1>
            <p class="mt-1 text-sm text-gray-600">Prepare a new procurement request to send to supplier</p>
          </div>
        </div>

        <!-- Form -->
        <form @submit.prevent="submit" class="space-y-6">
          <!-- Header Section -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h2 class="text-lg font-semibold text-gray-900 mb-4">Order Details</h2>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Order Number</label>
                  <input
                    type="text"
                    v-model="form.order_number"
                    disabled
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Financial Year</label>
                  <input
                    type="text"
                    v-model="form.financial_year"
                    disabled
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50"
                  />
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Supplier *</label>
                  <select
                    v-model="form.supplier_id"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                    @change="onSupplierChange"
                  >
                    <option value="">Select Supplier</option>
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                      {{ supplier.name }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Order Date *</label>
                  <input
                    type="date"
                    v-model="form.order_date"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Required Delivery Date *</label>
                  <input
                    type="date"
                    v-model="form.required_date"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                  />
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Tax Type *</label>
                  <select
                    v-model="form.tax_type"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                  >
                    <option value="intra_state">Intra-State (SGST+CGST)</option>
                    <option value="inter_state">Inter-State (IGST)</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Line Items Section -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Line Items</h2>
                <button
                  type="button"
                  @click="addLineItem"
                  class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                  + Add Item
                </button>
              </div>

              <div v-if="form.items.length === 0" class="text-center py-8 text-gray-500">
                No items added yet. Click "Add Item" to get started.
              </div>

              <div v-for="(item, index) in form.items" :key="index" class="mb-6 pb-6 border-b">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Product *</label>
                    <select
                      v-model="item.product_id"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                      @change="() => onProductChange(index)"
                    >
                      <option value="">Select Product</option>
                      <option v-for="product in products" :key="product.id" :value="product.id">
                        {{ product.product_name }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Unit *</label>
                    <input
                      type="text"
                      v-model="item.unit"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                      placeholder="e.g., BOX"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Qty Ordered *</label>
                    <input
                      type="number"
                      v-model.number="item.qty_ordered"
                      min="1"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                      @change="() => calculateLineItem(index)"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">MRP *</label>
                    <input
                      type="number"
                      v-model.number="item.mrp"
                      min="0"
                      step="0.01"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                    />
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Rate *</label>
                    <input
                      type="number"
                      v-model.number="item.rate"
                      min="0"
                      step="0.01"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                      @change="() => calculateLineItem(index)"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">GST % *</label>
                    <input
                      type="number"
                      v-model.number="item.gst_percent"
                      min="0"
                      max="100"
                      step="0.01"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                      @change="() => calculateLineItem(index)"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Batch No</label>
                    <input
                      type="text"
                      v-model="item.batch_no"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Mfg Date</label>
                    <input
                      type="date"
                      v-model="item.mfg_date"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Expiry Date</label>
                    <input
                      type="date"
                      v-model="item.expiry_date"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                    />
                  </div>
                </div>

                <div class="flex justify-between items-center">
                  <div class="text-sm text-gray-600">
                    Line Total: <span class="font-semibold">₹{{ formatCurrency(calculateItemTotal(item)) }}</span>
                  </div>
                  <button
                    type="button"
                    @click="removeLineItem(index)"
                    class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700"
                  >
                    Remove
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Summary Section -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h2 class="text-lg font-semibold text-gray-900 mb-4">Payment Summary</h2>
              <div class="space-y-3">
                <div class="flex justify-between text-gray-600">
                  <span>Subtotal:</span>
                  <span>₹{{ formatCurrency(calculateSubtotal()) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                  <span>Discount Amount:</span>
                  <input
                    type="number"
                    v-model.number="form.discount_amount"
                    min="0"
                    step="0.01"
                    class="w-24 px-2 py-1 border border-gray-300 rounded"
                  />
                </div>
                <div class="flex justify-between text-gray-600 border-t pt-3">
                  <span class="font-semibold">Total Amount:</span>
                  <span class="text-2xl font-bold text-green-600">₹{{ formatCurrency(calculateTotal()) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Notes Section -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h2 class="text-lg font-semibold text-gray-900 mb-4">Additional Notes</h2>
              <textarea
                v-model="form.notes"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg h-24"
                placeholder="Add any special instructions or notes..."
              ></textarea>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex space-x-3">
            <button
              type="submit"
              class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold"
            >
              Create Purchase Order
            </button>
            <Link :href="route('admin.purchase-orders.index')">
              <button type="button" class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                Cancel
              </button>
            </Link>
          </div>
        </form>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
  suppliers: Array,
  products: Array,
  orderNumber: String,
  currentFY: String,
});

const form = useForm({
  order_number: props.orderNumber,
  financial_year: props.currentFY,
  supplier_id: '',
  order_date: new Date().toISOString().split('T')[0],
  required_date: '',
  tax_type: 'intra_state',
  items: [],
  discount_amount: 0,
  notes: '',
});

const addLineItem = () => {
  form.items.push({
    product_id: '',
    qty_ordered: 1,
    rate: 0,
    mrp: 0,
    gst_percent: 0,
    unit: 'pcs',
    batch_no: '',
    mfg_date: '',
    expiry_date: '',
    discount_percent: 0,
  });
};

const removeLineItem = (index) => {
  form.items.splice(index, 1);
};

const onSupplierChange = () => {
  // Can be used to populate supplier defaults
};

const onProductChange = (index) => {
  const selected = props.products.find((p) => p.id === form.items[index].product_id);
  if (!selected) return;

  form.items[index].mrp = Number(selected.mrp || 0);
  form.items[index].rate = Number(selected.ptr || selected.rate_a || selected.mrp || 0);
  form.items[index].unit = selected.unit || 'pcs';
};

const calculateLineItem = (index) => {
  const item = form.items[index];
  item.line_amount = (item.qty_ordered * item.rate);
  item.gst_amount = (item.line_amount * item.gst_percent) / 100;
  item.line_total = item.line_amount + item.gst_amount;
};

const calculateItemTotal = (item) => {
  const lineAmount = (item.qty_ordered || 0) * (item.rate || 0);
  const gstAmount = (lineAmount * (item.gst_percent || 0)) / 100;
  return lineAmount + gstAmount;
};

const calculateSubtotal = () => {
  return form.items.reduce((sum, item) => sum + calculateItemTotal(item), 0);
};

const calculateTotal = () => {
  return Math.max(0, calculateSubtotal() - (form.discount_amount || 0));
};

const formatCurrency = (amount) => {
  return parseFloat(amount || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
};

const submit = () => {
  if (form.items.length === 0) {
    alert('Please add at least one item');
    return;
  }
  form.post(route('admin.purchase-orders.store'));
};
</script>
