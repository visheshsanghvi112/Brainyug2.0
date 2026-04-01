<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ShoppingCartIcon, TrashIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';
import { PlusIcon, MinusIcon } from '@heroicons/vue/20/solid';

const props = defineProps({
    cart: Object,
    products: Array, // Catalog of products available for Franchisees
});

// Form for adding new items
const addForm = useForm({
    product_id: '',
    qty: 1,
    remark: '',
});

const searchQuery = ref('');
const showDropdown = ref(false);

const filteredProducts = computed(() => {
    if (!searchQuery.value) return props.products.slice(0, 100);
    const query = searchQuery.value.toLowerCase();
    return props.products.filter(p => 
        p.product_name.toLowerCase().includes(query) || 
        (p.sku && p.sku.toLowerCase().includes(query)) ||
        (p.salt?.name && p.salt.name.toLowerCase().includes(query))
    ).slice(0, 50);
});

const selectProduct = (product) => {
    addForm.product_id = product.id;
    searchQuery.value = product.product_name; // Just set name, let SKU be in details
    showDropdown.value = false;
};

const isCheckingOut = ref(false);
const updatingItems = ref({}); // Track loading state for individual items

const updateItem = (item) => {
    updatingItems.value[item.id] = true;
    router.patch(route('b2b.cart.updateQty', item.id), {
        qty: item.qty,
        remark: item.remark
    }, {
        preserveScroll: true,
        onFinish: () => {
            updatingItems.value[item.id] = false;
        }
    });
};

const addToCart = () => {
    addForm.post(route('b2b.cart.add'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset();
            searchQuery.value = '';
        },
    });
};

const removeItem = (itemId) => {
    if (confirm('Are you sure you want to remove this item?')) {
        router.delete(route('b2b.cart.remove', itemId), {
            preserveScroll: true,
        });
    }
};

const checkout = () => {
    isCheckingOut.value = true;
    router.post(route('b2b.cart.checkout'), {}, {
        onFinish: () => {
            isCheckingOut.value = false;
        }
    });
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR'
    }).format(amount || 0);
};

const selectedProductDetails = computed(() => {
    if (!addForm.product_id) return null;
    return props.products.find(p => p.id === addForm.product_id);
});
</script>

<template>
    <Head title="B2B Ordering Portal" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center gap-2">
                    <ShoppingCartIcon class="w-6 h-6 text-indigo-600" />
                    B2B Order Cart
                </h2>
                <div v-if="cart?.items?.length" class="text-lg font-bold text-gray-900 border px-4 py-2 rounded-lg bg-gray-50 shadow-sm">
                    Cart Total: <span class="text-indigo-600">{{ formatCurrency(cart.total_amount) }}</span>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Add Product Banner -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-100">
                    <div class="p-6 bg-gradient-to-r from-gray-50 to-white">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">Quick Add Product</h3>
                        
                        <form @submit.prevent="addToCart" class="flex flex-col md:flex-row gap-4 items-end">
                            <div class="flex-1 w-full relative">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Search Product (Name or SKU)</label>
                                <div class="relative">
                                    <input 
                                        type="text"
                                        v-model="searchQuery"
                                        @focus="showDropdown = true"
                                        placeholder="Type to search medicine..."
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                    <div v-if="showDropdown && filteredProducts.length" class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-60 overflow-y-auto">
                                        <div 
                                            v-for="product in filteredProducts" 
                                            :key="product.id"
                                            @click="selectProduct(product)"
                                            class="px-4 py-2 hover:bg-indigo-50 cursor-pointer border-b border-gray-50 last:border-0"
                                        >
                                            <div class="font-medium text-gray-900">{{ product.product_name }}</div>
                                            <div class="text-xs text-gray-500 flex justify-between">
                                                <span>SKU: {{ product.sku }}</span>
                                                <span class="font-bold text-indigo-600">{{ formatCurrency(product.rate_a) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else-if="showDropdown && searchQuery" class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg p-4 text-center text-gray-500 text-sm">
                                        No products found matching "{{ searchQuery }}"
                                    </div>
                                </div>
                            </div>

                            <div class="flex-1 w-full">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Remark (Optional)</label>
                                <input 
                                    type="text"
                                    v-model="addForm.remark"
                                    placeholder="Add any specific instructions..."
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>

                            <div class="w-full md:w-48">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity (Units)</label>
                                <div class="flex items-center">
                                    <button type="button" @click="addForm.qty > 1 ? addForm.qty-- : null" class="p-2 border border-gray-300 rounded-l-md bg-gray-50 hover:bg-gray-100">
                                        <MinusIcon class="w-5 h-5 text-gray-600" />
                                    </button>
                                    <input 
                                        type="number" 
                                        min="1" 
                                        v-model="addForm.qty" 
                                        class="block w-full border-y border-x-0 border-gray-300 text-center focus:ring-0 sm:text-sm"
                                        required
                                    />
                                    <button type="button" @click="addForm.qty++" class="p-2 border border-gray-300 rounded-r-md bg-gray-50 hover:bg-gray-100">
                                        <PlusIcon class="w-5 h-5 text-gray-600" />
                                    </button>
                                </div>
                            </div>
                            
                            <div class="w-full md:w-auto mt-2 md:mt-0">
                                <button 
                                    type="submit" 
                                    class="w-full inline-flex justify-center items-center py-2.5 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                    :disabled="addForm.processing || !addForm.product_id"
                                >
                                    Add to Cart
                                </button>
                            </div>
                        </form>
                        
                        <div v-if="selectedProductDetails" class="mt-4 p-4 bg-indigo-50 text-indigo-900 rounded-lg border border-indigo-100 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <div class="text-xs font-bold uppercase text-indigo-400">Selected Product</div>
                                <div class="font-semibold text-lg">{{ selectedProductDetails.product_name }}</div>
                                <div class="text-sm"><span class="font-medium">Salt:</span> {{ selectedProductDetails.salt?.name || 'N/A' }}</div>
                                <div class="text-sm"><span class="font-medium">Company:</span> {{ selectedProductDetails.company?.name || 'N/A' }}</div>
                            </div>
                            <div class="space-y-1 md:text-right flex flex-col justify-between">
                                <div class="text-sm">
                                    <span class="bg-indigo-200 text-indigo-800 px-2 py-0.5 rounded text-xs font-bold">SKU: {{ selectedProductDetails.sku }}</span>
                                    <span class="ml-2 bg-indigo-200 text-indigo-800 px-2 py-0.5 rounded text-xs font-bold">HSN: {{ selectedProductDetails.hsn?.hsn_code || 'N/A' }}</span>
                                </div>
                                <div class="text-sm">
                                    <span class="font-medium">Packing:</span> {{ selectedProductDetails.packing_desc || 'N/A' }} | 
                                    <span class="font-medium">Unit:</span> {{ selectedProductDetails.unit || 'N/A' }}
                                </div>
                                <div class="text-xl font-black text-indigo-700">
                                    Total Est: {{ formatCurrency(selectedProductDetails.rate_a * addForm.qty) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Cart View -->
                <div v-if="cart?.items?.length" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Info</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remark</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Rate</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Free Est.</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="item in cart.items" :key="item.id" class="hover:bg-gray-50 transition-colors" :class="{'opacity-50 pointer-events-none': updatingItems[item.id]}">
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-bold text-gray-900">{{ item.product?.product_name }}</div>
                                        <div class="text-xs text-gray-500 mt-1 uppercase tracking-tighter">
                                            SKU: {{ item.product?.sku }} | 
                                            {{ item.product?.packing_desc }} | 
                                            {{ item.product?.company?.name || 'N/A' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                        <div class="flex items-center justify-end group">
                                            <input 
                                                type="number" 
                                                v-model="item.qty" 
                                                @change="updateItem(item)"
                                                min="1"
                                                class="w-20 text-right border-gray-200 rounded-md focus:ring-indigo-500 focus:border-indigo-500 text-sm font-bold bg-transparent hover:bg-white"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <input 
                                            type="text" 
                                            v-model="item.remark" 
                                            @blur="updateItem(item)"
                                            placeholder="Add remark..."
                                            class="w-full border-transparent hover:border-gray-200 focus:border-indigo-500 focus:ring-0 bg-transparent hover:bg-white rounded-md text-sm transition-all"
                                        />
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-mono">
                                        {{ formatCurrency(item.rate) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                        <div v-if="item.free_qty > 0" class="inline-flex flex-col items-end">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-black bg-green-100 text-green-800 uppercase italic">
                                                +{{ item.free_qty }} Free
                                            </span>
                                            <span class="text-[10px] text-gray-400 mt-0.5">(Based on 10:1)</span>
                                        </div>
                                        <span v-else class="text-gray-300">-</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-black text-indigo-700 font-mono">
                                        {{ formatCurrency(item.total_amount) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button @click="removeItem(item.id)" class="text-gray-400 hover:text-red-600 p-2 rounded-lg transition-colors" title="Remove Item">
                                            <TrashIcon class="w-5 h-5" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Empty Cart State -->
                <div v-else class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-12 text-center">
                    <ShoppingCartIcon class="mx-auto h-12 w-12 text-gray-300 mb-4" />
                    <h3 class="text-lg font-medium text-gray-900">Your B2B Cart is empty</h3>
                    <p class="mt-1 text-sm text-gray-500">Search for products above to begin building your order.</p>
                </div>

                <!-- Checkout Footer -->
                <div v-if="cart?.items?.length" class="flex flex-col md:flex-row items-center justify-between p-6 bg-white border border-gray-100 shadow-sm sm:rounded-lg gap-4">
                    <div v-if="cart.total_amount < 10000" class="flex items-center gap-2 text-amber-600 bg-amber-50 px-4 py-2 rounded-lg border border-amber-200 flex-1 w-full md:w-auto">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                        <span class="text-sm font-medium">Minimum order {{ formatCurrency(10000) }} required (Current: {{ formatCurrency(cart.total_amount) }})</span>
                    </div>
                    <div v-else class="flex items-center gap-2 text-green-600 bg-green-50 px-4 py-2 rounded-lg border border-green-200 flex-1 w-full md:w-auto">
                        <CheckCircleIcon class="w-5 h-5" />
                        <span class="text-sm font-medium">Order ready for submission</span>
                    </div>

                    <div class="text-right flex flex-col md:items-end gap-2 w-full md:w-auto">
                        <div class="text-xs text-gray-400">Final Tax & Batches applied upon HO Approval.</div>
                        <button 
                            @click="checkout" 
                            :disabled="isCheckingOut || cart.total_amount < 10000"
                            class="inline-flex justify-center items-center py-3 px-10 border border-transparent shadow-sm text-base font-bold rounded-lg text-white transition-all"
                            :class="cart.total_amount < 10000 ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700 active:scale-95 shadow-green-200'"
                        >
                            <span v-if="isCheckingOut">Processing...</span>
                            <span v-else class="flex items-center gap-2">
                                <CheckCircleIcon class="w-5 h-5" />
                                Submit Order to HO
                            </span>
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
