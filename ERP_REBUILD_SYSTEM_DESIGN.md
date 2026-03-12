# BrainYug ERP Rebuild System Design

> This document is the authoritative design blueprint for the remaining rebuild.
> If a page, route, model, migration, or workflow is not aligned with this document, this document wins.
> Goal: stop ad-hoc building. Build a role-driven, workflow-driven, launchable ERP core.

---

## 1. Executive Decision

### What We Are Building
We are **not** rebuilding the legacy system table-by-table.
We are building a **new ERP platform** with:
- strong role-based access
- franchise ERP surface
- HO/internal ERP surface
- clean identity and hierarchy
- preserved GPM continuity
- fresh operational transactions
- legacy archived for reference where needed

### What We Are Not Doing
We are **not** trying to clone:
- legacy broken table design
- legacy type-ID role logic
- legacy mixed concerns
- legacy cross-database stock mutation model
- legacy every-screen parity before launch

### Product Direction
The rebuild will be:
- **new architecture**
- **new workflow logic**
- **selected legacy continuity**
- **fresh launch for operations**

### Non-Negotiable Continuity Requirements
These must survive into the new ERP:
- franchise identity
- GPM shop code continuity
- active user continuity
- hierarchy continuity where valid
- product/catalog continuity
- operational ability for franchisees to run daily work
- operational ability for HO to receive, process, and dispatch orders

---

## 2. Core Principles

### 2.1 Product-First, Not Migration-First
We define the new ERP product model first.
Migration only serves the product. It does not define the product.

### 2.2 Identity Continuity > Transaction Continuity
Old transactions are already incomplete because real business drifted to phone/manual operations.
Old identity continuity still matters because:
- franchisees know their GPM code
- agreements reference GPM codes
- users expect continuity of login and role
- heads and internal teams need continuity of responsibility

### 2.3 Workflows Over Tables
We build around business workflows, not around importing tables.
Each workflow must have:
- role entry point
- dashboard visibility
- page structure
- service layer
- validation rules
- audit trail

### 2.4 Launchable Core Before Parity
We do not aim for 100% legacy feature parity before launch.
We aim for a **smaller but complete ERP core** that is structurally correct and operationally reliable.

### 2.5 No Ad-Hoc Screens
No one should build random pages based on guesswork.
Every page must come from:
- a role
- a module
- a workflow
- a route group
- a service boundary
- an acceptance criterion

---

## 3. System Boundary

### 3.1 Two Primary ERP Surfaces
The new system consists of two major operational surfaces.

#### A. Franchise ERP
This is what a franchise uses daily.
Responsibilities:
- browse product catalog
- place B2B stock orders to HO
- manage store-side sales / POS
- manage customers
- manage returns
- manage store-side staff users
- access franchise reports
- track ledger/balance with HO
- operate like a mini-business ERP

#### B. HO / Internal ERP
This is what the central team uses.
Responsibilities:
- manage users and roles
- manage franchise network
- manage product masters
- manage suppliers
- process incoming orders
- accept/reject/dispatch
- manage procurement
- monitor network performance
- manage finance/reconciliation
- manage hierarchy and approvals

### 3.2 Legacy Archive Surface
Legacy should remain a reference archive, not a live operational dependency.
Use cases:
- lookup old bills
- lookup old reports
- verify old balances if required
- manual reconciliation

It should not define runtime behavior in the new ERP.

### 3.3 Franchise ERP Is A One-Shot Operating System
The franchise-side product must be designed as a **complete operating ERP**, not a lightweight order-placing frontend.

That means a franchise should be able to use the new system for:
- product lookup
- stock replenishment from HO
- daily billing / POS
- customer and doctor handling
- returns
- day book / ledger visibility
- store staff access
- local operational reporting
- basic finance and cash/credit awareness

The franchise should not need:
- a second billing app
- an Excel workaround for daily operations
- manual phone dependency for routine replenishment
- side-channel stock records outside the ERP

If a franchise still has to fall back to external tools for everyday work, the rebuild is incomplete.

### 3.4 Franchise ERP Data Ownership Boundary
Each franchise must operate inside its own clean business data space.

That means the new ERP must support franchise-owned data such as:
- local customers
- local doctors
- local supplier / vendor parties
- local billing transactions
- local returns
- local expenses
- local cash / bank entries where applicable
- local stock records
- local product records when franchise creates its own products

This data belongs to the franchise operationally, even though the platform is centrally managed.

The new system must therefore separate:
- HO-managed shared master data
- franchise-managed local operational data
- franchise-managed local master data

Without this separation, the franchise ERP will collapse back into confusion between central data and store-owned business data.

### 3.5 HO Catalog And Franchise Catalog Must Coexist
The franchise ERP must support two product realities.

#### Shared HO Catalog
- products supplied by HO
- centrally governed pricing/tax/master structure where applicable
- used for franchise replenishment from HO

#### Franchise Local Catalog
- products created locally by the franchise
- products sourced from outside suppliers
- products the franchise wants to bill even if HO does not own the master

The system design must not assume every sellable product comes from HO.

Legacy behavior already indicates franchise-side product creation and independent procurement existed.
The new ERP must support this intentionally, with clean data ownership and audit-safe rules.

---

## 4. Launch Strategy

### 4.1 Fresh Operations, Preserved Identity
At launch, the new ERP should:
- preserve users
- preserve franchisees
- preserve GPM codes
- preserve products and master data
- optionally preserve suppliers
- start fresh orders, invoices, returns, and stock movements from go-live

### 4.1A User Continuity Without Data Confusion
We should preserve the users, but not blindly preserve all of their old operating data.

This means:
- keep active user identities
- keep franchise linkage
- keep role continuity where valid
- keep GPM-based identity continuity
- do not preload messy legacy operational history into live runtime tables just to make the system feel familiar

Instead, when preserved users begin using the new ERP:
- their new live business data starts fresh in the clean new model
- their new invoices, purchases, parties, products, stock, and reports belong to the new ERP only
- legacy records remain reference/archive data only where needed

This is the cleanest way to preserve people without polluting the new runtime.

### 4.2 Why This Is The Right Strategy
Because trying to perfectly migrate old operational history will:
- consume huge time
- still be wrong in places
- recreate old structural garbage
- slow launch
- create more support issues than it solves

### 4.3 Practical Decision Table
| Area | Strategy |
|---|---|
| Products | Migrate |
| Companies | Migrate |
| HSN / Salt / Categories | Migrate |
| Franchise master | Migrate |
| GPM codes | Preserve exactly |
| Active users | Migrate cleanly |
| Hierarchy | Rebuild from clean rules |
| Suppliers | Migrate master only |
| Opening stock | Optional controlled import |
| Old orders | Archive only |
| Old invoices | Archive only |
| Old returns | Archive only |
| Old pending queues | Archive only |
| Legacy permissions | Do not copy |

### 4.5 Canonical Live Data Rule By User Category
The new ERP must distinguish between preserving a user and preserving that user's old runtime data.

#### Franchise Users
Preserve live:
- identity
- franchise linkage
- valid role
- GPM continuity

Do not preserve as live runtime by default:
- old local customers
- old doctors
- old local vendors/parties
- old local purchases
- old local expenses
- old local receivables/payables
- old retail invoices and returns

Reason:
- this is the highest-confusion data surface in legacy FMS
- franchisees need a clean restart for live ERP operations

#### HO / Internal Users
Preserve live:
- identity
- territory/hierarchy responsibility where valid
- role continuity after mapping

Do not preserve as live runtime by default:
- legacy temporary queues
- mixed ledger residues
- workflow-specific artifacts that do not fit the new service model

#### Heads / Supervisory Users
Preserve live:
- identity
- territory scope
- reporting and supervision role continuity

Do not preserve as live runtime by default:
- legacy indirect calculation state
- CSV-based hierarchy assumptions

This rule exists so the new ERP launches with clean live behavior while preserving who belongs in the system.

---

## 5. Role Architecture

### 5.1 Canonical New Roles
These are the official new-system roles.

#### Core Roles
- Super Admin
- Admin
- State Head
- Regional Head
- Zonal Head
- District Head
- Franchisee
- Distributer
- Account
- Sales Team

#### Conditional Compatibility Roles
These existed in legacy systems and should be preserved only if active users, workflows, or launch scope actually require them:
- Order
- Warehouse
- Inward
- Outward
- Orderstaff

#### Optional Future Roles
- Procurement Staff
- Support Staff
- Audit Staff

### 5.1A Role Preservation Rule
For this ERP, the role model should stay close to the previous business structure instead of being renamed into cleaner-but-artificial labels.

That means:
- keep `Admin` as a real role
- keep `Super Admin` as a separate top-level platform/governance role
- keep the legacy field hierarchy roles such as `State Head`, `Regional Head`, `District Head`, and `Zonal Head`
- keep `Franchisee` as the franchise business role
- keep `Distributer` for now unless the business explicitly decides to rename it later
- keep `Account` as the finance-oriented operational role
- keep `Sales Team` as the sales-side role

### 5.1AA Super Admin Sovereignty Rule
`Super Admin` must be treated as the god-level authority of the ERP.

That means:
- `Super Admin` sits above `Admin`, heads, HO desk roles, and franchise roles
- `Super Admin` is not just a slightly stronger `Admin`; it is the final platform/governance authority
- `Super Admin` may cross all business surfaces, territories, and franchise scopes when required for governance, recovery, audit, or emergency intervention
- destructive or globally sensitive operations should default to `Super Admin` control unless this document explicitly delegates them lower
- `Admin` runs day-to-day business operations, but `Super Admin` owns the final keys to identity, access, platform configuration, and exceptional override

Practical meaning:
- if `Admin` is the main operational boss, `Super Admin` is the god account
- there should be very few `Super Admin` users
- migration into `Super Admin` must be manual and deliberate, never inferred casually

It also means we do **not** create a separate canonical runtime role called `Franchisee Staff` right now.
If a franchise has additional users, they should remain franchise-linked users operating under franchisee business scope, not a newly invented business role unless the business formally approves that split later.

### 5.1B Role Families Learned From Legacy
The old shit teaches that not all roles were the same kind of thing. Some were hierarchy roles, some were operational desk roles, and some were source-specific duty roles.

The rebuilt role model should make that distinction explicit.

#### Family 1: Governance roles
- Super Admin
- Admin

These govern the platform and network, not just a territory slice.

#### Family 2: Network hierarchy roles
- State Head
- Regional Head
- Zonal Head
- District Head

These are supervision and escalation roles. Their main difference is scope depth and reporting responsibility, not entirely different product modules.

#### Family 3: Franchise runtime role
- Franchisee

This is the full store-operating role. Additional franchise-linked users stay inside this business role and are narrowed by permissions if needed.

#### Family 4: HO desk roles
- Distributer
- Account
- Sales Team

These are internal operating-desk roles. They are not territory-head roles and they are not franchise roles.

#### Family 5: Source-specific duty roles
- Order
- Warehouse
- Inward
- Outward
- Orderstaff

These came from PharmaERP and look more like specialized desk assignments than network hierarchy roles.
They should not be auto-promoted into universal ERP roles unless active users and launch workflows prove they are necessary.

### 5.1C Current User Weightage Evidence
Actual legacy user distribution is useful evidence, but it must not become the thing that dictates the role architecture.

Use these numbers to understand:
- which runtime surfaces need the highest UX and workflow quality
- which legacy roles were common vs rare
- which compatibility roles were probably narrow desk artifacts

Do **not** use these numbers to decide:
- whether a role deserves to exist conceptually
- whether a business-critical role can be collapsed away just because user count is low
- whether a team must have one login or multiple logins
- whether future team growth should change the role model itself

#### FMS (`genericp_franchisee.sql`) user distribution
Total users found: `1539`

| Legacy type | Legacy meaning | Count | Weightage |
|---|---|---:|---:|
| 1 | Admin | 3 | 0.19% |
| 2 | State Head | 4 | 0.26% |
| 3 | Regional Head | 21 | 1.36% |
| 4 | District Head | 87 | 5.65% |
| 5 | Franchisee | 1404 | 91.23% |
| 6 | Distributer | 7 | 0.45% |
| 7 | MR | 1 | 0.06% |
| 8 | Zonal Head | 9 | 0.58% |
| 9 | Account | 2 | 0.13% |
| 10 | Franchisee User | 1 | 0.06% |

#### PharmaERP (`pharmaer_pharmaerp.sql`) user distribution
Total users found: `12`

| Legacy type | Legacy meaning | Count | Weightage |
|---|---|---:|---:|
| 1 | Admin | 1 | 8.33% |
| 5 | Franchisee | 7 | 58.33% |
| 6 | Distributer | 1 | 8.33% |
| 8 | Accountant | 1 | 8.33% |
| 9 | Order | 1 | 8.33% |
| 11 | Inward | 1 | 8.33% |

#### Weightage interpretation
The real network is overwhelmingly franchise-weighted.

That means:
- the ERP must optimize first for franchisee runtime quality because more than 90% of the FMS user base is franchise-side
- district and regional hierarchy roles matter next because they carry the operational supervision layer above the franchise network
- admin, distributer, account, and sales-side roles are numerically tiny but still business-critical because small internal teams often run high-leverage workflows
- franchisee-user volume is negligible in the legacy dump, which supports the decision not to over-engineer a separate canonical franchise-staff business role right now
- PharmaERP-only duty roles such as `Order` and `Inward` are too small and too source-specific to dictate the core global role model

#### Design implication rule
The role model should be designed as:
- structurally correct first
- franchise-strong in workflow quality
- hierarchy-aware by supervision needs
- HO-desk-capable by business criticality
- compatibility-aware for tiny legacy duty roles without letting those tiny roles distort the main model

The numbers help prioritize effort.
They do not define the ontology of the ERP.

#### Seat-count independence rule
Business role design must be independent from seat count.

Examples:
- `Sales Team` remains a valid business role whether the business keeps one shared operational user or gives every team member a separate user
- `Account` remains a valid role whether finance has one accountant or several
- `Distributer` remains a valid role whether one person or a full desk handles order intake/dispatch visibility

The role exists because the workflow exists, not because the user count crosses some threshold.

#### Migration implication rule
Because type `1` is very small, the split between `Super Admin` and `Admin` should be done manually during migration review, not inferred automatically from count alone.

Because type `5` dominates massively, franchisee onboarding, login, dashboard, billing, reporting, and order workflows should be treated as the primary runtime path of the product, not as one role among many.

At the same time, low-volume internal roles must still get strong core logic because one weak internal desk workflow can break the whole network.

### 5.2 Mapping Legacy Concepts To New Roles
| Legacy concept | New role |
|---|---|
| Super Admin | Super Admin |
| Admin | Admin |
| State Head | State Head |
| Regional Head | Regional Head |
| Zonal Head | Zonal Head |
| District Head | District Head |
| Franchisee | Franchisee |
| Franchisee User | Franchisee |
| Distributer | Distributer |
| Account / Accountant | Account |
| MR / Sales | Sales Team |

### 5.2A Source-Aware Legacy Role Mapping Rule
Legacy role mapping must remain source-aware because FMS and PharmaERP used overlapping numeric types with different meanings.

#### FMS mapping
- `1 Admin` -> `Admin` or `Super Admin` after manual governance review
- `2 State Head` -> `State Head`
- `3 Regional Head` -> `Regional Head`
- `4 District Head` -> `District Head`
- `5 Franchisee` -> `Franchisee`
- `6 Distributer` -> `Distributer`
- `7 MR` -> `Sales Team`
- `8 Zonal Head` -> `Zonal Head`
- `9 Account` -> `Account`
- `10 Franchisee User` -> `Franchisee` with narrower permissions if needed

#### PharmaERP mapping
- `1 Admin` -> `Admin` or `Super Admin` after review
- `2 State Head` -> `State Head`
- `3 Regional Head` -> `Regional Head`
- `4 District Head` -> `District Head`
- `5 Franchisee` -> `Franchisee` only if still business-valid in current structure
- `6 Distributer` -> `Distributer`
- `7 MR` -> `Sales Team`
- `8 Accountant` -> `Account`
- `9 Order` -> conditional compatibility role or `Distributer`-desk permission profile
- `10 Warehouse` -> conditional compatibility role if active users require it
- `11 Inward` -> conditional compatibility role or warehouse permission profile
- `12 Outward` -> conditional compatibility role or warehouse permission profile
- `13 Orderstaff` -> conditional compatibility role or distributer support permission profile

#### Migration decision rule
When importing users, we should not only map `legacy_type` to a role.
We should also classify the user into one of these buckets:
- hierarchy role
- franchise runtime role
- HO desk role
- source-specific duty role

That extra classification is how we avoid repeating legacy confusion where completely different operator types were all treated as just another `type` number.

### 5.2B Canonical Role Vocabulary Rule
The rebuilt ERP must have one canonical role vocabulary.

Allowed canonical business-role names are:
- `Super Admin`
- `Admin`
- `State Head`
- `Regional Head`
- `Zonal Head`
- `District Head`
- `Franchisee`
- `Distributer`
- `Account`
- `Sales Team`

Conditional compatibility-role names are allowed only when an active migration path needs them:
- `Order`
- `Warehouse`
- `Inward`
- `Outward`
- `Orderstaff`

Anything outside this list is drift until explicitly approved.

That means the rebuilt app should not keep inventing or reviving extra runtime labels such as:
- `Payment Manager`
- `Sales Staff`
- `Franchisee Staff`
- `Sister Head`
- ad hoc replacements like `Sales Operations`

If we need a different capability split, that should be expressed through permissions, not by silently creating another business role label.

### 5.2C Current Implementation Divergence Warning
The current Laravel codebase already shows role drift away from the canonical model.

Observed examples in the current app:
- `HomeRouteService` still routes by `Payment Manager`, `Distributor`, and `Sales Staff`
- `routes/web.php` still protects routes with `Zone Head`, `Sister Head`, `Franchisee Staff`, `Payment Manager`, and `Distributor`
- `DashboardController` still builds separate dashboards for `Franchisee Staff`, `Sister Head`, `Payment Manager`, and `Sales Staff`
- `SupportTicketController`, `ShopVisitController`, and `ReportController` still gate access with non-canonical role names
- `User` model helpers still expose `isZoneHead()` and `isDistributor()` based on the older role vocabulary

This matters because the document is already stronger than parts of the current implementation.
If that divergence is not corrected, the app will keep drifting back into the same legacy confusion under nicer code.

### 5.2D Role Normalization Directive
Before expanding more feature surfaces, the implementation must normalize role usage across the codebase.

Required normalization steps:
1. define the final seeded role list from this document
2. create an explicit compatibility mapping for legacy and temporary names
3. replace direct runtime checks against non-canonical role names in routes, controllers, services, dashboards, and model helpers
4. move behavior splits that are really capability differences into permissions/policies
5. keep compatibility names only at migration boundaries or temporary alias layers, not as the long-term runtime language of the app

Normalization success means:
- one role name for one business concept
- no route middleware using stale role labels
- no dashboard branching on deprecated role names
- no controller scope logic that depends on pre-normalization role vocabulary

### 5.3 Role Philosophy
Roles define **what a user can do**.
They do not define:
- territory
- parent hierarchy
- franchise ownership

Those are separate concepts.

### 5.4 Multi-Role Support
The new system should support multiple roles where required.
Example:
- a head may also be linked to a franchise
- a finance supervisor may also have reporting permissions

But multi-role use should be explicit and rare, not a lazy workaround.

### 5.5 Access Control Architecture Rule
Legacy used three mixed concepts at once:
- Tank Auth session login
- numeric `users.type` branching
- `access_rights` checks keyed by controller name

That combination produced duplicate logic, weak guarantees, and controller-level permission overreach.

The rebuild must use a single coherent access-control model:
- authentication handled centrally by Laravel auth/session middleware
- authorization handled by roles + permissions + policies
- territory scope handled separately from role membership
- franchise ownership handled separately from role membership
- every write action and sensitive read must be protected server-side at the route/controller/service boundary

The rebuild must not:
- infer authorization from menu visibility
- map permissions to raw controller names
- depend on numeric legacy type IDs for runtime access decisions
- rely on a parent chain alone for data scope

### 5.5A Access Control Implementation Blueprint
This is how we will actually implement access control in Laravel.

#### Role model
Use Spatie roles for business personas only:
- `super-admin`
- `admin`
- `state-head`
- `regional-head`
- `zonal-head`
- `district-head`
- `franchisee`
- `distributer`
- `account`
- `sales-team`

Seed conditional compatibility roles only if migration analysis shows active users still require them:
- `order`
- `warehouse`
- `inward`
- `outward`
- `orderstaff`

Roles answer one question only: what kind of operator this user is.

If franchise-linked secondary users exist, they should normally receive the `franchisee` role and be constrained by permissions and franchise scope, not by inventing a separate business role.

#### Operator account scaling rule
The system must support both of these realities without changing the underlying role model:
- one operational account used by a team for practical reasons
- one named account per operator for better audit and control

That means:
- role definitions must not assume one human equals one user account
- workflow permissions must work whether a team has one account or many
- dashboards, queues, and action permissions must be stable under both models

Preferred long-term practice is named accounts for audit quality.
But the core ERP logic must remain valid even if the business temporarily runs some internal teams through one shared operational user.

#### Shared-account safety rule
Because some teams may remain on one shared operational login for practical reasons, the system must not hide critical workflow state inside user-specific personal queues unless that workflow is intentionally person-bound.

That means:
- desk queues should normally be role/scope-driven, not only `created_by_user_id` driven
- approvals, dispatch queues, finance queues, and reporting surfaces must still work if one team user performs all actions
- audit trails should record `user_id` accurately, but queue visibility should not collapse just because the business chose a shared login model temporarily

#### Permission model
Use granular capability-style permissions, not controller-name permissions.
Examples:
- `dashboard.view.network`
- `franchises.view`
- `franchises.approve`
- `franchises.activate`
- `users.view`
- `users.manage`
- `catalog.view`
- `catalog.manage.global`
- `catalog.manage.local`
- `orders.view.ho`
- `orders.place.franchise`
- `orders.dispatch`
- `pos.bill`
- `pos.return`
- `ledger.view.franchise`
- `ledger.reconcile.ho`
- `reports.view.stock`
- `reports.export.stock`

Permission names must reflect business capability, not implementation detail.

#### Scope model
Scope resolution must be independent from role resolution.

Required scope dimensions:
- `franchisee_id`
- territory assignments
- ownership type where needed
- role capability set

Required runtime components:
- `AccessContext` object for the current authenticated user
- `ScopeResolverService` to resolve allowed franchise/territory scope
- policies for record-level authorization
- query scopes or dedicated query services that accept `AccessContext`

#### Request enforcement order
Every protected request should be enforced in this order:
1. route middleware checks authentication
2. route middleware or controller checks coarse capability
3. FormRequest or action-level guard validates action permission
4. service/query layer applies franchise and territory scope
5. policy check applies object-level authorization where needed

This layered order is deliberate. It prevents the old legacy shit where a menu item disappeared but the endpoint still executed.

#### Data access rule
Controllers must never manually reconstruct scope using ad hoc parent-chain logic.
Instead they must call a dedicated service or query object that already knows how to apply:
- franchise scope
- territory scope
- role capability restrictions
- archive/live data distinction

#### Laravel structure
Use these implementation anchors:
- route middleware for authentication and coarse capability checks
- policies for entity actions
- FormRequest `authorize()` for action-level gates
- service/query classes under `app/Services` for runtime business scope
- shared permission seeding in database seeders, not hidden in runtime controllers

#### Seeder and mapping rule
Legacy role IDs and `users.type` values may be stored as reference metadata, but new runtime access must be seeded and mapped explicitly through:
- a legacy-role mapping table or config
- role seeder
- permission seeder
- user-role assignment migration logic

No implicit magic conversion from `legacy_type` to runtime authority should remain in the app after migration.

### 5.6 Routing Architecture Rule
Legacy route files exposed many flat, action-style endpoints such as `submitDataAndGetReciept`, `getCustOfMobNo`, `submitConfirmPaymentInfo`, and `generateStockReport`.

That approach made the system hard to reason about and easy to secure inconsistently.

The rebuild must use structured route groups by domain, for example:
- auth
- dashboard
- master-data
- catalog
- franchise
- procurement
- order-desk
- pos
- billing
- inventory
- ledger
- reports
- exports

Action routes are allowed, but only as explicit sub-actions under authenticated domain resources.
Examples:
- `POST /pos/invoices`
- `POST /pos/invoices/{invoice}/payments`
- `POST /inventory/receipts`
- `GET /reports/stock`
- `POST /exports/reports/stock.xlsx`

No public or semi-public business-action endpoints should exist outside the auth boundary unless they are intentionally external APIs.

### 5.6A Routing Implementation Blueprint
This is how route structure should be implemented in the Laravel app.

#### Route organization
Web ERP routes should be grouped by business domain, not by legacy controller habit.

Recommended route files:
- `routes/web.php` for top-level composition only
- `routes/web/auth.php`
- `routes/web/dashboard.php`
- `routes/web/franchises.php`
- `routes/web/users.php`
- `routes/web/catalog.php`
- `routes/web/orders.php`
- `routes/web/pos.php`
- `routes/web/inventory.php`
- `routes/web/ledger.php`
- `routes/web/reports.php`
- `routes/web/exports.php`

If Laravel route-file splitting is not used immediately, the same domain grouping must still exist clearly inside `routes/web.php`.

#### Naming convention
Route names must be predictable and composable.
Examples:
- `dashboard.index`
- `franchises.index`
- `franchises.show`
- `franchises.approvals.index`
- `franchises.approvals.approve`
- `users.index`
- `catalog.products.index`
- `orders.index`
- `orders.place.store`
- `orders.dispatch.store`
- `pos.invoices.index`
- `pos.invoices.store`
- `pos.invoices.payments.store`
- `reports.stock.index`
- `exports.reports.stock.xlsx`

#### Controller split
Do not put page rendering, data fetches, billing mutations, and exports inside one giant controller.

Preferred split:
- page controller for Inertia page rendering
- action controller for state-changing operations
- query endpoint controller only where async JSON is justified
- export controller for file generation

#### Request format rule
If the UI needs filtered async data, the endpoint still lives under the same protected module namespace.
Example:
- page: `GET /reports/stock`
- filter/query JSON: `GET /reports/stock/data`
- export: `POST /exports/reports/stock.xlsx`

Do not recreate legacy-style orphan endpoints like `generateStockReport` at the application root.

#### Route authorization rule
Every domain route group must declare:
- auth middleware
- capability middleware where appropriate
- named-route namespace
- consistent controller namespace or folder structure

No route should rely on the frontend to decide whether it is safe to call.

### 5.6B Franchise Registration Routing Rule
Franchise onboarding must not be collapsed into a generic user-registration route or into one catch-all franchise CRUD controller.

The new route model must separate five different things clearly:
- public franchise enquiry or lead capture
- internal registration review queue
- franchise approval decision
- franchise activation or suspension
- franchise-user provisioning

Required route families:
- public enquiry routes such as `GET /franchise/apply` and `POST /franchise/apply`
- protected review routes such as `GET /admin/franchise-registrations` and `GET /admin/franchise-registrations/{registration}`
- protected decision routes such as `POST /admin/franchise-registrations/{registration}/approve` and `POST /admin/franchise-registrations/{registration}/reject`
- protected activation routes such as `POST /admin/franchises/{franchise}/activate` and `POST /admin/franchises/{franchise}/suspend`
- protected provisioning routes such as `POST /admin/franchises/{franchise}/provision-owner`

Rules:
- public enquiry must create a franchise registration record, not a runtime ERP user session
- guest auth registration must not be used as the franchise onboarding entry point
- approval must not be just another generic `update` on the franchise resource
- activation must remain a separate operational step from approval when business checks still remain
- user provisioning must remain explicit and auditable instead of being hidden inside unrelated form submission logic

Current implementation divergence to fix:
- `routes/auth.php` still exposes a generic guest `register` endpoint that creates a plain user account
- `routes/web.php` places franchise onboarding under the admin franchise resource, which hides the difference between registration intake, review, approval, and live franchise management
- `FranchiseeController` currently mixes create/edit CRUD with approval and activation actions in one controller surface

---

## 6. Identity Model

### 6.1 User Model Requirements
Each user record must support:
- name
- username
- email
- phone
- password
- is_active
- parent_id
- franchisee_id nullable
- legacy_source nullable
- legacy_user_id nullable
- legacy_type nullable
- legacy_username nullable
- preferences
- 2FA / security support

### 6.1A User Continuity Rule
User continuity means identity continuity, not transactional continuity.

The new system should preserve:
- who the user is
- which franchise or territory the user belongs to
- what business role they should have now

The new system should not assume the user must inherit:
- old carts
- old pending billing state
- old broken balances
- old operational drafts

Users continue. Runtime business data restarts cleanly.

### 6.2 Franchisee Model Requirements
Each franchise record must support:
- shop_code (GPM code)
- shop_name
- owner_name
- contact details
- state_id
- district_id
- address
- status
- activated_at
- hierarchy links
- legacy_franchise_id nullable
- legacy_source nullable

### 6.3 Territory Model
Territory must not be stored as CSV or hidden strings.
Use explicit relational assignments.

Required territory models:
- State
- District
- optionally Zone as a first-class concept or derived grouping
- TerritoryAssignment

### 6.4 Parent Hierarchy Model
Use `parent_id` for chain-of-command.
Use territory assignments for scope.
Do not use parent_id alone as scope logic.

### 6.5 Legacy Reference Fields
To make migration safe and auditable, preserve these references:
- `legacy_source`
- `legacy_user_id`
- `legacy_type`
- `legacy_franchise_id`
- `legacy_username`

These are for traceability only, not runtime authorization.

### 6.5A Authentication Continuity Rule
User continuity does not mean carrying forward the legacy auth model.

The rebuild should preserve:
- user identity continuity
- username or GPM continuity where appropriate
- active account continuity after validation

The rebuild must not preserve:
- legacy numeric type-ID auth branching
- legacy controller-name permission coupling
- silent password-repair logic during normal login
- mixed auth and business-repair code in the same request flow

Authentication requirements for the rebuild:
- standard Laravel session authentication for web ERP usage
- password reset and email/OTP flows handled as explicit product features
- optional forced password reset during migration/onboarding when password quality is uncertain
- auditable login, logout, password change, and account lock events
- optional 2FA support for privileged users

### 6.5B Authentication Cutover Plan
This is how legacy users should be brought into the new auth system.

#### Import decision rules
For each legacy user we will decide:
- whether the account is still active and valid
- which franchise or territory the account belongs to now
- which new role set the account should receive
- whether the existing password hash can be trusted and reused

#### Password handling rule
Do not repeat the legacy behavior where login silently repairs auth data on the fly.

Cutover rules:
- if the legacy password format is verified as compatible and trustworthy, import it once during migration
- if compatibility or trust is uncertain, mark the user as `must_reset_password = true`
- password migration logic must run in migration/provisioning code, never during normal login flow

#### Provisioning flow
Required provisioning sequence:
1. create/import user
2. attach franchise/territory relationships
3. assign runtime roles
4. assign any direct permissions only if truly necessary
5. set auth state flags such as `must_reset_password`, `is_active`, `last_migrated_at`
6. record legacy references for audit
7. issue onboarding/reset flow if needed

#### Login behavior rule
Normal login must do only auth and session establishment.
It must not:
- rewrite password hashes
- repair user-role assignments
- repair franchise links
- mutate authorization data

If a migrated account is incomplete, login should fail cleanly with an explicit support or reset path instead of trying to patch runtime data invisibly.

#### Audit rule
Every auth-sensitive event must be auditable:
- imported user created
- password reset required
- password reset completed
- login success/failure
- role assignment changed
- account activated/deactivated

### 6.6 Franchise Business Identity Model
Franchise is not just an addressable store record. It is a business unit.

The franchise domain should eventually support:
- franchise master profile
- GPM code
- owner and partner details
- billing profile
- GST and drug license profile
- store staff roster
- local customer base
- local doctor reference data
- order relationship with HO
- financial standing with HO
- activation lifecycle

This is the correct abstraction for a franchise ERP.

### 6.7 Franchise Data Ownership Model
Every franchise must have a clearly scoped data domain.

Franchise-owned master data should support:
- local products
- local supplier parties
- local customer parties
- local doctor references
- local expense heads if allowed
- local bank / payment references if required

Franchise-owned transactional data should support:
- retail invoices
- credit sales
- collections
- sales returns
- local purchases
- purchase returns
- stock adjustments under audit
- expenses

Central users may have visibility by role, but ownership and operational scope remain franchise-bound.

### 6.8 Product Ownership Model
Products in the new ERP should support ownership/source classification.

Minimum classification:
- `source_type = ho_shared`
- `source_type = franchise_local`

Optional future refinement:
- `source_type = third_party_supplier`

This is necessary because the franchise ERP must support both HO-supplied products and franchise-created/local-procured products.

### 6.9 Canonical Live Data Domains
The live ERP should be modeled as explicit domains, not as a pile of reused legacy tables.

#### Domain A: Identity & Access
Contains:
- users
- roles
- permissions
- user-role assignments
- login/security state

Scope:
- global

#### Domain B: Network & Territory
Contains:
- franchisees
- states
- districts
- territory assignments
- head/franchise relationships

Scope:
- global with role/territory visibility

#### Domain C: Shared Catalog
Contains:
- shared products
- salts
- companies
- HSN
- categories
- box sizes

Scope:
- centrally owned
- visible to franchisees where allowed

#### Domain D: Franchise Local Masters
Contains:
- local products
- local supplier parties
- local customers
- local doctors
- local expense heads

Scope:
- franchise-owned

#### Domain E: Ordering & Replenishment
Contains:
- franchise orders to HO
- order lines
- acceptance/rejection state
- dispatch records

Scope:
- cross-surface workflow between franchise and HO

#### Domain F: Procurement
Contains two separate flows:
- HO procurement from suppliers
- franchise local procurement from outside parties

These must not be merged into one ambiguous runtime path.

#### Domain G: Inventory
Contains:
- stock batches
- stock movement ledger
- adjustments
- inward/outward references

Scope:
- location/franchise-aware
- audit-first

#### Domain H: Sales & Billing
Contains:
- invoices
- invoice lines
- payment splits
- returns
- credit tracking
- collections

Scope:
- franchise-owned runtime

#### Domain I: Finance & Ledger
Contains:
- ledger movements
- receivables/payables
- expenses
- payment entries
- settlement references

Scope:
- separate HO and franchise contexts

#### Domain J: Archive
Contains:
- legacy searchable reference records
- legacy identifiers
- migrated audit metadata

Scope:
- read-only

### 6.10 Legacy Table Decomposition Rule
Legacy tables such as `create_new_ledger`, `tbl_bank`, `tbl_cash`, `tbl_credit_payment`, and direct-stock tables mixed multiple business concerns together.

The new ERP must decompose these into explicit concepts.

Example decomposition:
- party master
- payable/receivable account
- payment transaction
- invoice settlement
- stock movement event
- expense voucher

No single catch-all table should represent multiple business concepts just because the legacy system did so.

### 6.11 Recommended Canonical Runtime Entity Set
The rebuild should converge toward a clear runtime entity set.

#### Identity & Network
- `users`
- `roles`
- `permissions`
- `franchisees`
- `territory_assignments`

#### Product & Catalog
- `products`
- `product_companies`
- `product_salts`
- `product_categories`
- `product_tax_profiles`

Recommended product ownership fields:
- `source_type`
- `owner_franchisee_id nullable`
- `legacy_source nullable`
- `legacy_product_id nullable`

#### Party & Reference Masters
- `suppliers`
- `customers`
- `doctors`
- `expense_heads`

Scope rule:
- HO suppliers are central
- franchise customers/doctors are franchise-scoped
- franchise-local suppliers are franchise-scoped

#### Procurement
- `purchase_invoices`
- `purchase_invoice_lines`
- `purchase_returns`
- `purchase_return_lines`

Scope rule:
- HO procurement and franchise procurement should share patterns, not the same ownership scope

#### Inventory
- `inventory_batches`
- `inventory_movements`
- `stock_adjustments`

Rule:
- current stock should be derivable from movement history and batch state, not maintained as a blind mutable truth source

#### Sales
- `sales_invoices`
- `sales_invoice_lines`
- `sales_returns`
- `sales_return_lines`
- `invoice_payments`

#### Finance
- `ledger_accounts`
- `ledger_entries`
- `expense_vouchers`
- `receivable_allocations`
- `payable_allocations`

#### Ordering & Dispatch
- `dist_orders`
- `dist_order_lines`
- `dispatches`
- `dispatch_items`

#### Archive & Migration Traceability
- `legacy_record_links`
- `legacy_import_runs`
- archive search/read models where needed

This is not a demand to create every table immediately.
It is the canonical target model so the rebuild stops drifting back toward legacy table reuse.

---

## 7. GPM Continuity Rules

### 7.1 GPM Is A First-Class Business Identifier
GPM codes are not cosmetic.
They appear in:
- offline agreements
- business identity
- franchise recognition
- operational continuity

### 7.2 Rules
- preserve all existing GPM codes exactly
- do not reassign existing GPM codes
- do not mutate casing or sequence unless correcting a validated data issue
- use GPM code as the primary franchise external identity

### 7.3 New Generation Logic
New franchise approval should generate:
- `GP` + `{state_abbreviation}` + `{4-digit sequence}`

Examples:
- GPMH1532
- GPKA0142
- GPTS0002

### 7.4 Sequence Rules
- sequence should be state-specific
- must continue from highest existing code in that state prefix
- sequence generation must be transactional / race-safe

### 7.5 Username Policy
For franchisee users, username should default to the GPM code unless there is a compelling reason not to.
This preserves continuity and reduces confusion.

---

## 8. Operational Surfaces

### 8.0 Navigation Composition Rule
Legacy navigation became unmaintainable because sidebars contained:
- repeated permission loops
- direct database queries in views
- hardcoded type exceptions
- duplicated sidebar files for different visual versions
- label drift and dead links

The rebuild must treat navigation as a composed application surface, not handwritten view sprawl.

Navigation requirements:
- one canonical navigation definition per product surface
- view components receive already-resolved nav data; they do not query the database directly
- menu visibility comes from server-side capabilities and scoped modules, not ad hoc template conditions
- active state must derive from named routes/current domain context, not hardcoded string comparisons spread through templates
- desktop sidebar, tablet rail, and mobile navigation drawer must all use the same underlying information architecture

UI mistakes from legacy that must not be repeated:
- giant sidebars maintained by copy-paste
- opening core workflows in arbitrary new tabs
- mixing inline styles/scripts into business views as a default pattern
- inconsistent naming across menus, controllers, and labels
- using alert boxes for critical validation feedback

### 8.0A Navigation Implementation Blueprint
This is how navigation should be built in the new app.

#### Single source of truth
Navigation should come from one canonical registry, not from scattered template conditionals.

Recommended implementation:
- `NavigationRegistry` or equivalent config-driven structure
- each nav item declares label, route name, icon, capability requirement, surface, and optional children
- nav visibility is computed in backend composition code and passed to Inertia as resolved data

Each nav item should support these fields at minimum:
- `id`
- `label`
- `route`
- `icon`
- `surface`
- `required_permissions`
- `required_roles` only where truly needed
- `children`
- `badge_key` optional

#### Composition flow
Required composition sequence:
1. resolve authenticated user
2. build `AccessContext`
3. resolve current product surface
4. filter nav registry by capability and scope
5. compute active node from named route
6. send the resolved nav tree to the layout

Views should render the nav tree only. They should not decide who is allowed to see what.

#### Surface rule
There should be distinct navigation surfaces for:
- HO/admin users
- monitoring heads
- franchise operations
- finance/reporting focused users where needed

These surfaces can share modules, but they should not share one bloated legacy-style mega-sidebar.

#### Badge/data rule
Counts such as pending approvals, low stock alerts, pending orders, or support backlog should come from dedicated dashboard/navigation composition services.
They must not be queried ad hoc from Blade/Vue layout code.

#### Responsive implementation rule
Desktop sidebar, tablet condensed navigation, and mobile drawer must all render from the same resolved nav payload.
Only the presentation changes.
The information architecture does not.

#### Naming rule
Navigation labels, route names, page titles, and permission names must align.
If the route is `catalog.products.index`, the nav should not say something vague or legacy-broken like `Add New Product Report Master`.

This alignment is how we stop the old confusion from leaking into the new ERP.

## 8.1 Super Admin Surface
Purpose:
- full visibility
- governance
- system configuration
- approvals
- network health
- emergency override across all ERP surfaces
- final authority over identity, access, and platform integrity

Super Admin rule:
- this is the god surface of the ERP
- it is meant for ownership, recovery, governance, and exceptional intervention, not routine delegation of every daily task
- anything capable of affecting the whole platform, all roles, or all franchises must remain visible to or recoverable by `Super Admin`

Primary modules:
- users
- roles / permissions
- franchise network
- product master
- supplier master
- procurement overview
- order desk overview
- finance overview
- reports

Primary dashboard blocks:
- active franchisees
- pending approvals
- open orders
- dispatch backlog
- procurement value
- retail sales summary
- expense summary
- support backlog

## 8.1A Admin Surface
Purpose:
- operational control of the ERP network
- approvals and day-to-day governance
- user, franchise, product, and order oversight
- reports and operational intervention without platform-owner responsibilities

Primary modules:
- users
- roles / permissions within business rules
- franchise network
- product master
- supplier master
- order desk
- dispatch and procurement visibility
- finance/reporting access as allowed

Primary dashboard blocks:
- pending franchise approvals
- pending orders
- dispatch backlog
- active franchisee count
- support or issue backlog
- operational alerts requiring intervention

## 8.2 State Head Surface
Purpose:
- monitor assigned states
- supervise network performance
- see scoped franchise operations
- view orders and escalations

Primary modules:
- franchise network scoped to assigned states
- order visibility
- product catalog read access
- reports scoped to territory
- support/ticket oversight

## 8.3 Regional Head Surface
Purpose:
- regional operational monitoring
- zonal, district, and franchise visibility
- follow sales/order performance

Primary modules:
- regional-scoped franchise network
- regional-scoped order desk visibility
- reporting
- escalation monitoring

## 8.3A Zonal Head Surface
Purpose:
- sub-regional operational monitoring
- district and franchise performance follow-up
- escalation support between regional and district layers

Primary modules:
- zonal-scoped franchise network
- zonal-scoped order visibility
- reporting
- operational follow-up and escalation visibility

## 8.4 District Head Surface
Purpose:
- local cluster supervision
- direct oversight of franchises
- order and health visibility

Primary modules:
- district-scoped franchise list
- pending franchise follow-ups
- orders and local performance
- reports

## 8.5 Distributer Surface
Keep the legacy role name for now because it exists in the operating model and user expectation, even if the name is imperfect.

Purpose:
- internal order desk
- incoming franchise order handling
- accept / reject / dispatch workflow
- coordination with procurement and stock

Primary modules:
- order desk
- dispatch queue
- procurement documents
- supplier base
- stock visibility
- operational alerts

Primary dashboard blocks:
- pending orders
- accepted awaiting dispatch
- dispatch completed today
- procurement intake
- supplier readiness
- stock shortage alerts

## 8.6 Sales Team Surface
Purpose:
- field sales and network support
- franchise onboarding momentum
- order pipeline follow-up
- catalog assistance

Primary modules:
- franchise network
- product catalog
- order visibility
- meetings and activities
- support/tickets

## 8.7 Franchisee Surface
Purpose:
- run the franchise daily
- replenish stock from HO
- bill customers
- manage franchise-linked users if enabled
- monitor store health
- operate the store as a self-sufficient business system

Primary modules:
- franchise dashboard
- B2B order cart
- order history
- POS
- invoices
- returns
- customers
- doctors if required
- franchise-linked users if enabled
- ledger / balance
- reports
- expenses if required

Primary dashboard blocks:
- today's sales
- cash / credit split
- pending customer dues
- low stock alerts
- pending HO orders
- dispatched orders awaiting receipt context
- recent bills
- top selling products
- near-expiry products
- ledger/balance with HO

### Franchisee Capability Rule
If a franchisee cannot complete a normal pharmacy/store workday inside this surface, the franchise ERP is not finished.

## 8.8 Franchise-Linked User Rule
If additional users are created under a franchise, they should operate inside the franchisee surface and franchisee scope.

They may receive narrower permissions, but that is a permission split, not a separate canonical business role.

## 8.9 Account Surface
Purpose:
- receivables
- payment tracking
- ledger reconciliation
- expense oversight
- finance reporting

Primary modules:
- ledger
- expenses
- receivable reporting
- payment reconciliation
- GST/finance reports where required

## 8.9A Conditional Compatibility Desk Surfaces
If active PharmaERP users require continuity at launch, the following specialized desk roles may be preserved temporarily:
- Order
- Warehouse
- Inward
- Outward
- Orderstaff

Rule:
- do not invent these if they are not required
- do not collapse them blindly into unrelated hierarchy roles
- do not let them shape the entire ERP role model if they are only desk-specialization artifacts

Implementation preference:
- preserve as compatibility roles only when needed for migration continuity
- otherwise model their behavior through permissions inside `Distributer` / `Account` / warehouse-oriented future roles

---

## 9. Module Architecture

### 9.1 Phase-1 Mandatory Modules
These are the modules required before launch.

#### Identity & Access
- login
- role-based redirect
- password reset
- 2FA optional but framework-ready
- user creation and management
- franchise user creation/invitation

#### Franchise Network
- franchise list
- franchise profile
- registration
- approval
- activation/suspension
- shop-code generation with legacy GPMH continuity

#### Product Master
- products
- companies
- categories
- salts
- HSN

#### Ordering
- product browsing
- B2B cart
- checkout to order
- order list
- order detail
- accept
- reject
- dispatch

#### Franchise ERP Sales Core
- POS
- invoices
- returns
- customers
- doctor references
- party-aware billing context where applicable

#### Franchise ERP Billing & Store Ops Core
- bill number generation
- payment mode handling
- cash / bank / split / credit billing support
- customer dues visibility
- doctor tagging where applicable
- batch-aware billing
- stock deduction integrity
- return and credit-note logic
- daily sales summary
- print / reprint workflows
- quotations if business still needs them
- customer payment collection tracking

#### Franchise ERP Stock & Local Ops Core
- received stock visibility
- batch-level stock viewing
- low stock alerts
- near-expiry alerts
- inward visibility from HO dispatch
- controlled manual adjustments if business allows
- opening stock strategy if needed per franchise
- local purchase intake from outside suppliers
- local stock for franchise-created products

#### Franchise ERP Local Master Core
- local product creation
- local product editing with tax/pricing controls
- local supplier / vendor party master
- customer master
- doctor master
- franchise expense type master if needed

#### Franchise ERP Local Procurement Core
- purchase entry from non-HO suppliers
- purchase history
- purchase return support
- supplier balance / payable awareness where applicable

#### Procurement Core
- suppliers
- purchase invoices
- purchase returns
- stock adjustment / controlled movement

#### Reports Core
- stock summary
- basic finance / ledger view
- basic order reports
- basic franchise performance report
- daily sales register
- invoice register
- customer credit report
- expiry and non-moving stock reports
- purchase register
- supplier party report
- customer party report
- franchise-local product report

#### Report & Export Foundation
- reusable report filters by date / role / territory / franchise / party / product where applicable
- reusable table-report component pattern
- Excel export support
- PDF export support
- report title, subtitle, and filter-context rendering
- role-aware report variants from shared query/report services
- print-friendly document output where business needs it
- large report handling strategy for heavy exports

### 9.2 Phase-2 Modules
These can come after launch.
- meetings
- support ticket refinement
- deeper BI reports
- GST advanced suite
- franchise feedback
- activity management
- audit workflows
- advanced sales team workflows

### 9.4 Franchise ERP Minimum Completeness Standard
The franchise ERP can be called launch-ready only if all of these are true:
- franchise can place stock order without sales-team manual mediation
- franchise can generate retail bills reliably
- franchise can access invoice history and returns
- franchise can search customers and manage credit context
- franchise can operate staff users safely
- franchise can see stock condition, not just raw product list
- franchise can use reports needed for store-level daily control
- franchise can create and use local business masters needed for actual store operations
- franchise can buy and manage stock from outside parties if the business uses that flow
- franchise has usable export/print capability for the report and billing surfaces that matter operationally

If any of the above is missing, the franchise ERP is still partial.

### 9.3 Phase-3 Modules
These are polish / scale modules.
- thermal print specialization
- advanced export packs
- mobile optimizations
- notification center
- workflow automation
- approval SLA engine
- deep analytics

---

## 10. Workflow Design

## 10.1 Franchise Registration Workflow
1. public applicant or internal operator initiates franchise enquiry/registration
2. system creates a franchise registration record with `enquiry` or `registered` status
3. captured data includes applicant identity, proposed shop details, contact data, location, investment/compliance context, and source metadata
4. no runtime ERP user login is created at this stage
5. no live shop code is assigned at this stage unless it is a validated migrated franchise
6. review workspace validates territory mapping, duplicate mobile/email/shop checks, document readiness, and commercial/compliance prerequisites
7. reviewer either rejects, keeps pending for clarification, or approves
8. on approval, system creates or confirms the canonical franchise master record
9. system generates or preserves the shop code with legacy GPMH continuity rules
10. franchise owner user provisioning happens explicitly after approval, with franchise linkage and role assignment
11. activation turns the approved franchise into a live runtime unit only after required checks are complete
12. audit trail records enquiry source, reviewer, approval/rejection reason, shop-code issuance, user provisioning, and activation timestamps

### Acceptance Criteria
- registration cannot bypass approval
- public registration cannot bypass review by silently creating a live ERP user
- generic guest user signup cannot act as franchise onboarding
- shop-code generation with legacy GPMH continuity is race-safe
- approval, provisioning, and activation are separately traceable
- franchise activation and user linkage are traceable

### 10.1A Legacy Registration Lessons To Preserve
Legacy franchise creation was not one clean submit-and-login flow.

What the old system actually did:
- pending and registered franchise data was reviewed through `Get_franchisee_res`
- approval screens generated the next `GPMH` code and wrote final franchise fields during the approval step
- user creation for the franchise owner happened during approval logic, not during the initial lead capture step
- `Activate_shop` later controlled whether the franchise was operationally active in the menu/runtime sense

What we should learn from that old flow:
- the lifecycle stages were real even if the implementation was messy
- approval and activation were separate business decisions and should remain separate when needed
- shop-code issuance belongs to controlled approval logic, not to public self-service registration
- franchise owner provisioning is part of onboarding, but it should be explicit and auditable instead of being hidden inside controller spaghetti

What we should not copy from the old flow:
- hardcoded `GPMH` generation in controller code
- user creation mixed directly into unstructured approval form handlers
- permissions inferred from controller-name rights
- one controller/view pile handling list, review, approval, export, and update side effects together

### 10.1B Current New-App Registration Drift Warning
The current Laravel implementation still blurs unrelated flows:
- `RegisteredUserController` in `routes/auth.php` creates generic guest user accounts that are not aligned with franchise onboarding
- `Admin\FranchiseeController` currently treats franchise registration as a standard admin create/edit form and then attaches approval/activation methods onto the same surface
- the current `Network/Franchisees/CreateEdit` page behaves like a back-office data-entry form, not a proper registration-review workflow

The corrected target flow is:
- public or internal enquiry intake
- protected review queue
- explicit approval/rejection decision
- explicit franchise-owner provisioning
- explicit activation to live runtime state

## 10.2 Franchise User Provisioning Workflow
1. franchise record exists
2. franchisee user created or migrated
3. username defaults to GPM where required
4. `franchisee_id` linked
5. role assigned
6. dashboard redirect works
7. optional additional franchise-linked user creation available if enabled

### Acceptance Criteria
- franchisee user lands in correct franchise surface
- franchise-linked users, if enabled, cannot land in admin screens unless explicitly authorized
- legacy continuity fields available for audit

## 10.3 Product Ordering Workflow
1. franchise searches product catalog
2. adds items to cart
3. submits order
4. order enters pending state
5. distributer reviews
6. accept or reject lines/order
7. dispatch moves order to shipped state
8. franchise sees updated status

### Acceptance Criteria
- franchise can place order without manual sales team mediation
- HO can operate pending/accepted/dispatched queue
- order state transitions are explicit and auditable

## 10.4 Procurement Workflow
1. HO records purchase invoice
2. stock intake recorded
3. purchase return if needed
4. supplier balance updated if applicable

### Acceptance Criteria
- supplier documents stay separate from internal user roles
- stock movements are auditable

## 10.4A Franchise Local Procurement Workflow
1. franchise creates or selects local supplier party
2. franchise records local purchase invoice/challan
3. system resolves local or shared product
4. batch/expiry/rate/quantity captured
5. stock movement recorded for franchise scope only
6. supplier payable context updated if enabled
7. purchase history becomes visible in franchise reports

### Acceptance Criteria
- franchise can procure outside HO when business requires it
- local procurement does not corrupt HO procurement data
- stock and financial effects stay franchise-scoped

## 10.4B Franchise Local Product Workflow
1. franchise creates local product master
2. product is marked as local ownership/source
3. tax/pricing/unit fields validated
4. product becomes available for local purchase and billing
5. stock is created through opening/local purchase flow, not raw silent mutation

### Acceptance Criteria
- franchise can create sellable products without polluting HO catalog
- local product search and billing works like first-class ERP behavior
- reporting can distinguish HO-shared products from franchise-local products

## 10.5 POS / Store Billing Workflow
1. franchisee-side user logs in under franchisee scope
2. searches products / batches
3. builds retail bill
4. checks stock
5. issues invoice
6. records payment mode
7. stock reduces
8. invoice available in history
9. return flow available

### Acceptance Criteria
- franchisee-side users can use POS safely within granted permissions
- bill numbers are collision-safe
- invoice history is accessible

## 10.6 Reporting Workflow
1. user lands on scoped dashboard
2. opens role-appropriate report
3. report is filtered by scope automatically
4. export if allowed by role

### Acceptance Criteria
- heads see territory scope only
- franchisees see own store scope only
- distributer sees order desk scope

---

## 11. Dashboard Design Rules

### 11.1 Dashboards Must Not Be Decorative
Every dashboard must answer:
- what should this user do right now?
- what is blocked?
- what is at risk?
- where do they go next?

### 11.2 Dashboard Structure
Each dashboard must contain:
- role identity banner
- top operational stats
- priority actions
- operational backbone/workflow section
- optionally alerts or queues

Dashboard layouts must also degrade cleanly across screens:
- mobile must stack without losing key actions
- tablet must preserve workflow clarity without cramped cards
- desktop must use space intentionally, not just stretch cards wider
- large screens must feel information-rich, not empty

### 11.3 No Generic Dashboard For Everyone
Different roles must not share a meaningless common dashboard.
They may share layout components, not business content.

### 11.4 Dashboard Richness Rule
Legacy dashboards were not simple welcome pages.
They combined multiple operational patterns in one surface:
- KPI cards
- quick links
- ranked lists
- daily operational summaries
- top/bottom performer views
- product movement visibility
- drill-down entry points
- sometimes direct export entry points

The new ERP dashboards should preserve this level of operational usefulness, but in a cleaner architecture.

Each major dashboard should be designed as a decision and navigation surface, not just a count display.

### 11.5 Dashboard Data Block Types
Dashboard blocks should come from a standard set of patterns:
- KPI summary cards
- action cards
- alert blocks
- ranked lists
- queue tables
- trend summaries
- new/recent items
- quick-link modules

This keeps dashboards rich without becoming chaotic.

---

## 12. Navigation Design Rules

### 12.1 Sidebar Principles
- sidebar is role-aware
- top home item must match role surface
- navigation categories must reflect modules
- no dead links
- no links to unauthorized routes

### 12.2 Navigation Categories
Suggested categories:
- Main
- Identity / Network
- Product Master
- Ordering
- Procurement
- Store Operations
- Accounts
- Reports
- Communication

### 12.3 No Legacy Menu Cloning
We do not rebuild every legacy sidebar item just because it existed.
Each menu item must justify its place in the new product.

### 12.4 Cross-Screen Navigation Rule
Navigation must work cleanly on all screen classes:
- mobile
- tablet
- laptop
- desktop
- large desktop

Requirements:
- no hidden critical action should become unreachable on smaller screens
- no sidebar pattern should trap the user or cover the full work area unnecessarily
- search, cart, invoice, and billing actions must remain fast on touch devices
- role-aware navigation must remain understandable without hover-only behavior

---

## 13. Backend Architectural Boundaries

### 13.1 Controllers
Controllers should:
- validate request
- call service or query layer
- return Inertia view or redirect

Controllers should not:
- calculate complex business flows inline
- duplicate authorization logic
- manipulate raw transaction state directly where a service is needed

### 13.2 Services
Required core services:
- HomeRouteService
- InventoryService
- LedgerService
- CommissionService
- FranchiseApprovalService
- GpmCodeService
- OrderDeskService
- DispatchService
- UserProvisioningService
- PosBillingService
- SalesReturnService
- CustomerCreditService
- FranchiseReportingService
- StockIntelligenceService
- ReportQueryService
- ExportService
- DocumentTemplateService
- DashboardCompositionService
- Migration services where needed

### 13.3 Policies / Authorization
Role checks should be layered with:
- route middleware
- policies where model-level permissions matter
- UI gating only as a convenience, not as real security

### 13.4 Query Scoping
All scoping must be explicit:
- by role
- by territory assignment
- by franchise link

Never by:
- guessed type code
- CSV field matching
- hidden parent chain assumptions alone

### 13.5 One-Way Business Recording Principle
For store and HO operations, the system should prefer immutable business recording over mutable counters.

Examples:
- invoices create financial and inventory effects
- returns create reverse effects with reason codes
- dispatch creates stock movement events
- credit collections create ledger movements

Avoid any design that lets the system silently rewrite history without audit.

### 13.6 Data Scope Columns Must Be Explicit
Every live business record should have an explicit ownership/scope model.

Examples:
- shared master records are global and centrally managed
- franchise-local masters must carry `franchisee_id`
- franchise billing/procurement/inventory records must carry `franchisee_id`
- HO procurement records must not be stored as franchise-local records

At the data-model level, the system must always be able to answer:
- who owns this record?
- who can view it?
- which workflow produced it?
- whether it is live runtime data or archive/reference data?

If a table cannot answer those questions clearly, the design is too loose.

### 13.7 Report Architecture Rule
Reports must not be built as unrelated page-specific SQL blobs.

The architecture should separate:
- filter contract
- scoped query builder
- aggregation logic
- presentation model
- export adapter

That allows one report definition to support:
- on-screen table rendering
- Excel export
- PDF export
- role-based scoped variants

This is necessary because the legacy system had many report surfaces with the same business subject rendered differently by role and export mode.

### 13.8 Export Architecture Rule
Exports must be treated as a platform capability, not a pile of one-off buttons.

The new system should support a shared export layer for:
- Excel
- PDF
- print-ready layouts where required

Every export should be able to carry:
- report title
- report scope
- selected filters
- business identity / branding context
- timestamp / generated-by context

For larger exports, the architecture should be able to evolve toward queued/background generation without redesigning every page.

---

## 14. Data Strategy

### 14.1 What To Migrate
Migrate:
- states
- districts
- product masters
- franchise masters
- active user identities
- GPM codes
- maybe suppliers
- maybe opening stock

### 14.1A What To Preserve For Users But Not Carry Forward As Live Runtime
Preserve:
- active user identity
- franchise-user linkage
- role continuity where valid
- legacy references for traceability

Do not carry forward as live runtime unless explicitly validated:
- old franchise billing transactions
- old local supplier transactions
- old customer credit state
- old expense streams
- old temporary operational drafts

### 14.2 What To Recreate Fresh
Recreate:
- user permissions model
- hierarchy logic
- operational transactions
- order queue
- invoice numbering runtime
- stock movement runtime
- reporting logic
- franchise-local masters as clean new records from go-live onward

### 14.3 What To Archive Only
Archive:
- old orders
- old pending queues
- old invoices and returns
- old carts
- broken support/process data
- raw legacy permissions matrix

### 14.4 Opening Stock Decision
Opening stock can be handled in two ways.

#### Option A: Controlled Import
Pros:
- faster launch if stock is trusted enough
Cons:
- brings reconciliation risk

#### Option B: Manual Opening Entry
Pros:
- cleaner launch
- more trust
Cons:
- more operational effort

Recommendation:
- use controlled import only if counts can be validated
- otherwise do manual opening stock entry per location

### 14.5 Billing History Strategy
Billing history is sensitive because franchisees may expect it, but it should not destabilize launch.

Decision framework:
- if old billing data is needed for legal lookup only, keep it in archive/reference mode
- if only invoice continuity is needed, preserve identifiers in archive search instead of importing all sale logic
- do not force legacy retail invoices into new runtime billing tables unless data quality is validated

The new live billing system should prioritize correctness from go-live onward.

### 14.6 Franchise Local Data Strategy
For franchise-owned ERP data, the default rule should be:

- keep the user
- keep the franchise
- do not import confusing operational history
- let new franchise-owned masters and transactions start clean in the new system

This applies especially to:
- local products
- local supplier parties
- local customer/doctor relationships
- local purchases
- local expenses
- local collections and credit behavior

The rebuild goal is a clean start for franchise operations, not a dirty carry-forward of unreliable runtime data.

### 14.7 Legacy-To-New Data Handling Matrix
This is the minimum concrete handling matrix for the rebuild.

| Legacy area | Legacy tables/examples | New-system action | Live in new ERP? | Notes |
|---|---|---|---|---|
| User identity | `users`, `franchisee_users` | transform and migrate | Yes | preserve identity, re-map roles, keep legacy references |
| Franchise identity | `tbl_franchisee` | transform and migrate | Yes | preserve GPM/shop code and core business identity |
| Territory hierarchy | `tbl_state_head`, `tbl_master_head`, `tbl_district_head`, `users.parent_id` | transform and rebuild | Yes | remove CSV/implicit hierarchy logic |
| Shared product master | `add_new_product`, company/salt/HSN tables | transform and migrate | Yes | central catalog only |
| Franchise local products | franchise-created product flows in legacy | recreate fresh in local-master domain | Yes, fresh only | do not import old local product runtime by default |
| Franchise local parties | `create_new_ledger`, `customers`, `doctors_info` | archive by default, optional later import tool | Not by default | mixed and confusing in legacy; clean restart preferred |
| HO suppliers | supplier-side subset of `create_new_ledger` | transform and migrate | Yes | keep only true HO suppliers |
| Franchise local purchases | `purchase_challan_vendor`, `purchase_challan_product` | archive by default | No | new franchise procurement starts clean |
| Retail sales | `client_sale_info`, `sale_info` | archive | No | invoice history remains reference only |
| Payments/collections | `tbl_bank`, `tbl_cash`, `tbl_credit_payment`, `tbl_credit_note` | archive and redesign | No | replace with clean ledger/payment model |
| Expenses | `tbl_expenses`, `expenses_master` | archive by default, rebuild clean masters | Not by default | new expense runtime starts clean |
| Stock position | `tbl_stock` | controlled opening only | Conditional | use only if validated; otherwise manual opening |
| Legacy carts/temporary state | `tbl_cart`, temp/cart helper tables | ignore/archive | No | not worth carrying into runtime |
| Permissions | `erp_rolemaster`, `access_rights` | redesign only | No | use Spatie roles/permissions |

This matrix is the rule until explicitly revised.

---

## 15. Migration Design Rules

### 15.1 Migration Is Translation
Migration must map legacy facts into the new clean model.
It must not clone legacy structure.

### 15.2 Migration Layers
Use three stages:
1. extract
2. transform
3. load

### 15.3 Idempotency
All migration commands must be rerunnable.
Use deterministic matching and legacy references.

### 15.4 Conflict Handling
Migration must explicitly report:
- duplicate emails
- duplicate usernames
- missing franchise links
- missing territory matches
- inconsistent role records
- duplicate GPM codes

### 15.5 Source Awareness
Every migration must know whether source is:
- FMS
- PharmaERP

Because type codes and meanings differ.

### 15.6 Module-Level Migration Classification
Every legacy table must be classified as one of:
- **migrate directly**
- **transform before load**
- **archive only**
- **ignore**

No table should be imported without being explicitly classified first.

---

## 16. Security Design

### 16.1 Authentication
- session-based auth for web
- optional Sanctum for API/mobile later
- secure password hashing
- optional forced reset for migrated users if compatibility is uncertain

### 16.2 Authorization
- Spatie permission-based roles
- route middleware
- policy checks
- no hardcoded legacy numeric role checks

### 16.3 Sensitive Operations
These must be audited:
- franchise approval
- shop-code generation with legacy GPMH continuity
- role assignment
- dispatch
- purchase approval
- stock adjustment
- financial adjustments
- invoice cancellation
- return approval if applicable
- credit collection reversal
- user role escalation

---

## 17. UX Design Rules

### 17.1 Structure First
Before deep backend data wiring, every major launch-critical role should have:
- proper layout
- proper menu
- proper dashboard
- proper page shells
- obvious primary actions

### 17.2 No Random Page Building
No page should be built because someone thought of it in isolation.
Every page must map to:
- module
- role
- workflow
- launch phase

### 17.3 Progressive Wiring
Page build order:
1. shell
2. route
3. role access
4. mocked data / UX logic
5. backend data contract
6. final persistence

This is the correct approach for a system this large.

### 17.4 ERP UX Rule For Franchise Surfaces
Franchise users should always be able to answer these questions within 1-2 clicks:
- what can I sell right now?
- what stock is low?
- what did I bill today?
- what customer still owes me?
- what order is pending with HO?
- what got dispatched to me?

If the UX does not answer these naturally, the franchise ERP is not operational enough.

### 17.5 Responsive-First Product Rule
This ERP must be designed as premium software quality, not an admin template patched with breakpoints.

Every launch-critical surface must work properly on:
- mobile phones
- tablets
- standard laptops
- full desktop monitors
- large admin/operations screens

That means:
- layouts reflow intentionally instead of randomly collapsing
- tables get a real small-screen strategy, not just overflow and pray
- forms remain fast and readable on touch devices
- action buttons stay reachable with one hand on smaller screens
- cards, filters, and stat blocks keep visual hierarchy across widths
- modals and drawers do not break billing, ordering, or approval flows
- typography, spacing, and density feel deliberate and premium at every size

No core workflow is considered complete if it only feels correct on one developer laptop screen.

### 17.6 Responsive Acceptance Standard
For each major page, definition of done must include:
- mobile usability
- tablet usability
- laptop usability
- desktop usability
- no horizontal-scroll dependency for primary workflow completion
- no hidden critical controls behind hover-only interactions
- no broken overlays, clipped forms, or inaccessible action bars

This must be treated as a product requirement, not polish.

### 17.7 Premium UI Standard
The visual and interaction quality should feel like expensive business software.

That requires:
- consistent spacing system
- strong typography hierarchy
- predictable action placement
- clear empty, loading, and error states
- dense but readable information layout
- refined states for hover, active, disabled, pending, and success/error feedback

The target is software that feels deliberate, trustworthy, and operationally fast.

### 17.8 Reporting UX Rule
Reports are first-class product surfaces, not secondary admin leftovers.

Every important report should provide:
- obvious filters
- clear scope labeling
- totals/subtotals where business expects them
- export actions that match the on-screen data scope
- readable dense tables on desktop
- survivable small-screen behavior for lookup use cases

If users cannot trust what a report includes, excludes, totals, or exports, the report is not finished.

### 17.9 Document Output Rule
PDF and Excel output must feel like intentional business output.

That means:
- consistent titles
- consistent branding rules
- date/filter context included
- document-safe table layouts
- invoice/report output that users can actually share or print

Legacy behavior clearly exposed PDF/Excel from many operational pages.
The new ERP should support that depth through shared document rules, not random page hacks.

---

## 18. Phase-1 Build Order

This is the official build order from here.

### Phase 1A: Structural Spine
- role-based dashboards
- role-based sidebar
- role-based home redirect
- role-aware page shells
- explicit module map
- responsive layout system and screen behavior rules

### Phase 1B: Identity Spine
- user model finalization
- role map finalization
- canonical role vocabulary normalization in code
- franchise user linkage
- franchise-linked user permission model validation
- shop-code generation service hardening with legacy GPMH continuity
- migration-safe legacy reference fields

### Phase 1C: Franchise ERP Core
- franchise dashboard
- B2B cart/order flow
- POS core
- invoices
- returns
- customers
- franchise-linked user management if enabled
- billing print/reprint
- daily sales summary
- customer dues visibility
- batch-aware billing and stock alerts
- responsive billing, cart, and invoice workflows across all screen sizes
- franchise reporting and export foundation

### Phase 1D: HO ERP Core
- network view
- product master
- order desk
- dispatch workflow
- supplier master
- procurement docs
- ledger/finance essentials
- scoped operational reports with export capability

### Phase 1E: Launch Safety
- opening stock decision
- migration scripts for masters + users + franchisees
- route/role audit
- current role-name drift cleanup across routes/controllers/services
- smoke test per role
- launch checklist
- report/export verification for launch-critical surfaces

### Phase 1F: Franchise Readiness Validation
- test a full billing day for one franchise
- test one full replenishment cycle from franchise to HO to dispatch
- test one return cycle
- test one restricted franchise-linked login doing only allowed actions if that feature is enabled
- test one franchisee login doing end-to-end store operations
- test all launch-critical screens on mobile, tablet, laptop, and desktop

---

## 19. Acceptance Criteria For Launch

Launch is allowed only if these are true.

### Identity
- all active franchises exist
- all active required users exist
- GPM continuity preserved
- each role lands on correct surface

### Franchise Operations
- franchise can log in
- franchise can browse products
- franchise can place B2B order
- franchise can run POS
- franchise can access invoices and returns
- franchise can manage daily billing without external system help
- franchise can see local operational reporting
- franchisee and franchise-linked user permissions behave correctly without introducing a separate franchise-staff role
- franchise core workflows remain usable and fast across all supported screen sizes
- franchise can create and manage local products if needed
- franchise can manage local supplier/customer/doctor masters needed to run the store
- franchise can record local purchases without depending on HO catalog-only assumptions
- franchise has operational reports and exports for billing, stock, purchases, and credit-critical views
- franchise-linked users, if enabled, are permission-limited without becoming a separate canonical business role

### HO Operations
- distributer can view and process incoming orders
- HO can accept/reject/dispatch
- HO can manage products and suppliers
- account role can access ledger/expenses
- HO users have scoped dashboards and report exports appropriate to their responsibility level

### System Integrity
- no role sees wrong menu
- no critical page 403s due to structural mismatch
- dashboard routes and module routes are aligned
- core workflows are auditable
- franchise billing and stock effects are internally consistent
- launch does not require phone-order dependence for routine replenishment
- no launch-critical screen breaks responsiveness or hides critical actions on smaller devices
- launch-critical reports export the same scope and totals users see on screen

### Scalability
- role logic does not depend on numeric legacy type codes
- route design can absorb additional roles without sidebar chaos
- inventory and ledger design can handle growth without mutable stock hacks
- dashboard and reporting contracts can evolve without rewriting page architecture

---

## 20. What Must Not Happen Again

These are explicit anti-patterns.

Do not:
- build pages ad hoc
- model business logic around legacy `type` numbers
- use a mixed-source user mapping without source awareness
- import legacy tables blindly into one modern table
- rebuild old menu clutter without workflow purpose
- make generic dashboards for everyone
- wire UI without route authorization alignment
- preserve legacy structure just because it exists
- assume franchise business data is only an extension of HO data
- force every billed product to come from HO catalog
- merge franchise-local procurement into HO procurement flows
- preserve user identity by dragging broken legacy runtime data into live tables

---

## 21. Immediate Execution Directive

For the next development cycle, the team must follow this order:

1. finish the role-based structural shell
2. finalize identity and home-surface rules
3. complete franchise-side operational core pages
4. complete HO order desk and dispatch flow
5. finalize migration rules only after structure is stable

If a future change conflicts with this document, update this document first.
Do not silently diverge.

---

## 22. Practical Build Checklist

### Role & Surface
- [ ] Finalize canonical role names
- [ ] Finalize `Super Admin` vs `Admin` boundary
- [ ] Reserve god-level platform controls for `Super Admin` only
- [ ] Keep legacy roles aligned with current business naming
- [ ] Create explicit alias/deprecation map for non-canonical role names still present in code
- [ ] Normalize route middleware to canonical role vocabulary
- [ ] Normalize controller/service/dashboard role checks to canonical role vocabulary
- [ ] Create role-to-dashboard map
- [ ] Create role-to-sidebar map

### Identity
- [ ] Add legacy reference fields for users
- [ ] Add legacy reference fields for franchisees if needed
- [ ] Finalize franchisee and franchise-linked user permission boundaries without introducing a separate franchise-staff role
- [ ] Finalize head territory assignment rules
- [ ] Separate franchise enquiry intake from generic guest user registration
- [ ] Define explicit franchise review, approval, provisioning, and activation route groups

### Franchise ERP
- [ ] Franchise home dashboard refinement
- [ ] Product ordering UX refinement
- [ ] POS hardening
- [ ] Invoice and return usability pass
- [ ] Franchise-linked user management screens if enabled

### Franchise Onboarding
- [ ] Build franchise registration review queue page
- [ ] Build franchise approval workspace instead of generic CRUD-only edit flow
- [ ] Keep shop-code issuance only inside controlled approval logic
- [ ] Keep activation as an explicit operational action
- [ ] Keep franchise-owner provisioning auditable and separate from guest signup

### HO ERP
- [ ] Order desk dedicated page
- [ ] Dispatch workspace
- [ ] Supplier/procurement workbench
- [ ] Finance desk refinement

### Franchise ERP Deepening
- [ ] One-shot billing workflow definition
- [ ] Cash / credit / split payment handling standard
- [ ] Customer credit visibility and collection flow
- [ ] Batch-aware invoice and return UX
- [ ] Stock alert and near-expiry widgets
- [ ] Daily sales and invoice reporting pack
- [ ] Local product ownership and source-type model
- [ ] Franchise supplier/customer/doctor master model
- [ ] Local purchase workflow outside HO procurement
- [ ] Franchise data-boundary and scoping rules
- [ ] Franchise report pack and export matrix

### Migration
- [ ] Master migration audit
- [ ] Franchise migration audit
- [ ] User migration design
- [ ] Opening stock strategy finalization

### Dashboard / Reports / Exports
- [ ] Dashboard block inventory by role
- [ ] Launch-critical report inventory by role
- [ ] Shared export service contract
- [ ] PDF branding/layout rules
- [ ] Excel export consistency rules

### Launch Safety
- [ ] Role-route audit
- [ ] Per-role smoke tests
- [ ] Legacy archive strategy
- [ ] Cutover checklist

---

## 23. Final Position

The remaining rebuild should follow this philosophy:

**Preserve business identity. Rebuild business workflows. Archive messy history. Ship a smaller but complete ERP core.**

That is the most practical, scalable, and least self-destructive way to finish the system.

For franchisees specifically, the target is not "a portal".
The target is a **single dependable ERP for daily store operations, billing, replenishment, and reporting**.

That also means preserving users while letting their new franchise-owned ERP data begin cleanly inside the new system.
Their identity continues. Their new operational data belongs to the new ERP model, not to the legacy runtime mess.
