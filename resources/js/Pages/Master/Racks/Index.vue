<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    PencilIcon,
    PlusIcon,
    Squares2X2Icon,
    TrashIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    sections: Array,
});

const selectedSectionId = ref(props.sections?.[0]?.id ?? null);
const showSectionModal = ref(false);
const showAreaModal = ref(false);
const editingSection = ref(null);
const editingArea = ref(null);

const sectionForm = useForm({
    name: '',
    description: '',
    status: true,
});

const areaForm = useForm({
    rack_section_id: selectedSectionId.value || '',
    name: '',
    status: true,
});

const selectedSection = computed(() => props.sections.find((section) => section.id === selectedSectionId.value) || null);

function openCreateSection() {
    editingSection.value = null;
    sectionForm.reset();
    sectionForm.status = true;
    showSectionModal.value = true;
}

function openEditSection(section) {
    editingSection.value = section;
    sectionForm.name = section.name;
    sectionForm.description = section.description || '';
    sectionForm.status = !!section.status;
    showSectionModal.value = true;
}

function saveSection() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showSectionModal.value = false;
            sectionForm.reset();
        },
    };

    if (editingSection.value) {
        sectionForm.put(route('admin.rack-layout.sections.update', editingSection.value.id), options);
        return;
    }

    sectionForm.post(route('admin.rack-layout.sections.store'), options);
}

function deleteSection(section) {
    if (confirm(`Delete rack section "${section.name}"?`)) {
        router.delete(route('admin.rack-layout.sections.destroy', section.id), { preserveScroll: true });
    }
}

function openCreateArea() {
    if (!selectedSection.value) {
        return;
    }

    editingArea.value = null;
    areaForm.reset();
    areaForm.rack_section_id = selectedSection.value.id;
    areaForm.status = true;
    showAreaModal.value = true;
}

function openEditArea(area) {
    editingArea.value = area;
    areaForm.rack_section_id = area.rack_section_id;
    areaForm.name = area.name;
    areaForm.status = !!area.status;
    showAreaModal.value = true;
}

function saveArea() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showAreaModal.value = false;
            areaForm.reset();
            areaForm.rack_section_id = selectedSection.value?.id || '';
        },
    };

    if (editingArea.value) {
        areaForm.put(route('admin.rack-layout.areas.update', editingArea.value.id), options);
        return;
    }

    areaForm.post(route('admin.rack-layout.areas.store'), options);
}

function deleteArea(area) {
    if (confirm(`Delete rack area "${area.name}"?`)) {
        router.delete(route('admin.rack-layout.areas.destroy', area.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Rack Layout" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Squares2X2Icon class="h-7 w-7 text-indigo-500" />
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Rack Layout</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Manage warehouse rack sections and their physical sub-areas.</p>
                    </div>
                </div>
                <button @click="openCreateSection" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    <PlusIcon class="h-4 w-4" />
                    Add Section
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[0.95fr_1.05fr] sm:px-6 lg:px-8">
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Sections</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Each section represents a top-level rack block in the warehouse.</p>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
                        <div
                            v-for="section in sections"
                            :key="section.id"
                            class="flex w-full items-start justify-between gap-4 px-6 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-700/20"
                            :class="selectedSectionId === section.id ? 'bg-indigo-50 dark:bg-indigo-950/20' : ''"
                            @click="selectedSectionId = section.id"
                        >
                            <div>
                                <div class="flex items-center gap-3">
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ section.name }}</h4>
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="section.status ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                        {{ section.status ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ section.description || 'No description added.' }}</p>
                                <p class="mt-2 text-xs uppercase tracking-wide text-gray-400">{{ section.areas_count }} area(s) • {{ section.products_count }} mapped product(s)</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" class="rounded-lg p-2 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-gray-700" @click.stop="openEditSection(section)">
                                    <PencilIcon class="h-4 w-4" />
                                </button>
                                <button type="button" class="rounded-lg p-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-gray-700" @click.stop="deleteSection(section)">
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <div v-if="!sections.length" class="px-6 py-10 text-center text-sm text-gray-400 dark:text-gray-500">No rack sections created yet.</div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Areas</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                <span v-if="selectedSection">Sub-locations inside {{ selectedSection.name }}.</span>
                                <span v-else>Select a section to manage its areas.</span>
                            </p>
                        </div>
                        <button :disabled="!selectedSection" @click="openCreateArea" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">
                            <PlusIcon class="h-4 w-4" />
                            Add Area
                        </button>
                    </div>

                    <div v-if="selectedSection" class="divide-y divide-gray-100 dark:divide-gray-700/60">
                        <div v-for="area in selectedSection.all_areas" :key="area.id" class="flex items-center justify-between gap-4 px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-700/20">
                            <div>
                                <div class="flex items-center gap-3">
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ area.name }}</h4>
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="area.status ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                        {{ area.status ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs uppercase tracking-wide text-gray-400">{{ area.products_count }} mapped product(s)</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" class="rounded-lg p-2 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-gray-700" @click="openEditArea(area)">
                                    <PencilIcon class="h-4 w-4" />
                                </button>
                                <button type="button" class="rounded-lg p-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-gray-700" @click="deleteArea(area)">
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <div v-if="!selectedSection.all_areas?.length" class="px-6 py-10 text-center text-sm text-gray-400 dark:text-gray-500">No rack areas created for this section yet.</div>
                    </div>
                    <div v-else class="px-6 py-12 text-center text-sm text-gray-400 dark:text-gray-500">Pick a rack section from the left to manage its areas.</div>
                </section>
            </div>
        </div>

        <div v-if="showSectionModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="showSectionModal = false" />
            <div class="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ editingSection ? 'Edit Rack Section' : 'Add Rack Section' }}</h3>
                <form class="mt-5 space-y-4" @submit.prevent="saveSection">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Section Name</label>
                        <input v-model="sectionForm.name" type="text" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                        <p v-if="sectionForm.errors.name" class="mt-1 text-xs text-red-500">{{ sectionForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                        <textarea v-model="sectionForm.description" rows="3" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600"></textarea>
                        <p v-if="sectionForm.errors.description" class="mt-1 text-xs text-red-500">{{ sectionForm.errors.description }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                        <select v-model="sectionForm.status" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                            <option :value="true">Active</option>
                            <option :value="false">Inactive</option>
                        </select>
                        <p v-if="sectionForm.errors.status" class="mt-1 text-xs text-red-500">{{ sectionForm.errors.status }}</p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700" @click="showSectionModal = false">Cancel</button>
                        <button type="submit" :disabled="sectionForm.processing" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">Save Section</button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="showAreaModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="showAreaModal = false" />
            <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ editingArea ? 'Edit Rack Area' : 'Add Rack Area' }}</h3>
                <form class="mt-5 space-y-4" @submit.prevent="saveArea">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Area Name</label>
                        <input v-model="areaForm.name" type="text" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-emerald-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                        <p v-if="areaForm.errors.name" class="mt-1 text-xs text-red-500">{{ areaForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                        <select v-model="areaForm.status" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-emerald-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                            <option :value="true">Active</option>
                            <option :value="false">Inactive</option>
                        </select>
                        <p v-if="areaForm.errors.status" class="mt-1 text-xs text-red-500">{{ areaForm.errors.status }}</p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700" @click="showAreaModal = false">Cancel</button>
                        <button type="submit" :disabled="areaForm.processing" class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">Save Area</button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>