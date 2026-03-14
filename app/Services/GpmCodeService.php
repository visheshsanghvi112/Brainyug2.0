<?php

namespace App\Services;

use App\Models\Franchisee;

class GpmCodeService
{
    private const ACTIVE_PREFIX = 'BR';
    private const SEED_SEQUENCE = 2000;

    public function nextFor(Franchisee $franchisee): string
    {
        $prefix = self::ACTIVE_PREFIX;

        $lastCode = Franchisee::query()
            ->where('shop_code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING(shop_code, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
            ->value('shop_code');

        $lastSequence = $lastCode ? (int) substr($lastCode, strlen($prefix)) : self::SEED_SEQUENCE;

        return $prefix . (string) ($lastSequence + 1);
    }
}