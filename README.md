
# Day Energy Flow Analyzer (DEFA)

Ein IP‑Symcon Modul zur tagesgenauen Analyse von Energieflüssen zwischen PV, Wärmepumpe (WP), Hauslast, Batterie (SOC) und Netz (Import/Export) – inkl. automatischem **Backfill** von Tageswerten.

---

## ✨ Funktionen im Überblick
- **Analyze()** – Simulation der Energieflüsse im gewählten Zeitfenster; schreibt Tagesergebnisse
- **ExportDetails()** – CSV‑Export der Intervallwerte
- **BackfillRange()** – Tages‑Zählerwerte automatisch erstellen
- **RunDailyBackfillNow() / RunDailyBackfill()** – täglicher automatischer Backfill
- **ShowHelp() / OpenHelp()** – Anzeige der Modulhilfe (Text und HTML)

---

## 🔧 Voraussetzungen
- IP‑Symcon mit **Archive Control**
- Geloggte Variablen für PV, Verbrauch, Wärmepumpe (gesamt oder H+WW), Import
- Optional: Export & SOC‑Werte

---

## ⚙️ Konfiguration
### 1) Energiequellen & Flags
Jede Eingangsvariable wird mit einer IPS‑Variable verknüpft und optional als Zähler (kumulativ) markiert.

| Variable | Ident | Beschreibung |
|---------|-------|--------------|
| PV‑Erzeugung | VarPV | Gesamt‑PV‑Energieverbrauch (Impuls oder Zähler) |
| PV ist Zähler | PVIsCounter | True, wenn PV kumulativer Zähler ist |
| Hauslast | VarLoad | Hausverbrauch ohne WP |
| Last ist Zähler | LoadIsCounter | True für kumulativen Verbrauchszähler |
| WP Gesamt | VarHP | Gesamtverbrauch der WP |
| WP ist Zähler | HPIsCounter | True, falls WP‑Zähler |
| WP Heizen | VarHPHeat | Nur Heiz‑WP‑Energie |
| WP Warmwasser | VarHPDHW | Nur WW‑WP‑Energie |
| Import | VarImport | Netzbezug |
| Import ist Zähler | ImportIsCounter | True für Zähler |
| Export (optional) | VarExport | Netzeinspeisung |
| Export ist Zähler | ExportIsCounter | True für Zähler |
| SOC (optional) | VarSOC | Ladezustand Batterie in % |

---

### 2) Zeitbereich
- **Relativ**: X Tage/Stunden zurück
- **Absolut**: definierter Zeitraum (Backfill nutzt diesen Modus intern)
- `StepMinutes`: Breite der Analyse‑Intervalle

---

### 3) Batterie‑Parameter
- `CapKWh` – Kapazität in kWh
- `EtaC` – Lade‑Wirkungsgrad
- `EtaD` – Entlade‑Wirkungsgrad
- `ChargeKW` / `DischargeKW` – Leistungsgrenzen
- `ParamsJSON` – erweiterte Parameter wie `epsilon_import`, `return_details`

---

## 📊 Ergebnisvariablen (Analyse‑Ausgabe)
_Nach jeder Analyze() werden diese Variablen automatisch beschrieben:_

| Ident | Inhalt |
|-------|--------|
| **TargetDate** | Ziel‑Kalendertag der Analyse |
| **HP_kWh** | elektrische Tages‑Energie der WP |
| **PV_to_WP_total_kWh** | PV‑Energie, die der WP zugutekommt |
| **Import_real_kWh** | realer Netzbezug am Zieltag |
| **Import_no_wp_kWh** | simuliert ohne WP‑Last |
| **WP_import_change_signed_kWh** | Nettoeffekt der WP auf den Netzbezug |
| **ResultJSON** | JSON‑Block aller Tageswerte |
| **ResultDetailsJSON** | JSON‑Intervalltabelle (optional) |

---

## 🧠 Analyse‑Ablauf im Detail
Der Energietag wird in Intervalle (Buckets) zerlegt. Für jedes Intervall:
1. PV deckt Hauslast
2. PV deckt WP
3. PV‑Rest → Batterie (ηC)
4. Batterie → Hauslast (ηD)
5. Batterie → WP (ηD)
6. Restbedarf → Netzimport
7. Parallelsimulation ohne WP → Import_no_wp_kWh

Sanity‑Regel: **PV_to_WP_total ≤ HP_kWh**.

---

## 📦 Backfill (Tages‑Zähler)
Backfill erzeugt automatisch Tages‑Summen und schreibt zwei Messpunkte:
- 00:00 = 0
- 23:59 = Tageswert

Zielvariablen werden automatisch erzeugt:
- HP_kWh_Backfilled
- PV_to_WP_total_kWh_Backfilled
- Import_real_kWh_Backfilled
- Import_no_wp_kWh_Backfilled
- WP_import_change_signed_kWh_Backfilled

---

## ▶️ Bedienfunktionen
- Analyze()
- BackfillRange()
- RunDailyBackfillNow()
- ExportDetails()
- OpenHelp() / ShowHelp()

---

## 💡 Tipps
- Logger‑Auflösung 1–5 Minuten ist optimal
- SOC möglichst regelmäßig loggen
- Backfill nutzt immer 48h‑Fenster für präzise Tagesmitte

---

## 💙 Unterstützung

Wenn dir das IP‑Symcon Modul gefällt oder du die Weiterentwicklung unterstützen möchtest, freue ich mich über eine Spende:

[💙 Jetzt per PayPal spenden](https://www.paypal.com/pool/9mms4CEXrr?sr=wccr)

---

# 📄 Lizenz (MIT License)

```
MIT License

Copyright (c) 2026 Matthias Fenske

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
