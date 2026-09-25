<?php

namespace App\Services;

use App\Enums\InboxStatus;
use App\Exceptions\CloudflareApiException;
use App\Exceptions\InboxLimitException;
use App\Models\Domain;
use App\Models\Inbox;
use Illuminate\Support\Str;

class InboxService
{
    public const RULE_LIMIT = 200;

    public function __construct(private readonly CloudflareEmailService $cloudflare) {}

    /**
     * @param  array{user_id?: int, domain_id: int, local_part: string, display_name?: string|null, status?: InboxStatus|string}  $data
     */
    public function create(array $data): Inbox
    {
        $domain = Domain::query()->findOrFail($data['domain_id']);

        if ($domain->inboxes()->count() >= self::RULE_LIMIT) {
            throw new InboxLimitException('Cloudflare allows 200 routing rules per domain.');
        }

        $localPart = Str::lower(trim($data['local_part']));
        $status = $data['status'] ?? InboxStatus::Active;

        if (is_string($status)) {
            $status = InboxStatus::from($status);
        }

        $inbox = Inbox::query()->create([
            'user_id' => $data['user_id'] ?? $domain->user_id,
            'domain_id' => $domain->id,
            'local_part' => $localPart,
            'address' => $localPart.'@'.Str::lower($domain->domain),
            'display_name' => $data['display_name'] ?? null,
            'status' => $status,
        ]);

        try {
            $ruleId = $this->cloudflare->createRoutingRule($inbox);
            $inbox->update(['cloudflare_rule_id' => $ruleId]);
        } catch (CloudflareApiException $exception) {
            $inbox->delete();

            throw $exception;
        }

        return $inbox->refresh();
    }

    public function delete(Inbox $inbox): void
    {
        $inbox->loadMissing('domain.user');

        if ($inbox->cloudflare_rule_id !== null && $inbox->domain?->cloudflare_zone_id) {
            $this->cloudflare->deleteRoutingRule(
                $inbox->domain->cloudflare_zone_id,
                $inbox->cloudflare_rule_id,
                $inbox->domain->user,
            );
        }

        $inbox->delete();
    }

    public function pause(Inbox $inbox): void
    {
        if ($inbox->cloudflare_rule_id !== null) {
            $this->cloudflare->setRuleEnabled($inbox, false);
        }

        $inbox->update(['status' => InboxStatus::Paused]);
    }

    public function resume(Inbox $inbox): void
    {
        $inbox->status = InboxStatus::Active;

        if ($inbox->cloudflare_rule_id === null) {
            $ruleId = $this->cloudflare->createRoutingRule($inbox);
            $inbox->cloudflare_rule_id = $ruleId;
        } else {
            $this->cloudflare->setRuleEnabled($inbox, true);
        }

        $inbox->save();
    }
}
