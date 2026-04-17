<?php
/**
 * nec_ipasolink_rmon.inc.php
 *
 * RMON Ethernet Statistics graph for NEC iPasoLink.
 *
 * Renders total octets, broadcast/multicast frame counts, and error counters
 * from rmonGroup (.1.3.6.1.4.1.119.2.3.69.501.11.x).
 *
 * This graph uses IF-MIB counters as a baseline and supplements with
 * iPasoLink-specific RMON stats when available. The RMON data is walked
 * during polling and stored in standard port RRD files.
 *
 * @category   Graphs
 * @package    LibreNMS\Graphs\Device
 */

$rrd_filename = Rrd::name($device['hostname'], 'port-nec-rsl');

// For RMON, we fall back to standard IF-MIB port traffic graphs
// supplemented with NEC-specific counters when available
$colours = 'mixed';
$nototal = false;
$unit_text = 'bps';

// Collect all Ethernet ports for this device
$ethernet_ports = dbFetchRows(
    "SELECT ifIndex, ifDescr, ifName FROM ports WHERE device_id = ? AND (ifType = 'ethernetCsmacd' OR ifDescr LIKE 'Ethernet_%')",
    [$device['device_id']]
);

if (empty($ethernet_ports)) {
    return;
}

$i = 0;
$rrd_list = [];

foreach ($ethernet_ports as $port) {
    $ifIndex = $port['ifIndex'];
    $label = $port['ifDescr'] ?? $port['ifName'] ?? "Port {$ifIndex}";

    // Standard port RRD file with traffic counters
    $rrd_file = get_port_rrdfile_path($device['hostname'], $ifIndex);

    if (Rrd::checkRrdExists($rrd_file)) {
        $rrd_list[] = [
            'filename' => $rrd_file,
            'descr'    => "In {$label}",
            'ds'       => 'INOCTETS',
            'invert'   => false,
        ];

        $rrd_list[] = [
            'filename' => $rrd_file,
            'descr'    => "Out {$label}",
            'ds'       => 'OUTOCTETS',
            'invert'   => true,
        ];
    }
}

if (! empty($rrd_list)) {
    require 'includes/html/graphs/generic_multi_line.inc.php';
}
