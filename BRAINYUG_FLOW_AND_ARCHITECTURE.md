# BrainYug ERP: Flow & Architecture

## 🏛️ Core Design Philosophy
The new BrainYug ERP is built on the principle of **Centralized Truth**. Unlike the legacy system, where business rules were scattered across hundreds of files, the new architecture uses dedicated services and standard Laravel patterns to ensure robustness.

---

## 🚫 Why the Legacy Role Logic was Broken
Based on deep-dive analysis of the legacy codebase, several structural failures were identified that led to inconsistent behavior and "hidden" bugs:

1. **Ad-hoc Permission Gating**: Permissions were hardcoded in views (e.g., `sidebar_menu.php`) using raw user type IDs. This meant adding a new feature required manual updates in multiple files, often leading to mismatches (e.g., Distributor reports guarded by `courier_master` permissions).
2. **String-Based Hierarchy**: Territorial scoping (State/Zone/District) relied on CSV strings stored in columns (e.g., `FIND_IN_SET` or `explode(",")`). This destroyed relational integrity and made it impossible to use database-level optimizations or constraints.
3. **Implicit Roles**: Roles like `Type 9` or `Type 10` were "ghost roles"—documented nowhere but referenced in specific menu branches. They had partial implementations that led to silent failures.
4. **Error Suppression**: The use of `error_reporting(0)` in core controllers (like `Home.php`) buried runtime logic errors (like undefined variables in the Franchisee dashboard), creating a system that appeared "stable" while actually computing incorrect data.
5. **Contract Violations**: The API/Webservices layer frequently returned `success=0` even for valid data sets, making the system untrustworthy for mobile or external integrations.

---

## 🛡️ The New Robust Architecture

### 1. Role-Based Access Control (RBAC) via Spatie
We use the industry-standard `spatie/laravel-permission` package. 
*   **Permissions** are the atom of authorization (e.g., `dispatch orders`).
*   **Roles** are collections of permissions.
*   **UI Gating**: The Sidebar and Frontend logic now check for *permissions* or *defined roles*, not hardcoded IDs.

### 2. Territorial Scoping via Eloquent
Territories are now first-class citizens:
*   `State`, `District`, and `Zone` are distinct models.
*   `TerritoryAssignment` model links users to their managed regions using foreign keys.
*   **Query Scoping**: Scoping logic is centralized in the `User` model and `DashboardController`, ensuring a Super Admin and a District Head see the same "type" of data, filtered only by their assigned scope.

### 3. Separation of Concerns (Services)
Complex logic is moved out of Controllers into dedicated Services:
*   `InventoryService`: Single point of truth for stock movements.
*   `LedgerService`: Immutable double-entry accounting for all financial hits.
*   `CommissionService`: Handles the recursive hierarchy traversal for earnings.

### 4. Financial Integrity
Every transaction (POS sale, B2b dispatch, Expense) triggers an immutable entry in the `financial_ledgers` table. This ensures the "Account Balance" shown on the dashboard is always auditable and consistent.

---

## 🗺️ Role Mapping: Legacy to New

| Legacy ID | Legacy Name | New Role Name | Key Functionality |
| :--- | :--- | :--- | :--- |
| 1 | Super Admin | Super Admin | Unrestricted access. |
| 2 | State Head | State Head | Oversees assigned State territories. |
| 3 | Zone Head | Zone Head | Oversees assigned Zone territories. |
| 4 | District Head | District Head | Oversees assigned District territories. |
| 5 | Franchisee | Franchisee | Manages local store POS, stock, and B2B orders. |
| 6 | Distributor | Distributor | Manages HO warehouse, dispatch, and procurement. |
| 8 | Sister Head | Sister Head | Specialized audit/view-only support role. |
| 9 | Undocumented | Payment Manager | Specialized logic for accounts and payment clearance. |
| 10 | Sales Staff | Sales Staff | Field sales and order monitoring. |

---

## 📈 Next Steps
- Implement **Phase 7: GST & Stock Reports** using the high-fidelity data now captured in the new ledgers.
- Finalize the **Commission Engine** integration for all transaction types.
