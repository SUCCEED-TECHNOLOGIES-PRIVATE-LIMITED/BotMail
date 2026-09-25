<?php

namespace App\Filament\Resources\DomainResource\Pages;

use App\Exceptions\CloudflareApiException;
use App\Filament\Resources\DomainResource;
use App\Models\User;
use App\Services\CloudflareEmailService;
use Filament\Resources\Pages\CreateRecord;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['domain'] = strtolower(trim((string) $data['domain']));

        if (blank($data['cloudflare_zone_id'] ?? null)) {
            try {
                $user = auth()->user();
                $data['cloudflare_zone_id'] = app(CloudflareEmailService::class)->resolveZoneId(
                    $data['domain'],
                    $user instanceof User ? $user : null,
                );
            } catch (CloudflareApiException) {
                $data['cloudflare_zone_id'] = null;
            }
        }

        return $data;
    }
}
