<?php

namespace App\Services\Campaigns;

use App\Models\CampaignVariant;
use Illuminate\Support\Collection;

/**
 * Chooses which version of an email a prospect gets. The choice depends only on the campaign, the prospect and the step,
 * so it never changes between retries or runs, and the shares follow each version's weight. Version A (null) gets the
 * traffic the other versions leave.
 */
class VariantPicker
{
    /**
     * @param  Collection<int, CampaignVariant>  $variants  The alternatives (B, C, …); inactive ones are never chosen.
     */
    public function pick(Collection $variants, int $campaignId, int $contactId, int $stepNumber): ?CampaignVariant
    {
        $active = $variants->filter(fn (CampaignVariant $variant): bool => $variant->is_active)->sortBy('position')->values();

        if ($active->isEmpty()) {
            return null;
        }

        $alternativeShare = $active->sum('weight');
        // Weights over 100 (which validation prevents) are scaled down so every version keeps its proportion.
        $scale = $alternativeShare > 100 ? 100 / $alternativeShare : 1;
        $bucket = crc32("{$campaignId}:{$contactId}:{$stepNumber}") % 100;
        $threshold = 100 - min(100, $alternativeShare * $scale);

        if ($bucket < $threshold) {
            return null;
        }

        foreach ($active as $variant) {
            $threshold += $variant->weight * $scale;

            if ($bucket < $threshold) {
                return $variant;
            }
        }

        return $active->last();
    }
}
