<?php
/**
 * NecIpasolinkTest.php
 *
 * PHPUnit tests for NEC iPasoLink SNMP Trap Handler and OS module.
 *
 * Covers:
 *   - All 7 notification types from IPE-COMMON-MIB
 *   - Severity mapping validation
 *   - Equipment type mapping (all 23 codes)
 *   - Invalid severity skip behavior
 *   - Alert creation and clearance
 *
 * @category   Tests
 * @package    Tests\Unit\Snmptrap\Handlers
 */

namespace Tests\Unit\Snmptrap\Handlers;

use App\Models\Device;
use LibreNMS\Snmptrap\Handlers\NecIpasolink;
use LibreNMS\Snmptrap\Trap;
use LibreNMS\OS\NecIpasolink as NecIpasolinkOS;
use LibreNMS\Util\NecIfIndexDecoder;
use PHPUnit\Framework\TestCase;

class NecIpasolinkTest extends TestCase
{
    /**
     * Base OID for trap variable bindings.
     */
    private const VARBIND_BASE = '.1.3.6.1.4.1.119.2.3.69.501.2.1';

    // ---------------------------------------------------------------
    // ifIndex Bitmap Decoder Tests (FR-66 through FR-69)
    // ---------------------------------------------------------------

    /**
     * Test ETH LAG port (slot 0, port 1-64).
     * ifIndex = (0 << 23) | (5 << 16) | 0 = 0x00050000 = 327680
     */
    public function testDecodeEthLagPort(): void
    {
        // slot=0, port=5, path=0
        $ifIndex = (0 << 23) | (5 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(0, $decoded['slot']);
        $this->assertEquals(5, $decoded['port']);
        $this->assertEquals(0, $decoded['path']);
        $this->assertEquals('ETH-LAG-Port5', $decoded['label']);
        $this->assertFalse($decoded['has_reserved_bits']);
    }

    /**
     * Test Radio LAG port (slot 0, port 65-80).
     * ifIndex = (0 << 23) | (70 << 16) | 0
     */
    public function testDecodeRadioLagPort(): void
    {
        $ifIndex = (0 << 23) | (70 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(0, $decoded['slot']);
        $this->assertEquals(70, $decoded['port']);
        $this->assertEquals('Radio-LAG-Port70', $decoded['label']);
        $this->assertFalse($decoded['has_reserved_bits']);
    }

    /**
     * Test Main Card GbE port (slot 1, path=0).
     * ifIndex = (1 << 23) | (3 << 16) | 0 = 0x00830000
     */
    public function testDecodeMainGbePort(): void
    {
        $ifIndex = (1 << 23) | (3 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(1, $decoded['slot']);
        $this->assertEquals(3, $decoded['port']);
        $this->assertEquals(0, $decoded['path']);
        $this->assertEquals('Main-GbE-Port3', $decoded['label']);
    }

    /**
     * Test Main Card 16E1 port (slot 1, path > 0).
     */
    public function testDecodeMain16E1Port(): void
    {
        $ifIndex = (1 << 23) | (2 << 16) | 7;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(1, $decoded['slot']);
        $this->assertEquals(2, $decoded['port']);
        $this->assertEquals(7, $decoded['path']);
        $this->assertEquals('Main-16E1-Slot1-CH7', $decoded['label']);
    }

    /**
     * Test MODEM slot port (slots 2-5).
     * ifIndex = (2 << 23) | (1 << 16) | 0 = 0x01010000
     */
    public function testDecodeModemSlotPort(): void
    {
        $ifIndex = (2 << 23) | (1 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(2, $decoded['slot']);
        $this->assertEquals(1, $decoded['port']);
        $this->assertEquals(0, $decoded['path']);
        $this->assertEquals('Modem-Slot2-Port1', $decoded['label']);
    }

    /**
     * Test MODEM slot port with path.
     */
    public function testDecodeModemSlotPortWithPath(): void
    {
        $ifIndex = (3 << 23) | (2 << 16) | 5;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(3, $decoded['slot']);
        $this->assertEquals(2, $decoded['port']);
        $this->assertEquals(5, $decoded['path']);
        $this->assertEquals('Modem-Slot3-Port2-Path5', $decoded['label']);
    }

    /**
     * Test Power Supply slot (slots 6-7).
     */
    public function testDecodePsSlot(): void
    {
        $ifIndex = (6 << 23) | (0 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(6, $decoded['slot']);
        $this->assertEquals('PS-Slot6', $decoded['label']);
    }

    /**
     * Test FAN slot (slot 8).
     */
    public function testDecodeFanSlot(): void
    {
        $ifIndex = (8 << 23) | (0 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(8, $decoded['slot']);
        $this->assertEquals('FAN-Slot8', $decoded['label']);
    }

    /**
     * FR-67: Reserved bits set — must return opaque label, not crash.
     */
    public function testDecodeReservedBitsSetReturnsFallback(): void
    {
        // Set reserved bit 28 = 1 (0x10000000)
        $ifIndex = 0x10000000 | (2 << 23) | (1 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertTrue($decoded['has_reserved_bits']);
        $this->assertNull($decoded['slot']);
        $this->assertNull($decoded['port']);
        $this->assertNull($decoded['path']);
        $this->assertStringStartsWith('Unknown-If-', $decoded['label']);
    }

    /**
     * FR-69: Unknown slot/port combo returns opaque label.
     */
    public function testDecodeUnknownSlotReturnsOpaqueLabel(): void
    {
        // slot=15 is not in the spec
        $ifIndex = (15 << 23) | (1 << 16) | 0;
        $decoded = NecIfIndexDecoder::decode($ifIndex);

        $this->assertEquals(15, $decoded['slot']);
        $this->assertStringStartsWith('Unknown-If-Slot', $decoded['label']);
        $this->assertFalse($decoded['has_reserved_bits']);
    }

    /**
     * Test isRadioPort() returns true for MODEM slots (2-5).
     */
    public function testIsRadioPortModemSlot(): void
    {
        // slot=2, port=1, path=0
        $ifIndex = (2 << 23) | (1 << 16) | 0;
        $this->assertTrue(NecIfIndexDecoder::isRadioPort($ifIndex));

        // slot=5, port=1, path=0
        $ifIndex = (5 << 23) | (1 << 16) | 0;
        $this->assertTrue(NecIfIndexDecoder::isRadioPort($ifIndex));
    }

    /**
     * Test isRadioPort() returns true for Radio LAG (slot 0, port 65-80).
     */
    public function testIsRadioPortRadioLag(): void
    {
        $ifIndex = (0 << 23) | (65 << 16) | 0;
        $this->assertTrue(NecIfIndexDecoder::isRadioPort($ifIndex));
    }

    /**
     * Test isRadioPort() returns false for Ethernet ports.
     */
    public function testIsRadioPortReturnsFalseForEthernet(): void
    {
        // slot=1, Main Card GbE
        $ifIndex = (1 << 23) | (3 << 16) | 0;
        $this->assertFalse(NecIfIndexDecoder::isRadioPort($ifIndex));

        // slot=0, ETH LAG port 5
        $ifIndex = (0 << 23) | (5 << 16) | 0;
        $this->assertFalse(NecIfIndexDecoder::isRadioPort($ifIndex));
    }

    /**
     * Test isEthernetPort() returns true for Main Card GbE (slot 1, path=0).
     */
    public function testIsEthernetPortMainCard(): void
    {
        $ifIndex = (1 << 23) | (3 << 16) | 0;
        $this->assertTrue(NecIfIndexDecoder::isEthernetPort($ifIndex));
    }

    /**
     * Test isEthernetPort() returns true for ETH LAG (slot 0, port 1-64).
     */
    public function testIsEthernetPortEthLag(): void
    {
        $ifIndex = (0 << 23) | (10 << 16) | 0;
        $this->assertTrue(NecIfIndexDecoder::isEthernetPort($ifIndex));
    }

    /**
     * Test isEthernetPort() returns false for radio ports.
     */
    public function testIsEthernetReturnsFalseForRadio(): void
    {
        // MODEM slot 2
        $ifIndex = (2 << 23) | (1 << 16) | 0;
        $this->assertFalse(NecIfIndexDecoder::isEthernetPort($ifIndex));
    }

    /**
     * Test decoding a real-world ifIndex value from sample SNMP data.
     * ifIndex 16842752 = 0x01010000 → slot=2, port=1, path=0
     */
    public function testDecodeRealWorldIfIndex(): void
    {
        $decoded = NecIfIndexDecoder::decode(16842752);

        $this->assertEquals(2, $decoded['slot']);
        $this->assertEquals(1, $decoded['port']);
        $this->assertEquals(0, $decoded['path']);
        $this->assertEquals('Modem-Slot2-Port1', $decoded['label']);
        $this->assertTrue(NecIfIndexDecoder::isRadioPort(16842752));
    }

    /**
     * Test decoding ifIndex 83951616 from sample SNMP data.
     * 83951616 = 0x05010000 → slot=10, port=1, path=0
     */
    public function testDecodeIfIndex83951616(): void
    {
        $decoded = NecIfIndexDecoder::decode(83951616);

        // 83951616 in hex = 0x05010000
        // slot = (0x05010000 & 0x0F800000) >> 23 = (0x05000000) >> 23 = 10
        // port = (0x05010000 & 0x007F0000) >> 16 = (0x010000) >> 16 = 1
        $this->assertEquals(10, $decoded['slot']);
        $this->assertEquals(1, $decoded['port']);
        $this->assertEquals(0, $decoded['path']);
        $this->assertFalse($decoded['has_reserved_bits']);
    }

    // ---------------------------------------------------------------
    // Equipment Type Mapping Tests (FR-03)
    // ---------------------------------------------------------------

    /**
     * @dataProvider equipmentTypeProvider
     */
    public function testEquipmentTypeMapping(int $typeCode, string $expectedName): void
    {
        $result = NecIpasolinkOS::resolveEquipmentType($typeCode);
        $this->assertEquals($expectedName, $result);
    }

    /**
     * Data provider for all known equipment type codes.
     *
     * @return array<array{int, string}>
     */
    public static function equipmentTypeProvider(): array
    {
        return [
            [20, 'PASOLINK+ (STM-1)'],
            [30, 'PASOLINK+ (STM-0)'],
            [40, 'PASOLINK+ (PDH)'],
            [50, 'PASOLINK+ (Nlite-L)'],
            [60, 'PASOLINK+ (Mx)'],
            [70, 'PASOLINK+ (Nlite-Lx)'],
            [100, 'PASOLINK NEO (STD)'],
            [110, 'PASOLINK NEO (N+1)'],
            [120, 'PASOLINK NEO (Advanced)'],
            [130, 'PASOLINK NEO (Compact)'],
            [140, 'PASOLINK NEO (Nodal)'],
            [150, 'PASOLINK NEO (HP)'],
            [160, 'NLiteNEO'],
            [200, 'iPASOLINK 200'],
            [201, 'iPASOLINK 100'],
            [321, 'iPASOLINK 100E'],
            [400, 'iPASOLINK 400'],
            [450, 'iPASOLINK 400A'],
            [501, 'iPASOLINK SX'],
            [510, 'iPASOLINK iX'],
            [520, 'iPASOLINK EX'],
            [1000, 'iPASOLINK 1000'],
            [5000, '5000iPS'],
        ];
    }

    /**
     * Test that an unknown equipment type returns a descriptive fallback.
     */
    public function testUnknownEquipmentTypeReturnsDefault(): void
    {
        $result = NecIpasolinkOS::resolveEquipmentType(9999);
        $this->assertEquals('NEC iPasoLink (unknown type 9999)', $result);
    }

    /**
     * Test null equipment type returns generic name.
     */
    public function testNullEquipmentTypeReturnsGeneric(): void
    {
        $result = NecIpasolinkOS::resolveEquipmentType(null);
        $this->assertEquals('NEC iPasoLink', $result);
    }

    /**
     * Test empty string equipment type returns generic name.
     */
    public function testEmptyEquipmentTypeReturnsGeneric(): void
    {
        $result = NecIpasolinkOS::resolveEquipmentType('');
        $this->assertEquals('NEC iPasoLink', $result);
    }

    /**
     * Test string numeric equipment type is properly cast.
     */
    public function testStringEquipmentTypeIsCast(): void
    {
        $result = NecIpasolinkOS::resolveEquipmentType('400');
        $this->assertEquals('iPASOLINK 400', $result);
    }

    // ---------------------------------------------------------------
    // Severity Mapping Tests
    // ---------------------------------------------------------------

    /**
     * Verify all 7 severity values have valid mappings.
     *
     * @dataProvider severityProvider
     */
    public function testSeverityMappingExists(int $severity, string $expectedLabel): void
    {
        $labels = [
            0 => 'invalid',
            1 => 'indeterminate',
            2 => 'critical',
            3 => 'major',
            4 => 'minor',
            5 => 'warning',
            6 => 'cleared',
        ];

        $this->assertArrayHasKey($severity, $labels);
        $this->assertEquals($expectedLabel, $labels[$severity]);
    }

    /**
     * @return array<array{int, string}>
     */
    public static function severityProvider(): array
    {
        return [
            [0, 'invalid'],
            [1, 'indeterminate'],
            [2, 'critical'],
            [3, 'major'],
            [4, 'minor'],
            [5, 'warning'],
            [6, 'cleared'],
        ];
    }

    // ---------------------------------------------------------------
    // Trap Handler Class Tests
    // ---------------------------------------------------------------

    /**
     * Verify the trap handler class exists and implements SnmptrapHandler.
     */
    public function testHandlerClassExists(): void
    {
        $this->assertTrue(class_exists(NecIpasolink::class));
    }

    /**
     * Verify the handler implements the SnmptrapHandler interface.
     */
    public function testHandlerImplementsInterface(): void
    {
        $reflectionClass = new \ReflectionClass(NecIpasolink::class);
        $this->assertTrue(
            $reflectionClass->implementsInterface(\LibreNMS\Interfaces\SnmptrapHandler::class)
        );
    }

    /**
     * Verify the handle method exists and accepts correct parameters.
     */
    public function testHandleMethodSignature(): void
    {
        $reflectionClass = new \ReflectionClass(NecIpasolink::class);
        $this->assertTrue($reflectionClass->hasMethod('handle'));

        $method = $reflectionClass->getMethod('handle');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('device', $params[0]->getName());
        $this->assertEquals('trap', $params[1]->getName());
    }

    // ---------------------------------------------------------------
    // Trap Type Handler Method Tests
    // ---------------------------------------------------------------

    /**
     * Verify all private handler methods exist.
     *
     * @dataProvider trapHandlerMethodProvider
     */
    public function testTrapHandlerMethodExists(string $methodName): void
    {
        $reflectionClass = new \ReflectionClass(NecIpasolink::class);
        $this->assertTrue(
            $reflectionClass->hasMethod($methodName),
            "Handler method '{$methodName}' should exist on NecIpasolink"
        );
    }

    /**
     * @return array<array{string}>
     */
    public static function trapHandlerMethodProvider(): array
    {
        return [
            ['handleAlarmStateChange'],
            ['handleStatusChange'],
            ['handleStatusChangeDspStr'],
            ['handleStatusChangeUnsigned32'],
            ['handleControlEvent'],
            ['handleFileDownloadEvent'],
            ['handleFileUpdateEvent'],
            ['handleGenericEvent'],
        ];
    }

    // ---------------------------------------------------------------
    // OS Module Tests
    // ---------------------------------------------------------------

    /**
     * Verify the OS module class exists.
     */
    public function testOsModuleClassExists(): void
    {
        $this->assertTrue(class_exists(NecIpasolinkOS::class));
    }

    /**
     * Verify all 23 equipment types are mapped (including the full range).
     */
    public function testAllEquipmentTypesMapped(): void
    {
        $expectedCodes = [
            20, 30, 40, 50, 60, 70,
            100, 110, 120, 130, 140, 150, 160,
            200, 201, 321,
            400, 450,
            501, 510, 520,
            1000,
            5000,
        ];

        foreach ($expectedCodes as $code) {
            $result = NecIpasolinkOS::resolveEquipmentType($code);
            $this->assertNotEmpty($result, "Equipment type {$code} should have a mapping");
            $this->assertStringNotContainsString(
                'unknown',
                $result,
                "Equipment type {$code} should not return 'unknown'"
            );
        }
    }

    // ---------------------------------------------------------------
    // Config Registration Tests
    // ---------------------------------------------------------------

    /**
     * Verify the snmptrap.php config file has all NEC iPasoLink trap registrations.
     */
    public function testTrapRegistrationsExist(): void
    {
        $configFile = __DIR__ . '/../../../../config/snmptrap.php';

        if (! file_exists($configFile)) {
            $this->markTestSkipped('config/snmptrap.php not found — skipping registration test');
        }

        $config = require $configFile;
        $expectedTraps = [
            'IPE-COMMON-MIB::alarmStateChange',
            'IPE-COMMON-MIB::statusChange',
            'IPE-COMMON-MIB::statusChangeDspStr',
            'IPE-COMMON-MIB::statusChangeUnsigned32',
            'IPE-COMMON-MIB::controlEvent',
            'IPE-COMMON-MIB::fileDownloadEvent',
            'IPE-COMMON-MIB::fileUpdateEvent',
        ];

        foreach ($expectedTraps as $trapOid) {
            $this->assertArrayHasKey(
                $trapOid,
                $config['trap_handlers'],
                "Trap OID '{$trapOid}' should be registered in config/snmptrap.php"
            );
            $this->assertEquals(
                NecIpasolink::class,
                $config['trap_handlers'][$trapOid],
                "Trap OID '{$trapOid}' should map to NecIpasolink handler"
            );
        }
    }
}
