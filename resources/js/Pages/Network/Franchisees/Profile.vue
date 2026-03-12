<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    BuildingStorefrontIcon, ArrowLeftIcon, CheckCircleIcon,
    XCircleIcon, PlayIcon, PauseIcon, PencilSquareIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    franchisee: Object,
});

const f = props.franchisee;

const rejectReason = ref('');
const shopCode = ref(f.shop_code || '');
const showRejectModal = ref(false);
const showApproveModal = ref(false);

function approve() {
    router.post(route('admin.franchisees.approve', f.id), {
        shop_code: shopCode.value,
    });
}

function reject() {
    router.post(route('admin.franchisees.reject', f.id), {
        rejection_reason: rejectReason.value,
    });
}

function activate() {
    router.post(route('admin.franchisees.activate', f.id));
}

function suspend() {
    if (confirm('Are you sure you want to suspend this franchisee?')) {
        router.post(route('admin.franchisees.suspend', f.id));
    }
}

const statusColors = {
    enquiry: 'bg-sky-100 text-sky-800 border-sky-200',
    registered: 'bg-amber-100 text-amber-800 border-amber-200',
    approved: 'bg-blue-100 text-blue-800 border-blue-200',
    rejected: 'bg-red-100 text-red-800 border-red-200',
    active: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    suspended: 'bg-orange-100 text-orange-800 border-orange-200',
    banned: 'bg-gray-100 text-gray-800 border-gray-200',
};

const infoSections = [
    {
        title: 'Owner & Shop',
        items: [
            { label: 'Shop Name', value: f.shop_name },
            { label: 'Shop Code', value: f.shop_code },
            { label: 'Shop Type', value: f.shop_type },
            { label: 'Owner', value: `${f.owner_title || ''} ${f.owner_name}`.trim() },
            { label: 'Partner', value: f.partner_name },
            { label: 'DOB', value: f.owner_dob },
            { label: 'Education', value: f.education },
            { label: 'Occupation', value: f.occupation },
        ]
    },
    {
        title: 'Contact',
        items: [
            { label: 'Mobile', value: f.mobile },
            { label: 'WhatsApp', value: f.whatsapp },
            { label: 'Email', value: f.email },
            { label: 'Alternate', value: f.alternate_phone },
        ]
    },
    {
        title: 'Location',
        items: [
            { label: 'Address', value: f.address },
            { label: 'State', value: f.state?.name },
            { label: 'District', value: f.district?.name },
            { label: 'City', value: f.city?.name || f.other_city },
            { label: 'Pincode', value: f.pincode },
        ]
    },
    {
        title: 'Legal & Compliance',
        items: [
            { label: 'GST', value: f.gst_number },
            { label: 'PAN', value: f.pan_number },
            { label: 'DL (20B)', value: f.dl_number_20b },
            { label: 'DL (21B)', value: f.dl_number_21b },
            { label: 'FSSAI', value: f.fssai_number },
        ]
    },
    {
        title: 'Banking',
        items: [
            { label: 'Bank', value: f.bank_name },
            { label: 'Account Holder', value: f.bank_account_holder },
            { label: 'Account No', value: f.bank_account_number },
            { label: 'IFSC', value: f.bank_ifsc },
            { label: 'Branch', value: f.bank_branch },
            { label: 'UTR', value: f.utr_number },
            { label: 'Transaction Date', value: f.transaction_date },
            { label: 'Investment', value: f.investment_amount ? `₹${f.investment_amount}` : null },
        ]
    },
    {
        title: 'Hierarchy',
        items: [
            { label: 'District Head', value: f.district_head?.name },
            { label: 'Zone Head', value: f.zone_head?.name },
            { label: 'State Head', value: f.state_head?.name },
            { label: 'Approved By', value: f.approved_by?.name },
        ]
    },
];
</script>

<template>
    <Head :title="`${f.shop_name} - Profile`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('admin.franchisees.index')" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300 transition">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ f.shop_name }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ f.owner_name }} · {{ f.mobile }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span :class="statusColors[f.status]" class="rounded-full border px-4 py-1.5 text-sm font-semibold capitalize">
                        {{ f.status }}
                    </span>
                    <Link
                        :href="route('admin.franchisees.edit', f.id)"
                        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600 transition"
                    >
                        <PencilSquareIcon class="h-4 w-4" /> Edit
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8 space-y-6">

                <!-- Action Bar (Approval Workflow) -->
                <div v-if="['registered', 'enquiry', 'approved', 'active', 'suspended'].includes(f.status)" class="rounded-xl bg-white p-4 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400 mr-auto">Quick Actions:</span>

                        <!-- Approve -->
                        <button v-if="['registered', 'enquiry'].includes(f.status)"
                            @click="showApproveModal = true"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 transition">
                            <CheckCircleIcon class="h-5 w-5" /> Approve
                        </button>

                        <!-- Reject -->
                        <button v-if="['registered', 'enquiry'].includes(f.status)"
                            @click="showRejectModal = true"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500 transition">
                            <XCircleIcon class="h-5 w-5" /> Reject
                        </button>

                        <!-- Activate -->
                        <button v-if="f.status === 'approved'"
                            @click="activate"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 transition">
                            <PlayIcon class="h-5 w-5" /> Activate Shop
                        </button>

                        <!-- Suspend -->
                        <button v-if="f.status === 'active'"
                            @click="suspend"
                            class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500 transition">
                            <PauseIcon class="h-5 w-5" /> Suspend
                        </button>

                        <!-- Re-activate -->
                        <button v-if="f.status === 'suspended'"
                            @click="activate"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 transition">
                            <PlayIcon class="h-5 w-5" /> Re-Activate
                        </button>
                    </div>
                </div>

                <!-- Rejection reason display -->
                <div v-if="f.status === 'rejected' && f.rejection_reason" class="rounded-xl border-l-4 border-red-500 bg-red-50 p-4 dark:bg-red-900/20">
                    <p class="text-sm font-medium text-red-800 dark:text-red-300">Rejection Reason:</p>
                    <p class="text-sm text-red-700 dark:text-red-400">{{ f.rejection_reason }}</p>
                </div>

                <!-- Info Sections -->
                <div v-for="section in infoSections" :key="section.title" class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">{{ section.title }}</h3>
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="item in section.items.filter(i => i.value)" :key="item.label">
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ item.label }}</dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ item.value }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Timestamps -->
                <div class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Timeline</h3>
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Registered</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ f.created_at || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Approved</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ f.approved_at || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Activated</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ f.activated_at || '—' }}</dd>
                        </div>
                        <div v-if="f.deactivated_at">
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Deactivated</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ f.deactivated_at }}</dd>
                        </div>
                    </dl>
                </div>

            </div>
        </div>

        <!-- Approve Modal -->
        <Teleport to="body">
            <div v-if="showApproveModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showApproveModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Approve Franchisee</h3>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shop Code (optional)</label>
                        <input v-model="shopCode" type="text" maxlength="20" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono uppercase" placeholder="e.g., BYG-0042" />
                    </div>
                    <div class="flex justify-end gap-3">
                        <button @click="showApproveModal = false" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                        <button @click="approve" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Approve</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Reject Modal -->
        <Teleport to="body">
            <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showRejectModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Reject Franchisee</h3>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason *</label>
                        <textarea v-model="rejectReason" rows="3" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" placeholder="Explain why this application is being rejected..."></textarea>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button @click="showRejectModal = false" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                        <button @click="reject" :disabled="!rejectReason" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500 disabled:opacity-50">Reject</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
