<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    Bars3Icon,
    XMarkIcon,
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
    HomeIcon,
    ShoppingBagIcon,
    ArchiveBoxIcon,
    UsersIcon,
    BuildingStorefrontIcon,
    TruckIcon,
    ArrowUturnLeftIcon,
    ArrowUturnRightIcon,
    DocumentTextIcon,
    CurrencyRupeeIcon,
    WalletIcon,
    CreditCardIcon,
    ChartPieIcon,
    ChartBarSquareIcon,
    ShieldCheckIcon,
    BeakerIcon,
    TagIcon,
    Squares2X2Icon,
    ClipboardDocumentListIcon,
    ReceiptRefundIcon,
    ClipboardDocumentCheckIcon,
} from '@heroicons/vue/24/outline';
import { ChevronDownIcon, MagnifyingGlassIcon, SunIcon, MoonIcon } from '@heroicons/vue/20/solid';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const roles = computed(() => user.value?.roles || []);
const moduleAccess = computed(() => user.value?.module_access || {});
const supportAccess = computed(() => page.props.supportAccess || page.props.impersonation || null);

const roleAliases = {
    'Super Admin': ['Super Admin'],
    'Admin': ['Admin'],
    'State Head': ['State Head'],
    'Regional Head': ['Regional Head', 'Sister Head'],
    'Zonal Head': ['Zonal Head', 'Zone Head'],
    'District Head': ['District Head'],
    'Franchisee': ['Franchisee', 'Franchisee Staff'],
    'Distributer': ['Distributer', 'Distributor'],
    'Account': ['Account', 'Payment Manager'],
    'Sales Team': ['Sales Team', 'Sales Staff'],
};

const hasRole = (role) => (roleAliases[role] || [role]).some((candidate) => roles.value.includes(candidate));
const hasModuleAccess = (module, action = 'view') => Boolean(moduleAccess.value?.[module]?.[action]);
const isAdmin = computed(() => hasRole('Super Admin') || hasRole('Admin'));
const isTerritoryHead = computed(() => hasRole('State Head') || hasRole('Regional Head') || hasRole('Zonal Head') || hasRole('District Head'));
const isFranchisee = computed(() => hasRole('Franchisee'));
const isDistributer = computed(() => hasRole('Distributer'));
const isAccount = computed(() => hasRole('Account'));
const isSalesTeam = computed(() => hasRole('Sales Team'));

const homeSurface = computed(() => {
    if (isAccount.value && hasModuleAccess('ledger')) {
        return {
            name: 'Finance Desk',
            href: route('ledger.index'),
            current: route().current('ledger.*'),
            icon: WalletIcon,
        };
    }

    if (isDistributer.value && hasModuleAccess('dist_orders')) {
        return {
            name: 'Order Desk',
            href: route('admin.dist-orders.index'),
            current: route().current('admin.dist-orders.*'),
            icon: TruckIcon,
        };
    }

    if (isSalesTeam.value && hasModuleAccess('franchisees')) {
        return {
            name: 'Network Desk',
            href: route('admin.franchisees.index'),
            current: route().current('admin.franchisees.*'),
            icon: BuildingStorefrontIcon,
        };
    }

    return {
        name: 'Dashboard',
        href: route('dashboard'),
        current: route().current('dashboard'),
        icon: HomeIcon,
    };
});

const navigation = computed(() => {
    const items = [
        { name: homeSurface.value.name, href: homeSurface.value.href, icon: homeSurface.value.icon, current: homeSurface.value.current, show: hasModuleAccess('dashboard'), category: 'Main' },

        // System Masters
        { name: 'User Management', href: route('admin.users.index'), icon: ShieldCheckIcon, current: route().current('admin.users.*'), show: hasModuleAccess('users'), category: 'System Masters' },
        { name: 'User Access Audit', href: route('admin.user-access.audits'), icon: ClipboardDocumentListIcon, current: route().current('admin.user-access.*'), show: hasModuleAccess('users') && isAdmin.value, category: 'System Masters' },
        { name: 'Support Access Audit', href: route('admin.support-access.audits'), icon: ClipboardDocumentListIcon, current: route().current('admin.support-access.*'), show: hasModuleAccess('support_access'), category: 'System Masters' },
        { name: 'Registration Queue', href: route('admin.franchise-registrations.index'), icon: ClipboardDocumentListIcon, current: route().current('admin.franchise-registrations.*'), show: hasModuleAccess('franchise_registrations'), category: 'System Masters' },
        { name: 'Franchise Network', href: route('admin.franchisees.index'), icon: BuildingStorefrontIcon, current: route().current('admin.franchisees.*'), show: hasModuleAccess('franchisees'), category: 'System Masters' },

        // Product Master
        { name: 'Product Catalog', href: route('admin.products.index'), icon: ArchiveBoxIcon, current: route().current('admin.products.*'), show: hasModuleAccess('products'), category: 'Product Master' },
        { name: 'HSN & Tax Maps', href: route('admin.hsn-masters.index'), icon: TagIcon, current: route().current('admin.hsn-masters.*'), show: hasModuleAccess('hsn_masters'), category: 'Product Master' },
        { name: 'Salt & Drugs', href: route('admin.salt-masters.index'), icon: BeakerIcon, current: route().current('admin.salt-masters.*'), show: hasModuleAccess('salt_masters'), category: 'Product Master' },
        { name: 'Item Categories', href: route('admin.categories.index'), icon: TagIcon, current: route().current('admin.categories.*'), show: hasModuleAccess('categories'), category: 'Product Master' },
        { name: 'Company Masters', href: route('admin.companies.index'), icon: BuildingStorefrontIcon, current: route().current('admin.companies.*'), show: hasModuleAccess('companies'), category: 'Product Master' },
        { name: 'Rack Layout', href: route('admin.rack-layout.index'), icon: Squares2X2Icon, current: route().current('admin.rack-layout.*'), show: hasModuleAccess('rack_layout'), category: 'Product Master' },

        // Procurement
        { name: 'Suppliers', href: route('admin.suppliers.index'), icon: UsersIcon, current: route().current('admin.suppliers.*'), show: hasModuleAccess('suppliers'), category: 'Procurement' },
        { name: 'Purchase Invoices', href: route('admin.purchase-invoices.index'), icon: ClipboardDocumentCheckIcon, current: route().current('admin.purchase-invoices.*'), show: hasModuleAccess('purchase_invoices'), category: 'Procurement' },
        { name: 'Purchase Returns', href: route('admin.purchase-returns.index'), icon: ReceiptRefundIcon, current: route().current('admin.purchase-returns.*'), show: hasModuleAccess('purchase_returns'), category: 'Procurement' },
        { name: 'Stock Adjustment', href: route('admin.stock.adjust'), icon: ArchiveBoxIcon, current: route().current('admin.stock.adjust*'), show: hasModuleAccess('stock_adjustment'), category: 'Procurement' },

        // Operations
        { name: 'Order from HO (Cart)', href: route('b2b.cart.index'), icon: ShoppingBagIcon, current: route().current('b2b.cart.*'), show: hasModuleAccess('b2b_cart'), category: 'Operations' },
        { name: 'B2B Dispatch', href: route('admin.dist-orders.index'), icon: TruckIcon, current: route().current('admin.dist-orders.*'), show: hasModuleAccess('dist_orders'), category: 'Operations' },
        { name: 'My Purchase Orders', href: route('admin.dist-orders.index'), icon: ClipboardDocumentListIcon, current: route().current('admin.dist-orders.*'), show: hasModuleAccess('dist_orders'), category: 'Operations' },
        { name: 'Retail POS', href: route('pos.index'), icon: CurrencyRupeeIcon, current: route().current('pos.index'), show: hasModuleAccess('pos'), category: 'Operations' },
        { name: 'Sales Invoices', href: route('pos.invoices.index'), icon: ClipboardDocumentCheckIcon, current: route().current('pos.invoices.*'), show: hasModuleAccess('pos'), category: 'Operations' },
        { name: 'Sales Returns', href: route('pos.returns.index'), icon: ArrowUturnRightIcon, current: route().current('pos.returns.*'), show: hasModuleAccess('sales_returns'), category: 'Operations' },
        { name: 'Customer Directory', href: route('customers.index'), icon: UsersIcon, current: route().current('customers.*'), show: hasModuleAccess('customers'), category: 'Operations' },
        { name: 'Franchise Staff', href: route('franchise.staff.index'), icon: UsersIcon, current: route().current('franchise.staff.*'), show: hasModuleAccess('franchise_staff'), category: 'Operations' },

        // Communication
        { name: 'Support Tickets', href: route('tickets.index'), icon: DocumentTextIcon, current: route().current('tickets.*'), show: hasModuleAccess('tickets'), category: 'Communication' },
        { name: 'Meetings', href: route('meetings.index'), icon: ChartBarSquareIcon, current: route().current('meetings.*'), show: hasModuleAccess('meetings'), category: 'Communication' },
        { name: 'Shop Visit Audits', href: route('shop-visits.index'), icon: ClipboardDocumentCheckIcon, current: route().current('shop-visits.*'), show: hasModuleAccess('shop_visits'), category: 'Communication' },

        // Accounts
        { name: 'General Ledger', href: route('ledger.index'), icon: WalletIcon, current: route().current('ledger.*'), show: hasModuleAccess('ledger'), category: 'Accounts' },
        { name: 'Expenses', href: route('expenses.index'), icon: CreditCardIcon, current: route().current('expenses.*'), show: hasModuleAccess('expenses'), category: 'Accounts' },

        // Reports
        { name: 'Inventory & Stock', href: route('reports.stock.summary'), icon: ChartPieIcon, current: route().current('reports.stock.*'), show: hasModuleAccess('reports_stock'), category: 'Reports' },
        { name: 'Daily Sales Register', href: route('reports.sales.daily-register'), icon: ClipboardDocumentCheckIcon, current: route().current('reports.sales.*'), show: hasModuleAccess('reports_sales'), category: 'Reports' },
        { name: 'Tax Compliance (GST)', href: route('reports.gst.gstr1'), icon: DocumentTextIcon, current: route().current('reports.gst.*'), show: hasModuleAccess('reports_gst'), category: 'Reports' },
        { name: 'Vendor Outstanding', href: route('reports.finance.vendor-outstanding'), icon: WalletIcon, current: route().current('reports.finance.*'), show: hasModuleAccess('reports_finance'), category: 'Reports' },
        { name: 'MIS Dashboards', href: route('reports.bi.top-products'), icon: ChartBarSquareIcon, current: route().current('reports.bi.*'), show: hasModuleAccess('reports_bi'), category: 'Reports' },
    ];

    return items.filter(item => item.show);
});

const groupedNavigation = computed(() => {
    return navigation.value.reduce((groups, item) => {
        const category = item.category || 'Other';
        if (!groups[category]) {
            groups[category] = [];
        }
        groups[category].push(item);
        return groups;
    }, {});
});

const userNavigation = [
    { name: 'Settings', href: route('profile.edit') },
];

const sidebarOpen = ref(false);
const desktopSidebarCollapsed = ref(false);
const SIDEBAR_STORAGE_KEY = 'brainyug.sidebar.collapsed';

const isDark = ref(typeof window !== 'undefined' ? localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches) : false);

const toggleDarkMode = () => {
    isDark.value = !isDark.value;
    if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    }
};

// Initialize theme on mount
if (typeof window !== 'undefined') {
    if (isDark.value) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

// Close sidebar and skip dropdown transitions before every Inertia navigation
// to prevent Headless UI mid-animation race conditions (parentNode null error).
const navigating = ref(false);
let removeNavListener;

const desktopSidebarWidthClass = computed(() => (desktopSidebarCollapsed.value ? 'lg:w-20' : 'lg:w-72'));
const desktopMainPaddingClass = computed(() => (desktopSidebarCollapsed.value ? 'lg:pl-20' : 'lg:pl-72'));

const toggleDesktopSidebar = () => {
    desktopSidebarCollapsed.value = !desktopSidebarCollapsed.value;
    if (typeof window !== 'undefined') {
        localStorage.setItem(SIDEBAR_STORAGE_KEY, desktopSidebarCollapsed.value ? '1' : '0');
    }
};

const handleNavClick = () => {
    if (typeof window !== 'undefined' && window.innerWidth < 1024) {
        sidebarOpen.value = false;
    }
};

const stopImpersonation = () => {
    router.post(route('support-access.stop'));
};

onMounted(() => {
    const persisted = localStorage.getItem(SIDEBAR_STORAGE_KEY);
    desktopSidebarCollapsed.value = persisted === '1';

    removeNavListener = router.on('start', () => {
        sidebarOpen.value = false;
        navigating.value = true;
    });
    router.on('finish', () => {
        navigating.value = false;
    });
});
onBeforeUnmount(() => {
    if (removeNavListener) removeNavListener();
});

</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <TransitionRoot as="template" :show="sidebarOpen">
            <Dialog class="relative z-50 lg:hidden" @close="sidebarOpen = false">
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-linear duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="transition-opacity ease-linear duration-300"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-900/80" />
                </TransitionChild>

                <div class="fixed inset-0 flex">
                    <TransitionChild
                        as="template"
                        enter="transition ease-in-out duration-300 transform"
                        enter-from="-translate-x-full"
                        enter-to="translate-x-0"
                        leave="transition ease-in-out duration-300 transform"
                        leave-from="translate-x-0"
                        leave-to="-translate-x-full"
                    >
                        <DialogPanel class="relative mr-8 flex w-full max-w-sm flex-1">
                            <TransitionChild
                                as="template"
                                enter="ease-in-out duration-300"
                                enter-from="opacity-0"
                                enter-to="opacity-100"
                                leave="ease-in-out duration-300"
                                leave-from="opacity-100"
                                leave-to="opacity-0"
                            >
                                <div class="absolute left-full top-0 flex w-16 justify-center pt-5">
                                    <button type="button" class="-m-2.5 p-2.5" @click="sidebarOpen = false">
                                        <span class="sr-only">Close sidebar</span>
                                        <XMarkIcon class="h-6 w-6 text-white" aria-hidden="true" />
                                    </button>
                                </div>
                            </TransitionChild>
                            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-gray-950/95 backdrop-blur px-6 pb-4 ring-1 ring-white/10">
                                <div class="flex h-16 shrink-0 items-center justify-between">
                                    <ApplicationLogo class="h-8 w-auto text-white fill-current" />
                                    <button type="button" class="rounded-lg p-2 text-gray-300 hover:bg-gray-800 hover:text-white" @click="sidebarOpen = false">
                                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                                    </button>
                                </div>
                                <nav class="flex flex-1 flex-col">
                                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                                        <li v-for="(items, category) in groupedNavigation" :key="category">
                                            <div class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-2 mt-2 px-2">{{ category }}</div>
                                            <ul role="list" class="-mx-2 space-y-1">
                                                <li v-for="item in items" :key="item.name">
                                                    <Link
                                                        :href="item.href"
                                                        @click="handleNavClick"
                                                        :class="[
                                                            item.current
                                                                ? 'bg-gray-800 text-white'
                                                                : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                            'group flex gap-x-3 rounded-xl p-2.5 text-sm font-semibold leading-6',
                                                        ]"
                                                    >
                                                        <component
                                                            :is="item.icon"
                                                            class="h-5 w-5 shrink-0"
                                                            aria-hidden="true"
                                                        />
                                                        {{ item.name }}
                                                    </Link>
                                                </li>
                                            </ul>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Static sidebar for desktop -->
        <div :class="['hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:flex-col transition-all duration-300', desktopSidebarWidthClass]">
            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-gray-900 px-3 pb-4 transition-all duration-300" :class="desktopSidebarCollapsed ? 'px-2' : 'px-6'">
                <div class="flex h-16 shrink-0 items-center" :class="desktopSidebarCollapsed ? 'justify-center' : 'justify-between gap-3'">
                    <ApplicationLogo class="h-8 w-auto text-indigo-500 fill-current" />
                    <span v-if="!desktopSidebarCollapsed" class="text-white font-bold text-xl tracking-tight">BrainYug ERP</span>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-gray-400 hover:bg-gray-800 hover:text-white"
                        @click="toggleDesktopSidebar"
                        :title="desktopSidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    >
                        <ChevronDoubleRightIcon v-if="desktopSidebarCollapsed" class="h-5 w-5" aria-hidden="true" />
                        <ChevronDoubleLeftIcon v-else class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
                <nav class="flex flex-1 flex-col">
                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                        <li v-for="(items, category) in groupedNavigation" :key="category">
                            <div v-if="!desktopSidebarCollapsed" class="text-xs font-black uppercase tracking-wider text-gray-500 mb-2 mt-4">{{ category }}</div>
                            <ul role="list" class="-mx-2 space-y-1">
                                <li v-for="item in items" :key="item.name">
                                    <Link
                                        :href="item.href"
                                        @click="handleNavClick"
                                        :title="desktopSidebarCollapsed ? item.name : ''"
                                        :class="[
                                            item.current
                                                ? 'bg-indigo-600 shadow-md text-white'
                                                : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                            desktopSidebarCollapsed ? 'justify-center' : 'gap-x-3',
                                            'group flex rounded-xl p-2.5 text-sm font-semibold leading-6 transition-all duration-200',
                                        ]"
                                    >
                                        <component
                                            :is="item.icon"
                                            :class="[item.current ? 'text-white' : 'text-gray-400 group-hover:text-white', 'h-5 w-5 shrink-0 transition-colors']"
                                            aria-hidden="true"
                                        />
                                        <span v-if="!desktopSidebarCollapsed">{{ item.name }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>

        <div :class="[desktopMainPaddingClass, 'min-h-screen flex flex-col transition-all duration-300']">
            <div class="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:px-8 transition-colors">
                <button type="button" class="-m-2.5 p-2.5 text-gray-700 lg:hidden" @click="sidebarOpen = true">
                    <span class="sr-only">Open sidebar</span>
                    <Bars3Icon class="h-6 w-6" aria-hidden="true" />
                </button>

                <button
                    type="button"
                    class="hidden lg:inline-flex rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:text-gray-300"
                    @click="toggleDesktopSidebar"
                    :title="desktopSidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                >
                    <ChevronDoubleRightIcon v-if="desktopSidebarCollapsed" class="h-5 w-5" aria-hidden="true" />
                    <ChevronDoubleLeftIcon v-else class="h-5 w-5" aria-hidden="true" />
                </button>

                <div class="h-6 w-px bg-gray-200 lg:hidden" aria-hidden="true" />

                <div class="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
                    <form class="relative flex flex-1" action="#" method="GET">
                        <label for="search-field" class="sr-only">Search</label>
                        <MagnifyingGlassIcon class="pointer-events-none absolute inset-y-0 left-0 h-full w-5 text-gray-400" aria-hidden="true" />
                        <input
                            id="search-field"
                            class="block h-full w-full border-0 py-0 pl-8 pr-0 text-gray-900 dark:text-gray-100 bg-transparent placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:ring-0 sm:text-sm"
                            placeholder="Search globally..."
                            type="search"
                            name="search"
                        />
                    </form>
                    <div class="flex items-center gap-x-4 lg:gap-x-6">
                        
                        <!-- Theme Toggle -->
                        <button @click="toggleDarkMode" type="button" class="-m-2.5 p-2.5 text-gray-400 hover:text-gray-500 transition-colors">
                            <span class="sr-only">Toggle dark mode</span>
                            <MoonIcon v-if="!isDark" class="h-6 w-6" aria-hidden="true" />
                            <SunIcon v-else class="h-6 w-6" aria-hidden="true" />
                        </button>
                        
                        <div class="hidden lg:block lg:h-6 lg:w-px lg:bg-gray-200" aria-hidden="true" />

                        <!-- Profile dropdown -->
                        <Menu as="div" class="relative">
                            <MenuButton class="-m-1.5 flex items-center p-1.5">
                                <span class="sr-only">Open user menu</span>
                                <div class="h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold">
                                    {{ $page.props.auth.user.name.charAt(0) }}
                                </div>
                                <span class="hidden lg:flex lg:items-center">
                                    <span class="ml-4 text-sm font-semibold leading-6 text-gray-900" aria-hidden="true">{{ $page.props.auth.user.name }}</span>
                                    <ChevronDownIcon class="ml-2 h-5 w-5 text-gray-400" aria-hidden="true" />
                                </span>
                            </MenuButton>
                            <transition
                                :enter-active-class="navigating ? '' : 'transition ease-out duration-100'"
                                :enter-from-class="navigating ? '' : 'transform opacity-0 scale-95'"
                                :enter-to-class="navigating ? '' : 'transform opacity-100 scale-100'"
                                :leave-active-class="navigating ? '' : 'transition ease-in duration-75'"
                                :leave-from-class="navigating ? '' : 'transform opacity-100 scale-100'"
                                :leave-to-class="navigating ? '' : 'transform opacity-0 scale-95'"
                            >
                                <MenuItems class="absolute right-0 z-10 mt-2.5 w-32 origin-top-right rounded-md bg-white dark:bg-gray-800 py-2 shadow-lg ring-1 ring-gray-900/5 dark:ring-white/10 focus:outline-none">
                                    <MenuItem v-for="item in userNavigation" :key="item.name" v-slot="{ active }">
                                        <Link :href="item.href" :class="[active ? 'bg-gray-50 dark:bg-gray-700 outline-none' : '', 'block px-3 py-1 text-sm leading-6 text-gray-900 dark:text-gray-300']">{{ item.name }}</Link>
                                    </MenuItem>
                                    <MenuItem v-slot="{ active }">
                                        <Link :href="route('logout')" method="post" as="button" :class="[active ? 'bg-gray-50 dark:bg-gray-700 outline-none' : '', 'block w-full text-left px-3 py-1 text-sm leading-6 text-gray-900 dark:text-gray-300']">Log out</Link>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>
                    </div>
                </div>
            </div>

            <main class="py-4 sm:py-6 lg:py-10 flex-1">
                <div class="px-4 sm:px-6 lg:px-8">
                    <div
                        v-if="supportAccess"
                        class="mb-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p>
                                Support access login - developer/admin session.
                                You are operating as <strong>{{ supportAccess.accessed_user_name }}</strong>
                                (started by {{ supportAccess.admin_user_name }}).
                            </p>
                            <button
                                type="button"
                                @click="stopImpersonation"
                                class="inline-flex items-center rounded-lg border border-amber-500 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-600 dark:text-amber-200 dark:hover:bg-amber-800/30"
                            >
                                End Support Access
                            </button>
                        </div>
                        <p v-if="supportAccess.reason" class="mt-1 text-xs opacity-90">
                            Reason: {{ supportAccess.reason }}
                        </p>
                    </div>

                    <!-- Page Heading -->
                    <header v-if="$slots.header" class="mb-5 sm:mb-8 border-b border-gray-200 dark:border-gray-700 pb-4 sm:pb-5">
                        <slot name="header" />
                    </header>
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
