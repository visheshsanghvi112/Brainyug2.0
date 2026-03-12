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
});

const isCheckingOut = ref(false);

const addToCart = () => {
    addForm.post(route('b2b.cart.add'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset();
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
                            <div class="flex-1 w-full">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Select Product Base (HO allocates Batch)</label>
                                <select 
                                    v-model="addForm.product_id" 
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="" disabled>Search or select a product...</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ product.product_name }} ({{ product.sku }}) - {{ formatCurrency(product.rate_a) }}/unit
                                    </option>
                                </select>
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
                        
                        <div v-if="selectedProductDetails" class="mt-4 p-3 bg-blue-50 text-blue-800 rounded-md text-sm border border-blue-100 flex items-center justify-between">
                            <div>
                                <span class="font-semibold">Selected:</span> {{ selectedProductDetails.product_name }}
                                <span class="mx-2">|</span>
                                HSN: {{ selectedProductDetails.hsn?.hsn_code || 'N/A' }} 
                            </div>
                            <div class="font-bold">
                                Estimated Total: {{ formatCurrency(selectedProductDetails.rate_a * addForm.qty) }}
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
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Requested Qty</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Base Rate (Rate A)</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Free Qty Est.</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Line Total</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="item in cart.items" :key="item.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">{{ item.product?.product_name }}</div>
                                        <div class="text-xs text-gray-500">SKU: {{ item.product?.sku }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                        {{ item.qty }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                        {{ formatCurrency(item.rate) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                        <span v-if="item.free_qty > 0" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                            +{{ item.free_qty }} Free
                                        </span>
                                        <span v-else class="text-gray-400">-</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                                        {{ formatCurrency(item.total_amount) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button @click="removeItem(item.id)" class="text-red-500 hover:text-red-900 transition-colors p-1">
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
                <div v-if="cart?.items?.length" class="flex items-center justify-end p-6 bg-white border border-gray-100 shadow-sm sm:rounded-lg">
                    <div class="text-right">
                        <div class="text-sm text-gray-500 mb-1">Final Tax & Batch numbers will be applied upon HO Approval.</div>
                        <button 
                            @click="checkout" 
                            :disabled="isCheckingOut"
                            class="inline-flex justify-center items-center py-3 px-8 border border-transparent shadow-sm text-base font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
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
