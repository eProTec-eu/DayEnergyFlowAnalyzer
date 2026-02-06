
# Day Energy Flow Analyzer (DEFA)
Ein IP‑Symcon Modul zur tagesgenauen Analyse von Energieflüssen zwischen PV, Wärmepumpe (WP), Hauslast, Batterie (SOC) und Netz (Import/Export) – inkl. Dashboard‑Export (PDF), CSV‑Exports und automatischem Backfill.

## ✨ Funktionen im Überblick
### 🔍 Analyse & Datenverarbeitung
- **Analyze()** – Simulation der Energieflüsse im gewählten Zeitfenster
- **ExportDetails()** – CSV‑Export aller Intervallwerte
- **BackfillRange()** – erzeugt Tages‑Zählerwerte über beliebige Zeiträume
- **RunDailyBackfill() / RunDailyBackfillNow()** – täglicher Auto‑Backfill

### 📊 Dashboard
- **OpenDashboard()** – HTML/PHP‑Dashboard zur Jahresübersicht
- **ExportDashboardPDF()**
  - Erstellt ein PDF im Querformat
  - Nutzt automatisch das zuletzt im Dashboard gewählte Jahr
  - Rendert HTML via `wkhtmltopdf`

### 🧹 Temp‑Cleanup
- Automatischer täglicher Cleanup für `/user/temp/`
- Manuell triggerbar per:
  ```php
  DEFA_RunTempCleanupNow(<InstanceID>);
  ```

## 🔧 Voraussetzungen
### IP‑Symcon
- IP‑Symcon 7.x oder neuer
- Aktiviertes Archive Control
- Für die geloggten Archiv‑Variablen sind **1–5 Minuten Intervall** empfohlen  
  (die Analyse funktioniert aber auch zuverlässig mit größeren Intervallen)


### Geloggte Variablen
- PV‑Erzeugung
- Hauslast
- WP‑Verbräuche (Heizen/WW oder Gesamt)
- Import (Pflicht)
- Optional: Export, SOC, Wärmemengen

## 💾 Voraussetzungen für Dashboard‑Export (PDF)
### 1. **wkhtmltopdf installiert**
Beispiel (Debian/Ubuntu):
```
sudo apt-get install wkhtmltopdf
```

### 2. **Zugriff durch den Symcon‑Dienst**
Test:
```
sudo -u <symcon-user> wkhtmltopdf -V
```

### 3. **Dashboard muss über HTTP verfügbar sein**
Das PDF rendert via:
```
http://127.0.0.1:3777/user/defa_dashboard.php?year=YYYY
```
(PHP kann über `file://` nicht ausgeführt werden.)

### 4. **Erforderliche wkhtmltopdf‑Flags**
- `--orientation Landscape`
- `--page-size A4`
- `--encoding utf-8`
- `--print-media-type`
- `--disable-smart-shrinking`
- `--load-error-handling ignore`

### 5. **Dateirechte für /user/temp/**
Symcon‑Dienst braucht Lese/Schreibrechte:
```
sudo chown -R symcon:symcon /var/lib/symcon/user
sudo chmod -R 775 /var/lib/symcon/user
```

## ⚙️ Konfiguration
### 1) Energie‑Variablen (PV, Last, WP, Import …)
### 2) Zeitbereich (relativ/absolut)
### 3) Batterie‑Parameter (CapKWh, EtaC/D, Charge/DischargeKW)

## 📊 Dashboard – Jahresübersicht
- Monatswerte für Wärme, WP‑Strom, COP, PV, Haus, Netz, Kosten, Betriebsstunden
- Steuertasten: **Vorjahr / Folgejahr**
- Gewähltes Jahr wird gespeichert in
```
/user/temp/defa_dashboard_selected_year.txt
```
➡️ PDF‑Export verwendet dieses Jahr automatisch.

## 📄 PDF‑Export
- Querformat A4
- Sehr stabil durch HTTP‑Rendering
- Fehler landen in:
  ```
  /user/temp/wkhtml_err_*.log
  ```
- PDFs in:
  ```
  /user/temp/dashboard_*.pdf
  ```

## 🧹 Temp‑Cleanup
Das Modul erzeugt Dateien im Temp‑Ordner:
- `dashboard_*.pdf`
- `defa_export_*.csv`
- `diagram_*.svg`
- `defa_help_*.html`
- `wkhtml_err_*.log`

### Automatischer Cleanup
- Uhrzeit frei konfigurierbar
- Aufbewahrungsdauer einstellbar
- Optional: maximale Gesamtgröße, maximale Dateianzahl

### Manueller Cleanup
```
DEFA_RunTempCleanupNow(<InstanceID>);
```

## 📘 Bedienfunktionen
- Analyze()
- ExportDetails()
- BackfillRange()
- RunDailyBackfill()
- OpenDashboard()
- ExportDashboardPDF()
- RunTempCleanupNow()

## 💡 Tipps
- Logger‑Intervall 1–5 Minuten ideal
- SOC regelmäßig loggen
- Für PDF das Dashboard vorher öffnen & Jahr wählen

## 💙 Unterstützung
Wenn dir das Modul gefällt:
**https://www.paypal.com/pool/9mms4CEXrr?sr=wccr**

## 📄 Lizenz (MIT)
Copyright (c) 2026 Matthias Fenske
