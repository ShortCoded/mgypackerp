---
name: extend-existing-erp
description: Modify an existing MgyPack ERP screen or workflow by locating its current implementation, shared services/components, and a working reference before editing. Use for feature fixes and extensions; not for greenfield applications.
---

# Extend Existing ERP Behavior

Start from the real request path: UI field or action, validation, controller, service/query, model relationships, response, and tests. Identify the shared component or service and the closest working screen before deciding where the change belongs.

Preserve the existing contracts that apply:

- company, branch, and financial-period scoping;
- permission and role checks, including the difference between visibility and current responsibility;
- translations, locale direction, date/number formatting, and the established UI framework;
- soft deletes, statuses, locks, audit trails, sessions, and notification semantics;
- route names, public document identifiers, pagination, and query efficiency.

Use Laravel Boost documentation, schema, log, and browser tools when relevant. Confirm suspected data shapes with safe aggregate/read-only queries. Reuse an existing destination screen or component; create a small missing entry point only when no truthful existing view can represent the data.

Add or update focused Pest coverage with isolated factories. Test the successful path, empty/error states, permission and scope boundaries, and the state transition that should change counts or lists. Review the final diff against unrelated work and run Pint on only the PHP files in scope when other uncommitted PHP changes belong to someone else.
