<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    announcement: {
        type: Object,
        default: null,
    },
    roleOptions: {
        type: Array,
        default: () => [],
    },
});

const isEdit = computed(() => Boolean(props.announcement));

const form = useForm({
    title: props.announcement?.title || '',
    body: props.announcement?.body || '',
    target_roles: props.announcement?.target_roles || [],
    valid_from: props.announcement?.valid_from ? String(props.announcement.valid_from).slice(0, 16) : '',
    valid_to: props.announcement?.valid_to ? String(props.announcement.valid_to).slice(0, 16) : '',
    is_pinned: props.announcement?.is_pinned ?? false,
    is_active: props.announcement?.is_active ?? true,
});

function submit() {
    if (isEdit.value) {
        form.put(route('announcements.update', props.announcement.id));
        return;
    }

    form.post(route('announcements.store'));
}
</script>

<template>
    <Head :title="isEdit ? 'Edit Announcement' : 'New Announcement'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('announcements.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ isEdit ? 'Edit Announcement' : 'Publish Announcement' }}</h2>
            </div>
        </template>

        <div class="max-w-4xl">
            <div class="rounded-2xl bg-white p-8 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <form class="space-y-6" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Title</label>
                        <input
                            v-model="form.title"
                            type="text"
                            maxlength="255"
                            class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                        />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Notice Body</label>
                        <textarea
                            v-model="form.body"
                            rows="8"
                            class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                            placeholder="Write the operational announcement, rollout note, or franchise communication."
                        />
                        <p v-if="form.errors.body" class="mt-1 text-xs text-red-500">{{ form.errors.body }}</p>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Target Roles</label>
                        <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                            <label
                                v-for="role in roleOptions"
                                :key="role"
                                class="flex items-center gap-2 rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                            >
                                <input v-model="form.target_roles" :value="role" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                <span>{{ role }}</span>
                            </label>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Leave all unchecked to broadcast to every role.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Valid From</label>
                            <input
                                v-model="form.valid_from"
                                type="datetime-local"
                                class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                            />
                            <p v-if="form.errors.valid_from" class="mt-1 text-xs text-red-500">{{ form.errors.valid_from }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Valid To</label>
                            <input
                                v-model="form.valid_to"
                                type="datetime-local"
                                class="w-full rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                            />
                            <p v-if="form.errors.valid_to" class="mt-1 text-xs text-red-500">{{ form.errors.valid_to }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                            <input v-model="form.is_pinned" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <div>
                                <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Pin this announcement</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Pinned notices stay at the top of the board.</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                            <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <div>
                                <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Active</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Inactive notices remain in history but are hidden from users.</div>
                            </div>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <Link
                            :href="route('announcements.index')"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {{ isEdit ? 'Update Announcement' : 'Publish Announcement' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>