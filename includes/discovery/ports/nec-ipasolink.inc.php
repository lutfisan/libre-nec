<?php
/**
 * nec-ipasolink.inc.php
 *
 * Port discovery enrichment for NEC iPasoLink devices.
 *
 * Handles two iPasoLink firmware variants:
 *   1. Newer firmware (e.g. iPASOLINK 1000 v07.x) — ifDescr, ifType,
 *      ifAlias, ifOperStatus ARE populated. Only apply bitmap decoding
 *      to high ifIndex ports that have EMPTY ifDescr/ifName.
 *   2. Older firmware (e.g. iPASOLINK 400 v03.x) — only ifName responds.
 *      Pre-fill ifDescr from ifName for ALL ports.
 *
 * In BOTH cases, the NWD-156232 ifIndex bitmap decoder is applied only
 * to high-value ifIndex ports (>= 65536) that lack meaningful ifDescr,
 * since low ifIndex values (1-1024) are NOT bitmap-encoded.
 *
 * @category   Discovery
 * @package    LibreNMS\Discovery\Ports
 */

use LibreNMS\Util\NecIfIndexDecoder;

// This file is included in the LibreNMS discovery context.
// Variables available: $device, $port_stats (array of ports keyed by ifIndex)

if (! isset($port_stats) || ! is_array($port_stats)) {
    return;
}

// Minimum ifIndex value for NWD-156232 bitmap decoding.
// Low ifIndex values (1–1024) are simple sequential IDs, NOT bitmap-encoded.
// Bitmap encoding starts at slot 1 port 1 = (1 << 23) | (1 << 16) = 8454144.
// We use 65536 (0x10000) as threshold — any ifIndex below this is sequential.
$BITMAP_THRESHOLD = 65536;

foreach ($port_stats as $ifIndex => &$port) {
    // ---- CRITICAL: Pre-fill ifDescr from ifName if missing ----
    // This prevents PHP E_WARNING in core functions.php when accessing $port['ifDescr']
    if (! isset($port['ifDescr']) || $port['ifDescr'] === '') {
        if (! empty($port['ifName'])) {
            $port['ifDescr'] = $port['ifName'];
            d_echo("iPasoLink: Pre-filled ifDescr from ifName for ifIndex {$ifIndex}: {$port['ifDescr']}\n");
        }
    }

    // Pre-fill ifAlias if missing
    if (! isset($port['ifAlias']) || $port['ifAlias'] === '') {
        if (! empty($port['ifDescr'])) {
            $port['ifAlias'] = $port['ifDescr'];
        }
    }

    // ---- Bitmap decoding: ONLY for high ifIndex values ----
    // Low ifIndex values (1, 2, 3, 11-45, 101) are NOT bitmap-encoded.
    // They represent system interfaces (lo, lct, bridge, modem, etc.)
    // and already have correct ifDescr from the device.
    $ifIndexInt = (int) $ifIndex;
    if ($ifIndexInt < $BITMAP_THRESHOLD) {
        // Keep the device-provided ifDescr for low ifIndex ports
        continue;
    }

    // For high ifIndex values, apply the NWD-156232 bitmap decoder
    $decoded = NecIfIndexDecoder::decode($ifIndexInt);

    if ($decoded['has_reserved_bits']) {
        // FR-67: Reserved bits set — use opaque label only if no ifDescr exists
        if (empty($port['ifDescr'])) {
            $port['ifDescr'] = $decoded['label'];
        }
        d_echo("iPasoLink: ifIndex {$ifIndex} has reserved bits set, opaque label: {$decoded['label']}\n");
        continue;
    }

    // Only override ifDescr with bitmap label if the device didn't provide one
    // (Some firmware variants like iPASOLINK 1000 v07.x provide descriptive ifDescr)
    if (empty($port['ifDescr']) || $port['ifDescr'] === ($port['ifName'] ?? '')) {
        $port['ifDescr'] = $decoded['label'];
    }

    // Set ifAlias to bitmap label only if not already set to something useful
    $currentAlias = $port['ifAlias'] ?? '';
    if (empty($currentAlias)
        || $currentAlias === ($port['ifName'] ?? '')
        || $currentAlias === str_repeat('.', 40)
    ) {
        $port['ifAlias'] = $decoded['label'];
    }

    d_echo("iPasoLink: ifIndex {$ifIndex} → {$decoded['label']} (slot={$decoded['slot']}, port={$decoded['port']}, path={$decoded['path']})\n");
}

unset($port); // break reference

d_echo("iPasoLink: Port discovery enrichment complete.\n");
