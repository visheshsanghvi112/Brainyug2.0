<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    purchase: Object,
    canReview: Boolean,
    canApprove: Boolean,
    canReject: Boolean,
    canCancel: Boolean,
    canEdit: Boolean,
});

const rejectForm = useForm({ rejection_reason: '' });
const cancelForm = useForm({ reason: '' });

const approve = () => {
    if (!confirm('Approve this outside purchase and post stock?')) return;
    router.post(route('admin.franchisee-purchases.approve', props.purchase.id));
};

const reject = () => {
    const reason = prompt('Enter rejection reason:');
    if (!reason) return;
    rejectForm.rejection_reason = reason;
    rejectForm.post(route('admin.franchisee-purchases.reject', props.purchase.id));
};

const cancelPurchase = () => {
    const reason = prompt('Enter cancellation reason:');
    if (!reason) return;
    cancelForm.reason = reason;
    cancelForm.post(route('admin.franchisee-purchases.cancel', props.purchase.id));
};

const amount = (v) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(Number(v || 0));
const dt = (v) => v ? new Date(v).toLocaleDateString('en-IN') : '-';
const approvalTone = (s) => s === 'approved' ? 'bg-green-100 text-green-700 border-green-200' : (s === 'rejected' ? 'bg-red-100 text-red-700 border-red-200' : 'bg-yellow-100 text-yellow-700 border-yellow-200');
</script>

<template>
    <Head :title="`Outside Purchase ${purchase.transaction_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ purchase.transaction_number }}</h2>
                    <p class="text-sm text-gray-600 mt-1">{{ purchase.franchisee?.shop_name }} | {{ purchase.supplier?.name }}</p>
                </div>
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-black" :class="approvalTone(purchase.approval_status)">
                    {{ purchase.approval_status }}
                </span>
            </div>
        </template>

        <div class="py-6 lg:py-10">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">
                <div class="bg-white rounded-xl border border-gray-100 p-4 grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Purchase Date</div>
                        <div class="font-semibold">{{ dt(purchase.purchase_date) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Received Date</div>
                        <div class="font-semibold">{{ dt(purchase.received_date) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Reason</div>
                        <div class="font-semibold">{{ purchase.reason_code }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Record Status</div>
                        <div class="font-semibold">{{ purchase.status }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Total</div>
                        <div class="font-semibold text-emerald-700">{{ amount(purchase.total_amount) }}</div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Batch</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Qty</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Rate</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">GST</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="line in purchase.items" :key="line.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm">{{ line.product?.product_name }} <span class="text-gray-400">({{ line.product?.sku }})</span></td>
                                    <td class="px-4 py-3 text-sm">{{ line.batch_no }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ line.qty }} + {{ line.free_qty }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ amount(line.rate) }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ line.gst_percent }}%</td>
                                    <td class="px-4 py-3 text-sm text-right font-semibold">{{ amount(line.total_amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Subtotal</div>
                        <div class="font-semibold">{{ amount(purchase.subtotal) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">SGST</div>
                        <div class="font-semibold">{{ amount(purchase.sgst_amount) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">CGST</div>
                        <div class="font-semibold">{{ amount(purchase.cgst_amount) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">IGST</div>
                        <div class="font-semibold">{{ amount(purchase.igst_amount) }}</div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-4">
                    <div class="font-semibold text-sm mb-1">Notes</div>
                    <div class="text-sm text-gray-700 whitespace-pre-line">{{ purchase.notes || 'No notes' }}</div>
                    <div v-if="purchase.rejection_reason" class="mt-3 rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                        Rejection reason: {{ purchase.rejection_reason }}
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Link :href="route('admin.franchisee-purchases.index')" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">Back</Link>
                    <Link v-if="canEdit" :href="route('admin.franchisee-purchases.edit', purchase.id)" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500">Edit Draft</Link>
                    <button v-if="canApprove" type="button" @click="approve" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Approve</button>
                    <button v-if="canReject" type="button" @click="reject" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">Reject</button>
                    <button v-if="canCancel" type="button" @click="cancelPurchase" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-600">Cancel & Reverse</button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
