# 0001 — Envelope-Encryption für Schüler-Klarnamen

**Status:** accepted
**Datum:** 2026-04-29 (Phase 0)

## Kontext

Das LSP speichert Schüler-Klarnamen (Vor-/Nachname) verschlüsselt. Mehrere User
einer Schule sollen eigenständig (mit eigenem Passwort) entsperren können.
Ein Recovery-Mechanismus für vergessene Passwörter ist erforderlich, ohne dass
ein Admin alle Passwörter kennt.

## Entscheidung

**Envelope-Encryption** mit n-Wraps:
- Eine schulweite **DEK** (Data Encryption Key, 32 Byte, AES-256-GCM) verschlüsselt alle Klarnamen.
- Die DEK wird n-fach **gewrappt**: pro berechtigtem User-Passwort + 1× Recovery-Key.
- Wrap-Algorithmus: KEK = Argon2id(password, salt, params) → AES-256-GCM(plaintext=DEK, key=KEK).
- DEK liegt nur **in Session-Memory** vor (Hex-encoded in Session); nie auf Disk im Klartext.

## Konsequenzen

### Vorteile
- Jeder User hat ein eigenes Klarnamen-Passwort, unabhängig wechselbar.
- Recovery-Key (einmalig im Setup angezeigt) erlaubt Wiederherstellung ohne andere User.
- Bei Passwort-Wechsel werden NUR Wraps neu erzeugt — Daten bleiben unangetastet.
- Compromise eines User-Passworts gefährdet andere User nicht (jeder Wrap eigener Salt + KEK).

### Nachteile
- Komplexere Onboarding-UX: jeder neue User braucht expliziten Wrap-Provisioning durch Admin
  (siehe [Admin-Action `provisionClearname` in UserResource]).
- Verlust ALLER Wraps + Recovery-Key = unwiederbringlicher Datenverlust. Recovery-Key
  muss physisch sicher verwahrt werden.
- Sensitive Aktionen (Bulk-PDF mit Klarnamen) brauchen entsperrte Session — UX-Friction
  bei längeren Workflows.

### Alternativen (verworfen)
- **App-Key-basierte Verschlüsselung** (Laravel `encrypted`-Cast): keine User-Trennung,
  Recovery nicht möglich.
- **PGP/SOPS pro User**: zu schwerfällig für eine Schul-UI.

## Implementation

- `app/Domain/Crypto/CryptoService.php`
- Models: `EncryptionKey`, `KeyWrap`, `RecoveryKey`
- Filament-Pages: `ClearnameUnlock`, `ClearnamePasswordChange`, `RecoveryKeyManagement`
