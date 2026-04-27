<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    emailTemplate: {
        type: Object,
        default: null,
    },
    moduleSuggestions: {
        type: Array,
        default: () => [],
    },
});

const isEdit = computed(() => Boolean(props.emailTemplate));

const form = useForm({
    name: props.emailTemplate?.name || '',
    subject: props.emailTemplate?.subject || '',
    body: props.emailTemplate?.body || '',
    module: props.emailTemplate?.module || '',
    variablesText: (props.emailTemplate?.variables || []).join(', '),
    is_active: props.emailTemplate?.is_active ?? true,
});

function submit() {
    const payload = {
        name: form.name,
        subject: form.subject,
        body: form.body,
        module: form.module || null,
        variables: form.variablesText
            .split(',')
            .map((item) => item.trim())
            .filter((item) => item.length > 0),
        is_active: form.is_active,
    };

    if (isEdit.value) {
        form.transform(() => payload).put(route('admin.email-templates.update', props.emailTemplate.id));
        return;
    }

    form.transform(() => payload).post(route('admin.email-templates.store'));
}
</script>

<template>
    <Head :title="isEdit ? 'Edit Email Template' : 'New Email Template'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('admin.email-templates.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ isEdit ? 'Edit Email Template' : 'Create Email Template' }}</h2>
            </div>
        </template>

        <div class="max-w-4xl">
            <div class="rounded-2xl bg-white p-8 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <form class="space-y-6" @submit.prevent="submit">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Template Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                maxlength="120"
                                class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Module</label>
                            <input
                                v-model="form.module"
                                list="module-options"
                                type="text"
                                maxlength="100"
                                placeholder="e.g. Procurement"
                                class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                            />
                            <datalist id="module-options">
                                <option v-for="option in moduleSuggestions" :key="option" :value="option" />
                            </datalist>
                            <p v-if="form.errors.module" class="mt-1 text-xs text-red-500">{{ form.errors.module }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Email Subject</label>
                        <input
                            v-model="form.subject"
                            type="text"
                            maxlength="255"
                            class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                        />
                        <p v-if="form.errors.subject" class="mt-1 text-xs text-red-500">{{ form.errors.subject }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Template Body</label>
                        <textarea
                            v-model="form.body"
                            rows="12"
                            class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                            placeholder="Use placeholders like {{franchise_name}}, {{order_number}}, {{amount}}"
                        />
                        <p v-if="form.errors.body" class="mt-1 text-xs text-red-500">{{ form.errors.body }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Variables (comma separated)</label>
                        <input
                            v-model="form.variablesText"
                            type="text"
                            placeholder="franchise_name, order_number, amount"
                            class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                        />
                        <p v-if="form.errors.variables" class="mt-1 text-xs text-red-500">{{ form.errors.variables }}</p>
                        <p v-if="form.errors['variables.0']" class="mt-1 text-xs text-red-500">{{ form.errors['variables.0'] }}</p>
                    </div>

                    <label class="flex items-center gap-3 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        <div>
                            <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Active Template</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Inactive templates stay archived and cannot be selected in future mail flows.</div>
                        </div>
                    </label>

                    <div class="flex justify-end gap-3 pt-2">
                        <Link
                            :href="route('admin.email-templates.index')"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {{ isEdit ? 'Update Template' : 'Create Template' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
