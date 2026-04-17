<?php
/**
 * NecIpasolink.php
 *
 * NEC iPasoLink OS module for LibreNMS.
 * Handles equipment type mapping and port discovery enrichment
 * for NEC iPasoLink microwave radio equipment.
 *
 * MIB references:
 *   - IPE-SYSTEM-MIB  (.1.3.6.1.4.1.119.2.3.69.502)
 *   - IPE-COMMON-MIB  (.1.3.6.1.4.1.119.2.3.69.501)
 *
 * @category   OS
 * @package    LibreNMS\OS
 * @link       https://docs.librenms.org/Developing/os/
 */

namespace LibreNMS\OS;

use LibreNMS\OS;
use LibreNMS\Util\NecIfIndexDecoder;

class NecIpasolink extends OS
{
    /**
     * Equipment type mapping from ipeSysEquipmentType integer to human-readable product name.
     * OID: IPE-SYSTEM-MIB::ipeSysEquipmentType (.1.3.6.1.4.1.119.2.3.69.502.1.1.10)
     *
     * @var array<int, string>
     */
    private const EQUIPMENT_TYPE_MAP = [
        20   => 'PASOLINK+ (STM-1)',
        30   => 'PASOLINK+ (STM-0)',
        40   => 'PASOLINK+ (PDH)',
        50   => 'PASOLINK+ (Nlite-L)',
        60   => 'PASOLINK+ (Mx)',
        70   => 'PASOLINK+ (Nlite-Lx)',
        100  => 'PASOLINK NEO (STD)',
        110  => 'PASOLINK NEO (N+1)',
        120  => 'PASOLINK NEO (Advanced)',
        130  => 'PASOLINK NEO (Compact)',
        140  => 'PASOLINK NEO (Nodal)',
        150  => 'PASOLINK NEO (HP)',
        160  => 'NLiteNEO',
        200  => 'iPASOLINK 200',
        201  => 'iPASOLINK 100',
        321  => 'iPASOLINK 100E',
        400  => 'iPASOLINK 400',
        450  => 'iPASOLINK 400A',
        501  => 'iPASOLINK SX',
        510  => 'iPASOLINK iX',
        520  => 'iPASOLINK EX',
        1000 => 'iPASOLINK 1000',
        5000 => '5000iPS',
    ];

    /**
     * Resolve ipeSysEquipmentType integer to a human-readable model name.
     *
     * @param  int|string|null  $typeValue  The raw integer from SNMP
     * @return string  The product name, or 'NEC iPasoLink (unknown type N)' for unmapped values
     */
    public static function resolveEquipmentType(int|string|null $typeValue): string
    {
        if ($typeValue === null || $typeValue === '') {
            return 'NEC iPasoLink';
        }

        $typeInt = (int) $typeValue;

        return self::EQUIPMENT_TYPE_MAP[$typeInt]
            ?? "NEC iPasoLink (unknown type {$typeInt})";
    }

    /**
     * Discover device hardware attributes.
     *
     * Called during the discovery cycle to populate
     * hardware, serial, version, and sysname fields.
     *
     * @return void
     */
    public function discoverOS(\App\Models\Device $device): void
    {
        parent::discoverOS($device);

        // Resolve equipment type to human-readable model name
        // ipeSysEquipmentType: .1.3.6.1.4.1.119.2.3.69.502.1.1.10.0
        $equipType = snmp_get(
            $this->getDeviceArray(),
            '.1.3.6.1.4.1.119.2.3.69.502.1.1.10.0',
            '-Ovq',
            'IPE-SYSTEM-MIB'
        );

        if (is_numeric($equipType)) {
            $modelName = self::resolveEquipmentType($equipType);

            // If YAML discovery already set a hardware value, prepend model name
            if (! empty($device->hardware)) {
                $device->hardware = "{$modelName} ({$device->hardware})";
            } else {
                $device->hardware = $modelName;
            }
        }

        // Fallback sysname from IPE-SYSTEM-MIB if not already set
        if (empty($device->sysName)) {
            $neName = snmp_get(
                $this->getDeviceArray(),
                '.1.3.6.1.4.1.119.2.3.69.502.1.1.3.0',
                '-Ovq',
                'IPE-SYSTEM-MIB'
            );
            if (! empty($neName) && $neName !== 'No Such Object available on this agent at this OID') {
                $device->sysName = trim($neName, '"');
            }
        }
    }
}
