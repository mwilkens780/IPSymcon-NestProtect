<?php

declare(strict_types=1);

/**
 * Holds the login for one Google/Nest account and polls Google's
 * undocumented Nest API (the same one the Home Assistant ha-nest-protect /
 * nest_legacy projects use) since Nest Protect is not exposed by Google's
 * official Smart Device Management API. NestProtect instances read the
 * cached device data from here via GetTopazBuckets() instead of each
 * authenticating and polling separately.
 */
class NestAccount extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('issue_token', '');
        $this->RegisterPropertyString('cookies', '');
        $this->RegisterPropertyInteger('update_interval', 300);

        $this->RegisterAttributeString('NestAccessToken', '');
        $this->RegisterAttributeString('NestUserId', '');
        $this->RegisterAttributeInteger('NestExpiresAt', 0);
        $this->RegisterAttributeString('TopazBucketsJson', '[]');

        $this->RegisterTimer('UpdateTimer', 0, 'NEST_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyString('issue_token') === '' || $this->ReadPropertyString('cookies') === '') {
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
            if ($this->ensureSession()) {
                $this->fetchBuckets();
                $this->SetStatus(102);
            }
        } catch (\Throwable $e) {
            $this->LogMessage('NestAccount Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    /** JSON array of the current Nest Protect ("topaz" bucket) values, for NestProtect instances to read. */
    public function GetTopazBuckets(): string
    {
        return $this->ReadAttributeString('TopazBucketsJson') ?: '[]';
    }

    /** Forces a fresh login+poll and returns a human-readable summary, for the "Geräte auflisten" config button. */
    public function TestConnection(): string
    {
        $this->WriteAttributeInteger('NestExpiresAt', 0); // force a fresh handshake, not a cached one
        if (!$this->ensureSession()) {
            return $this->Translate('Anmeldung fehlgeschlagen -- Details im IPS-Log. issue_token/cookies vermutlich abgelaufen.');
        }
        if (!$this->fetchBuckets()) {
            return $this->Translate('Anmeldung erfolgreich, aber Geräteabfrage fehlgeschlagen -- Details im IPS-Log.');
        }

        $devices = json_decode($this->ReadAttributeString('TopazBucketsJson'), true) ?: [];
        if (count($devices) === 0) {
            return $this->Translate('Verbunden, aber keine Nest Protect Geräte in diesem Konto gefunden.');
        }

        $lines = [$this->Translate('Gefundene Geräte (Seriennummer für die NestProtect-Instanz):')];
        foreach ($devices as $d) {
            $lines[] = '- ' . ($d['serial_number'] ?? '?') . ' (' . ($d['model'] ?? 'Nest Protect') . ')';
        }
        return implode("\n", $lines);
    }

    /** Reuses the cached Nest session (access_token/userid) while valid, otherwise redoes the full Google/Nest handshake. */
    private function ensureSession(): bool
    {
        $expiresAt = $this->ReadAttributeInteger('NestExpiresAt');
        if ($expiresAt > time() + 60 && $this->ReadAttributeString('NestAccessToken') !== '') {
            return true;
        }
        return $this->authenticate();
    }

    /**
     * Three-step handshake matching the undocumented Nest web-app login:
     * 1) exchange the account's cookies for a short-lived Google OAuth token,
     * 2) exchange that for a Nest JWT,
     * 3) exchange the JWT for a Nest session (access_token + userid), which
     * is what actually authorizes the device-data endpoint.
     */
    private function authenticate(): bool
    {
        $issueToken = $this->ReadPropertyString('issue_token');
        $cookies    = $this->ReadPropertyString('cookies');
        if ($issueToken === '' || $cookies === '') {
            $this->SetStatus(201);
            return false;
        }

        $resp = $this->httpRequest($issueToken, 'GET', [
            'Sec-Fetch-Mode: cors',
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/77.0.3865.120 Safari/537.36',
            'X-Requested-With: XmlHttpRequest',
            'Referer: https://accounts.google.com/o/oauth2/iframe',
            'Cookie: ' . $cookies,
        ]);
        $google = json_decode($resp['body'], true);
        if ($resp['status'] !== 200 || !isset($google['access_token'])) {
            $this->LogMessage('NestAccount: Google-Anmeldung fehlgeschlagen (HTTP ' . $resp['status'] . ') -- issue_token/cookies sind vermutlich abgelaufen, bitte im Browser neu aus home.nest.com holen.', KL_ERROR);
            $this->SetStatus(201);
            return false;
        }
        $googleAccessToken = $google['access_token'];

        $resp = $this->httpRequest('https://nestauthproxyservice-pa.googleapis.com/v1/issue_jwt', 'POST', [
            'Authorization: Bearer ' . $googleAccessToken,
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'embed_google_oauth_access_token' => 'true',
            'expire_after'                    => '3600s',
            'google_oauth_access_token'       => $googleAccessToken,
            'policy_id'                       => 'authproxy-oauth-policy',
        ]));
        $jwtData = json_decode($resp['body'], true);
        if ($resp['status'] !== 200 || !isset($jwtData['jwt'])) {
            $this->LogMessage('NestAccount: Nest-JWT-Anfrage fehlgeschlagen (HTTP ' . $resp['status'] . ')', KL_ERROR);
            $this->SetStatus(201);
            return false;
        }

        $resp = $this->httpRequest('https://home.nest.com/session', 'GET', [
            'Authorization: Basic ' . $jwtData['jwt'],
        ]);
        $session = json_decode($resp['body'], true);
        if ($resp['status'] !== 200 || !isset($session['access_token'], $session['userid'])) {
            $this->LogMessage('NestAccount: Nest-Session-Anfrage fehlgeschlagen (HTTP ' . $resp['status'] . ')', KL_ERROR);
            $this->SetStatus(201);
            return false;
        }

        $this->WriteAttributeString('NestAccessToken', (string) $session['access_token']);
        $this->WriteAttributeString('NestUserId', (string) $session['userid']);

        // Nest's own "expires_in" here is an absolute expiry date string, not
        // a duration -- fall back to a conservative ~55 minutes if it can't
        // be parsed, matching the 3600s lifetime requested from the JWT step.
        $expiresAt = 0;
        if (isset($session['expires_in'])) {
            $parsed = strtotime((string) $session['expires_in']);
            if ($parsed !== false) {
                $expiresAt = $parsed;
            }
        }
        if ($expiresAt <= 0) {
            $expiresAt = time() + 3300;
        }
        $this->WriteAttributeInteger('NestExpiresAt', $expiresAt);

        return true;
    }

    /** Polls the current device data and caches the Nest Protect ("topaz.*") buckets. */
    private function fetchBuckets(): bool
    {
        $accessToken = $this->ReadAttributeString('NestAccessToken');
        $userId      = $this->ReadAttributeString('NestUserId');
        if ($accessToken === '' || $userId === '') {
            return false;
        }

        $body = json_encode([
            'known_bucket_types'    => ['kryptonite', 'structure', 'topaz', 'where', 'user'],
            'known_bucket_versions' => [],
        ]);

        $resp = $this->httpRequest(
            'https://home.nest.com/api/0.1/user/' . rawurlencode($userId) . '/app_launch',
            'POST',
            [
                'Authorization: Basic ' . $accessToken,
                'X-nl-user-id: ' . $userId,
                'X-nl-protocol-version: 1',
                'Content-Type: application/json',
            ],
            (string) $body
        );

        if ($resp['status'] === 401 || $resp['status'] === 403) {
            // Session died mid-cycle (e.g. revoked) -- force a fresh handshake next time instead of retrying the same dead token.
            $this->WriteAttributeInteger('NestExpiresAt', 0);
            $this->LogMessage('NestAccount: Sitzung abgelaufen, erneuere beim nächsten Zyklus', KL_WARNING);
            return false;
        }

        $data = json_decode($resp['body'], true);
        if ($resp['status'] !== 200 || !isset($data['updated_buckets'])) {
            $this->LogMessage('NestAccount: Geräteabfrage fehlgeschlagen (HTTP ' . $resp['status'] . ')', KL_ERROR);
            return false;
        }

        $topaz = [];
        foreach ($data['updated_buckets'] as $bucket) {
            $key = (string) ($bucket['object_key'] ?? '');
            if (strpos($key, 'topaz.') === 0 && isset($bucket['value']) && is_array($bucket['value'])) {
                $value = $bucket['value'];
                if (!isset($value['serial_number'])) {
                    $value['serial_number'] = substr($key, strlen('topaz.'));
                }
                $topaz[] = $value;
            }
        }

        $this->WriteAttributeString('TopazBucketsJson', json_encode($topaz));
        return true;
    }

    /** @return array{status:int,body:string} */
    private function httpRequest(string $url, string $method, array $headers, ?string $body = null): array
    {
        $options = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $context = stream_context_create($options);
        $result  = @file_get_contents($url, false, $context);

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return ['status' => $status, 'body' => $result === false ? '' : $result];
    }
}
