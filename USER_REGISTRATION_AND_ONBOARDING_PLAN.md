# User Registration and Onboarding Plan

## Purpose

This document defines how Brainyug ERP should handle new users, franchise applications, distributor onboarding, territory heads, staff accounts, and old legacy users.

The main decision is deliberate:

- Do not open one generic public registration form for every role.
- Do not blindly migrate legacy users as live ERP users when their related data may not migrate cleanly.
- Use separate onboarding flows based on role, risk, and business ownership.

The ERP has role-scoped stock, sales, orders, commissions, territory visibility, finance, and support workflows. User creation is therefore a governance workflow, not just an auth screen.

## Current System Snapshot

The new Laravel ERP already has the core building blocks:

- `users`
  - login identity
  - `parent_id`
  - `franchisee_id`
  - `is_active`
  - optional `legacy_source`, `legacy_user_id`, `legacy_username`
- `roles`, `permissions`, `model_has_roles`
  - Spatie permission system
- `territory_assignments`
  - replaces legacy CSV `districtcode`
  - supports state, district, and city assignment
- `franchisees`
  - shop/business profile
  - status lifecycle
  - hierarchy links to state, zone, and district heads
- `franchisee_staff`
  - staff users scoped to a franchise
- `/franchise/apply`
  - public franchise application route
- `admin.users.*`
  - internal admin-managed user creation
- `admin.franchise-registrations.*`
  - franchise registration review flow

The important gap is not just visual. The product needs a clear onboarding policy and workflow so different roles enter the system correctly.

## Legacy Learnings

Legacy auth was not a single signup flow. It was a mixture of:

- Tank Auth login/session handling.
- Numeric `users.type` role behavior.
- Parent-chain hierarchy through `parent_id`.
- Territory scoping through `statecode` and CSV `districtcode`.
- Franchise linkage through `franch_id`.
- Role-specific menus and controller conditions.

### Legacy Role Types

| Legacy Type | Legacy Meaning | New ERP Role | Notes |
|---|---|---|---|
| 1 | Admin / Super Admin | Super Admin or Admin | Should be manually assigned, never self-registered. |
| 2 | State Head | State Head | Needs state territory assignment. |
| 3 | Zone / Master / Regional Head | Regional Head or Zonal Head | Needs multiple district assignments. |
| 4 | District Head | District Head | Needs one or more district assignments. |
| 5 | Franchisee | Franchisee | Must link to a franchisee/shop. |
| 6 | Distributor | Distributer | Supply-side role; may also use shop/distributor business profile. |
| 7 | Sales Team | Sales Team | Internal/field role, admin-created. |
| 8 | Sister Head or Account depending source | Zonal Head or Account | Needs manual review. |
| 9 | Payment / Order role | Account, Order, or Distributer | Needs manual review. |
| 10 | Sales / Warehouse style role | Sales Team, Warehouse, or Franchisee staff | Needs manual review. |
| 11 | Inward / Warehouse role | Inward or Warehouse | Internal role. |

### Legacy Franchise Onboarding Flow

Legacy franchise onboarding was separate from user login:

1. Public visitor submitted a franchise form.
2. The form created a franchise record, not necessarily a login user.
3. Admin reviewed the application.
4. Admin approved or rejected it.
5. Admin activated the shop.
6. Admin or district head created a login user linked to `franch_id`.
7. Franchisee could then access local shop workflows.

This is a good pattern and should remain, but with cleaner tables, cleaner states, and better UX.

### Legacy Head / Staff Onboarding Flow

State heads, zone heads, district heads, distributors, account users, warehouse users, and sales users were not public self-signup users.

They were created by Admin or by a parent authority in the hierarchy, then assigned:

- numeric role type
- parent user
- state or district scope
- sometimes a franchise/shop link

The new ERP should keep this controlled creation model.

## Core Product Decision

There should be two broad onboarding modes:

1. **Application / request first**
   - used for public franchise and distributor applicants
   - used for people requesting access who are not yet trusted
   - creates a pending record, not an active login

2. **Admin provisioned login**
   - used for Super Admin, Admin, heads, finance, sales, warehouse, distributor operators, and franchise staff
   - creates a user only after review
   - assigns role, territory, parent, and scope at creation time

## User Categories

### Super Admin

Super Admin is platform-level authority.

Rules:

- Never public signup.
- Never inferred from legacy type automatically.
- Created manually by an existing Super Admin.
- Should be very rare.
- Controls:
  - users
  - roles
  - module access
  - support access
  - emergency account recovery

### Admin

Admin handles daily HO operations.

Rules:

- Admin-created only.
- No public registration.
- May create operational users depending policy.
- Should not automatically receive Super Admin powers.

### State Head

State Head manages a state-level territory.

Required fields:

- name
- username
- email
- phone
- parent user, usually Admin or Super Admin
- assigned state
- active/inactive status

On approval/create:

- create `users` row
- assign `State Head` role
- add `territory_assignments` row with `territory_type = state`
- set `parent_id`

### Regional Head / Zonal Head

These users manage clusters of districts.

Required fields:

- name
- username
- email
- phone
- parent user
- one or more districts
- optional state context

On approval/create:

- create `users`
- assign `Regional Head` or `Zonal Head`
- add multiple district territory assignments
- set `parent_id`

Important note:

Legacy mixed naming such as Zone Head, Master Head, Sister Head, and Regional Head. The new ERP should use canonical roles in UI, and keep aliases only for compatibility.

### District Head

District Head manages a district or small set of districts.

Required fields:

- name
- username
- email
- phone
- parent user, usually Zonal/Regional/State Head
- assigned district(s)

On approval/create:

- create `users`
- assign `District Head`
- add district territory assignments
- set `parent_id`

### Franchisee Owner

Franchisee owner is tied to a shop/business profile.

Public form should not instantly create a login.

Required application fields:

- shop name
- owner name
- mobile
- WhatsApp
- email
- state
- district
- city or other city
- pincode
- shop address
- residence address
- GST number
- PAN number
- drug license numbers
- investment amount
- ready to invest
- bank details
- optional document uploads
- optional old username/reference

Lifecycle:

1. application submitted
2. review
3. approved or rejected
4. shop code assigned
5. hierarchy assigned
6. owner login provisioned
7. shop activated

On provisioning:

- create `users`
- assign `Franchisee` role
- link `users.franchisee_id`
- set parent/head hierarchy
- optionally create `franchisee_staff` entry for owner or owner-like staff if needed

### Franchisee Staff

Staff includes cashier, pharmacist, store manager, purchase operator, or helper.

Rules:

- Should not use public signup.
- Must be created by franchise owner, Admin, or Super Admin.
- Must link to an active franchisee.
- Access should be scoped to the franchisee only.

Required fields:

- name
- username
- email or phone
- designation
- franchisee
- permissions/profile baseline
- active status

On create:

- create `users`
- assign `Franchisee` role or a future `Franchisee Staff` role
- set `franchisee_id`
- create `franchisee_staff`

Future improvement:

- Split `Franchisee` and `Franchisee Staff` into separate roles if permissions need to differ strongly.

### Distributer

The current code uses `Distributer` spelling for compatibility. UI can display `Distributor`, but role values should stay consistent unless a full rename is done.

Distributor onboarding is not identical to franchisee onboarding.

Distributor may be:

- a business entity needing a distributor profile
- a warehouse/supply operator
- a user who can approve, dispatch, and manage supply-side workflows

Recommended flow:

- Public distributor application can exist, similar to franchise application.
- Login is provisioned only after approval.
- Admin assigns whether the distributor has stock, dispatch, payment, or reporting access.

Required application fields:

- business name
- owner/contact name
- mobile
- email
- address
- state/district
- GST/PAN
- bank details
- supply territory
- optional legacy reference

Open design decision:

- Reuse `franchisees.shop_type = distributor`, or create a dedicated `distributors` table later.
- Short-term: reuse `franchisees` with `shop_type = distributor` because the migration already supports it.
- Long-term: create dedicated distributor profile if workflows diverge deeply.

### Account / Finance

Rules:

- Internal only.
- Admin-created.
- No public registration.
- Needs limited finance access.

Required fields:

- name
- email
- username
- phone
- parent user
- role = Account
- dashboard/module permissions

### Sales Team

Rules:

- Internal or field staff.
- Admin-created or approved from access request.
- Territory assignment may be required.

Required fields:

- name
- email
- phone
- assigned districts or franchise cluster
- parent user

### Warehouse / Inward / Order Roles

Rules:

- Internal operational roles.
- No public registration.
- Admin-created.
- Must have tightly scoped module permissions.

Potential roles:

- Warehouse
- Inward
- Outward
- Order
- Orderstaff

These roles already exist as compatibility roles after seeding, but they should not be casually exposed in public forms.

## Old Legacy Users Policy

Old users should not be automatically restored as active ERP users unless their business scope and data can also be linked safely.

Reason:

- A login without linked orders, franchise, stock, sales, territory, or finance data creates confusion.
- Legacy email data contains invalid and duplicate values.
- Some legacy users are banned but still marked active.
- Role meaning differs between HO and FMS sources.
- Type `8`, `9`, `10`, and `11` are ambiguous and need review.

Recommended policy:

1. Keep legacy users as reference data.
2. Do not bulk-create active logins.
3. During new registration/request, allow optional fields:
   - old username
   - old shop code
   - old mobile
   - old email
4. Admin can search legacy source while reviewing.
5. If verified, store legacy metadata:
   - `legacy_source`
   - `legacy_user_id`
   - `legacy_username`
6. Require new credentials and current contact details.
7. Force password setup via invite/reset flow.

## Proposed New Data Model

### `access_requests`

Purpose:

Stores pending access requests for non-franchise users and optionally distributor applicants if distributor is not stored directly as a franchisee profile.

Suggested fields:

- `id`
- `request_type`
  - `state_head`
  - `regional_head`
  - `zonal_head`
  - `district_head`
  - `distributor`
  - `account`
  - `sales_team`
  - `warehouse`
  - `franchise_staff`
  - `other`
- `status`
  - `submitted`
  - `under_review`
  - `approved`
  - `rejected`
  - `provisioned`
  - `cancelled`
- `name`
- `email`
- `phone`
- `username`
- `organization_name`
- `designation`
- `requested_role`
- `requested_state_id`
- `requested_district_ids` as JSON
- `requested_franchisee_id`
- `requested_parent_id`
- `legacy_source`
- `legacy_user_id`
- `legacy_username`
- `legacy_notes`
- `notes`
- `review_notes`
- `reviewed_by`
- `reviewed_at`
- `provisioned_user_id`
- `provisioned_by`
- `provisioned_at`
- timestamps
- soft deletes

Important:

This table should not grant access. It is only a queue.

### `franchisees`

Use existing table for:

- franchise applications
- distributor applications if `shop_type = distributor`
- sub-distributor if needed

Add or improve fields only if needed:

- `application_source`
- `applicant_type`
- `legacy_reference`
- document upload metadata

### `user_invitations`

Optional but recommended.

Purpose:

Let Admin approve and create a user without manually sharing a password.

Suggested fields:

- `id`
- `user_id`
- `token_hash`
- `email`
- `expires_at`
- `accepted_at`
- `created_by`
- timestamps

Flow:

1. Admin provisions user.
2. System sends or displays invite link.
3. User sets password.
4. `email_verified_at` can be set after invite acceptance.

If mail is not ready:

- Admin can manually set temporary password.
- User is flagged for forced password reset.

## Proposed Public Page

Route:

- `/register`
  - new public landing for registration/access requests

Alternative:

- Keep `/franchise/apply` for direct franchise application
- Add `/access-request`
- Homepage links to both

Recommended UX:

One public page with a first-step segmented choice:

- Franchise / Medical Shop
- Distributor
- Existing Staff / Head Access
- Existing Legacy User Help

### Track 1: Franchise / Medical Shop

Creates or updates a `franchisees` application.

Sections:

1. Shop identity
2. Owner/contact
3. Location
4. Compliance
5. Investment/bank
6. Legacy reference
7. Submit

Status after submit:

- `enquiry` for short form
- `registered` for full form

### Track 2: Distributor

Short-term:

- create `franchisees` row with `shop_type = distributor`

Sections:

1. Business identity
2. Owner/contact
3. Location and territory
4. GST/PAN
5. Bank
6. Legacy reference

Status after submit:

- `enquiry` or `registered`

### Track 3: Head / Staff Access Request

Creates `access_requests`.

Sections:

1. Personal info
2. Requested role
3. Territory or franchise link
4. Who asked you to join / reporting manager
5. Legacy reference
6. Notes

Important:

The page should clearly state that access is created only after review.

### Track 4: Existing Legacy User Help

This should not create a user directly.

It should collect:

- old username
- old mobile
- old email
- shop code if any
- current contact details
- requested access type

It creates an `access_request` or attaches metadata to franchise/distributor application.

## Admin Review Queue

Add a unified review area:

Route ideas:

- `/admin/onboarding`
- `/admin/onboarding/franchise-applications`
- `/admin/onboarding/access-requests`

Tabs:

- Franchise Applications
- Distributor Applications
- Head / Staff Requests
- Legacy Verification
- Approved
- Rejected

### Review Actions

For franchise/distributor:

- mark under review
- approve
- reject
- assign shop code
- assign state/zone/district heads
- activate
- provision owner login

For access request:

- approve
- reject
- choose role
- choose parent
- assign territory
- link franchisee if staff
- provision login

### Approval Guardrails

Super Admin only:

- create Super Admin
- assign Super Admin
- approve platform-wide admin roles
- override module access

Admin or Super Admin:

- create Admin, Account, Sales Team, Warehouse, Distributor users
- approve franchise/distributor applications

State Head:

- may review franchise applications in assigned state if allowed
- should not create Admin/Super Admin

District Head:

- may review/assist franchise applications in assigned districts if allowed
- may request or create franchise staff depending final policy

Franchisee Owner:

- may request/create staff for own franchise only

## User Provisioning Rules

When creating a user from approval:

1. Validate role.
2. Validate scope requirements.
3. Create user with `is_active = true`.
4. Assign role.
5. Set `parent_id`.
6. Set `franchisee_id` if role requires it.
7. Create territory assignments if role requires them.
8. Add `franchisee_staff` if staff.
9. Store legacy reference if verified.
10. Create invitation or force password reset.
11. Audit the action.

## Role Scope Requirements

| Role | Requires Parent | Requires Territory | Requires Franchisee | Public Request Allowed |
|---|---:|---:|---:|---:|
| Super Admin | No | No | No | No |
| Admin | Usually | No | No | No |
| State Head | Yes | State | No | Request only |
| Regional Head | Yes | Districts | No | Request only |
| Zonal Head | Yes | Districts | No | Request only |
| District Head | Yes | Districts | No | Request only |
| Franchisee | Yes | From franchise | Yes | Application only |
| Distributer | Yes | Optional | Optional distributor profile | Application or internal |
| Account | Yes | Optional | No | Request only |
| Sales Team | Yes | Optional districts | No | Request only |
| Warehouse | Yes | No or warehouse scope | No | No |
| Inward | Yes | No or warehouse scope | No | No |
| Order | Yes | No or supply scope | No | No |
| Franchisee Staff | Yes | No | Yes | Invite/request only |

## Migration Policy For Legacy Users

### Do Not Do

- Do not import all legacy users as active ERP users.
- Do not preserve old passwords as the main auth strategy.
- Do not assign Super Admin automatically from old type `1`.
- Do not use old `districtcode` CSV directly.
- Do not trust old active/banned flags blindly.

### Do

- Import legacy users into a reference/archive table if needed.
- Use legacy data to prefill review screens.
- Let Admin confirm one user at a time.
- Create new credentials.
- Link old identity as metadata.
- Keep old source visible in audit.

## UI Principles

The registration UI should feel operational, not like marketing.

Guidelines:

- Dense but clear forms.
- Progressive sections.
- Save incomplete application if possible later.
- Clear status after submit.
- Avoid creating active accounts from public forms.
- Show role-specific fields only when needed.
- Use select controls for role, state, district, franchise.
- Use checkboxes/toggles for readiness and document availability.
- Use file upload fields only when backend storage is ready.
- Do not overwhelm applicants with internal ERP language.

## Implementation Phases

### Phase 1: Policy and Safety

- Keep generic Breeze `register` route disabled for direct user signup.
- Keep `/franchise/apply` active.
- Confirm role list and naming.
- Confirm distributor representation:
  - short-term `franchisees.shop_type = distributor`
  - long-term dedicated distributor table if needed

### Phase 2: Access Request Backend

- Create `access_requests` migration.
- Create `AccessRequest` model.
- Create controller:
  - public create/store
  - admin index/show
  - approve/reject
  - provision
- Add validation per request type.
- Add audit trail.

### Phase 3: Public Registration UI

- Replace or extend current `Franchise/Apply.vue`.
- Add first-step request type chooser.
- Build tracks:
  - Franchise
  - Distributor
  - Head/Staff Access
  - Legacy Help
- Store franchise/distributor applications in `franchisees`.
- Store head/staff/legacy-help requests in `access_requests`.

### Phase 4: Admin Review UI

- Add `/admin/onboarding`.
- Add filters:
  - status
  - type
  - state
  - district
  - search
- Add review screen.
- Add approve/reject forms.
- Add provisioning form.

### Phase 5: Provisioning Engine

- Create service:
  - `UserProvisioningService`
- Inputs:
  - request/application
  - role
  - parent
  - territory
  - franchisee
  - module overrides
- Outputs:
  - user
  - assigned role
  - territory rows
  - franchise staff row if needed
  - invite or forced reset

### Phase 6: Invitation / Password Setup

- Add user invitation table.
- Add invite route.
- Add password setup page.
- Mark invite accepted.
- Clear forced password reset after setup.

### Phase 7: Legacy Verification Helper

- Add optional legacy lookup panel for admins.
- Search old users by:
  - username
  - mobile
  - email
  - franchise code
- Show old role/type, state, district, franchise id, active/banned flags.
- Let admin attach verified legacy identity to new user/application.

### Phase 8: Hardening

- Add tests:
  - public form does not create active users
  - approval creates user correctly
  - State Head gets state territory
  - District Head gets district territory
  - Franchisee user must have franchisee link
  - Franchise staff cannot access other franchise data
  - Super Admin cannot be self-requested
- Add rate limiting to public requests.
- Add spam protection if exposed publicly.
- Add email notifications later.

## Minimum Viable Version

The smallest useful build:

1. Keep `/franchise/apply`.
2. Add `access_requests`.
3. Add public `/access-request`.
4. Add admin review list for access requests.
5. Let Super Admin provision from request.
6. Do not implement email invite yet; use temporary password plus forced reset.

This gives operational value quickly without overbuilding.

## Open Questions

- Should Distributor be stored as `franchisees.shop_type = distributor`, or get its own table now?
- Should Franchisee Owner and Franchisee Staff be separate roles immediately?
- Should State/Zone/District Heads be allowed to approve applications, or only recommend approval?
- Should legacy lookup read directly from old databases, or should we import a read-only legacy archive table?
- Should public access requests require OTP/email verification before entering the queue?
- Should staff requests require an existing active franchisee owner to approve?

## Recommended Decision

Build a multi-track registration system, but keep actual user creation behind approval.

Recommended flows:

- Public franchise/distributor application creates business application only.
- Public head/staff/legacy help creates access request only.
- Admin approval provisions login.
- Legacy identities are linked as metadata only after manual verification.

This preserves the useful parts of legacy hierarchy while avoiding the old system's biggest failures: ambiguous roles, CSV territory scoping, unsafe self-signup, and live users with broken business links.
