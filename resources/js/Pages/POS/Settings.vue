<script setup>
import { computed, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    preferences: {
        type: Object,
        default: () => ({}),
    },
});

const buildState = (preferences = {}) => ({
    round_off_enabled: preferences.round_off_enabled ?? true,
    round_off_mode: preferences.round_off_mode ?? 'nearest',
    supervisor_override_enabled: preferences.supervisor_override_enabled ?? true,
    supervisor_override_discount_threshold: Number(preferences.supervisor_override_discount_threshold ?? 15),
    receipt_layout: preferences.receipt_layout ?? 'thermal',
    auto_print_after_checkout: preferences.auto_print_after_checkout ?? true,
    printer_paper_width: preferences.printer_paper_width ?? '80mm',
    print_copies: Number(preferences.print_copies ?? 1),
    printer_name: preferences.printer_name ?? '',
    bill_logo_url: preferences.bill_logo_url ?? '',
    bill_header_line_1: preferences.bill_header_line_1 ?? '',
    bill_header_line_2: preferences.bill_header_line_2 ?? '',
    csv_format: preferences.csv_format ?? 'marg',
    auto_open_invoice_after_checkout: preferences.auto_open_invoice_after_checkout ?? true,
    auto_lock_bill_on_hold: preferences.auto_lock_bill_on_hold ?? false,
    smart_batch_suggestion: preferences.smart_batch_suggestion ?? true,
});

const form = useForm(buildState(props.preferences));

const hasErrors = computed(() => Object.keys(form.errors || {}).length > 0);
const effectiveRoundOffMode = computed(() => (form.round_off_enabled ? form.round_off_mode : 'none'));
const effectivePrintProfile = computed(() => {
    if (form.receipt_layout === 'a4' || form.printer_paper_width === 'a4') return 'A4';
    if (form.printer_paper_width === '58mm') return 'Thermal 58mm';
    return 'Thermal 80mm';
});

watch(
    () => form.round_off_enabled,
    (enabled) => {
        if (!enabled) {
            form.round_off_mode = 'none';
        } else if (form.round_off_mode === 'none') {
            form.round_off_mode = 'nearest';
        }
    }
);

watch(
    () => form.receipt_layout,
    (layout) => {
        if (layout === 'a4') {
            form.printer_paper_width = 'a4';
        } else if (form.printer_paper_width === 'a4') {
            form.printer_paper_width = '80mm';
        }
    }
);

const resetForm = () => {
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.supervisor_override_discount_threshold = Math.min(100, Math.max(0, Number(form.supervisor_override_discount_threshold || 0)));
    form.print_copies = Math.min(5, Math.max(1, Number(form.print_copies || 1)));

    if (!form.round_off_enabled) {
        form.round_off_mode = 'none';
    }

    form.patch(route('pos.settings.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="POS Settings" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                        <span class="inline-block"><svg class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 1.343-3 3s1.343 3 3 3 3-1.343 3-3-1.343-3-3-3zm0 10c-4.418 0-8-1.79-8-4V6c0-2.21 3.582-4 8-4s8 1.79 8 4v8c0 2.21-3.582 4-8 4z"/></svg></span>
                        POS Settings
                    </h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">These controls are saved per-user and applied during live checkout, hold, and print flows.</p>
                </div>
                <Link :href="route('pos.index')" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Back To POS</Link>
            </div>
        </template>

        <div class="max-w-5xl space-y-6">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/70 dark:bg-emerald-950/30 flex items-center gap-3">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Round-Off</p>
                        <p class="mt-2 text-sm font-medium text-emerald-900 dark:text-emerald-100">{{ effectiveRoundOffMode === 'none' ? 'Disabled' : `Mode: ${effectiveRoundOffMode}` }}</p>
                    </div>
                </div>
                <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 dark:border-cyan-900/70 dark:bg-cyan-950/30 flex items-center gap-3">
                    <svg class="w-5 h-5 text-cyan-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 12h8"/></svg>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">Supervisor Rule</p>
                        <p class="mt-2 text-sm font-medium text-cyan-900 dark:text-cyan-100">{{ form.supervisor_override_enabled ? `Threshold: ${form.supervisor_override_discount_threshold}%` : 'Disabled' }}</p>
                    </div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/70 dark:bg-amber-950/30 flex items-center gap-3">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M16 3v4M8 3v4"/></svg>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Print Profile</p>
                        <p class="mt-2 text-sm font-medium text-amber-900 dark:text-amber-100">{{ effectivePrintProfile }}</p>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6 pb-20">
                <div v-if="hasErrors" class="rounded-xl border-2 border-rose-400 bg-rose-50 p-4 text-sm text-rose-700 dark:border-rose-900/80 dark:bg-rose-950/60 dark:text-rose-200 flex items-center gap-2">
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
                    <span>Please fix the highlighted fields and save again.</span>
                </div>

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300 flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                            Round-Off
                        </h3>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">Live in checkout</span>
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700 cursor-pointer">
                            <input v-model="form.round_off_enabled" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" />
                            <span class="text-sm text-slate-700 dark:text-slate-200">Enable invoice round-off in checkout</span>
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Round-Off Mode</label>
                            <select v-model="form.round_off_mode" :disabled="!form.round_off_enabled" class="w-full rounded-lg border-slate-300 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-800">
                                <option value="nearest">Nearest Rupee</option>
                                <option value="up">Always Up</option>
                                <option value="down">Always Down</option>
                                <option value="none">No Round-Off</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Choose how rounding is applied to invoice totals.</p>
                            <p v-if="form.errors.round_off_mode" class="mt-1 text-xs text-rose-600 font-semibold">{{ form.errors.round_off_mode }}</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">Supervisor Override</h3>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">Enforced at checkout</span>
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <input v-model="form.supervisor_override_enabled" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" />
                            <span class="text-sm text-slate-700 dark:text-slate-200">Require supervisor approval for discounts above threshold</span>
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Discount Threshold (%)</label>
                            <input v-model.number="form.supervisor_override_discount_threshold" type="number" min="0" max="100" step="0.01" :disabled="!form.supervisor_override_enabled" class="w-full rounded-lg border-slate-300 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-800" />
                            <p class="mt-1 text-xs text-slate-500">Bill-level and line-level discounts above this value will require approval.</p>
                            <p v-if="form.errors.supervisor_override_discount_threshold" class="mt-1 text-xs text-rose-600">{{ form.errors.supervisor_override_discount_threshold }}</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">Print And Branding</h3>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">Applied in print window</span>
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Receipt Layout</label>
                            <select v-model="form.receipt_layout" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <option value="thermal">Thermal</option>
                                <option value="a4">A4</option>
                            </select>
                            <p v-if="form.errors.receipt_layout" class="mt-1 text-xs text-rose-600">{{ form.errors.receipt_layout }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Paper Width</label>
                            <select v-model="form.printer_paper_width" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <option value="58mm">58mm</option>
                                <option value="72mm">72mm</option>
                                <option value="80mm">80mm</option>
                                <option value="a4">A4</option>
                            </select>
                            <p v-if="form.errors.printer_paper_width" class="mt-1 text-xs text-rose-600">{{ form.errors.printer_paper_width }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Printer Name</label>
                            <input v-model="form.printer_name" type="text" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Epson TM-T82" />
                            <p v-if="form.errors.printer_name" class="mt-1 text-xs text-rose-600">{{ form.errors.printer_name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Print Copies</label>
                            <input v-model.number="form.print_copies" type="number" min="1" max="5" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                            <p v-if="form.errors.print_copies" class="mt-1 text-xs text-rose-600">{{ form.errors.print_copies }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Bill Logo URL</label>
                            <input v-model="form.bill_logo_url" type="text" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="https://example.com/logo.png" />
                            <p v-if="form.errors.bill_logo_url" class="mt-1 text-xs text-rose-600">{{ form.errors.bill_logo_url }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Header Line 1</label>
                            <input v-model="form.bill_header_line_1" type="text" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                            <p v-if="form.errors.bill_header_line_1" class="mt-1 text-xs text-rose-600">{{ form.errors.bill_header_line_1 }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Header Line 2</label>
                            <input v-model="form.bill_header_line_2" type="text" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                            <p v-if="form.errors.bill_header_line_2" class="mt-1 text-xs text-rose-600">{{ form.errors.bill_header_line_2 }}</p>
                        </div>
                    </div>
                    <label class="mt-4 flex items-center gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <input v-model="form.auto_print_after_checkout" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" />
                        <span class="text-sm text-slate-700 dark:text-slate-200">Auto print after checkout</span>
                    </label>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">Automation And Export</h3>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">Live behavior toggles</span>
                    </div>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <input v-model="form.auto_open_invoice_after_checkout" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" />
                            <span class="text-sm text-slate-700 dark:text-slate-200">Auto open invoice screen after checkout</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <input v-model="form.auto_lock_bill_on_hold" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" />
                            <span class="text-sm text-slate-700 dark:text-slate-200">Auto lock bill after hold</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700 md:col-span-2">
                            <input v-model="form.smart_batch_suggestion" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" />
                            <span class="text-sm text-slate-700 dark:text-slate-200">Enable smart batch suggestion (in-stock and near-expiry prioritization)</span>
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Default CSV Format</label>
                            <select v-model="form.csv_format" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                <option value="marg">Marg</option>
                                <option value="acme">ACME</option>
                                <option value="medvision">Medvision</option>
                            </select>
                            <p v-if="form.errors.csv_format" class="mt-1 text-xs text-rose-600">{{ form.errors.csv_format }}</p>
                        </div>
                    </div>
                </section>

                <div class="fixed bottom-4 right-4 left-4 md:left-auto md:w-[44rem] rounded-xl border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm text-slate-600 dark:text-slate-300">
                            <span v-if="form.isDirty">Unsaved changes</span>
                            <span v-else>All changes saved</span>
                            <span v-if="form.recentlySuccessful" class="ml-2 text-emerald-600 dark:text-emerald-400">Saved successfully.</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="resetForm" :disabled="form.processing || !form.isDirty" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">Reset</button>
                            <button type="submit" :disabled="form.processing" class="rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-60">{{ form.processing ? 'Saving...' : 'Save POS Settings' }}</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
