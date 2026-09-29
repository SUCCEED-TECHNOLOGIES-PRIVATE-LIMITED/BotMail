<?php

namespace App\Services;

use App\Exceptions\CloudflareApiException;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloudflareEmailService
{
    public function createRoutingRule(Inbox $inbox): string
    {
        $inbox->loadMissing('domain.user');
        $zoneId = $this->zoneIdForDomain($inbox->domain);

        $result = $this->result($this->send('POST', "/zones/{$zoneId}/email/routing/rules", $inbox->domain->user, [
            'matchers' => [
                ['type' => 'literal', 'field' => 'to', 'value' => $inbox->address],
            ],
            'actions' => [
                ['type' => 'worker', 'value' => [$this->workerName()]],
            ],
            'enabled' => $inbox->status->value === 'active',
            'name' => "Inbox: {$inbox->address}",
        ]), 'create routing rule');

        $ruleId = $result['id'] ?? $result['tag'] ?? null;

        if (! is_string($ruleId) || $ruleId === '') {
            throw new CloudflareApiException('Cloudflare did not return a routing rule id.');
        }

        return $ruleId;
    }

    public function deleteRoutingRule(string $zoneId, string $ruleId, ?User $user = null): void
    {
        $response = $this->send('DELETE', "/zones/{$zoneId}/email/routing/rules/{$ruleId}", $user);

        if ($response->status() === 404) {
            return;
        }

        $this->result($response, 'delete routing rule');
    }

    public function setRuleEnabled(Inbox $inbox, bool $enabled): void
    {
        $inbox->loadMissing('domain.user');

        if ($inbox->cloudflare_rule_id === null) {
            throw new CloudflareApiException("Inbox {$inbox->address} has no Cloudflare routing rule.");
        }

        $zoneId = $this->zoneIdForDomain($inbox->domain);

        $this->result($this->send('PUT', "/zones/{$zoneId}/email/routing/rules/{$inbox->cloudflare_rule_id}", $inbox->domain->user, [
            'matchers' => [
                ['type' => 'literal', 'field' => 'to', 'value' => $inbox->address],
            ],
            'actions' => [
                ['type' => 'worker', 'value' => [$this->workerName()]],
            ],
            'enabled' => $enabled,
            'name' => "Inbox: {$inbox->address}",
        ]), 'update routing rule');
    }

    /**
     * @return array<int, mixed>
     */
    public function listRoutingRules(string $zoneId, ?User $user = null): array
    {
        $result = $this->result(
            $this->send('GET', "/zones/{$zoneId}/email/routing/rules", $user),
            'list routing rules',
        );

        return array_is_list($result) ? $result : ($result['rules'] ?? []);
    }

    public function resolveZoneId(string $domain, ?User $user = null): ?string
    {
        $result = $this->result(
            $this->send('GET', '/zones?name='.urlencode($domain), $user),
            'look up zone',
        );

        $zoneId = $result[0]['id'] ?? null;

        return is_string($zoneId) && $zoneId !== '' ? $zoneId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function emailRoutingStatus(string $zoneId, ?User $user = null): array
    {
        return $this->result(
            $this->send('GET', "/zones/{$zoneId}/email/routing", $user),
            'read email routing',
        );
    }

    public function enableEmailRouting(string $zoneId, ?User $user = null): void
    {
        $this->result(
            $this->send('POST', "/zones/{$zoneId}/email/routing/enable", $user),
            'enable email routing',
        );
    }

    public function upsertCatchAll(string $zoneId, ?User $user = null): void
    {
        $this->result($this->send('PUT', "/zones/{$zoneId}/email/routing/rules/catch_all", $user, [
            'matchers' => [
                ['type' => 'all'],
            ],
            'actions' => [
                ['type' => 'worker', 'value' => [$this->workerName()]],
            ],
            'enabled' => true,
            'name' => 'BotMail catch-all',
        ]), 'update catch-all rule');
    }

    public function verifyDomain(Domain $domain): Domain
    {
        $domain->loadMissing('user');

        if ($domain->cloudflare_zone_id === null || $domain->cloudflare_zone_id === '') {
            $zoneId = $this->resolveZoneId($domain->domain, $domain->user);

            if ($zoneId === null) {
                throw new CloudflareApiException("No Cloudflare zone found for {$domain->domain}.");
            }

            $domain->cloudflare_zone_id = $zoneId;
        }

        $status = $this->emailRoutingStatus($domain->cloudflare_zone_id, $domain->user);

        if (! $this->routingIsReady($status)) {
            $this->enableEmailRouting($domain->cloudflare_zone_id, $domain->user);
            $status = $this->emailRoutingStatus($domain->cloudflare_zone_id, $domain->user);
        }

        if (! $this->routingIsReady($status)) {
            $domain->save();

            throw new CloudflareApiException("Email Routing is not ready for {$domain->domain}.");
        }

        $this->upsertCatchAll($domain->cloudflare_zone_id, $domain->user);

        $domain->verified_at = now();
        $domain->save();

        return $domain;
    }

    /**
     * Cloudflare's spam verdict is on the routing analytics event, not on the Worker payload.
     * Null means the event was not available yet or the token cannot read analytics.
     */
    public function routingMessageIsSpam(Inbox $inbox, ?string $messageId): ?bool
    {
        $inbox->loadMissing('domain');
        $zoneId = $inbox->domain?->cloudflare_zone_id;
        $needle = $this->normalizeMessageId($messageId);

        if (! is_string($zoneId) || $zoneId === '' || $needle === '') {
            return null;
        }

        try {
            $response = Http::withToken($this->tokenFor($inbox->domain?->user))
                ->acceptJson()
                ->timeout(15)
                ->post('https://api.cloudflare.com/client/v4/graphql', [
                    'query' => <<<'GQL'
query ($zoneTag: string!, $start: Time!, $end: Time!) {
  viewer {
    zones(filter: { zoneTag: $zoneTag }) {
      emailRoutingAdaptive(
        filter: { datetime_geq: $start, datetime_leq: $end }
        limit: 100
        orderBy: [datetime_DESC]
      ) {
        messageId
        to
        isSpam
      }
    }
  }
}
GQL,
                    'variables' => [
                        'zoneTag' => $zoneId,
                        'start' => now()->subMinutes(30)->utc()->format('Y-m-d\TH:i:s\Z'),
                        'end' => now()->addMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Cloudflare spam lookup failed', [
                'inbox' => $inbox->address,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $errors = $response->json('errors');

        if (! $response->successful() || (is_array($errors) && $errors !== [])) {
            Log::warning('Cloudflare spam lookup failed', [
                'inbox' => $inbox->address,
                'status' => $response->status(),
                'message' => $this->errorMessage($response, 'read routing spam'),
            ]);

            return null;
        }

        $events = $response->json('data.viewer.zones.0.emailRoutingAdaptive');

        if (! is_array($events)) {
            return null;
        }

        $to = strtolower($inbox->address);

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $id = $this->normalizeMessageId(is_string($event['messageId'] ?? null) ? $event['messageId'] : null);
            $eventTo = strtolower(trim((string) ($event['to'] ?? '')));

            if ($id === '' || $id !== $needle) {
                continue;
            }

            if ($eventTo !== '' && $eventTo !== $to) {
                continue;
            }

            return (int) ($event['isSpam'] ?? 0) === 1;
        }

        return null;
    }

    public function tokenFor(?User $user): string
    {
        $token = $user?->cloudflare_api_token ?: config('services.cloudflare.token');

        if (! is_string($token) || $token === '') {
            throw new CloudflareApiException('Cloudflare API token is not configured.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $method, string $url, ?User $user, array $data = []): Response
    {
        try {
            $pending = Http::withToken($this->tokenFor($user))
                ->acceptJson()
                ->asJson()
                ->baseUrl('https://api.cloudflare.com/client/v4')
                ->timeout(20)
                ->retry(3, function (int $attempt, mixed $exception): int {
                    $response = $exception instanceof RequestException ? $exception->response : null;
                    $retryAfter = (int) ($response?->header('Retry-After') ?: 1);

                    return max(1, $retryAfter) * 1000;
                }, function (mixed $exception): bool {
                    return $exception instanceof RequestException && $exception->response?->status() === 429;
                }, throw: false);

            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url),
                'POST' => $pending->post($url, $data),
                'PUT' => $pending->put($url, $data),
                'DELETE' => $pending->delete($url),
                default => throw new CloudflareApiException("Unsupported Cloudflare method {$method}."),
            };
        } catch (ConnectionException $exception) {
            Log::error('Cloudflare connection failed', ['url' => $url, 'error' => $exception->getMessage()]);

            throw new CloudflareApiException('Cloudflare could not be reached.', previous: $exception);
        }

        if (! $response instanceof Response) {
            throw new CloudflareApiException('Cloudflare could not be reached.');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    protected function result(Response $response, string $action): array
    {
        if (! $response->successful() || $response->json('success') === false) {
            $message = $this->errorMessage($response, $action);
            Log::warning('Cloudflare API error', [
                'action' => $action,
                'status' => $response->status(),
                'message' => $message,
            ]);

            throw new CloudflareApiException($message);
        }

        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }

    protected function errorMessage(Response $response, string $action): string
    {
        $errors = collect($response->json('errors') ?? [])
            ->map(fn (mixed $error): ?string => is_array($error) ? ($error['message'] ?? null) : null)
            ->filter()
            ->implode(' ');

        if ($errors !== '') {
            return $errors;
        }

        return "Cloudflare {$action} failed ({$response->status()}).";
    }

    protected function zoneIdForDomain(Domain $domain): string
    {
        if (is_string($domain->cloudflare_zone_id) && $domain->cloudflare_zone_id !== '') {
            return $domain->cloudflare_zone_id;
        }

        $zoneId = $this->resolveZoneId($domain->domain, $domain->user);

        if ($zoneId === null) {
            throw new CloudflareApiException("Cloudflare zone id is missing for {$domain->domain}.");
        }

        $domain->update(['cloudflare_zone_id' => $zoneId]);

        return $zoneId;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected function routingIsReady(array $status): bool
    {
        return ($status['enabled'] ?? false) === true || ($status['status'] ?? null) === 'ready';
    }

    protected function normalizeMessageId(?string $messageId): string
    {
        return strtolower(trim((string) $messageId, "<> \t"));
    }

    protected function workerName(): string
    {
        $worker = config('services.cloudflare.worker');

        return is_string($worker) && $worker !== '' ? $worker : 'botmail-inbound';
    }
}
