# 0002 — Permission-Modell mit Klassen, Scopes und User-Overrides

**Status:** accepted
**Datum:** 2026-04-29 (Phase 0)

## Kontext

Schul-Workflows haben sehr heterogene Rollen: Admin, Schulleitung, Sekretariat,
Lehrkraft, Förderkoordination, Stufenleitung. Permissions müssen feingranular sein
(z. B. Klarnamen sehen vs. nicht), und Lehrkräfte sollen nur ihre eigenen Klassen
sehen können.

## Entscheidung

**Drei-Schichten-Modell:**

1. **Permission-Katalog** als Single Source of Truth in Code (`PermissionCatalog::all()`).
   Jeder Eintrag: `{key, area, description, is_scopeable, requires_two_factor}`.

2. **User-Klassen** (`user_groups`) bekommen Permissions via `group_permissions`-Pivot.
   Default-Klassen (Admin/Schulleitung/Sekretariat/Lehrkraft) im Seeder definiert.

3. **User-Overrides** (`user_permission_overrides`) als Grant/Revoke pro User.
   Effektive Permission = (Σ Klassen-Permissions) ∪ User-Grants ∖ User-Revokes.

**Scoping** über `user_scope_assignments`: User kann auf bestimmte Lerngruppen begrenzt sein
(Default für Lehrkraft, schulweit für Schulleitung/Sekretariat).

**2FA-Gate** pro Permission via `requires_two_factor` — wird über `EnsureRecentTwoFactor`-
Middleware durchgesetzt (TTL aus `lsp.two_factor.reauth_ttl_minutes`).

## Konsequenzen

### Vorteile
- Sehr feingranular ohne Code-Anpassung: neue Permission = neuer Katalog-Eintrag + Resource.
- Override-Mechanismus erlaubt Ausnahmen ohne neue Klassen-Definition.
- 2FA-Re-Auth nur dort, wo es nötig ist (Sensitive-Actions), nicht bei jedem Login.

### Nachteile
- Permission-Resolver muss bei jedem Request laufen — Cache pro User-Session ist nötig.
- Bei vielen User-Overrides verliert man Übersicht: empfohlen, statt Overrides eigene
  Klassen anzulegen.

## Implementation

- `app/Domain/Permission/PermissionCatalog.php` (Katalog)
- `app/Domain/Permission/PermissionResolver.php` (Auflösung mit Cache)
- `app/Filament/Concerns/AuthorizedResource.php`, `AuthorizedPage.php`
- `app/Http/Middleware/EnsurePermission.php`, `EnsureRecentTwoFactor.php`
