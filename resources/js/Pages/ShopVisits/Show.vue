<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    visit: Object,
});

const checklistLabels = {
    shop_cleanliness: 'Shop Cleanliness',
    product_display: 'Product Display',
    stock_adequacy: 'Stock Adequacy',
    staff_in_uniform: 'Staff in Uniform',
    pos_system_working: 'POS System Working',
    license_displayed: 'License Displayed',
    fridge_maintained: 'Fridge Maintained',
    feedback_register: 'Feedback Register Updated',
};

function scoreColor(score) {
    if (score >= 80) return 'text-emerald-600';
    if (score >= 60) return 'text-amber-600';
    return 'text-red-600';
}
</script>

<template>
    <Head title="Shop Visit Audit" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('shop-visits.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                        Audit: {{ visit.franchisee?.shop_name }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        Visited {{ new Date(visit.visit_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' }) }}
                        &bull; Audited by {{ visit.auditor?.name }}
                    </p>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 max-w-5xl">

            <!-- Main -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Score & Notes -->
                <div class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <div class="flex items-center gap-6 mb-4">
                        <div class="text-center">
                            <p class="text-4xl font-bold" :class="scoreColor(visit.inspection_score)">{{ visit.inspection_score }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">out of 100</p>
                        </div>
                        <div class="flex-1">
                            <div class="h-3 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                                <div class="h-full rounded-full transition-all"
                                    :style="{ width: visit.inspection_score + '%' }"
                                    :class="visit.inspection_score >= 80 ? 'bg-emerald-500' : visit.inspection_score >= 60 ? 'bg-amber-500' : 'bg-red-500'" />
                            </div>
                        </div>
                    </div>
                    <div v-if="visit.notes" class="border-t border-gray-100 dark:border-gray-700 pt-4">
                        <h4 class="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Observations</h4>
                        <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed">{{ visit.notes }}</p>
                    </div>
                </div>

                <!-- Checklist -->
                <div v-if="visit.checklist" class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Inspection Checklist</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div v-for="(passed, key) in visit.checklist" :key="key"
                            :class="['flex items-center gap-3 rounded-lg px-4 py-3', passed ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-red-50 dark:bg-red-900/20']">
                            <span :class="['flex-shrink-0 rounded-full p-0.5', passed ? 'bg-emerald-500' : 'bg-red-500']">
                                <component :is="passed ? CheckIcon : XMarkIcon" class="h-3.5 w-3.5 text-white" />
                            </span>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ checklistLabels[key] ?? key }}</span>
                        </div>
                    </div>
                </div>

                <!-- Photos -->
                <div v-if="visit.photos?.length" class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Photos</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <img v-for="(photo, i) in visit.photos" :key="i" :src="'/storage/' + photo"
                            class="rounded-lg w-full h-36 object-cover" :alt="'Photo ' + (i+1)" />
                    </div>
                </div>
            </div>

            <!-- Sidebar Details -->
            <div class="space-y-4">
                <div class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Audit Details</h4>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Shop</dt>
                            <dd class="font-medium text-gray-900 dark:text-white text-right">{{ visit.franchisee?.shop_name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">District</dt>
                            <dd class="text-gray-700 dark:text-gray-300">{{ visit.franchisee?.district }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Auditor</dt>
                            <dd class="text-gray-700 dark:text-gray-300">{{ visit.auditor?.name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Status</dt>
                            <dd>
                                <span :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    visit.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700']">
                                    {{ visit.status }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Checklist Pass</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">
                                {{ visit.checklist ? Object.values(visit.checklist).filter(Boolean).length : 0 }}
                                / {{ visit.checklist ? Object.keys(visit.checklist).length : 0 }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
