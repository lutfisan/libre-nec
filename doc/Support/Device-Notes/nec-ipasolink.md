# NEC iPasoLink Support

## Overview

LibreNMS provides native support for NEC iPasoLink family microwave radio equipment. This includes automatic OS detection, hardware inventory discovery, sensor monitoring, port discovery/polling, SNMP trap handling, and custom graphs for RSL and RMON statistics.

## Supported Hardware

The following NEC iPasoLink equipment types are supported:

| Equipment Code | Model Name |
|:-:|:--|
| 20 | PASOLINK+ (STM-1) |
| 30 | PASOLINK+ (STM-0) |
| 40 | PASOLINK+ (PDH) |
| 50 | PASOLINK+ (Nlite-L) |
| 60 | PASOLINK+ (Mx) |
| 70 | PASOLINK+ (Nlite-Lx) |
| 100 | PASOLINK NEO (STD) |
| 110 | PASOLINK NEO (N+1) |
| 120 | PASOLINK NEO (Advanced) |
| 130 | PASOLINK NEO (Compact) |
| 140 | PASOLINK NEO (Nodal) |
| 150 | PASOLINK NEO (HP) |
| 160 | NLiteNEO |
| 200 | iPASOLINK 200 |
| 201 | iPASOLINK 100 |
| 321 | iPASOLINK 100E |
| 400 | iPASOLINK 400 |
| 450 | iPASOLINK 400A |
| 501 | iPASOLINK SX |
| 510 | iPASOLINK iX |
| 520 | iPASOLINK EX |
| 1000 | iPASOLINK 1000 |
| 5000 | 5000iPS |

## SNMP Requirements

### SNMP Version
- SNMPv2c or SNMPv3, UDP port 161

### Required MIB Files
The following MIB files must be placed in the LibreNMS `mibs/nec/` directory:
- `IPE-COMMON-MIB` — Core alarms, metering, inventory, communications, and trap definitions
- `IPE-COMMON1000-MIB` — Extended objects for iPASOLINK 1000 series
- `IPE-SYSTEM-MIB` — System-level info, equipment type, software version, NE name

### OS Detection
Devices are automatically detected by sysObjectID `.1.3.6.1.4.1.119.2.3.69.502`.

## Discovery

### Device Attributes
On discovery, the following fields are populated:

| Field | Source |
|:--|:--|
| Hardware | `invChassisName` + `invChassisCodeNo` + equipment type mapping |
| Serial | `invChassisSerialNo` |
| Version | `invChassisFirmVersion` or `ipeSysInvSoftwareVersion` |
| Sysname | `ipeSysNeName` |

### Sensors
The following sensors are discovered from the metering tables:

| Sensor | MIB Object | Unit |
|:--|:--|:--|
| ODU Temperature | `metSysTempODUValue` | °C |
| IDU Temperature | `metSysTempIDUValue` | °C |
| Main Card Temperature | `ctrlTemperature` (÷10) | °C |
| TX Power | `metSysTxPowerValue` | dBm |
| RX Level (RSL) | `metSysRxLevelValue` | dBm |
| PSU Voltage | `metSysPSVoltageValue` | V |
| Fan Speed 1-3 | `meteringFanSpeed1/2/3` | RPM |
| Bit Error Rate | `metSysBitErrorRateValue` | BER |

> **Note:** Sensors with value `invalid` are automatically skipped.

### Alarm State Sensors
The following alarm state sensors are discovered:

| Group | MIB Object | States |
|:--|:--|:--|
| ODU | `oduTotalAlarm` | indeterminate, critical, major, minor, warning, cleared |
| Modem | `modemTotalAlarm` | indeterminate, critical, major, minor, warning, cleared |
| Fan | `fanAlarm` | indeterminate, critical, major, minor, warning, cleared |
| PSU | `psPowerSupply` | indeterminate, critical, major, minor, warning, cleared |

## Port Discovery & ifIndex Decoding

### Required Core Patch

iPasoLink firmware does not implement `ifDescr` — a core patch to `includes/discovery/ports.inc.php` is required to register the OS-specific discovery hook. Add these lines after the Nokia 1830 block (around line 97):

```php
// NEC iPasoLink - ensure ifDescr is set from ifName (FR-66)
if ($device['os'] == 'nec-ipasolink') {
    require base_path('includes/discovery/ports/nec-ipasolink.inc.php');
}
```

A patch file is provided at `patches/ports.inc.php.patch`.

### ifIndex Bitmap Decoder

Ports are discovered via IF-MIB and labelled using the **NWD-156232 ifIndex bitmap decoder**.

### ifIndex Bitmap Layout

Each NEC iPasoLink ifIndex encodes slot, port, and path in a 32-bit bitmap:

```
Bit: 31  | 30-28    | 27-23       | 22-16       | 15-0
     0   | Reserved | Slot Number | Port Number | Path Number
```

### Slot Assignments

| Slot | Function | Label Format |
|:--:|:--|:--|
| 0 | LAG interfaces | `ETH-LAG-Port<N>` (port 1-64), `Radio-LAG-Port<N>` (port 65-80) |
| 1 | Main Card | `Main-GbE-Port<N>` (path=0), `Main-16E1-Slot1-CH<N>` (path>0) |
| 2-5 | MODEM (radio) | `Modem-Slot<S>-Port<P>` or `Modem-Slot<S>-Port<P>-Path<N>` |
| 6-7 | Power Supply | `PS-Slot<S>` |
| 8 | Fan | `FAN-Slot8` |
| 9-31 | Extended I/O (iPASOLINK 1000) | `ExtIO-Slot<S>-Port<P>` or `ExtIO-Slot<S>-Port<P>-Path<N>` |

> **Note:** Bitmap decoding only applies to ifIndex values ≥ 65536. Low ifIndex values (1–1024) are simple sequential IDs used for system interfaces (lo, lct, bridge, modem, etc.) and retain the device-provided ifDescr.

### Resilience

- If reserved bits (30-28) are non-zero, the interface is labelled `Unknown-If-<ifIndex>` and a warning is logged. This protects against 5000iPS or future chassis variants.
- If slot/port decodes to an unmapped combination, the interface is labelled `Unknown-If-Slot<S>-Port<P>-Path<N>` and polling continues without error.
- If `ifDescr` is empty, `ifName` is used as a fallback.

## Port Polling

Radio ports (MODEM slots 2-5 and Radio LAG ports 65-80) are polled for NEC-specific metrics:
- **TX Power** — from `metSysTxPowerValue` (tenths of dBm, divided by 10)
- **RX Level** — from `metSysRxLevelValue` (tenths of dBm, divided by 10)
- **Modulation** — from `modemGroupTable.modemModulation`

These values are stored in RRD files and displayed in the device graphs.

## Graphs

### RSL Min/Max
- **Graph key:** `device_nec_ipasolink_rsl`
- Shows received signal level (dBm) per radio port over time

### RMON Ethernet Statistics
- **Graph key:** `device_nec_ipasolink_rmon`
- Shows octets, frame counts, and error counters for Ethernet ports

## SNMP Trap Handling

### Configuration
1. Ensure SNMP trap receiver is configured on UDP/162
2. The trap handler is registered in `config/snmptrap.php`

### Supported Traps

| Trap | Action |
|:--|:--|
| `alarmStateChange` | Maps severity (0-6), creates/clears LibreNMS alert |
| `statusChange` | Logs status change event |
| `statusChangeDspStr` | Logs status change with display string |
| `statusChangeUnsigned32` | Logs numeric status change |
| `controlEvent` | Logs control/provisioning event |
| `fileDownloadEvent` | Logs firmware download event |
| `fileUpdateEvent` | Logs config update event |

### Severity Mapping

| IPE Value | Label | LibreNMS Action |
|:-:|:--|:--|
| 0 | invalid | Skip/ignore |
| 1 | indeterminate | Log only |
| 2 | critical | Raise alert |
| 3 | major | Raise alert |
| 4 | minor | Raise alert |
| 5 | warning | Raise alert |
| 6 | cleared | Clear alert |

### Trap Registration (config/snmptrap.php)

The following entries must be present in `config/snmptrap.php`:

```php
'IPE-COMMON-MIB::alarmStateChange' => LibreNMS\Snmptrap\Handlers\NecIpasolink::class,
'IPE-COMMON-MIB::statusChange' => LibreNMS\Snmptrap\Handlers\NecIpasolink::class,
'IPE-COMMON-MIB::statusChangeDspStr' => LibreNMS\Snmptrap\Handlers\NecIpasolink::class,
'IPE-COMMON-MIB::statusChangeUnsigned32' => LibreNMS\Snmptrap\Handlers\NecIpasolink::class,
'IPE-COMMON-MIB::controlEvent' => LibreNMS\Snmptrap\Handlers\NecIpasolink::class,
'IPE-COMMON-MIB::fileDownloadEvent' => LibreNMS\Snmptrap\Handlers\NecIpasolink::class,
'IPE-COMMON-MIB::fileUpdateEvent' => LibreNMS\Snmptrap\Handlers\NecIpasolink::class,
```

## Troubleshooting

### Device not detected
- Verify sysObjectID is `.1.3.6.1.4.1.119.2.3.69.502` using `snmpget -v2c -c <community> <host> sysObjectID.0`
- Ensure MIB files are in `mibs/nec/` directory
- Check `mib_dir: nec` is set in the OS detection YAML

### Missing sensors
- Run `./lnms device:poll <hostname> -m sensors -vv` to see discovery debug output
- Sensors with value `invalid` are intentionally skipped
- Some iPasoLink models may not implement all metering OIDs

### Empty ports list
- Some firmware omits `ifDescr`. LibreNMS falls back to `ifName` automatically.
- Run `snmpwalk -v2c -c <community> <host> .1.3.6.1.2.1.31.1.1.1.1` to verify ifName is available.

### No TX/RX power values
- Only radio ports are polled (MODEM slots 2-5, Radio LAG ports 65-80 per NWD-156232 bitmap)
- Verify meteringTable responds: `snmpwalk -v2c -c <community> <host> .1.3.6.1.4.1.119.2.3.69.501.8.1`
- Check ifIndex values decode correctly by examining discovery logs for `iPasoLink: ifIndex N → label=...` entries

### Traps not processing
- Verify trap receiver is running on UDP/162
- Check that `config/snmptrap.php` contains the IPE-COMMON-MIB entries
- Check LibreNMS logs for trap processing errors
