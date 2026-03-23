# 🧠 BrainYug ERP: The Modern Enterprise Core

<p align="center">
  <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="200" alt="Laravel Powered">
  <br>
  <strong>A high-fidelity rebuild of a legacy distribution and franchise network system.</strong>
</p>

---

## 🏗️ The Rebuild Vision: "Centralized Truth"

BrainYug ERP is not just a migration; it is a **clean-slate architectural evolution**. The system has been redesigned from the ground up to solve the fragmentation, inconsistent business rules, and "hidden" bugs of a massive legacy codebase.

### Core Architectural Pillars
*   **Decoupled Logic (Services)**: Controllers are thin. Complex business logic (Inventory, Ledgers, Commissions) is encapsulated in dedicated, auditable Service layers.
*   **Immutable Financial Ledgers**: Using a double-entry bookkeeping engine, every transaction—from POS sales to HO dispatches—leaves an immutable, auditable trail.
*   **Precision Territorial Scoping**: Move beyond CSV-based hierarchies. Our system uses a relational territorial model (State, Zone, District, Franchisee) for real-time reporting and supervision.
*   **Inertia.js + Vue 3 Frontend**: A premium, spa-like experience using modern stack choices (Vue 3, Tailwind, Headless UI) for operational speed and reactive dashboards.

---

## 🛡️ Robust Role-Based Access Control (RBAC)

Authorization is managed via `spatie/laravel-permission`, mapping granular capabilities to a deep organizational hierarchy.

### The Operational Surfaces
1.  **🎓 Head Office (HO) Control Center**: For Super Admins and Internal staff to manage global product catalogs, procurement, network-wide dispatches, and financial reconciliation.
2.  **🏪 Franchise Operating System**: A "mini-ERP" for franchisees. Features include POS billing, stock order management, customer relationship handling, and local expense tracking.
3.  **📈 Supervisory Dashboards**: Custom-tailored views for State, Zone, and District heads to oversee their assigned territories with filtered data integrity.

---

## 🛠️ Technical Stack & Depth

*   **Backend**: Laravel 10+, PHP 8.2+
*   **Frontend**: Vue 3 (Composition API), Inertia.js (SSR enabled)
*   **Styling**: Tailwind CSS & Headless UI
*   **State & Auth**: Integrated Laravel session-based security with Spatie permissions
*   **Database**: MySQL/MariaDB with strict relational constraints and query-scoping logic
*   **Build Tools**: Vite 7 with Tailwind & Vue plugins

---

## 🚀 Key Modules: Feature Highlights

### 📦 Smart Inventory System
A single point of truth for stock movements across the entire network. Supports B2B order flows from Franchisees to HO, with automated stock deduction and warehouse dispatch queues.

### 💰 Commission & Earnings Engine
A recursive hierarchy-aware engine that calculates earnings and commissions based on real-time transaction data, integrated directly into the financial ledger.

### 🧾 Unified POS & Billing
A low-latency retail billing interface designed for high-volume franchisee usage, supporting both credit and cash transactions with real-time stock feedback.

---

## 📖 Related Internal Documentation
For developers and internal teams, please refer to:
- [System Design Blueprint](./ERP_REBUILD_SYSTEM_DESIGN.md)
- [Project Flow & Architecture](./BRAINYUG_FLOW_AND_ARCHITECTURE.md)
- [Implementation Roadmap](./IMPLEMENTATION_PLAN.md)
- [Legacy Knowledge Archive](./LEGACY_KNOWLEDGE.md)

---

<p align="center">
  <em>Built with precision for the future of distribution.</em>
</p>
