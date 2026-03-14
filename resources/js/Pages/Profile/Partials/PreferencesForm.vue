<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';

const page = usePage();
const prefs = page.props.auth.user.preferences || {};

const form = useForm({
    email_orders: prefs.email_orders ?? true,
    email_tickets: prefs.email_tickets ?? true,
    email_reports: prefs.email_reports ?? false,
    sms_alerts: prefs.sms_alerts ?? false,
    desktop_alerts: prefs.desktop_alerts ?? true,
    weekly_digest: prefs.weekly_digest ?? false,
    language: prefs.language ?? 'English',
    timezone: prefs.timezone ?? 'Asia/Kolkata',
    printer_type: prefs.printer_type ?? 'laserJet',
    printer_connection: prefs.printer_connection ?? 'system_spooler',
    printer_paper_width: prefs.printer_paper_width ?? '80mm',
    printer_ip: prefs.printer_ip ?? '',
    printer_port: prefs.printer_port ?? 9100,
    printer_name: prefs.printer_name ?? '',
    printer_driver: prefs.printer_driver ?? 'browser_native',
    print_copies: prefs.print_copies ?? 1,
    auto_cut_receipt: prefs.auto_cut_receipt ?? true,
    open_cash_drawer: prefs.open_cash_drawer ?? false,
    epos_timeout_ms: prefs.epos_timeout_ms ?? 5000,
    show_purchase_rate: prefs.show_purchase_rate ?? false,
    csv_format: prefs.csv_format ?? 'marg',
    receipt_layout: prefs.receipt_layout ?? 'thermal',
    auto_print_after_checkout: prefs.auto_print_after_checkout ?? true,
    auto_open_invoice_after_checkout: prefs.auto_open_invoice_after_checkout ?? true,
    auto_lock_bill_on_hold: prefs.auto_lock_bill_on_hold ?? false,
    smart_batch_suggestion: prefs.smart_batch_suggestion ?? true,
    low_stock_daily_digest: prefs.low_stock_daily_digest ?? true,
});

const submit = () => {
    form.patch(route('profile.update-preferences'), {
        preserveScroll: true,
        onSuccess: () => {
            form.recentlySuccessful = true;
            setTimeout(() => form.recentlySuccessful = false, 2000);
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Workspace Preferences
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Fine tune alerts, billing output, and checkout automation for your daily workflow.
            </p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-7">
            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-400">Localization</p>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="language" value="Language" />
                        <select id="language" v-model="form.language" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option>English</option>
                            <option>Hindi</option>
                            <option>Marathi</option>
                            <option>Gujarati</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="timezone" value="Timezone" />
                        <select id="timezone" v-model="form.timezone" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option>Asia/Kolkata</option>
                            <option>America/New_York</option>
                            <option>Europe/London</option>
                            <option>Australia/Sydney</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-400">Notifications</p>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.email_orders" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Order updates by email</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.email_tickets" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Support ticket replies</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.email_reports" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Monthly business report</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.sms_alerts" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Critical alerts on SMS</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.desktop_alerts" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Desktop browser notifications</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.weekly_digest" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Weekly digest every Monday</span>
                    </label>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-400">POS And Printing</p>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="printer_type" value="Printer Type" />
                        <select id="printer_type" v-model="form.printer_type" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option value="dotMatrix">Legacy Dot Matrix (LX-310)</option>
                            <option value="laserJet">Legacy LaserJet / A4</option>
                            <option value="thermal">Generic Thermal</option>
                            <option value="epson_epos">Epson ePOS (Network)</option>
                            <option value="escpos_network">ESC/POS Network Printer</option>
                            <option value="escpos_usb">ESC/POS USB Printer</option>
                            <option value="browser_print">Browser Print Dialog</option>
                            <option value="generic_windows">Windows Installed Printer</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="receipt_layout" value="Receipt Layout" />
                        <select id="receipt_layout" v-model="form.receipt_layout" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option value="thermal">Thermal</option>
                            <option value="a4">A4</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="printer_connection" value="Connection" />
                        <select id="printer_connection" v-model="form.printer_connection" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option value="network">Network (LAN/Wi-Fi)</option>
                            <option value="usb">USB</option>
                            <option value="bluetooth">Bluetooth</option>
                            <option value="browser">Browser/Client Print</option>
                            <option value="system_spooler">OS Spooler</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="csv_format" value="Inventory CSV Format" />
                        <select id="csv_format" v-model="form.csv_format" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option value="marg">Marg</option>
                            <option value="acme">ACME</option>
                            <option value="medvision">Medvision</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="printer_driver" value="Driver Mode" />
                        <select id="printer_driver" v-model="form.printer_driver" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option value="epson_epos_sdk">Epson ePOS SDK</option>
                            <option value="escpos_raw">ESC/POS Raw</option>
                            <option value="windows_gdi">Windows GDI</option>
                            <option value="browser_native">Browser Native</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="printer_paper_width" value="Paper Width" />
                        <select id="printer_paper_width" v-model="form.printer_paper_width" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600">
                            <option value="58mm">58mm</option>
                            <option value="72mm">72mm</option>
                            <option value="80mm">80mm</option>
                            <option value="a4">A4</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="printer_name" value="Printer Name (Optional)" />
                        <input id="printer_name" v-model="form.printer_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 py-2 px-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" placeholder="e.g. Epson TM-T82" />
                    </div>
                    <div>
                        <InputLabel for="printer_ip" value="Printer IP (Network)" />
                        <input id="printer_ip" v-model="form.printer_ip" type="text" class="mt-1 block w-full rounded-md border-gray-300 py-2 px-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" placeholder="e.g. 192.168.1.50" />
                    </div>
                    <div>
                        <InputLabel for="printer_port" value="Port" />
                        <input id="printer_port" v-model="form.printer_port" type="number" min="1" max="65535" class="mt-1 block w-full rounded-md border-gray-300 py-2 px-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>
                    <div>
                        <InputLabel for="epos_timeout_ms" value="ePOS Timeout (ms)" />
                        <input id="epos_timeout_ms" v-model="form.epos_timeout_ms" type="number" min="500" max="20000" class="mt-1 block w-full rounded-md border-gray-300 py-2 px-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>
                    <div>
                        <InputLabel for="print_copies" value="Print Copies" />
                        <input id="print_copies" v-model="form.print_copies" type="number" min="1" max="5" class="mt-1 block w-full rounded-md border-gray-300 py-2 px-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.show_purchase_rate" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Show purchase rate in POS item details</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.auto_print_after_checkout" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Auto open print dialog after checkout</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.auto_cut_receipt" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Auto cut receipt (if supported)</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.open_cash_drawer" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Trigger cash drawer pulse</span>
                    </label>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-400">Automation</p>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.auto_open_invoice_after_checkout" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Open invoice summary after successful checkout</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.auto_lock_bill_on_hold" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Lock bill tabs after putting them on hold</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.smart_batch_suggestion" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Smart batch suggestion by expiry and stock</span>
                    </label>
                    <label class="flex items-start rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        <input v-model="form.low_stock_daily_digest" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900" />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-200">Daily low stock digest for assigned location</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Save Preferences</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600 dark:text-gray-400">Saved.</p>
                </Transition>
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                Legacy compatibility: legacy printer type, purchase rate visibility, and CSV format are mapped from old settings, while modern Epson ePOS and ESC/POS profiles are supported for new deployments.
            </p>

            <div v-if="Object.keys(form.errors).length" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300">
                Please review highlighted settings and try saving again.
            </div>
        </form>
    </section>
</template>
