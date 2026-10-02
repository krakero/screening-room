<?php

namespace App\Services\Plex;

use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Sign in with Plex" (PIN flow), server discovery, and connection selection against plex.tv —
 * kept separate from PlexClient, which only ever talks to the already-selected local server.
 */
class PlexDiscovery
{
    private const PLEX_TV = 'https://plex.tv';

    private const REQUEST_TIMEOUT_SECONDS = 5;

    private const CONNECTION_TEST_TIMEOUT_SECONDS = 3;

    private const RESELECT_COOLDOWN_MINUTES = 10;

    public function __construct(
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * A stable per-install id Plex requires on every request; generated once and reused.
     */
    public function clientIdentifier(): string
    {
        $identifier = $this->settings->get('plex.client_identifier');

        if (is_string($identifier) && $identifier !== '') {
            return $identifier;
        }

        $identifier = (string) Str::uuid();
        $this->settings->set('plex.client_identifier', $identifier);

        return $identifier;
    }

    /**
     * Start a "Sign in with Plex" PIN. The caller opens `auth_url` in a new tab and polls
     * `checkPin()` with the returned id until it resolves.
     *
     * @return array{id: int, code: string, auth_url: string}
     */
    public function createPin(): array
    {
        $response = Http::baseUrl(self::PLEX_TV)
            ->withHeaders($this->clientHeaders())
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->post('/api/v2/pins', ['strong' => 'true']);

        $id = (int) $response->json('id', 0);
        $code = (string) $response->json('code', '');

        if ($id === 0 || $code === '') {
            throw new PlexException('Plex did not return a sign-in PIN.');
        }

        return [
            'id' => $id,
            'code' => $code,
            'auth_url' => $this->authUrl($code),
        ];
    }

    /**
     * Poll a pin created by `createPin()`. Returns the account auth token once the user finishes
     * signing in at `auth_url`, or null while still pending.
     */
    public function checkPin(int $id): ?string
    {
        $response = Http::baseUrl(self::PLEX_TV)
            ->withHeaders($this->clientHeaders())
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->get("/api/v2/pins/{$id}");

        $token = $response->json('authToken');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The signed-in account's identity: stable id/uuid, username, and email. Kept generic (not
     * settings-page specific) so PLEX-A1 can reuse it for app login/the API, not just "Connected
     * as …" display text.
     *
     * @return array{id: int, uuid: string, username: string, email: string}|null
     */
    public function account(string $token): ?array
    {
        try {
            $response = Http::baseUrl(self::PLEX_TV)
                ->withHeaders($this->clientHeaders($token))
                ->acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get('/api/v2/user');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $id = (int) $response->json('id', 0);
        $uuid = (string) ($response->json('uuid') ?? '');

        if ($id === 0 && $uuid === '') {
            return null;
        }

        return [
            'id' => $id,
            'uuid' => $uuid,
            'username' => (string) ($response->json('username') ?? ''),
            'email' => (string) ($response->json('email') ?? ''),
        ];
    }

    /**
     * The signed-in account's display name, for showing "Connected as …" in settings.
     */
    public function accountName(string $token): ?string
    {
        $account = $this->account($token);

        if ($account === null) {
            return null;
        }

        $name = filled($account['username']) ? $account['username'] : $account['email'];

        return filled($name) ? $name : null;
    }

    /**
     * Owned + shared servers this account can reach, with their connection options, from
     * plex.tv's resource discovery.
     *
     * @return array<int, array{machine_identifier: string, name: string, owned: bool, presence: bool, access_token: string, connections: array<int, array{uri: string, local: bool, relay: bool, protocol: string}>}>
     */
    public function servers(string $token): array
    {
        $response = Http::baseUrl(self::PLEX_TV)
            ->withHeaders($this->clientHeaders($token))
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->get('/api/v2/resources', ['includeHttps' => 1, 'includeRelay' => 1]);

        $servers = [];

        foreach ((array) $response->json() as $resource) {
            $provides = explode(',', (string) ($resource['provides'] ?? ''));

            if (! in_array('server', $provides, true)) {
                continue;
            }

            $connections = [];

            foreach ($resource['connections'] ?? [] as $connection) {
                $connections[] = [
                    'uri' => (string) ($connection['uri'] ?? ''),
                    'local' => (bool) ($connection['local'] ?? false),
                    'relay' => (bool) ($connection['relay'] ?? false),
                    'protocol' => (string) ($connection['protocol'] ?? 'http'),
                ];
            }

            $servers[] = [
                'machine_identifier' => (string) ($resource['clientIdentifier'] ?? ''),
                'name' => (string) ($resource['name'] ?? ''),
                'owned' => (bool) ($resource['owned'] ?? false),
                'presence' => (bool) ($resource['presence'] ?? true),
                'access_token' => (string) ($resource['accessToken'] ?? $token),
                'connections' => $connections,
            ];
        }

        return $servers;
    }

    /**
     * Try a server's connections in preference order (local https plex.direct, local http,
     * remote https plex.direct, relay last), verifying `/identity` reports the expected machine
     * id before trusting a candidate. Returns the winning connection, or null if every candidate
     * failed.
     *
     * @param  array{connections: array<int, array{uri: string, local: bool, relay: bool, protocol: string}>}  $server
     * @return array{url: string, mode: string, latency_ms: int}|null
     */
    public function selectConnection(array $server, string $machineIdentifier, string $token): ?array
    {
        foreach ($this->orderedConnections($server['connections']) as $connection) {
            $result = $this->testConnection($connection, $machineIdentifier, $token);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Re-run connection selection for the currently selected server, rate-limited to at most
     * once per cooldown window unless `$force` is set. Updates `plex.url`/`plex.connection_*` on
     * success; leaves the previous connection in place (and returns false) when every candidate
     * still fails, when no server is selected, or when a manual override is active.
     */
    public function reselectConnection(bool $force = false): bool
    {
        if ((bool) $this->settings->get('plex.manual_override', false)) {
            return false;
        }

        $machineIdentifier = (string) $this->settings->get('plex.selected_machine_identifier', '');
        $token = (string) $this->settings->get('plex.token', '');
        $servers = (array) $this->settings->get('plex.servers', []);

        $server = null;

        foreach ($servers as $candidate) {
            if (($candidate['machine_identifier'] ?? null) === $machineIdentifier) {
                $server = $candidate;
                break;
            }
        }

        if ($server === null || $machineIdentifier === '' || $token === '') {
            return false;
        }

        if (! $force && $this->onCooldown()) {
            return false;
        }

        $this->settings->set('plex.last_connection_check_at', now()->toIso8601String());

        $winner = $this->selectConnection($server, $machineIdentifier, $token);

        if ($winner === null) {
            Log::warning('Plex connection re-check: no working connection found for the selected server.', [
                'machine_identifier' => $machineIdentifier,
            ]);

            $this->settings->setMany([
                'plex.unreachable' => true,
                'plex.unreachable_since' => $this->settings->get('plex.unreachable_since') ?? now()->toIso8601String(),
            ]);

            return false;
        }

        $this->settings->setMany([
            'plex.url' => $winner['url'],
            'plex.connection_mode' => $winner['mode'],
            'plex.connection_latency_ms' => $winner['latency_ms'],
            'plex.unreachable' => false,
        ]);

        return true;
    }

    private function onCooldown(): bool
    {
        $lastCheckedAt = $this->settings->get('plex.last_connection_check_at');

        if (! is_string($lastCheckedAt) || $lastCheckedAt === '') {
            return false;
        }

        try {
            return Carbon::parse($lastCheckedAt)->addMinutes(self::RESELECT_COOLDOWN_MINUTES)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    private function authUrl(string $code): string
    {
        return 'https://app.plex.tv/auth#?'.http_build_query([
            'clientID' => $this->clientIdentifier(),
            'code' => $code,
            'context[device][product]' => 'Screening Room',
        ]);
    }

    /**
     * @param  array<int, array{uri: string, local: bool, relay: bool, protocol: string}>  $connections
     * @return array<int, array{uri: string, local: bool, relay: bool, protocol: string, mode: string}>
     */
    private function orderedConnections(array $connections): array
    {
        $ranked = [];

        foreach ($connections as $connection) {
            $rank = match (true) {
                $connection['local'] && $connection['protocol'] === 'https' => 0,
                $connection['local'] => 1,
                $connection['relay'] => 3,
                default => 2,
            };

            $ranked[] = [$rank, $connection];
        }

        usort($ranked, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $row): array => [
            ...$row[1],
            'mode' => match (true) {
                $row[0] <= 1 => 'local',
                $row[0] === 3 => 'relay',
                default => 'remote',
            },
        ], $ranked);
    }

    /**
     * @param  array{uri: string, local: bool, relay: bool, protocol: string, mode: string}  $connection
     * @return array{url: string, mode: string, latency_ms: int}|null
     */
    private function testConnection(array $connection, string $machineIdentifier, string $token): ?array
    {
        $url = rtrim($connection['uri'], '/');

        if ($url === '') {
            return null;
        }

        $started = microtime(true);

        try {
            $response = Http::withHeaders(['X-Plex-Token' => $token, 'Accept' => 'application/json'])
                ->timeout(self::CONNECTION_TEST_TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECTION_TEST_TIMEOUT_SECONDS)
                ->get("{$url}/identity");
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('MediaContainer.machineIdentifier') !== $machineIdentifier) {
            return null;
        }

        return [
            'url' => $url,
            'mode' => $connection['mode'],
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function clientHeaders(?string $token = null): array
    {
        return array_filter([
            'X-Plex-Client-Identifier' => $this->clientIdentifier(),
            'X-Plex-Product' => 'Screening Room',
            'X-Plex-Version' => '1.0',
            'X-Plex-Token' => $token,
        ], fn (?string $value): bool => $value !== null);
    }
}
