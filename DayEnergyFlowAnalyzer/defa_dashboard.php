<?php
//
// DayEnergyFlowAnalyzer – HTML Dashboard Tabelle
// Speichern als: /var/lib/symcon/user/defa_dashboard.php
//

// === KONFIGURATION ==========================================================

// Wärmemengenzähler (Heizen / Warmwasser)
$ID_WMZ_HEIZEN     = 12345;   // kWh
$ID_WMZ_WW         = 23456;   // kWh

// WP Verbrauch (Strom)
$ID_WP_STROM_HEIZEN = 34567;  // kWh
$ID_WP_STROM_WW     = 45678;  // kWh
$ID_WP_STROM_GES    = 56789;  // kWh

// Netto-Effekt (aus Analyzer)
$ID_WP_NETTO        = 67890;  // kWh

// COP Variablen (nur Tageswert, kein Zähler)
$ID_COP_TOTAL       = 78901;
$ID_COP_HEIZEN      = 78902;
$ID_COP_WW          = 78903;

// PV / Verbrauch / Netz
$ID_PV_ERZEUGUNG    = 89012;  // kWh
$ID_VERBRAUCH_HAUS  = 90123;  // kWh
$ID_BEZUG_NETZ      = 91234;  // kWh

// Betriebsstunden
$ID_STD_HEIZEN      = 92345;  // Stunden
$ID_STD_WW          = 93456;  // Stunden

// ============================================================================

// Hilfsfunktion: Monatswerte aus Archiv lesen
function monthValue($varID, $year, $month) {
    $ac = AC_GetAggregatedValues(IPS_GetProperty(0, "ArchiveControlID") ?? 0, $varID, 1, 
        strtotime("$year-$month-01 00:00:00"),
        strtotime("$year-$month-01 00:00:00 +1 month")
    );
    if (!is_array($ac) || count($ac) == 0) return 0;
    return $ac[0]['Sum'] ?? 0;
}

// Für COP (kein zähler): Mittelwert des Monats
function monthAvg($varID, $year, $month) {
    $ac = AC_GetAggregatedValues(IPS_GetProperty(0, "ArchiveControlID") ?? 0, $varID, 0, 
        strtotime("$year-$month-01 00:00:00"),
        strtotime("$year-$month-01 00:00:00 +1 month")
    );
    if (!is_array($ac) || count($ac) == 0) return 0;
    return $ac[0]['Avg'] ?? 0;
}

$year = date("Y");

// ============================================================================
// HTML START
// ============================================================================

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>WP Jahresübersicht</title>

<style>
body {
    background: #222;
    color: #fff;
    font-family: Arial;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th {
    background: #444;
    padding: 6px;
}
td {
    padding: 6px;
    text-align: right;
    border-bottom: 1px solid #333;
}
.row-header {
    background: #333;
    text-align: left;
}
.sum-row {
    background: #004400;
    font-weight: bold;
}
</style>

</head>
<body>

<h2>Wärmepumpen-Jahreswerte <?= $year ?></h2>

<table>
<tr class="row-header">
    <th>Monat</th>
    <th colspan="2" style="color:#f88;">Wärme (kWh)</th>
    <th colspan="3" style="color:#faa;">WP Strom (kWh)</th>
    <th style="color:#8ff;">Netto</th>
    <th colspan="3" style="color:#8f8;">COP</th>
    <th colspan="3" style="color:#fc0;">Energie</th>
    <th colspan="2" style="color:#0cf;">Betriebsstunden</th>
</tr>
<tr>
    <th></th>
    <th>Heizen</th><th>WW</th>
    <th>Heizen</th><th>WW</th><th>Gesamt</th>
    <th>Δ Netz</th>
    <th>Gesamt</th><th>Heizen</th><th>WW</th>
    <th>PV</th><th>Haus</th><th>Netz</th>
    <th>Heizen</th><th>WW</th>
</tr>

<?php

$sum = array_fill_keys([
    'wmzH','wmzW','wpH','wpW','wpG','net','copT','copH','copW','pv','haus','netz','stdH','stdW'
], 0);

for ($m=1; $m<=12; $m++) {
    $wmzH = monthValue($ID_WMZ_HEIZEN, $year, $m);
    $wmzW = monthValue($ID_WMZ_WW,      $year, $m);

    $wpH  = monthValue($ID_WP_STROM_HEIZEN, $year, $m);
    $wpW  = monthValue($ID_WP_STROM_WW,     $year, $m);
    $wpG  = monthValue($ID_WP_STROM_GES,    $year, $m);

    $net  = monthValue($ID_WP_NETTO, $year, $m);

    $copT = monthAvg($ID_COP_TOTAL,   $year, $m);
    $copH = monthAvg($ID_COP_HEIZEN,  $year, $m);
    $copW = monthAvg($ID_COP_WW,      $year, $m);

    $pv   = monthValue($ID_PV_ERZEUGUNG, $year, $m);
    $haus = monthValue($ID_VERBRAUCH_HAUS, $year, $m);
    $netz = monthValue($ID_BEZUG_NETZ,     $year, $m);

    $stdH = monthValue($ID_STD_HEIZEN, $year, $m);
    $stdW = monthValue($ID_STD_WW,     $year, $m);

    // Summen
    $sum['wmzH'] += $wmzH;
    $sum['wmzW'] += $wmzW;
    $sum['wpH']  += $wpH;
    $sum['wpW']  += $wpW;
    $sum['wpG']  += $wpG;
    $sum['net']  += $net;
    $sum['copT'] += $copT;
    $sum['copH'] += $copH;
    $sum['copW'] += $copW;
    $sum['pv']   += $pv;
    $sum['haus'] += $haus;
    $sum['netz'] += $netz;
    $sum['stdH'] += $stdH;
    $sum['stdW'] += $stdW;

    echo "<tr>
        <td style='text-align:left;'>".strftime('%B', strtotime(\"$year-$m-01\"))."</td>
        <td>".number_format($wmzH,1)."</td>
        <td>".number_format($wmzW,1)."</td>
        <td>".number_format($wpH,1)."</td>
        <td>".number_format($wpW,1)."</td>
        <td>".number_format($wpG,1)."</td>
        <td>".number_format($net,1)."</td>
        <td>".number_format($copT,2)."</td>
        <td>".number_format($copH,2)."</td>
        <td>".number_format($copW,2)."</td>
        <td>".number_format($pv,1)."</td>
        <td>".number_format($haus,1)."</td>
        <td>".number_format($netz,1)."</td>
        <td>".number_format($stdH,1)."</td>
        <td>".number_format($stdW,1)."</td>
    </tr>";
}

echo \"<tr class='sum-row'>
    <td>Gesamt</td>
    <td>{$sum['wmzH']}</td>
    <td>{$sum['wmzW']}</td>
    <td>{$sum['wpH']}</td>
    <td>{$sum['wpW']}</td>
    <td>{$sum['wpG']}</td>
    <td>{$sum['net']}</td>
    <td>{$sum['copT']}</td>
    <td>{$sum['copH']}</td>
    <td>{$sum['copW']}</td>
    <td>{$sum['pv']}</td>
    <td>{$sum['haus']}</td>
    <td>{$sum['netz']}</td>
    <td>{$sum['stdH']}</td>
    <td>{$sum['stdW']}</td>
</tr>\";

?>

</table>

</body>
</html>