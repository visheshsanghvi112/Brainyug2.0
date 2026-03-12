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
    HomeIcon,
    ShoppingBagIcon,
    ArchiveBoxIcon,
    UsersIcon,
    BuildingStorefrontIcon,
    TruckIcon,
    ArrowUturnLeftIcon,
    ArrowUturnRightIcon,
    Cog6ToothIcon,
    DocumentTextIcon,
    CurrencyRupeeIcon,
    WalletIcon,
    CreditCardIcon,
    ChartPieIcon,
    ChartBarSquareIcon,
    ShieldCheckIcon,
    BeakerIcon,
    TagIcon,
    ClipboardDocumentListIcon,
    ReceiptRefundIcon,
    ClipboardDocumentCheckIcon,
} from '@heroicons/vue/24/outline';
import { ChevronDownIcon, MagnifyingGlassIcon, SunIcon, MoonIcon } from '@heroicons/vue/20/solid';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const roles = computed(() => user.value?.roles || []);

const hasRole = (role) => roles.value.includes(role);
const isAdmin = computed(() => hasRole('Super Admin'));
const isTerritoryHead = computed(() => hasRole('State Head') || hasRole('Zone Head') || hasRole('District Head') || hasRole('Sister Head'));
const isFranchisee = computed(() => hasRole('Franchisee'));
const isDistributor = computed(() => hasRole('Distributor'));
const isPaymentManager = computed(() => hasRole('Payment Manager'));
const isSalesStaff = computed(() => hasRole('Sales Staff'));
const isFranchiseStaff = computed(() => hasRole('Franchisee Staff'));

const homeSurface = computed(() => {
    if (isPaymentManager.value) {
        return {
            name: 'Finance Desk',
            href: route('ledger.index'),
            current: route().current('ledger.*'),
            icon: WalletIcon,
        };
    }

    if (isDistributor.value) {
        return {
            name: 'Order Desk',
            href: route('admin.dist-orders.index'),
            current: route().current('admin.dist-orders.*'),
            icon: TruckIcon,
        };
    }

    if (isSalesStaff.value) {
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
        { name: homeSurface.value.name, href: homeSurface.value.href, icon: homeSurface.value.icon, current: homeSurface.value.current, show: true, category: 'Main' },

        // System Masters
        { name: 'User Management', href: route('admin.users.index'), icon: ShieldCheckIcon, current: route().current('admin.users.*'), show: isAdmin.value, category: 'System Masters' },
        { name: 'Franchise Network', href: route('admin.franchisees.index'), icon: BuildingStorefrontIcon, current: route().current('admin.franchisees.*'), show: isAdmin.value || isTerritoryHead.value, category: 'System Masters' },

        // Product Master
        { name: 'Product Catalog', href: route('admin.products.index'), icon: ArchiveBoxIcon, current: route().current('admin.products.*'), show: isAdmin.value || isTerritoryHead.value || isSalesStaff.value, category: 'Product Master' },
        { name: 'HSN & Tax Maps', href: route('admin.hsn-masters.index'), icon: TagIcon, current: route().current('admin.hsn-masters.*'), show: isAdmin.value, category: 'Product Master' },
        { name: 'Salt & Drugs', href: route('admin.salt-masters.index'), icon: BeakerIcon, current: route().current('admin.salt-masters.*'), show: isAdmin.value, category: 'Product Master' },
        { name: 'Item Categories', href: route('admin.categories.index'), icon: TagIcon, current: route().current('admin.categories.*'), show: isAdmin.value, category: 'Product Master' },
        { name: 'Company Masters', href: route('admin.companies.index'), icon: BuildingStorefrontIcon, current: route().current('admin.companies.*'), show: isAdmin.value, category: 'Product Master' },

        // Procurement
        { name: 'Suppliers', href: route('admin.suppliers.index'), icon: UsersIcon, current: route().current('admin.suppliers.*'), show: isAdmin.value || isDistributor.value, category: 'Procurement' },
        { name: 'Purchase Invoices', href: route('admin.purchase-invoices.index'), icon: ClipboardDocumentCheckIcon, current: route().current('admin.purchase-invoices.*'), show: isAdmin.value || isDistributor.value, category: 'Procurement' },
        { name: 'Purchase Returns', href: route('admin.purchase-returns.index'), icon: ReceiptRefundIcon, current: route().current('admin.purchase-returns.*'), show: isAdmin.value || isDistributor.value, category: 'Procurement' },
        { name: 'Stock Adjustment', href: route('admin.stock.adjust'), icon: ArchiveBoxIcon, current: route().current('admin.stock.adjust*'), show: isAdmin.value || isDistributor.value, category: 'Procurement' },

        // Operations
        { name: 'Order from HO (Cart)', href: route('b2b.cart.index'), icon: ShoppingBagIcon, current: route().current('b2b.cart.*'), show: isAdmin.value || isFranchisee.value, category: 'Operations' },
        { name: 'B2B Dispatch', href: route('admin.dist-orders.index'), icon: TruckIcon, current: route().current('admin.dist-orders.*'), show: isAdmin.value || isTerritoryHead.value || isDistributor.value, category: 'Operations' },
        { name: 'My Purchase Orders', href: route('admin.dist-orders.index'), icon: ClipboardDocumentListIcon, current: route().current('admin.dist-orders.*'), show: isAdmin.value || isFranchisee.value, category: 'Operations' },
        { name: 'Retail POS', href: route('pos.index'), icon: CurrencyRupeeIcon, current: route().current('pos.index'), show: isAdmin.value || isFranchisee.value || isFranchiseStaff.value, category: 'Operations' },
        { name: 'Sales Invoices', href: route('pos.invoices.index'), icon: ClipboardDocumentCheckIcon, current: route().current('pos.invoices.*'), show: isAdmin.value || isFranchisee.value || isFranchiseStaff.value, category: 'Operations' },
        { name: 'Sales Returns', href: route('pos.returns.index'), icon: ArrowUturnRightIcon, current: route().current('pos.returns.*'), show: isAdmin.value || isFranchisee.value || isFranchiseStaff.value, category: 'Operations' },
        { name: 'Customer Directory', href: route('customers.index'), icon: UsersIcon, current: route().current('customers.*'), show: isAdmin.value || isFranchisee.value || isFranchiseStaff.value, category: 'Operations' },

        // Communication
        { name: 'Support Tickets', href: route('tickets.index'), icon: DocumentTextIcon, current: route().current('tickets.*'), show: true, category: 'Communication' },
        { name: 'Meetings', href: route('meetings.index'), icon: ChartBarSquareIcon, current: route().current('meetings.*'), show: true, category: 'Communication' },
        { name: 'Shop Visit Audits', href: route('shop-visits.index'), icon: ClipboardDocumentCheckIcon, current: route().current('shop-visits.*'), show: isAdmin.value || isTerritoryHead.value || isFranchisee.value, category: 'Communication' },

        // Accounts
        { name: 'General Ledger', href: route('ledger.index'), icon: WalletIcon, current: route().current('ledger.*'), show: true, category: 'Accounts' },
        { name: 'Expenses', href: route('expenses.index'), icon: CreditCardIcon, current: route().current('expenses.*'), show: isAdmin.value || isFranchisee.value || isPaymentManager.value, category: 'Accounts' },

        // Reports
        { name: 'Inventory & Stock', href: route('reports.stock.summary'), icon: ChartPieIcon, current: route().current('reports.stock.*'), show: isAdmin.value || isTerritoryHead.value || isFranchisee.value || isDistributor.value, category: 'Reports' },
        { name: 'Tax Compliance (GST)', href: route('reports.gst.gstr1'), icon: DocumentTextIcon, current: route().current('reports.gst.*'), show: isAdmin.value || isPaymentManager.value || isFranchisee.value, category: 'Reports' },
        { name: 'MIS Dashboards', href: route('reports.bi.top-products'), icon: ChartBarSquareIcon, current: route().current('reports.bi.*'), show: isAdmin.value || isTerritoryHead.value || isFranchisee.value, category: 'Reports' },
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
onMounted(() => {
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
                        <DialogPanel class="relative mr-16 flex w-full max-w-xs flex-1">
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
                            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-gray-900 px-6 pb-4 ring-1 ring-white/10">
                                <div class="flex h-16 shrink-0 items-center">
                                    <ApplicationLogo class="h-8 w-auto text-white fill-current" />
                                </div>
                                <nav class="flex flex-1 flex-col">
                                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                                        <li v-for="(items, category) in groupedNavigation" :key="category">
                                            <div class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-2 mt-2 px-2">{{ category }}</div>
                                            <ul role="list" class="-mx-2 space-y-1">
                                                <li v-for="item in items" :key="item.name">
                                                    <Link
                                                        :href="item.href"
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
        <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col">
            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-gray-900 px-6 pb-4">
                <div class="flex h-16 shrink-0 items-center gap-3">
                    <ApplicationLogo class="h-8 w-auto text-indigo-500 fill-current" />
                    <span class="text-white font-bold text-xl tracking-tight">BrainYug ERP</span>
                </div>
                <nav class="flex flex-1 flex-col">
                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                        <li v-for="(items, category) in groupedNavigation" :key="category">
                            <div class="text-xs font-black uppercase tracking-wider text-gray-500 mb-2 mt-4">{{ category }}</div>
                            <ul role="list" class="-mx-2 space-y-1">
                                <li v-for="item in items" :key="item.name">
                                    <Link
                                        :href="item.href"
                                        :class="[
                                            item.current
                                                ? 'bg-indigo-600 shadow-md text-white'
                                                : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                            'group flex gap-x-3 rounded-xl p-2.5 text-sm font-semibold leading-6 transition-all duration-200',
                                        ]"
                                    >
                                        <component
                                            :is="item.icon"
                                            :class="[item.current ? 'text-white' : 'text-gray-400 group-hover:text-white', 'h-5 w-5 shrink-0 transition-colors']"
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
        </div>

        <div class="lg:pl-72 min-h-screen flex flex-col">
            <div class="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:px-8 transition-colors">
                <button type="button" class="-m-2.5 p-2.5 text-gray-700 lg:hidden" @click="sidebarOpen = true">
                    <span class="sr-only">Open sidebar</span>
                    <Bars3Icon class="h-6 w-6" aria-hidden="true" />
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
