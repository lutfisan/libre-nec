<?php
/**
 * NecIfIndexDecoder.php
 *
 * Decodes NEC iPasoLink ifIndex values into slot/port/path components
 * and generates human-readable interface labels per NWD-156232 Appendix 1.
 *
 * ifIndex Bitmap Layout:
 *   Bit: 31  | 30-28    | 27-23       | 22-16       | 15-0
 *        0   | Reserved | Slot Number | Port Number | Path Number
 *
 * @category   Util
 * @package    LibreNMS\Util
 */

namespace LibreNMS\Util;

use Illuminate\Support\Facades\Log;

class NecIfIndexDecoder
{
    // Per NWD-156232 Appendix 1 bitmap layout
    const RESERVED_MASK = 0x70000000; // bits 30-28
    const SLOT_MASK     = 0x0F800000; // bits 27-23
    const PORT_MASK     = 0x007F0000; // bits 22-16
    const PATH_MASK     = 0x0000FFFF; // bits 15-0

    /**
     * Decode an iPasolink ifIndex into slot/port/path and a human-readable label.
     * Returns an array with keys: slot, port, path, label, has_reserved_bits.
     *
     * @param  int  $ifIndex  The raw ifIndex value from SNMP
     * @return array{slot: ?int, port: ?int, path: ?int, label: string, has_reserved_bits: bool}
     */
    public static function decode(int $ifIndex): array
    {
        $reservedBits = ($ifIndex & self::RESERVED_MASK) >> 28;
        $slot  = ($ifIndex & self::SLOT_MASK)  >> 23;
        $port  = ($ifIndex & self::PORT_MASK)  >> 16;
        $path  = ($ifIndex & self::PATH_MASK);

        $hasReservedBits = ($reservedBits !== 0);

        if ($hasReservedBits) {
            Log::warning("NEC iPasolink: Unrecognized ifIndex format — reserved bits (30-28) are non-zero. "
                . "ifIndex={$ifIndex}, reservedBits={$reservedBits}. "
                . "This may indicate a new chassis type (e.g. 5000iPS cascade). "
                . "Treating as opaque interface identifier.");

            return [
                'slot'              => null,
                'port'              => null,
                'path'              => null,
                'label'             => "Unknown-If-{$ifIndex}",
                'has_reserved_bits' => true,
            ];
        }

        $label = self::buildLabel($slot, $port, $path);

        return [
            'slot'              => $slot,
            'port'              => $port,
            'path'              => $path,
            'label'             => $label,
            'has_reserved_bits' => false,
        ];
    }

    /**
     * Build a human-readable label from decoded slot/port/path.
     *
     * Slot assignments per NWD-156232 Appendix 2:
     *   Slot 0        = LAG interfaces (ETH ports 1-64, Radio ports 65-80)
     *   Slot 1        = Main Card (GbE or 16E1)
     *   Slots 2-5     = MODEM slots (standard iPASOLINK)
     *   Slots 6-7     = Power Supply
     *   Slot 8        = FAN
     *   Slots 9-17    = Extended I/O / MODEM (iPASOLINK 1000 / extended chassis)
     *
     * @param  int  $slot  Decoded slot number (bits 27-23)
     * @param  int  $port  Decoded port number (bits 22-16)
     * @param  int  $path  Decoded path number (bits 15-0)
     * @return string  Human-readable interface label
     */
    private static function buildLabel(int $slot, int $port, int $path): string
    {
        // Slot 0 = LAG interfaces
        if ($slot === 0) {
            if ($port >= 1 && $port <= 64) {
                return "ETH-LAG-Port{$port}";
            }
            if ($port >= 65 && $port <= 80) {
                return "Radio-LAG-Port{$port}";
            }
            return "Unknown-If-LAG-Port{$port}";
        }

        // Slot 1 = Main Card
        if ($slot === 1) {
            if ($path === 0) {
                return "Main-GbE-Port{$port}";
            }
            return "Main-16E1-Slot{$slot}-CH{$path}";
        }

        // Slots 2-5 = MODEM slots (standard)
        if ($slot >= 2 && $slot <= 5) {
            if ($path === 0) {
                return "Modem-Slot{$slot}-Port{$port}";
            }
            return "Modem-Slot{$slot}-Port{$port}-Path{$path}";
        }

        // Slots 6-7 = PS
        if ($slot >= 6 && $slot <= 7) {
            return "PS-Slot{$slot}";
        }

        // Slot 8 = FAN
        if ($slot === 8) {
            return "FAN-Slot{$slot}";
        }

        // Slots 9-31 = Extended I/O card / MODEM (iPASOLINK 1000, extended chassis)
        if ($slot >= 9 && $slot <= 31) {
            if ($path === 0) {
                return "ExtIO-Slot{$slot}-Port{$port}";
            }
            return "ExtIO-Slot{$slot}-Port{$port}-Path{$path}";
        }

        // Anything else — log and return opaque label
        Log::info("NEC iPasolink: Unexpected slot/port combination. slot={$slot}, port={$port}, path={$path}.");
        return "Unknown-If-Slot{$slot}-Port{$port}-Path{$path}";
    }

    /**
     * Determine whether a decoded ifIndex represents a radio (modem) port.
     *
     * Radio ports are in MODEM slots 2-5, extended MODEM slots 9-31
     * (iPASOLINK 1000), or Radio-LAG ports (slot 0, port 65-80).
     *
     * @param  int  $ifIndex  The raw ifIndex value
     * @return bool  True if the ifIndex represents a radio port
     */
    public static function isRadioPort(int $ifIndex): bool
    {
        // Only bitmap-encoded ifIndex values can be radio ports
        if ($ifIndex < 65536) {
            return false;
        }

        $decoded = self::decode($ifIndex);

        if ($decoded['has_reserved_bits']) {
            return false;
        }

        // MODEM slots 2-5 are radio (standard)
        if ($decoded['slot'] >= 2 && $decoded['slot'] <= 5) {
            return true;
        }

        // Extended I/O slots 9-31 (iPASOLINK 1000)
        if ($decoded['slot'] >= 9 && $decoded['slot'] <= 31) {
            return true;
        }

        // Radio LAG ports
        if ($decoded['slot'] === 0 && $decoded['port'] >= 65 && $decoded['port'] <= 80) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether a decoded ifIndex represents an Ethernet port.
     *
     * Ethernet ports are Main Card GbE (slot 1) or ETH-LAG (slot 0, port 1-64).
     *
     * @param  int  $ifIndex  The raw ifIndex value
     * @return bool  True if the ifIndex represents an Ethernet port
     */
    public static function isEthernetPort(int $ifIndex): bool
    {
        // Only bitmap-encoded ifIndex values can be classified
        if ($ifIndex < 65536) {
            return false;
        }

        $decoded = self::decode($ifIndex);

        if ($decoded['has_reserved_bits']) {
            return false;
        }

        // Main Card GbE
        if ($decoded['slot'] === 1 && $decoded['path'] === 0) {
            return true;
        }

        // ETH LAG ports
        if ($decoded['slot'] === 0 && $decoded['port'] >= 1 && $decoded['port'] <= 64) {
            return true;
        }

        return false;
    }
}
