<?php

declare(strict_types=1);

/**
 * One physical Nest Protect smoke/CO detector. Owns no connection of its
 * own -- reads the shared account instance's cached device data
 * (NEST_GetTopazBuckets) and picks out its own serial number, matching
 * the pattern in [[project_room_dashboard]] of deriving state from an
 * already-fetched source instead of every instance polling separately.
 * Read-only: Nest Protect has no remotely controllable actions.
 */
class NestProtect extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('account_instance', 0);
        $this->RegisterPropertyString('serial_number', '');
        $this->RegisterPropertyInteger('update_interval', 60);

        $this->RegisterVariableBoolean('Smoke', $this->Translate('Rauch'), '~Alert', 1);
        $this->RegisterVariableBoolean('CO', $this->Translate('Kohlenmonoxid'), '~Alert', 2);
        $this->RegisterVariableBoolean('Heat', $this->Translate('Hitze'), '~Alert', 3);
        $this->RegisterVariableInteger('Battery', $this->Translate('Batterie'), '~Battery.100', 4);
        $this->RegisterVariableBoolean('Wired', $this->Translate('Netzstrom'), '~Switch', 5);
        $this->RegisterVariableBoolean('Hushed', $this->Translate('Stummgeschaltet'), '', 6);
        $this->RegisterVariableInteger('LastTest', $this->Translate('Letzter Selbsttest'), '~UnixTimestamp', 7);
        $this->RegisterVariableInteger('ReplaceBy', $this->Translate('Austausch fällig'), '~UnixTimestamp', 8);

        $this->RegisterTimer('UpdateTimer', 0, 'NEST_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $accountId = $this->ReadPropertyInteger('account_instance');
        $serial    = $this->ReadPropertyString('serial_number');
        if ($accountId <= 0 || $serial === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->Refresh();
    }

    public function Refresh(): void
    {
        try {
            $accountId = $this->ReadPropertyInteger('account_instance');
            $serial    = $this->ReadPropertyString('serial_number');
            if ($accountId <= 0 || $serial === '' || !@IPS_InstanceExists($accountId)) {
                $this->SetStatus(201);
                return;
            }

            $devices = json_decode(NEST_GetTopazBuckets($accountId), true) ?: [];
            $device  = null;
            foreach ($devices as $d) {
                if (($d['serial_number'] ?? '') === $serial) {
                    $device = $d;
                    break;
                }
            }

            if ($device === null) {
                $this->LogMessage('NestProtect: Seriennummer ' . $serial . ' nicht in den Kontodaten gefunden -- Konto-Instanz noch nicht aktualisiert oder falsche Seriennummer?', KL_WARNING);
                $this->SetStatus(104);
                return;
            }

            $this->SetValue('Smoke', (int) ($device['smoke_status'] ?? 0) !== 0);
            $this->SetValue('CO', (int) ($device['co_status'] ?? 0) !== 0);
            $this->SetValue('Heat', (int) ($device['heat_status'] ?? 0) !== 0);
            $this->SetValue('Wired', (bool) ($device['line_power_present'] ?? false));
            $this->SetValue('Hushed', (bool) ($device['hushed_state'] ?? false));

            if (isset($device['battery_level'])) {
                $pct = $this->batteryPercent((int) $device['battery_level']);
                if ($pct !== null) {
                    $this->SetValue('Battery', $pct);
                }
            }
            if (isset($device['latest_manual_test_end_utc_secs'])) {
                $this->SetValue('LastTest', (int) $device['latest_manual_test_end_utc_secs']);
            }
            if (isset($device['replace_by_date_utc_secs'])) {
                $this->SetValue('ReplaceBy', (int) $device['replace_by_date_utc_secs']);
            }

            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('NestProtect Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    /**
     * Nest Protect reports raw battery millivolts, not a percentage. This
     * piecewise approximation (matching the one the ha-nest-protect project
     * derived from the L91 cell datasheet) is the only known way to turn it
     * into something readable; out-of-range readings return null so a bad
     * sample doesn't overwrite the last good value with a misleading 0%.
     */
    private function batteryPercent(int $milliVolts): ?int
    {
        if ($milliVolts <= 3000 || $milliVolts > 6000) {
            return null;
        }
        if ($milliVolts > 4950) {
            $slope = 0.001816609;
            $yint  = -8.548096886;
        } elseif ($milliVolts > 4800) {
            $slope = 0.000291667;
            $yint  = -0.991176471;
        } elseif ($milliVolts > 4500) {
            $slope = 0.001077342;
            $yint  = -4.730392157;
        } else {
            $slope = 0.000434641;
            $yint  = -1.825490196;
        }
        $pct = (int) round((($slope * $milliVolts) + $yint) * 100);
        return max(0, min(100, $pct));
    }
}
