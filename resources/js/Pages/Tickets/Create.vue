<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const form = useForm({
    subject:     '',
    description: '',
    priority:    'normal',
});

function submit() {
    form.post(route('tickets.store'));
}
</script>

<template>
    <Head title="Raise a Support Ticket" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('tickets.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Raise a Support Ticket</h2>
            </div>
        </template>

        <div class="max-w-2xl">
            <div class="rounded-2xl bg-white dark:bg-gray-800 p-8 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                    Describe your issue clearly. Our team will respond as soon as possible.
                </p>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject *</label>
                        <input v-model="form.subject" type="text" required maxlength="255"
                            placeholder="e.g. Login issue, Missing stock, Billing error…"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        <p v-if="form.errors.subject" class="mt-1 text-xs text-red-500">{{ form.errors.subject }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Priority *</label>
                        <select v-model="form.priority"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent — system is down</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description *</label>
                        <textarea v-model="form.description" required rows="6"
                            placeholder="Explain the issue in detail. Include steps to reproduce, expected outcome, etc."
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        <p v-if="form.errors.description" class="mt-1 text-xs text-red-500">{{ form.errors.description }}</p>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <Link :href="route('tickets.index')"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                            Cancel
                        </Link>
                        <button type="submit" :disabled="form.processing"
                            class="rounded-lg bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                            Submit Ticket
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
