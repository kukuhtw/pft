<?php

declare(strict_types=1);

namespace App\Modules\Categories\Services;

use App\Models\User;
use App\Modules\Categories\Models\Merchant;
use Illuminate\Support\Str;

final class MerchantService
{
    public function resolve(User $user, ?string $name): ?Merchant
    {
        $name = $name !== null ? trim($name) : null;

        if ($name === null || $name === '') {
            return null;
        }

        $normalized = Str::of($name)->lower()->squish()->limit(128, '')->toString();

        return Merchant::query()->firstOrCreate(
            ['user_id' => $user->id, 'normalized_name' => $normalized],
            ['name' => Str::limit($name, 128, '')],
        );
    }
}
