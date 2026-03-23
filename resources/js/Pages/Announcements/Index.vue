<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { CalendarDaysIcon, MegaphoneIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    announcements: Object,
    filters: Object,
    canManage: Boolean,
});

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');
const deletingId = ref(null);
const deleteForm = useForm({});

const activeCount = computed(() => (props.announcements?.data || []).filter((item) => item.is_active).length);

function applyFilters() {
    router.get(route('announcements.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function destroyAnnouncement(id) {
    deletingId.value = id;
    deleteForm.delete(route('announcements.destroy', id), {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
        },
    });
}

function fmtDate(value) {
    if (!value) return 'Always active';
    return new Date(value).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head title="Notice Board" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Notice Board</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Operational announcements and rollout notes for the network.</p>
                </div>
                <Link
                    v-if="canManage"
                    :href="route('announcements.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    <MegaphoneIcon class="h-4 w-4" />
                    New Announcement
                </Link>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3 mb-6">
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Visible Notices</div>
                <div class="mt-2 text-3xl font-black text-gray-900 dark:text-white">{{ announcements?.total ?? 0 }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Active In This Page</div>
                <div class="mt-2 text-3xl font-black text-emerald-600">{{ activeCount }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Management</div>
                <div class="mt-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    {{ canManage ? 'You can publish, edit, and archive notices.' : 'You can read notices targeted to your role.' }}
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-4 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 mb-6">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search title or body"
                    class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                    @keyup.enter="applyFilters"
                />
                <select
                    v-if="canManage"
                    v-model="status"
                    class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive / scheduled / expired</option>
                </select>
                <button
                    class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                    @click="applyFilters"
                >
                    Apply Filters
                </button>
                <button
                    class="rounded-xl bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    @click="() => { search = ''; status = ''; applyFilters(); }"
                >
                    Reset
                </button>
            </div>
        </div>

        <div class="space-y-4">
            <div
                v-for="announcement in announcements.data"
                :key="announcement.id"
                class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                :class="announcement.is_pinned ? 'ring-amber-300 bg-amber-50/30 dark:bg-amber-900/10' : ''"
            >
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span v-if="announcement.is_pinned" class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-700">Pinned</span>
                            <span :class="announcement.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700'" class="rounded-full px-2.5 py-0.5 text-xs font-bold">
                                {{ announcement.is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700">
                                {{ announcement.target_roles?.length ? announcement.target_roles.join(', ') : 'All roles' }}
                            </span>
                        </div>
                        <h3 class="text-lg font-black text-gray-900 dark:text-white">{{ announcement.title }}</h3>
                        <p class="mt-3 whitespace-pre-line text-sm leading-6 text-gray-600 dark:text-gray-300">{{ announcement.body }}</p>

                        <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                            <div class="flex items-center gap-1.5">
                                <CalendarDaysIcon class="h-4 w-4" />
                                <span>From {{ fmtDate(announcement.valid_from) }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <CalendarDaysIcon class="h-4 w-4" />
                                <span>Until {{ fmtDate(announcement.valid_to) }}</span>
                            </div>
                            <div>By {{ announcement.creator?.name || 'System' }}</div>
                        </div>
                    </div>

                    <div v-if="canManage" class="flex shrink-0 items-center gap-2">
                        <Link
                            :href="route('announcements.edit', announcement.id)"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                        >
                            <PencilSquareIcon class="h-4 w-4" />
                            Edit
                        </Link>
                        <button
                            class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-100 disabled:opacity-50"
                            :disabled="deleteForm.processing && deletingId === announcement.id"
                            @click="destroyAnnouncement(announcement.id)"
                        >
                            <TrashIcon class="h-4 w-4" />
                            Archive
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="!announcements.data.length" class="rounded-2xl bg-white p-10 text-center text-gray-400 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                No announcements found.
            </div>
        </div>

        <Pagination :data="announcements" class="mt-6" />
    </AuthenticatedLayout>
</template>