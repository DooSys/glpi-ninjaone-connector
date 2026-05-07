<?php

namespace GlpiPlugin\Ninjaone\Client;

final class NinjaOneClient
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret = '',
        private readonly string $scopes = 'monitoring',
        ?string $accessToken = null,
        private ?string $refreshToken = null,
        private readonly ?string $redirectUri = null
    ) {
        $this->accessToken = $accessToken;
    }

    public function authMode(): string
    {
        if ($this->clientSecret !== '') {
            return 'client_credentials';
        }

        if ($this->refreshToken !== null && $this->refreshToken !== '') {
            return 'refresh_token';
        }

        if ($this->accessToken !== null && $this->accessToken !== '') {
            return 'access_token';
        }

        return 'not_configured';
    }

    public function listOrganizationsDetailed(): array
    {
        return $this->getPaginated('/api/v2/organizations-detailed');
    }

    public function listOrganizations(): array
    {
        return $this->getPaginated('/api/v2/organizations');
    }

    public function listDevicesDetailed(?string $deviceFilter = null): array
    {
        $query = [];
        if ($deviceFilter !== null && $deviceFilter !== '') {
            $query['df'] = $deviceFilter;
        }

        return $this->getPaginated('/api/v2/devices-detailed', 1000, $query);
    }

    public function getDevice(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d', $deviceId));
    }

    public function listDeviceJobs(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/jobs', $deviceId));
    }

    public function listDeviceActivities(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/activities', $deviceId));
    }

    public function listDeviceAlerts(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/alerts', $deviceId));
    }

    public function listDeviceDisks(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/disks', $deviceId));
    }

    public function listDeviceOsPatchInstalls(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/os-patch-installs', $deviceId));
    }

    public function listDeviceSoftwarePatchInstalls(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/software-patch-installs', $deviceId));
    }

    public function getDeviceLastLoggedOnUser(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/last-logged-on-user', $deviceId));
    }

    public function listDeviceNetworkInterfaces(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/network-interfaces', $deviceId));
    }

    public function listDeviceOsPatches(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/os-patches', $deviceId));
    }

    public function listDeviceSoftwarePatches(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/software-patches', $deviceId));
    }

    public function listDeviceProcessors(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/processors', $deviceId));
    }

    public function listDeviceWindowsServices(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/windows-services', $deviceId));
    }

    public function listDeviceSoftware(int $deviceId): array
    {
        return $this->getPaginated(sprintf('/api/v2/device/%d/software', $deviceId));
    }

    public function listDeviceVolumes(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/volumes', $deviceId));
    }

    public function listDeviceCustomFields(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/custom-fields', $deviceId));
    }

    public function getDevicePolicyOverrides(int $deviceId): array
    {
        return $this->request('GET', sprintf('/api/v2/device/%d/policy/overrides', $deviceId));
    }

    public function listQuery(string $queryName, array $query = []): array
    {
        return $this->getCursorPaginated('/api/v2/queries/' . ltrim($queryName, '/'), 1000, $query);
    }

    public function listQueryForDeviceFilter(string $queryName, string $deviceFilter): array
    {
        return $this->listQuery($queryName, ['df' => $deviceFilter]);
    }

    public function testConnection(): array
    {
        $token = $this->accessToken = $this->fetchAccessToken();
        $organizations = $this->request('GET', '/api/v2/organizations', ['pageSize' => 1]);

        return [
            'token_received'      => $token !== '',
            'organization_sample' => count($organizations),
        ];
    }

    public function getPaginated(string $path, int $pageSize = 1000, array $baseQuery = []): array
    {
        $items = [];
        $after = null;

        do {
            $query = $baseQuery + ['pageSize' => $pageSize];
            if ($after !== null) {
                $query['after'] = $after;
            }

            $page = $this->request('GET', $path, $query);
            if (!is_array($page) || $page === []) {
                break;
            }

            $items = array_merge($items, $page);
            $last = end($page);
            $after = is_array($last) && isset($last['id']) ? $last['id'] : null;
        } while ($after !== null && count($page) === $pageSize);

        return $items;
    }

    public function getCursorPaginated(string $path, int $pageSize = 1000, array $baseQuery = []): array
    {
        $items = [];
        $cursor = null;

        do {
            $query = $baseQuery + ['pageSize' => $pageSize];
            if ($cursor !== null && $cursor !== '') {
                $query['cursor'] = $cursor;
            }

            $page = $this->request('GET', $path, $query);
            $pageItems = $this->extractPageItems($page);
            if ($pageItems === []) {
                break;
            }

            $items = array_merge($items, $pageItems);
            $cursor = $this->extractNextCursor($page);
        } while ($cursor !== null && $cursor !== '');

        return $items;
    }

    private function extractPageItems(array $page): array
    {
        foreach (['results', 'data', 'items', 'rows'] as $key) {
            if (isset($page[$key]) && is_array($page[$key])) {
                return $page[$key];
            }
        }

        if (array_is_list($page)) {
            return $page;
        }

        return [];
    }

    private function extractNextCursor(array $page): ?string
    {
        foreach (['cursor', 'nextCursor', 'next_cursor', 'after'] as $key) {
            if (isset($page[$key]) && is_scalar($page[$key]) && (string) $page[$key] !== '') {
                return (string) $page[$key];
            }
        }

        return null;
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if ($this->accessToken === null) {
            $this->accessToken = $this->fetchAccessToken();
        }

        $url = $this->buildUrl($path, $query);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $response = $this->send($method, $url, $headers, $body !== null ? json_encode($body) : null);

        if ($response['status'] === 401 && $this->clientSecret === '' && $this->refreshToken !== null && $this->refreshToken !== '') {
            $tokens = $this->refreshAccessToken();
            $headers[1] = 'Authorization: Bearer ' . $tokens['access_token'];
            $response = $this->send($method, $url, $headers, $body !== null ? json_encode($body) : null);
        }

        if ($response['status'] >= 400) {
            throw new \RuntimeException(sprintf(
                'NinjaOne API request failed with HTTP %d: %s',
                $response['status'],
                $response['body']
            ));
        }

        if ($response['body'] === '') {
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('NinjaOne API returned an invalid JSON response.');
        }

        return $decoded;
    }

    public function fetchAccessToken(): string
    {
        if ($this->clientId === '') {
            throw new \RuntimeException('NinjaOne client_id is required.');
        }

        if ($this->clientSecret !== '') {
            return $this->fetchClientCredentialsAccessToken();
        }

        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        if ($this->refreshToken !== null && $this->refreshToken !== '') {
            return $this->refreshAccessToken()['access_token'];
        }

        if ($this->clientSecret === '') {
            throw new \RuntimeException('NinjaOne is not authorized yet. Connect NinjaOne first to store a refresh token.');
        }
    }

    private function fetchClientCredentialsAccessToken(): string
    {
        $url = $this->buildUrl('/ws/oauth/token');
        $payload = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => $this->scopes,
        ]);

        $response = $this->send('POST', $url, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], $payload);

        if ($response['status'] >= 400) {
            throw new \RuntimeException(sprintf(
                'NinjaOne OAuth token request failed with HTTP %d: %s',
                $response['status'],
                $response['body']
            ));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            throw new \RuntimeException('NinjaOne OAuth token response does not contain an access_token.');
        }

        return (string) $decoded['access_token'];
    }

    public function buildAuthorizationUrl(string $redirectUri, string $state): string
    {
        return $this->buildUrl('/ws/oauth/authorize', [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => $this->scopes,
            'state'         => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        $payload = [
            'grant_type'   => 'authorization_code',
            'client_id'    => $this->clientId,
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($this->clientSecret !== '') {
            $payload['client_secret'] = $this->clientSecret;
        }

        return $this->tokenRequest($payload);
    }

    public function refreshAccessToken(): array
    {
        if ($this->refreshToken === null || $this->refreshToken === '') {
            throw new \RuntimeException('NinjaOne refresh token is missing. Reconnect NinjaOne.');
        }

        $payload = [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'refresh_token' => $this->refreshToken,
        ];

        if ($this->clientSecret !== '') {
            $payload['client_secret'] = $this->clientSecret;
        }

        return $this->tokenRequest($payload);
    }

    private function tokenRequest(array $payload): array
    {
        $response = $this->send('POST', $this->buildUrl('/ws/oauth/token'), [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($payload));

        if ($response['status'] >= 400) {
            throw new \RuntimeException(sprintf(
                'NinjaOne OAuth token request failed with HTTP %d: %s',
                $response['status'],
                $response['body']
            ));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            throw new \RuntimeException('NinjaOne OAuth token response does not contain an access_token.');
        }

        if (!empty($decoded['refresh_token'])) {
            $this->refreshToken = (string) $decoded['refresh_token'];
        }
        $this->accessToken = (string) $decoded['access_token'];

        return $decoded;
    }

    private function buildUrl(string $path, array $query = []): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    private function send(string $method, string $url, array $headers, ?string $payload = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('NinjaOne HTTP request failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => (string) $body,
        ];
    }
}
