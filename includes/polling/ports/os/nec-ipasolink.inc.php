<?php
/**
 * nec-ipasolink.inc.php
 *
 * Port polling enrichment for NEC iPasoLink devices.
 *
 * Runs after standard IF-MIB polling for each port on nec-ipasolink devices.
 * Enriches radio port records with NEC-specific metrics from meteringTable
 * and modemGroupTable.
 *
 * Uses NecIfIndexDecoder to identify radio ports via the NWD-156232 ifIndex
 * bitmap layout instead of relying on ifType or ifName patterns.
 *
 * Per PRD section 4.5:
 *   - metSysTxPowerValue and metSysRxLevelValue are in tenths of dBm — divide by 10.
 *   - Only process radio ports (MODEM slots 2-5, or Radio LAG slot 0 port 65-80).
 *   - Values written to $port array as extra fields: txpower, rxlevel.
 *   - modemModulation from modemGroupTable added when available.
 *
 * OID references:
 *   - meteringTable:        .1.3.6.1.4.1.119.2.3.69.501.8.1.1
 *   - metSysTxPowerValue:   .501.8.1.1.4.{ifIndex}
 *   - metSysRxLevelValue:   .501.8.1.1.6.{ifIndex}
 *   - modemGroupTable:      .1.3.6.1.4.1.119.2.3.69.501.3.2.1
 *
 * @category   Polling
 * @package    LibreNMS\Polling\Ports
 */

use LibreNMS\Util\NecIfIndexDecoder;

// This file is included in the LibreNMS polling context.
// Variables available: $device, $port_stats (array of ports keyed by ifIndex)

$meteringBase = '.1.3.6.1.4.1.119.2.3.69.501.8.1.1';

foreach ($port_stats as $ifIndex => &$port) {
    $ifIndexInt = (int) $ifIndex;

    // Use the NWD-156232 bitmap decoder to determine if this is a radio port
    if (!NecIfIndexDecoder::isRadioPort($ifIndexInt)) {
        continue;
    }

    // Decode for logging
    $decoded = NecIfIndexDecoder::decode($ifIndexInt);
    $portLabel = $decoded['label'];

    // Try to read TX Power from meteringTable
    $txPowerRaw = snmp_get(
        $device,
        "{$meteringBase}.4.{$ifIndex}",
        '-Ovq',
        'IPE-COMMON-MIB'
    );

    if (is_numeric($txPowerRaw)) {
        $port['txpower'] = round((float) $txPowerRaw / 10, 1);
        d_echo("iPasoLink: {$portLabel} (ifIndex {$ifIndex}) TX Power = {$port['txpower']} dBm\n");
    }

    // Try to read RX Level from meteringTable
    $rxLevelRaw = snmp_get(
        $device,
        "{$meteringBase}.6.{$ifIndex}",
        '-Ovq',
        'IPE-COMMON-MIB'
    );

    if (is_numeric($rxLevelRaw)) {
        $port['rxlevel'] = round((float) $rxLevelRaw / 10, 1);
        d_echo("iPasoLink: {$portLabel} (ifIndex {$ifIndex}) RX Level = {$port['rxlevel']} dBm\n");
    }

    // Try to read modem modulation type from modemGroupTable
    $modulation = snmp_get(
        $device,
        ".1.3.6.1.4.1.119.2.3.69.501.3.2.1.1.5.{$ifIndex}",
        '-Ovq',
        'IPE-COMMON-MIB'
    );

    if (!empty($modulation) && $modulation !== 'No Such Instance currently exists at this OID') {
        $port['modulation'] = trim($modulation, '"');
        d_echo("iPasoLink: {$portLabel} (ifIndex {$ifIndex}) Modulation = {$port['modulation']}\n");
    }
}

unset($port); // break reference

d_echo("iPasoLink: Port polling enrichment complete.\n");
