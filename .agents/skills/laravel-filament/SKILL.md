---
name: laravel-filament
version: 2.0.0
description: |
  Filament v5 admin-panel reference skill: resources, forms, tables, actions, filters, relation
  managers, widgets, panels, and testing. Use when the task touches Filament-specific admin code
  and needs the v5 namespace and API conventions rather than generic Laravel patterns.
allowed-tools:
  - Bash
  - Read
  - Write
  - Edit
  - Grep
paths:
  - "app/Filament/**/*.php"
  - "app/Providers/Filament/**/*.php"
  - "resources/views/filament/**/*.blade.php"
  - "config/filament.php"
  - "tests/**/*.php"
  - "composer.json"
argument-hint: "[Filament resource, form, table, widget, or panel task]"
arguments:
  - request
when_to_use: |
  Use when the task is specifically about Filament resources, forms, tables, actions, filters,
  relation managers, widgets, navigation, or panel configuration. Examples: "fix this Filament
  table action", "create a Resource", "update Filament filters", "add an admin widget". Do not use
  for general Laravel API work when the main Laravel skill is the better match.
effort: high
---

<EXTREMELY-IMPORTANT>
This skill exists to keep Filament work aligned with v5, not to duplicate the entire reference library.

Non-negotiable rules:
1. Read `references/namespaces.md` first.
2. Then load only the reference files the task actually needs.
3. Keep business logic out of Resources.
4. Use the v5 action and table APIs, not old v3 patterns.
5. Keep the heavy Filament cookbook in `references/`, not inline here.
</EXTREMELY-IMPORTANT>

# laravel-filament

## Inputs

- `$request`: The Filament admin task or component to modify

## Goal

Route Filament work through the correct v5 conventions so the implementation uses the right namespaces, table APIs, and admin-panel composition patterns.

## Step 0: Read the v5 namespace contract

Always start with:

- `references/namespaces.md`

That establishes the namespace and API changes that commonly break Filament work.

**Success criteria**: The task is grounded in the correct Filament v5 surface before editing.

## Step 1: Load only the relevant admin references

Use the routing table to pick reference files. Do not bulk-load everything.

| Task | Read |
|------|------|
| v5 namespace and API changes | `references/namespaces.md` |
| Creating or editing a Resource (CRUD) | `references/resources.md` |
| Form fields, sections, layout | `references/forms.md` |
| Table columns, sorting, searching | `references/tables.md` |
| Bulk actions, row actions, header actions | `references/actions.md` |
| Table filters, filter forms | `references/filters.md` |
| Relation managers, BelongsToMany, HasMany | `references/relationships.md` |
| Dashboard widgets, stats, charts | `references/widgets.md` |
| Multi-panel setup, navigation, tenancy | `references/panels.md` |
| Flash messages, database notifications | `references/notifications.md` |
| Infolist entries, read-only views | `references/infolists.md` |
| Testing resources, pages, actions | `references/testing.md` |

Multiple tasks? Read multiple files.

**Success criteria**: Only the task-relevant Filament conventions are in the active context.

## Step 2: Implement with the core Filament guardrails

Keep these rules active:

- actions come from `Filament\\Actions\\*`
- tables use the v5 record/toolbar/header action methods
- badge presentation uses `TextColumn->badge()`
- layout components come from the Schemas package
- Resource classes delegate real business work to application Actions

**Success criteria**: The change uses valid v5 APIs and stays maintainable.

## Step 3: Verify the admin surface

Use the narrowest relevant verification:

- focused feature tests
- Filament page/resource tests
- lightweight UI smoke coverage if the repo already has it

**Success criteria**: The Filament surface still behaves correctly after the change.

## Guardrails

- Do not inline the whole Filament handbook in `SKILL.md`.
- Do not skip `references/namespaces.md`.
- Do not use v3 action namespaces or deprecated table methods.
- Do not put business logic directly in Resource classes.

## When To Load References

- `references/namespaces.md`
  Always.

- then only the task-relevant files under `references/`

## Output Contract

Report:

1. which Filament references were loaded
2. the v5 pattern applied
3. the change made
4. the verification run
