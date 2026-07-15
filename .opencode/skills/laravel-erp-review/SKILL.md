---
name: laravel-erp-review
description: Perform skeptical Laravel ERP code review focused on production business-system risks.
compatibility: opencode
---

Use this for focused reviews of Laravel modules, controllers, services, requests, models, policies, jobs, and routes.

Prioritize correctness, permissions, authorization, audit fields, soft deletes, document numbers, transaction boundaries, tenant/company scope, branch and financial-period context, financial precision, N+1 queries, and reporting consistency. Lead with confirmed issues and file references. Avoid broad rewrites, dependency changes, migrations, or permission-model changes unless the inspected code proves they are required.
