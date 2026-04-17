<?php
/**
 * NecIpasolink.php
 *
 * SNMP Trap Handler for NEC iPasoLink microwave radio equipment.
 *
 * Handles all notification types from IPE-COMMON-MIB:
 *   - alarmStateChange      (.501.2.x.1)  — Map severity, create/clear alert
 *   - statusChange          (.501.2.x.2)  — Log status change event
 *   - statusChangeDspStr    (.501.2.x.3)  — Log status change with display string
 *   - statusChangeUnsigned32(.501.2.x.5)  — Log numeric status change
 *   - controlEvent          (.501.2.x.6)  — Log control/provisioning event
 *   - fileDownloadEvent     (.501.2.x.10) — Log firmware download event
 *   - fileUpdateEvent       (.501.2.x.11) — Log config update event
 *
 * Severity mapping (IPE-COMMON-MIB SeverityValue):
 *   0 = invalid       → skip/ignore
 *   1 = indeterminate  → ok (log only)
 *   2 = critical       → critical (raise alert)
 *   3 = major          → major (raise alert)
 *   4 = minor          → minor (raise alert)
 *   5 = warning        → warning (raise alert)
 *   6 = cleared        → ok (clear alert)
 *
 * Trap variable bindings (alarmStateChange):
 *   eventTotalCount      (.501.2.1.1)   — Sequence counter since agent boot
 *   eventCount           (.501.2.1.2)   — Per-type event counter
 *   eventTime            (.501.2.1.3)   — Local date/time of alarm
 *   eventType            (.501.2.1.4)   — Alarm(1) or StatusChange(2)
 *   eventResourceID      (.501.2.1.5)   — Affected resource (port/card)
 *   eventSeverity        (.501.2.1.6)   — SeverityValue 0-6
 *   eventAlarmType       (.501.2.1.7)   — CCITT X.733 alarm type
 *   eventProbableCause   (.501.2.1.8)   — Probable cause string
 *   eventAdditionalText1 (.501.2.1.9)   — Alarm description text
 *   eventAdditionalText2 (.501.2.1.10)  — Supplementary detail
 *   eventAdditionalText3 (.501.2.1.11)  — Supplementary detail
 *   eventAdditionalText4 (.501.2.1.12)  — Supplementary detail
 *   eventAdditionalText5 (.501.2.1.13)  — Supplementary detail
 *
 * @category   Snmptrap
 * @package    LibreNMS\Snmptrap\Handlers
 */

namespace LibreNMS\Snmptrap\Handlers;

use App\Models\Device;
use LibreNMS\Enum\Severity;
use LibreNMS\Interfaces\SnmptrapHandler;
use LibreNMS\Snmptrap\Trap;
use Log;

class NecIpasolink implements SnmptrapHandler
{
    /** Base OID for IPE-COMMON-MIB trap variable bindings */
    private const TRAP_VARBIND_BASE = '.1.3.6.1.4.1.119.2.3.69.501.2.1';

    /** Base OID for IPE-COMMON-MIB notification types */
    private const NOTIFICATION_BASE = '.1.3.6.1.4.1.119.2.3.69.501.2';

    /**
     * Map IPE-COMMON-MIB SeverityValue to LibreNMS severity.
     *
     * @var array<int, Severity>
     */
    private const SEVERITY_MAP = [
        0 => Severity::Ok,            // invalid — skip
        1 => Severity::Ok,            // indeterminate — log only
        2 => Severity::Error,         // critical
        3 => Severity::Error,         // major
        4 => Severity::Warning,       // minor
        5 => Severity::Warning,       // warning
        6 => Severity::Ok,            // cleared
    ];

    /**
     * Human-readable labels for severity values.
     *
     * @var array<int, string>
     */
    private const SEVERITY_LABELS = [
        0 => 'invalid',
        1 => 'indeterminate',
        2 => 'critical',
        3 => 'major',
        4 => 'minor',
        5 => 'warning',
        6 => 'cleared',
    ];

    /**
     * Handle an incoming SNMP trap from NEC iPasoLink.
     *
     * @param  Device  $device  The device that sent the trap
     * @param  Trap    $trap    The parsed trap data
     * @return void
     */
    public function handle(Device $device, Trap $trap): void
    {
        $trapOid = $trap->getTrapOid();

        // Determine which notification type this is
        $handler = match (true) {
            str_contains($trapOid, '.501.2') && str_ends_with($trapOid, '.1')
                => 'handleAlarmStateChange',
            str_contains($trapOid, '.501.2') && str_ends_with($trapOid, '.2')
                => 'handleStatusChange',
            str_contains($trapOid, '.501.2') && str_ends_with($trapOid, '.3')
                => 'handleStatusChangeDspStr',
            str_contains($trapOid, '.501.2') && str_ends_with($trapOid, '.5')
                => 'handleStatusChangeUnsigned32',
            str_contains($trapOid, '.501.2') && str_ends_with($trapOid, '.6')
                => 'handleControlEvent',
            str_contains($trapOid, '.501.2') && str_ends_with($trapOid, '.10')
                => 'handleFileDownloadEvent',
            str_contains($trapOid, '.501.2') && str_ends_with($trapOid, '.11')
                => 'handleFileUpdateEvent',
            default => 'handleGenericEvent',
        };

        $this->$handler($device, $trap);
    }

    /**
     * Handle alarmStateChange notification.
     * Maps severity, creates or clears LibreNMS alerts.
     */
    private function handleAlarmStateChange(Device $device, Trap $trap): void
    {
        $severity    = (int) $trap->getOidData(self::TRAP_VARBIND_BASE . '.6');
        $resourceId  = $trap->getOidData(self::TRAP_VARBIND_BASE . '.5');
        $alarmType   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.7');
        $probCause   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.8');
        $eventTime   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');

        // Build alarm message from eventAdditionalText1 through eventAdditionalText5
        $messageParts = [];
        for ($i = 9; $i <= 13; $i++) {
            $text = $trap->getOidData(self::TRAP_VARBIND_BASE . ".{$i}");
            if (! empty($text) && $text !== '""' && $text !== '-') {
                $messageParts[] = trim($text, '"');
            }
        }

        $message = implode(' | ', $messageParts) ?: 'No additional text';
        $severityLabel = self::SEVERITY_LABELS[$severity] ?? 'unknown';
        $librenmsState = self::SEVERITY_MAP[$severity] ?? Severity::Warning;

        // Skip invalid severity (0)
        if ($severity === 0) {
            Log::debug("iPasoLink trap: Ignoring alarm with invalid severity (0) from {$device->hostname}");
            return;
        }

        $logMessage = "iPasoLink Alarm [{$severityLabel}] Resource: {$resourceId} "
            . "Type: {$alarmType} Cause: {$probCause} — {$message}";

        // For cleared severity (6), clear the alert
        if ($severity === 6) {
            $trap->log(
                $logMessage,
                $librenmsState,
                'alarm',
                $eventTime
            );

            Log::info("iPasoLink: Alarm cleared on {$device->hostname}, resource: {$resourceId}");

            return;
        }

        // For active alarms (severity 1-5), raise alert and log
        $trap->log(
            $logMessage,
            $librenmsState,
            'alarm',
            $eventTime
        );

        Log::info("iPasoLink: Alarm [{$severityLabel}] on {$device->hostname}, resource: {$resourceId}");
    }

    /**
     * Handle statusChange notification.
     */
    private function handleStatusChange(Device $device, Trap $trap): void
    {
        $resourceId = $trap->getOidData(self::TRAP_VARBIND_BASE . '.5');
        $eventTime  = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');
        $addText1   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.9');

        $message = "iPasoLink Status Change — Resource: {$resourceId}";
        if (! empty($addText1)) {
            $message .= " — " . trim($addText1, '"');
        }

        $trap->log($message, Severity::Notice, 'status', $eventTime);
    }

    /**
     * Handle statusChangeDspStr notification (with display string).
     */
    private function handleStatusChangeDspStr(Device $device, Trap $trap): void
    {
        $resourceId = $trap->getOidData(self::TRAP_VARBIND_BASE . '.5');
        $eventTime  = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');

        $displayParts = [];
        for ($i = 9; $i <= 13; $i++) {
            $text = $trap->getOidData(self::TRAP_VARBIND_BASE . ".{$i}");
            if (! empty($text) && $text !== '""') {
                $displayParts[] = trim($text, '"');
            }
        }

        $display = implode(' ', $displayParts) ?: 'No display text';
        $message = "iPasoLink Status Change (display) — Resource: {$resourceId} — {$display}";

        $trap->log($message, Severity::Notice, 'status', $eventTime);
    }

    /**
     * Handle statusChangeUnsigned32 notification (numeric status change).
     */
    private function handleStatusChangeUnsigned32(Device $device, Trap $trap): void
    {
        $resourceId = $trap->getOidData(self::TRAP_VARBIND_BASE . '.5');
        $eventTime  = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');
        $addText1   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.9');

        $message = "iPasoLink Numeric Status Change — Resource: {$resourceId}";
        if (! empty($addText1)) {
            $message .= " — Value: " . trim($addText1, '"');
        }

        $trap->log($message, Severity::Notice, 'status', $eventTime);
    }

    /**
     * Handle controlEvent notification (control/provisioning event).
     */
    private function handleControlEvent(Device $device, Trap $trap): void
    {
        $resourceId = $trap->getOidData(self::TRAP_VARBIND_BASE . '.5');
        $eventTime  = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');
        $addText1   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.9');

        $message = "iPasoLink Control Event — Resource: {$resourceId}";
        if (! empty($addText1)) {
            $message .= " — " . trim($addText1, '"');
        }

        $trap->log($message, Severity::Notice, 'control', $eventTime);
    }

    /**
     * Handle fileDownloadEvent notification (firmware download event).
     */
    private function handleFileDownloadEvent(Device $device, Trap $trap): void
    {
        $resourceId = $trap->getOidData(self::TRAP_VARBIND_BASE . '.5');
        $eventTime  = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');
        $addText1   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.9');

        $message = "iPasoLink Firmware Download — Resource: {$resourceId}";
        if (! empty($addText1)) {
            $message .= " — " . trim($addText1, '"');
        }

        $trap->log($message, Severity::Notice, 'firmware', $eventTime);
    }

    /**
     * Handle fileUpdateEvent notification (config update event).
     */
    private function handleFileUpdateEvent(Device $device, Trap $trap): void
    {
        $resourceId = $trap->getOidData(self::TRAP_VARBIND_BASE . '.5');
        $eventTime  = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');
        $addText1   = $trap->getOidData(self::TRAP_VARBIND_BASE . '.9');

        $message = "iPasoLink Config Update — Resource: {$resourceId}";
        if (! empty($addText1)) {
            $message .= " — " . trim($addText1, '"');
        }

        $trap->log($message, Severity::Notice, 'config', $eventTime);
    }

    /**
     * Handle generic/unknown trap type as a fallback.
     */
    private function handleGenericEvent(Device $device, Trap $trap): void
    {
        $eventTime = $trap->getOidData(self::TRAP_VARBIND_BASE . '.3');
        $message = "iPasoLink Unhandled Trap — OID: {$trap->getTrapOid()}";

        $trap->log($message, Severity::Notice, 'generic', $eventTime);
    }
}
