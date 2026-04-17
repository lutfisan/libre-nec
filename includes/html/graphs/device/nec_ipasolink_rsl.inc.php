<?php
/**
 * nec_ipasolink_rsl.inc.php
 *
 * RSL (Received Signal Level) Min/Max graph for NEC iPasoLink.
 *
 * Renders received signal level per radio port over a rolling time window.
 * Reads RRD data populated by port polling (rxlevel field stored in
 * port-nec-rsl RRD files).
 *
 * @category   Graphs
 * @package    LibreNMS\Graphs\Device
 */

$rrd_filename = Rrd::name($device['hostname'], ['port-nec-rsl', '*']);
$rrd_list = glob($rrd_filename);

if (empty($rrd_list)) {
    // Fallback: no RRD files found yet
    return;
}

$colours = 'mixed';
$nototal = true;
$unit_text = 'dBm';
$scale_min = -80;
$scale_max = 0;

$i = 0;
foreach ($rrd_list as $rrd_file) {
    // Extract ifIndex from filename: port-nec-rsl-{ifIndex}.rrd
    if (preg_match('/port-nec-rsl-(\d+)\.rrd$/', $rrd_file, $matches)) {
        $ifIndex = $matches[1];
    } else {
        continue;
    }

    // Look up port name for label
    $port = dbFetchRow(
        'SELECT ifDescr, ifName FROM ports WHERE device_id = ? AND ifIndex = ?',
        [$device['device_id'], $ifIndex]
    );
    $label = $port['ifDescr'] ?? $port['ifName'] ?? "Port {$ifIndex}";

    $rrd_list[$i] = [
        'filename' => $rrd_file,
        'descr'    => "RSL {$label}",
        'ds'       => 'rxlevel',
    ];
    $i++;
}

// Remove any non-array entries from the glob
$rrd_list = array_filter($rrd_list, 'is_array');

require 'includes/html/graphs/generic_multi_line.inc.php';
