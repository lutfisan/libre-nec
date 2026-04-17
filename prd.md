  
**PRODUCT REQUIREMENT DOCUMENT**

**NEC iPasoLink Support for LibreNMS**

Microwave Radio Network Monitoring Integration

**Version:** 1.0

**Status:** Draft for Review

**Date:** April 7, 2026

**Target:** LibreNMS stable release 26.x and above

# **Table of Contents**

# **1\. Overview**

## **1.1 Purpose**

This document defines the product requirements for adding native support for the NEC iPasoLink family of microwave radio equipment in LibreNMS. The implementation covers OS detection, OS discovery (hardware inventory, sensors), port discovery, port polling, SNMP trap handling, and graph definitions for Received Signal Level (RSL) and RMON Ethernet statistics.

The integration targets LibreNMS stable release 26.x and above, requires PHP 8.3+ and Python 3.12+, and must adhere to all existing LibreNMS coding standards and contribution guidelines.

## **1.2 Background**

NEC iPasoLink is a widely deployed point-to-point microwave backhaul radio platform used by telecommunications carriers, ISPs, and private network operators worldwide. The platform spans multiple generations and product variants, from iPASOLINK 100 to the high-capacity iPASOLINK 1000\.

Currently, LibreNMS has no native OS module for NEC iPasoLink equipment. Operators managing these devices in LibreNMS rely on generic SNMP polling, resulting in missing inventory data, incomplete sensor discovery (RSL, TX power, temperature, fan speed), no alarm/trap correlation, and absent port-level statistics.

The three MIB files provided form the foundation for this integration:

* IPE-COMMON-MIB — core alarms, metering, inventory, communications, and trap definitions (.1.3.6.1.4.1.119.2.3.69.501)

* IPE-COMMON1000-MIB — extended objects for iPASOLINK 1000 series

* IPE-SYSTEM-MIB — system-level info, equipment type, software version, NE name (.1.3.6.1.4.1.119.2.3.69.502)

## **1.3 Scope**

In-scope for this PRD:

* OS detection YAML (resources/definitions/os\_detection/)

* OS discovery YAML (resources/definitions/os\_discovery/)

* MIB files placement under mibs/nec/

* NEC iPasoLink port discovery (includes/discovery/ports/) and core discovery patch

* ifIndex Bitmap Decoder (LibreNMS/Util/NecIfIndexDecoder.php)

* NEC iPasoLink port polling (includes/polling/ports/os/)

* SNMP Trap Handler (LibreNMS/Snmptrap/Handlers/)

* Graph definitions for RSL and RMON Ethernet statistics

* OID group polling: ipeCommunicationsGroup, alarmStatusGroup, inventoryGroup, meteringGroup

* PHPUnit tests for SNMP Trap Handler, OS module, and ifIndex Decoder

Out-of-scope for this PRD:

* Web UI changes beyond device overview panels

* NMS northbound integration or external alarm management

* Scheduled or historical PMON/TCN performance reports

# **2\. Requirements**

## **2.1 Functional Requirements**

### **FR-01 — OS Detection**

LibreNMS SHALL detect NEC iPasoLink devices via sysObjectID .1.3.6.1.4.1.119.2.3.69.502 and assign them the OS identifier nec-ipasolink with type radio, icon nec, group microwave.

### **FR-02 — OS Discovery**

On discovery, LibreNMS SHALL collect the following data from IPE-SYSTEM-MIB and IPE-COMMON-MIB:

| Field | MIB Object | OID |
| :---- | :---- | :---- |
| hardware | IPE-COMMON-MIB::invChassisName.1 / invChassisCodeNo.1 | .119.2.3.69.501.7.x.1.3 / .4 |
| serial | IPE-COMMON-MIB::invChassisSerialNo.1 | .119.2.3.69.501.7.x.1.5 |
| version | IPE-COMMON-MIB::invChassisFirmVersion.1 | .119.2.3.69.501.7.x.1.7 |
| version (alt) | IPE-SYSTEM-MIB::ipeSysInvSoftwareVersion.1 | .119.2.3.69.502.x |
| sysname | IPE-SYSTEM-MIB::ipeSysNeName.1 | .119.2.3.69.502.1.1.3 |
| equipment type | IPE-SYSTEM-MIB::ipeSysEquipmentType | .119.2.3.69.502.1.1.10 |

### **FR-03 — Equipment Type Mapping**

The ipeSysEquipmentType integer SHALL be translated to a human-readable product name in the device hardware field. The full mapping is:

| Value | Model Name | Value | Model Name |
| :---- | :---- | :---- | :---- |
| 20 | PASOLINK+ (STM-1) | 200 | iPASOLINK 200 |
| 30 | PASOLINK+ (STM-0) | 201 | iPASOLINK 100 |
| 40 | PASOLINK+ (PDH) | 321 | iPASOLINK 100E |
| 50 | PASOLINK+ (Nlite-L) | 400 | iPASOLINK 400 |
| 60 | PASOLINK+ (Mx) | 450 | iPASOLINK 400A |
| 70 | PASOLINK+ (Nlite-Lx) | 501 | iPASOLINK SX |
| 100 | PASOLINK NEO (STD) | 510 | iPASOLINK iX |
| 110 | PASOLINK NEO (N+1) | 520 | iPASOLINK EX |
| 120 | PASOLINK NEO (Advanced) | 1000 | iPASOLINK 1000 |
| 130 | PASOLINK NEO (Compact) | 5000 | 5000iPS |
| 140 | PASOLINK NEO (Nodal) |  |  |
| 150 | PASOLINK NEO (HP) |  |  |
| 160 | NLiteNEO |  |  |

### **FR-04 — Sensor Discovery (meteringGroup)**

LibreNMS SHALL discover and poll the following sensor types from the meteringTable (.1.3.6.1.4.1.119.2.3.69.501.8.1) and meteringFanTable (.1.3.6.1.4.1.119.2.3.69.501.8.3):

| Sensor Type | MIB Object | Numeric OID Suffix | Unit |
| :---- | :---- | :---- | :---- |
| temperature | metSysTempODUValue | .501.8.1.1.10.{{ $index }} | Celsius |
| temperature | metSysTempIDUValue | .501.8.1.1.11.{{ $index }} | Celsius |
| temperature | ctrlTemperature (ctrlGroupTable) | .501.3.3.1.1.13.{{ $index }} | Celsius (div 10\) |
| dbm | metSysTxPowerValue | .501.8.1.1.4.{{ $index }} | dBm |
| dbm | metSysRxLevelValue | .501.8.1.1.6.{{ $index }} | dBm |
| voltage | metSysPSVoltageValue | .501.8.1.1.8.{{ $index }} | Volts |
| fanspeed | meteringFanSpeed1 | .501.8.3.1.3.{{ $index }} | RPM |
| fanspeed | meteringFanSpeed2 | .501.8.3.1.5.{{ $index }} | RPM |
| fanspeed | meteringFanSpeed3 | .501.8.3.1.7.{{ $index }} | RPM |
| count | metSysBitErrorRateValue | .501.8.1.1.14.{{ $index }} | BER |

*All sensors with value 'invalid' SHALL be skipped via skip\_values.*

### **FR-05 — Alarm Status Polling (alarmStatusGroup)**

LibreNMS SHALL poll the following alarm status tables from alarmStatusGroup (.1.3.6.1.4.1.119.2.3.69.501.3) and expose alarm state as LibreNMS state sensors:

| Table | Key Alarm Fields | AlarmStatusGroup Sub-OID |
| :---- | :---- | :---- |
| oduGroupTable | oduAlarm, txPowerAlarm, rxLevelAlarm, oduTotalAlarm | .3.1.x |
| modemGroupTable | modemAlarm, berAlarm, modemTotalAlarm | .3.2.x |
| modemXpicTable | xpicAlarm, xpicTotalAlarm | .3.3.x |
| modemCardTable | modemCardAlarm, cardTotalAlarm | .3.4.x |
| fanGroupTable | fanAlarm, fanUnequipped | .3.7.x |
| psGroupTable | psPowerSupply, psUnequipped | .3.8.x |

### **FR-06 — Port Discovery**

The file includes/discovery/ports/nec-ipasolink.inc.php SHALL discover radio and Ethernet ports from ipeCommunicationsGroup (.1.3.6.1.4.1.119.2.3.69.501.4.x). Port labels SHALL be derived from IPE-COMMON-MIB interface descriptions and the standard IF-MIB ifDescr / ifAlias / ifSpeed / ifType fields. Discovered ports SHALL include:

* Radio ports (modem interfaces) — type: radio

* Ethernet aggregation ports — type: ether

* Port label format: {card\_type}\_{slot}/{port}, e.g. RF\_1/1, Ethernet\_9/1

### **FR-07 — Port Polling**

The file includes/polling/ports/os/nec-ipasolink.inc.php SHALL supplement standard IF-MIB polling with NEC-specific per-port metrics from meteringTable, indexed by ifIndex. Polled values per port SHALL include:

* TX Power (metSysTxPowerValue) — stored in port extra field txpower

* RX Level (metSysRxLevelValue) — stored in port extra field rxlevel

* Modulation type — from modemGroupTable.modemModulation

* Capacity / throughput — from modemGroupTable where applicable

### **FR-08 — SNMP Trap Handling**

A PHP trap handler class SHALL be created at LibreNMS/Snmptrap/Handlers/NecIpasolink.php. It SHALL handle the following NOTIFICATION-TYPEs from IPE-COMMON-MIB:

| Trap OID Name | OID | Handler Action |
| :---- | :---- | :---- |
| alarmStateChange | .1.3.6.1.4.1.119.2.3.69.501.2.x.1 | Map severity, create/clear LibreNMS alert |
| statusChange | .1.3.6.1.4.1.119.2.3.69.501.2.x.2 | Log status change event |
| statusChangeDspStr | .1.3.6.1.4.1.119.2.3.69.501.2.x.3 | Log status change with display string |
| statusChangeUnsigned32 | .1.3.6.1.4.1.119.2.3.69.501.2.x.5 | Log numeric status change |
| controlEvent | .1.3.6.1.4.1.119.2.3.69.501.2.x.6 | Log control/provisioning event |
| fileDownloadEvent | .1.3.6.1.4.1.119.2.3.69.501.2.x.10 | Log firmware download event |
| fileUpdateEvent | .1.3.6.1.4.1.119.2.3.69.501.2.x.11 | Log config update event |

*Severity mapping: 0=indeterminate, 1=critical, 2=major, 3=minor, 4=warning, 5=cleared. Each alarmStateChange trap SHALL update the device alert state and log eventAdditionalText1–5 as the alert message body.*

### **FR-09 — Graph Definitions**

The following device-level graphs SHALL be registered in the OS definition and implemented under includes/html/graphs/device/:

| Graph Key | File | Description |
| :---- | :---- | :---- |
| device\_nec\_ipasolink\_rsl | device\_nec\_ipasolink\_rsl.inc.php | RSL Min/Max per radio port over time |
| device\_nec\_ipasolink\_rmon | device\_nec\_ipasolink\_rmon.inc.php | RMON Ethernet statistics (octets, frames, errors) |

### **FR-10 — Port Discovery Core Patch**

IF LibreNMS core port discovery drops ports with empty `ifDescr`. A patch (`patches/ports.inc.php.patch`) SHALL ensure `ifDescr` is populated from `ifName` for `nec-ipasolink` devices before core discovery runs.

### **FR-11 — Reserved Bits Handling**

If an `ifIndex` has reserved bits (30-28) set, it indicates a potentially unsupported chassis type. The decoder SHALL return a fallback opaque label (`Unknown-If-<ifIndex>`) instead of crashing.

### **FR-12 — ifIndex Decoder**

A utility class (`LibreNMS/Util/NecIfIndexDecoder.php`) SHALL decode the `ifIndex` bitmap into slot, port, and path, generating human-readable labels according to NWD-156232 Appendix 1.

### **FR-13 — Unknown Slot Fallback**

If the decoder encounters an unknown slot/port combination, it SHALL return an opaque label (`Unknown-If-Slot<S>-Port<P>-Path<N>`) to ensure polling continues without error.

## **2.2 Non-Functional Requirements**

* Compatibility: Must work with LibreNMS 26.x+ without modifying core framework files.

* Performance: All discovery/polling additions must complete within the standard 300-second poller timeout. SNMP walk operations must use bulk-walk (snmpwalk\_cache\_oid) where possible.

* PHP version: PHP 8.3 minimum. Use typed properties, match expressions, and null-safe operators where appropriate.

* Python version: Python 3.12 minimum (for any poller helper scripts, if needed).

* Testing: PHPUnit tests SHALL be added for the Trap Handler. YAML validation tests SHALL pass via LibreNMS's built-in test suite (./lnms dev:test).

* Documentation: A Markdown user guide SHALL be placed at doc/Support/Device-Notes/nec-ipasolink.md.

# **3\. File Inventory**

The following files SHALL be created or modified as part of this implementation:

| Action | File Path | Description |
| :---- | :---- | :---- |
| NONE | mibs/nec/IPE-COMMON-MIB | file already present |
| NONE | mibs/nec/IPE-COMMON1000-MIB | file already present |
| NONE | mibs/nec/IPE-SYSTEM-MIB | file already present |
| NONE | mibs/nec/IPE-LAG-MIB | file already present |
| NONE | mibs/nec/NEC-MIB | file already present |
| NONE | resources/definitions/os\_detection/nec-ipasolink.yaml | file already present |
| NONE | resources/definitions/os\_discovery/nec-ipasolink.yaml | file already present |
| CREATE | LibreNMS/OS/NecIpasolink.php | OS and Port discovery for radio and Ethernet ports |
| CREATE | includes/discovery/ports/nec-ipasolink.inc.php | Port discovery for radio and Ethernet ports |
| CREATE | includes/polling/ports/os/nec-ipasolink.inc.php | Port polling: TX/RX power, modulation per port |
| CREATE | LibreNMS/Snmptrap/Handlers/NecIpasolink.php | SNMP trap handler class |
| CREATE | LibreNMS/Util/NecIfIndexDecoder.php | Utility to decode ifIndex into slot/port/path |
| CREATE | tests/Unit/Snmptrap/Handlers/NecIpasolinkTest.php | PHPUnit tests for trap handler, OS module, and decoder |
| CREATE | patches/ports.inc.php.patch | Patch to core port discovery for missing ifDescr |
| CREATE | includes/html/graphs/device/nec\_ipasolink\_rsl.inc.php | RSL graph include |
| CREATE | includes/html/graphs/device/nec\_ipasolink\_rmon.inc.php | RMON graph include |
| CREATE | doc/Support/Device-Notes/nec-ipasolink.md | User documentation |
| MODIFY | resources/definitions/os\_detection/nec-ipasolink.yaml | Add additional sysObjectID variants if needed |
| MODIFY | config/snmptrap.php | Register SNMP trap handlers |

# **4\. Implementation Details**

## **4.1 Code Structure**

```
.
├── app
├── bootstrap
├── cache
├── config
├── database
│   ├── factories
│   ├── migrations
│   ├── schema
│   └── seeders
│       └── config
├── dist
│   └── rrdcached
├── doc
│   ├── Alerting
│   │   ├── img
│   │   └── Transports
│   ├── API
│   ├── Developing
│   │   └── os
│   ├── Extensions
│   │   ├── Applications
│   │   └── metrics
│   ├── General
│   │   └── Changelogs
│   ├── img
│   ├── Installation
│   ├── js
│   └── Support
│       ├── Device-Notes
│       └── img
├── html
│   ├── build
│   │   └── assets
│   ├── css
│   │   ├── bootstrap
│   │   ├── images
│   │   └── img
│   │       └── network
│   ├── fonts
│   ├── images
│   │   ├── custommap
│   │   │   ├── background
│   │   │   └── icons
│   │   ├── icons
│   │   ├── logos
│   │   ├── notifications
│   │   ├── os
│   │   └── transports
│   ├── js
│   │   ├── lang
│   │   ├── mapael-maps
│   │   ├── maps
│   │   └── RrdGraphJS
│   ├── plugins
│   ├── svg
│   └── webfonts
├── includes
│   ├── discovery
│   │   ├── bgp-peers
│   │   ├── fdb-table
│   │   ├── loadbalancers
│   │   ├── ntp
│   │   ├── ports/nec-ipasolink.inc.php    ← IF-MIB + meteringTable radio port detection
│   │   └── sensors
│   │       ├── airflow
│   │       ├── ber
│   │       ├── bitrate
│   │       ├── charge
│   │       ├── chromatic_dispersion
│   │       ├── count
│   │       ├── current
│   │       ├── dbm
│   │       ├── delay
│   │       ├── fanspeed
│   │       ├── frequency
│   │       ├── humidity
│   │       ├── load
│   │       ├── percent
│   │       ├── power
│   │       ├── power_consumed
│   │       ├── power_factor
│   │       ├── pre-cache
│   │       ├── runtime
│   │       ├── signal
│   │       ├── snr
│   │       ├── state
│   │       ├── temperature
│   │       ├── tv_signal
│   │       └── voltage
│   ├── html
│   │   ├── application
│   │   ├── collectd
│   │   ├── common
│   │   ├── forms
│   │   ├── graphs
│   │   │   ├── accesspoints
│   │   │   ├── application
│   │   │   ├── atmvp
│   │   │   ├── bgp
│   │   │   ├── bill
│   │   │   ├── cefswitching
│   │   │   ├── customer
│   │   │   ├── customoid
│   │   │   ├── device
│   │   │   │   ├── nec_ipasolink_rsl.inc.php             ← RSL Min/Max graph
│   │   │   │   └── nec_ipasolink_rmon.inc.php            ← RMON Ethernet statistics graph
│   │   │   ├── diskio
│   │   │   ├── global
│   │   │   ├── ipsectunnel
│   │   │   ├── location
│   │   │   ├── macaccounting
│   │   │   ├── mempool
│   │   │   ├── multiport
│   │   │   ├── multisensor
│   │   │   ├── munin
│   │   │   ├── netscalervsvr
│   │   │   ├── port
│   │   │   ├── processor
│   │   │   ├── qfp
│   │   │   ├── rserver
│   │   │   ├── sensor
│   │   │   ├── service
│   │   │   ├── smokeping
│   │   │   ├── storage
│   │   │   ├── toner
│   │   │   ├── vserver
│   │   │   └── wireless
│   │   ├── modal
│   │   ├── output
│   │   ├── pages
│   │   │   ├── apps
│   │   │   │   └── proxmox
│   │   │   ├── bill
│   │   │   ├── device
│   │   │   │   ├── apps
│   │   │   │   ├── edit
│   │   │   │   ├── graphs
│   │   │   │   ├── health
│   │   │   │   ├── loadbalancer
│   │   │   │   ├── nfsen
│   │   │   │   ├── overview
│   │   │   │   │   ├── generic
│   │   │   │   │   └── sensors
│   │   │   │   ├── port
│   │   │   │   ├── routing
│   │   │   │   └── sla
│   │   │   ├── peering
│   │   │   ├── ports
│   │   │   ├── routing
│   │   │   ├── search
│   │   │   └── tools
│   │   └── table
│   ├── polling
│   │   ├── applications
│   │   ├── loadbalancers
│   │   ├── ntp
│   │   ├── ports
│   │   │   └── os/nec-ipasolink.inc.php   ← txpower / rxlevel enrichment per port
│   │   ├── sensors
│   │   │   ├── charge
│   │   │   ├── count
│   │   │   ├── current
│   │   │   ├── dbm
│   │   │   ├── fanspeed
│   │   │   ├── load
│   │   │   ├── percent
│   │   │   ├── runtime
│   │   │   ├── state
│   │   │   ├── temperature
│   │   │   └── voltage
│   │   └── unix-agent
│   └── services
├── lang
│   ├── de
│   └── en
├── LibreNMS
│   ├── Alert
│   │   └── Transport
│   ├── Alerting
│   ├── Authentication
│   ├── Cache
│   ├── Data
│   │   ├── Graphing
│   │   ├── Source
│   │   └── Store
│   ├── DB
│   ├── Device
│	│   ├── Availability.php
│	│   ├── Processor.php
│	│   ├── WirelessSensor.php
│	│   └── YamlDiscovery.php
│   ├── Discovery
│   │   └── Yaml
│   ├── Enum
│   ├── Exceptions
│   ├── Interfaces
│   │   ├── Alert
│   │   ├── Authentication
│   │   ├── Data
│   │   ├── Discovery
│   │   │   └── Sensors
│	│   │       ├── WirelessApCountDiscovery.php
│	│   │       ├── WirelessCapacityDiscovery.php
│	│   │       ├── WirelessCcqDiscovery.php
│	│   │       ├── WirelessCellDiscovery.php
│	│   │       ├── WirelessChannelDiscovery.php
│	│   │       ├── WirelessClientsDiscovery.php
│	│   │       ├── WirelessDistanceDiscovery.php
│	│   │       ├── WirelessErrorRateDiscovery.php
│	│   │       ├── WirelessErrorRatioDiscovery.php
│	│   │       ├── WirelessErrorsDiscovery.php
│	│   │       ├── WirelessFrequencyDiscovery.php
│	│   │       ├── WirelessMseDiscovery.php
│	│   │       ├── WirelessNoiseFloorDiscovery.php
│	│   │       ├── WirelessPowerDiscovery.php
│	│   │       ├── WirelessQualityDiscovery.php
│	│   │       ├── WirelessRateDiscovery.php
│	│   │       ├── WirelessRsrpDiscovery.php
│	│   │       ├── WirelessRsrqDiscovery.php
│	│   │       ├── WirelessRssiDiscovery.php
│	│   │       ├── WirelessSinrDiscovery.php
│	│   │       ├── WirelessSnrDiscovery.php
│	│   │       ├── WirelessSsrDiscovery.php
│	│   │       ├── WirelessUtilizationDiscovery.php
│	│   │       └── WirelessXpiDiscovery.php
│   │   ├── Exceptions
│   │   ├── Models
│   │   ├── Polling
│   │   │   ├── Netstats
│   │   │   └── Sensors
│	│   │       ├── WirelessApCountPolling.php
│	│   │       ├── WirelessCapacityPolling.php
│	│   │       ├── WirelessCcqPolling.php
│	│   │       ├── WirelessCellPolling.php
│	│   │       ├── WirelessChannelPolling.php
│	│   │       ├── WirelessClientsPolling.php
│	│   │       ├── WirelessDistancePolling.php
│	│   │       ├── WirelessErrorRatePolling.php
│	│   │       ├── WirelessErrorRatioPolling.php
│	│   │       ├── WirelessErrorsPolling.php
│	│   │       ├── WirelessFrequencyPolling.php
│	│   │       ├── WirelessMsePolling.php
│	│   │       ├── WirelessNoiseFloorPolling.php
│	│   │       ├── WirelessPowerPolling.php
│	│   │       ├── WirelessQualityPolling.php
│	│   │       ├── WirelessRatePolling.php
│	│   │       ├── WirelessRsrpPolling.php
│	│   │       ├── WirelessRsrqPolling.php
│	│   │       ├── WirelessRssiPolling.php
│	│   │       ├── WirelessSinrPolling.php
│	│   │       ├── WirelessSnrPolling.php
│	│   │       ├── WirelessSsrPolling.php
│	│   │       ├── WirelessUtilizationPolling.php
│	│   │       └── WirelessXpiPolling.php
│   │   └── UI
│   ├── Modules
│   ├── OS
│   │   ├── NecIpasolink.php
│   │   ├── Shared
│   │   └── Traits
│   ├── Polling
│   ├── __pycache__
│   ├── RRD
│   ├── Snmptrap
│   │   └── Handlers/NecIpasolink.php                          ← Full trap handler (all 11 notification types)
│   ├── Traits
│   ├── Util
│   └── Validations
│       ├── Database
│       ├── DistributedPoller
│       ├── Poller
│       └── Rrd
├── licenses
├── logs
├── mibs
│   ├── nec
│   │   ├── IPE-COMMON-MIB 
│   │   ├── IPE-COMMON1000-MIB
│   │   └── IPE-SYSTEM-MIB
│   ├── ...
│   ├── zte
│   └── zyxel
├── misc
├── resources
│   ├── css
│   ├── definitions
│   │   ├── os_detection/nec-ipasolink.yaml     ← sysObjectID .119.2.3.69.502 trigger
│   │   ├── os_discovery/nec-ipasolink.yaml     ← hardware/serial/version + all sensors + alarm states
│   │   └── schema
│   ├── js
│   │   ├── components
│   │   │   └── alpine
│   │   └── plugins
│   └── views
│       ├── about
│       ├── alerts
│       │   ├── modals
│       │   └── templates
│       ├── auth
│       ├── components
│       │   ├── accordion
│       │   ├── device
│       │   └── icons
│       ├── device
│       │   ├── edit
│       │   ├── overview
│       │   └── tabs
│       │       ├── logs
│       │       └── ports
│       │           └── includes
│       ├── device-group
│       ├── errors
│       │   └── static
│       ├── graphs
│       ├── install
│       ├── layouts
│       ├── map
│       ├── outages
│       ├── overview
│       │   └── custom
│       ├── plugins
│       ├── poller
│       ├── port
│       ├── port-group
│       ├── roles
│       ├── search
│       ├── sensor
│       ├── service-template
│       ├── settings
│       ├── ssl-certificates
│       ├── user
│       ├── validate
│       ├── vendor
│       │   └── pagination
│       ├── vlans
│       ├── widgets
│       │   └── settings
│       └── wireless-sensor
├── routes
├── rrd
├── scripts
│   ├── agent-local
│   ├── Migration
│   └── watchmaillog
├── storage
└── tests
```

## **4.2 OS Detection — nec-ipasolink.yaml**

This file is placed in resources/definitions/os\_detection/ and triggers OS assignment when the device sysObjectID matches the NEC iPasoLink enterprise OID.

os: nec-ipasolink

text: 'NEC iPasoLink'

type: radio

icon: nec

mib\_dir: nec

group: microwave

over:

    \- { graph: device\_bits, text: 'Device Traffic' }

    \- { graph: device\_nec\_ipasolink\_rsl, text: 'RSL Min/Max' }

    \- { graph: device\_nec\_ipasolink\_rmon, text: 'RMON Ethernet Statistics' }

discovery:

    \-

        sysObjectID:

            \- .1.3.6.1.4.1.119.2.3.69.502

## **4.3 OS Discovery — nec-ipasolink.yaml**

This file is placed in resources/definitions/os\_discovery/ and drives YAML-based discovery of hardware attributes and sensors.

Key design decisions:

* Multiple MIBs are declared: IPE-SYSTEM-MIB:IPE-COMMON-MIB:IPE-COMMON1000-MIB

* hardware uses invChassisName concatenated with invChassisCodeNo for a descriptive string

* All sensors use skip\_values: 'invalid' to suppress unequipped slots

* ctrlTemperature uses divisor: 10 because MIB stores value in tenths of a degree

* Fan speed sensors are indexed per slot; descriptors use $port\_label resolved from ifDescr

* The meteringFanTable OID base is .1.3.6.1.4.1.119.2.3.69.501.8.3

* The meteringTable OID base is .1.3.6.1.4.1.119.2.3.69.501.8.1

## **4.4 Port Discovery — includes/discovery/ports/nec-ipasolink.inc.php**

This PHP file is conditionally loaded by LibreNMS when the OS is identified as nec-ipasolink. It walks ipeCommunicationsGroup and the IF-MIB to build the port list.

Implementation notes:

* In LibreNMS 26.x, includes/discovery/ports/{os}.inc.php is not a standalone replacement — it runs as a supplemental enrichment hook after the core port discovery has already processed all ports. By the time our file ran, the core had already executed its discover\_port() loop with empty ifDescr values and dropped every port with "ignored: empty ifDescr". Our custom SNMP calls never appeared in the log because the ports were already gone.

* The LibreNMS core port discovery (not our custom file) drops ports with empty ifDescr before our code can inject the ifName fallback. We use a core patch (`patches/ports.inc.php.patch`) to ensure `ifDescr` is populated from `ifName` for `nec-ipasolink` before core discovery runs.

* The iPASOLINK 400 firmware doesn't implement ifDescr, ifAlias, ifType, or ifOperStatus — only ifName responds.

* Port labels are derived from the `ifIndex` using the `NecIfIndexDecoder` utility, which implements the NWD-156232 bitmap layout (slot, port, path).

* Radio ports are identified by the `NecIfIndexDecoder` (MODEM slots 2-5, extended MODEM slots 9-31, or Radio-LAG ports).

* Ethernet ports are identified by the `NecIfIndexDecoder` (Main Card GbE or ETH-LAG).

* Ethernet ports are identified by ifType \= 6 (ethernetCsmacd).

* Port labels are normalised to {type}\_{slot}/{port} format.

* Use the existing LibreNMS port discovery helpers (discover\_port()) for insertion.

* PHP 8.3+: Use typed parameters, null-safe operators (?-\>), and match() for ifType branching.

## **4.5 Port Polling — includes/polling/ports/os/nec-ipasolink.inc.php**

This PHP file runs after standard IF-MIB polling for each port on nec-ipasolink devices. It enriches port records with NEC-specific metrics from meteringTable indexed by ifIndex.

Implementation notes:

* Index alignment: meteringTable entries are indexed by InterfaceIndex (same as ifIndex). The file must validate that the ifIndex matches a valid meteringEntry before reading values.

* Values are written into the $port array which LibreNMS persists to the ports table and RRD files.

* metSysTxPowerValue and metSysRxLevelValue are in tenths of dBm — divide by 10 before storing.

* Only process ports where $port\['ifType'\] \== 'other' (radio) for TX/RX power metrics; skip Ethernet interfaces.

* Use snmp\_get() for single OID reads once the index is confirmed, or snmpwalk\_cache\_oid() for batch collection across all radio ports.

## **4.6 SNMP Trap Handler — LibreNMS/Snmptrap/Handlers/NecIpasolink.php**

The trap handler implements the Snmptrap\\Contracts\\SnmptrapHandler interface and is registered in snmptrap.php. It processes all notification types from IPE-COMMON-MIB.

Class structure:

\<?php

namespace LibreNMS\\Snmptrap\\Handlers;

use App\\Models\\Device;

use LibreNMS\\Interfaces\\SnmptrapHandler;

use LibreNMS\\Snmptrap\\Trap;

class NecIpasolink implements SnmptrapHandler

{

    public function handle(Trap $trap): void

    {

        $severity  \= $trap-\>getOidData('.1.3.6.1.4.1.119.2.3.69.501.2.1.6');

        $resourceId \= $trap-\>getOidData('.1.3.6.1.4.1.119.2.3.69.501.2.1.5');

        $addText1  \= $trap-\>getOidData('.1.3.6.1.4.1.119.2.3.69.501.2.1.9');

        // ... severity map, alert update, event log

    }

}

Severity mapping (IPE-COMMON-MIB SeverityValue):

| Integer | MIB Label | LibreNMS Severity | Action |
| :---- | :---- | :---- | :---- |
| 0 | invalid | — | Skip / ignore |
| 1 | indeterminate | ok | Log only |
| 2 | critical | critical | Raise alert |
| 3 | major | major | Raise alert |
| 4 | minor | minor | Raise alert |
| 5 | warning | warning | Raise alert |
| 6 | cleared | ok | Clear alert |

*The handler must also be registered in config/snmptrap.php under the correct trap OID key.*

## **4.7 Graph Definitions**

RSL graph (device\_nec\_ipasolink\_rsl) renders Min/Max received signal level per radio port over a rolling time window. It reads data from the RRD files populated by port polling (rxlevel). The RMON graph (device\_nec\_ipasolink\_rmon) renders total octets, broadcast/multicast frame counts, and error counters sourced from rmonGroup (.1.3.6.1.4.1.119.2.3.69.501.11.x).

# **5\. MIB OID Reference**

## **5.1 OID Tree Summary**

| Group | Base OID | Sub-components |
| :---- | :---- | :---- |
| Enterprise root (NEC) | .1.3.6.1.4.1.119 | — |
| radioEquipment | .1.3.6.1.4.1.119.2.3.69 | — |
| pasoNeoIpe-common (common MIB) | .1.3.6.1.4.1.119.2.3.69.501 | summaryGroup, trapGroup, alarmStatusGroup, ... |
| summaryGroup | .501.1 | alarmSummaryGroup |
| trapGroup | .501.2 | eventTotalCount, eventTime, alarmStateChange, ... |
| alarmStatusGroup | .501.3 | oduGroupTable, modemGroupTable, fanGroupTable, psGroupTable, ... |
| equipmentSetUpGroup | .501.4 | card setup, port config |
| inventoryGroup | .501.7 | invChassisInfoTable, invSlotInfoTable, invPortInfoTable |
| meteringGroup | .501.8 | meteringTable, meteringFanTable |
| pmonGroup | .501.9 | 15-min PMON counters |
| rmonGroup | .501.11 | RMON Ethernet stats |
| ipeCommunicationsGroup | .501.4.x | per-interface comm stats |
| ipeSystemGroup (System MIB) | .1.3.6.1.4.1.119.2.3.69.502 | ipeSysInfoTable, ipeSysEquipmentType, ipeSysNeName, ... |

## **5.2 Key Sensor OIDs (meteringTable)**

| Object Name | Full OID | Notes |
| :---- | :---- | :---- |
| metSysTxPowerValue | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.4.{idx} | tenths of dBm |
| metSysTxPowerStatus | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.5.{idx} | status valid flag |
| metSysRxLevelValue | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.6.{idx} | tenths of dBm |
| metSysRxLevelStatus | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.7.{idx} | status valid flag |
| metSysPSVoltageValue | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.8.{idx} | Volts |
| metSysTempODUValue | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.10.{idx} | Celsius |
| metSysTempIDUValue | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.11.{idx} | Celsius |
| metSysBitErrorRateValue | .1.3.6.1.4.1.119.2.3.69.501.8.1.1.14.{idx} | BER integer |
| meteringFanSpeed1 | .1.3.6.1.4.1.119.2.3.69.501.8.3.1.3.{idx} | RPM |
| meteringFanSpeed2 | .1.3.6.1.4.1.119.2.3.69.501.8.3.1.5.{idx} | RPM |
| meteringFanSpeed3 | .1.3.6.1.4.1.119.2.3.69.501.8.3.1.7.{idx} | RPM |

## **5.3 Trap Variable Bindings (alarmStateChange)**

| VarBind Name | OID | Usage in Handler |
| :---- | :---- | :---- |
| eventTotalCount | .501.2.1.1 | Sequence counter since agent boot |
| eventCount | .501.2.1.2 | Per-type event counter |
| eventTime | .501.2.1.3 | Local date/time of alarm |
| eventType | .501.2.1.4 | Alarm(1) or StatusChange(2) |
| eventResourceID | .501.2.1.5 | Affected resource (port/card) |
| eventSeverity | .501.2.1.6 | SeverityValue 0-6 |
| eventAlarmType | .501.2.1.7 | CCITT X.733 alarm type |
| eventProbableCause | .501.2.1.8 | Probable cause string |
| eventAdditionalText1 | .501.2.1.9 | Alarm description text |
| eventAdditionalText2..5 | .501.2.1.10–13 | Supplementary detail |

# **6\. Acceptance Criteria**

| ID | Criterion | Verification Method |
| :---- | :---- | :---- |
| AC-01 | A device with sysObjectID .1.3.6.1.4.1.119.2.3.69.502 is detected as nec-ipasolink. | Unit test \+ test device SNMP capture |
| AC-02 | Hardware, serial, version, and sysname are populated on discovery. | Discovery run against sample SNMP walk |
| AC-03 | Equipment type integer is correctly resolved to model name. | Unit test of type map |
| AC-04 | All meteringTable sensors are discovered and shown in the Sensors tab. | Live or mock SNMP discovery run |
| AC-05 | Sensors with value 'invalid' are not added to the database. | Discovery run with injected invalid values |
| AC-06 | Radio and Ethernet ports are correctly listed in the Ports tab with proper labels. | Discovery run against sample SNMP walk |
| AC-07 | TX/RX power values from meteringTable appear as port extra fields. | Polling run \+ database check |
| AC-08 | alarmStateChange trap is processed: alert is raised and eventAdditionalText is logged. | Simulated SNMP trap injection |
| AC-09 | Cleared alarm trap (severity=6) clears the corresponding LibreNMS alert. | Trap injection sequence test |
| AC-10 | RSL graph renders correctly with min/max lines in device overview. | Browser rendering check |
| AC-11 | RMON graph renders correctly with octets and error counters. | Browser rendering check |
| AC-12 | All PHP files pass php \-l syntax check and PSR-12 code style. | CI lint pipeline |
| AC-13 | ./lnms dev:test passes all existing and new test cases. | CI test run |
| AC-14 | ifIndex values decode to correct slot, port, and path labels per NWD-156232. | Unit test of NecIfIndexDecoder |
| AC-15 | Reserved bits or unknown slots fall back gracefully to opaque labels. | Unit test of NecIfIndexDecoder |

# **7\. Dependencies and Assumptions**

## **7.1 Dependencies**

* LibreNMS stable release 26.x branch

* PHP 8.3+ installed on the poller host

* Python 3.12+ (for any auxiliary helper scripts)

* net-snmp tools (snmpwalk, snmpget) available on the poller host

* MIB files placed in mibs/nec/ directory before the first discovery run

* Device accessible via SNMPv2c or SNMPv3 on UDP/161

* SNMP trap receiver configured on UDP/162 (for trap handling)

## **7.2 Assumptions**

* The provided IPE-COMMON-MIB, IPE-COMMON1000-MIB, and IPE-SYSTEM-MIB are complete and match the firmware deployed on target devices.

* Devices run firmware that responds to the meteringTable and inventoryGroup OIDs via SNMP GET/WALK.

* The sample\_snmp.txt SNMP walk provided confirms sysObjectID .1.3.6.1.4.1.119.2.3.69.502 and serves as the reference capture for test case development.

* Index alignment between meteringTable entries and IF-MIB ifIndex values is 1:1 for radio ports.

* The NEC icon (nec.png or nec.svg) is already present in html/images/os/ in the LibreNMS installation.

# **8\. Testing Strategy**

## **8.1 Unit Tests**

* NecIpasolinkTest.php — PHPUnit test for trap handler covering all 7 notification types

* Equipment type map test — verify all 20 equipment type codes map to correct product name

* Sensor skip\_values test — confirm 'invalid' string causes sensor to be excluded

## **8.2 Integration Tests**

* Record a full SNMP walk from a live iPASOLINK device (or use sample\_snmp.txt) and replay via LibreNMS's snmpsim test harness

* Verify discovery output: 1 device record, correct hardware/version/serial, all sensors, all ports

* Verify polling update: sensor values change on poll cycle when simulated values differ

## **8.3 Trap Tests**

* Inject synthetic alarmStateChange trap with severity=3 (major) — assert alert created

* Inject clearance trap with severity=6 — assert alert cleared

* Inject fileUpdateEvent — assert event log entry, no alert

## **8.4 Regression Tests**

* Run the LibreNMS full test suite (./lnms dev:test) with new files in place

* Verify no existing OS modules or MIB paths are broken by MIB file additions

# **9\. Revision History**

| Version | Date | Author | Changes |
| :---- | :---- | :---- | :---- |
| 1.0 | 2026-04-07 | Senior Developer | Initial draft based on MIB analysis and LibreNMS code structure review |
