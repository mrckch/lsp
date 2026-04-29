# LSP – Installations- & Betriebshandbuch

**Stand:** 2026-07-01 (v1.0.0)

---

## 1. Voraussetzungen

### Hardware (Empfehlung)
- Hetzner CX22 oder vergleichbar: 2 vCPU, 4 GB RAM, 40 GB SSD
- Ausreichend für eine Schule mit ~1000 SuS

### Software
- Linux-Server (Ubuntu 24.04 LTS empfohlen)
- Docker Engine ≥ 24
- Docker Compose v2

### DNS / TLS
- Eine Subdomain (z. B. `lsp.schule.de`), die auf den Server zeigt
- Mailadresse für Let's-Encrypt-Benachrichtigungen

---

## 2. Installation

### 2.1 Quellcode bereitstellen

```bash
git clone https://example.com/lsp.git /opt/lsp
cd /opt/lsp
git checkout v1.0.0     # für Produktion: festen Tag wählen
```

### 2.2 Konfiguration

```bash
cp .env.example .env
$EDITOR .env
```

Wichtige Einstellungen:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lsp.schule.de

DB_PASSWORD=<starkes-passwort>
DB_ROOT_PASSWORD=<starkes-passwort>

LSP_HOSTNAME=lsp.schule.de
LETSENCRYPT_EMAIL=admin@schule.de
```

### 2.3 Container bauen und starten

```bash
docker compose build
docker compose up -d
```

### 2.4 Erstinstallation

Beim ersten Start sind die Migrations noch nicht gelaufen:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Der Default-Seeder legt an:
- Permission-Katalog
- System-Benutzerklassen (Admin, Schulleitung, Sekretariat, Lehrkraft)
- Erhebungstypen (Eingangstest, Zwischen, Abschluss, Förderdiagnostik)
- Default-Förderbedarfs-Schwellen (LQ < 85, LQ < 70, Δ-LQ < -10)
- System-Druckvorlagen (Rückmeldebogen, QR-Liste, Verlaufsdiagramm, …)

### 2.5 Setup-Wizard

Im Browser öffnen: `https://lsp.schule.de/setup`

Vier Eingabeblöcke:
1. **Schule** (Name, optional Kürzel)
2. **Admin-Konto** (Username, Anzeigename, E-Mail, Passwort)
3. **Klarnamen-Passwort** (für die Verschlüsselung der Schülernamen)
4. **Bestätigung**, dass der Recovery-Key sicher verwahrt wird

Danach wird der **Recovery-Key** einmalig angezeigt:
- ⚠ **Diese Anzeige ist die einzige Gelegenheit.** Drucken Sie die Seite oder kopieren Sie den Schlüssel in einen verschlossenen Umschlag oder einen Passwort-Manager der Schulleitung.
- Ohne Recovery-Key UND ohne ein gültiges Klarnamen-Passwort sind die verschlüsselten Schülernamen nicht mehr lesbar.

---

## 3. Betrieb

### 3.1 Backups

Erst nach Setup ein Backup-Ziel anlegen:
- Im Admin-Bereich → System → Backup-Ziele → Neu
- Lokal (Default) oder SFTP
- Verschlüsselungspasswort vergeben (AES-256, Argon2id-KDF)

Manuell starten:
```bash
docker compose exec app php artisan backup:run
```

Geplante Backups laufen via Scheduler-Container alle 24h.

### 3.2 Restore

Restore ist absichtlich nur über die CLI verfügbar:

```bash
docker compose exec app php artisan backup:restore lsp_backup_20260701_run42.bin
```

Das Kommando entschlüsselt das Backup und gibt das Manifest aus. Die eigentliche Wiederherstellung erfolgt nach interaktiver Bestätigung.

### 3.3 SVWS-Import

In v1.0.0 ist der SVWS-Adapter **vorbereitet, aber noch nicht produktiv**. Aktuell verwendete Quelle: **SchiLD-CSV** (Format `ID;Name;Vorname;Klasse;Geschlecht`).

Importablauf:
1. Admin → Import → Quelle wählen
2. CSV hochladen, Schuljahr wählen
3. Validierung – fehlerhafte Zeilen markieren / ausschließen
4. **Diff-Anzeige** mit Kategorien:
   - Neu (Anlage)
   - Geändert (Update)
   - Im Import fehlend → **Archivkandidaten** (einzeln bestätigbar/ausschließbar)
5. Commit (in Transaktion)

### 3.4 Klarnamen-Passwortrotation

Admin → Klarnamen → Passwort-Rotation erzwingen:
- Setzt für alle User-Wraps `rotation_required_at`
- User müssen beim nächsten Login ein neues Klarnamen-Passwort vergeben
- Datenbestand bleibt unangetastet (nur User-Wrap wird neu erzeugt)

### 3.5 Updates

```bash
cd /opt/lsp
git fetch --tags
git checkout v1.x.y
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
```

---

## 4. Hinweise

### 4.1 Test-Material
LSP liefert das **Verfahren** des Salzburger Lese-Screenings, **nicht** die Original-Sätze, Original-Normtabellen oder Original-Testhefte. Diese müssen Schulen selbst lizenzieren bzw. eigene Materialien beschaffen und über das Admin-UI einspielen (Fragebögen-Import + Normtabellen-Import).

### 4.2 Datenschutz
- Single-Tenant pro Schule reduziert die DSGVO-Komplexität (i. d. R. kein Auftragsverarbeitungsvertrag mit einem Cloud-Provider, wenn auf eigenem Server betrieben).
- Klarnamen sind via Envelope Encryption verschlüsselt – ein DB-Dump ist ohne Klarnamen-Passwort nicht entschlüsselbar.
- Schülerergebnisse (Rohwert/LQ) sind im Klartext, da sie ohne Personenbezug ausgewertet werden können (Schülercode statt Name).

### 4.3 Sicherheitsempfehlungen
- 2FA für Admin- und Schulleitungs-Accounts aktivieren
- Recovery-Key im Schultresor, nicht digital
- Backups verschlüsselt, getrennt vom Server aufbewahren
- Caddy-TLS aktiv halten (Default)

---

## 5. Hilfe / Bugs

- **Doku:** [docs/](.)
- **Issue-Tracker:** GitHub-Repository
- **Sicherheitslücken:** privat an die Maintainer melden, nicht öffentlich
