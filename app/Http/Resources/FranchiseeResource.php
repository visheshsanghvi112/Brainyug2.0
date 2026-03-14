<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FranchiseeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Identity
            'shop_code' => $this->shop_code,
            'shop_name' => $this->shop_name,
            'shop_type' => $this->shop_type,
            // Owner
            'owner_name' => $this->owner_name,
            'owner_title' => $this->owner_title,
            'partner_name' => $this->partner_name,
            'owner_dob' => $this->owner_dob?->format('Y-m-d'),
            'education' => $this->education,
            'occupation' => $this->occupation,
            // Contact
            'email' => $this->email,
            'mobile' => $this->mobile,
            'whatsapp' => $this->whatsapp,
            'alternate_phone' => $this->alternate_phone,
            // Address
            'address' => $this->address,
            'state_id' => $this->state_id,
            'district_id' => $this->district_id,
            'city_id' => $this->city_id,
            'other_city' => $this->other_city,
            'pincode' => $this->pincode,
            // Legal
            'gst_number' => $this->gst_number,
            'pan_number' => $this->pan_number,
            'dl_number_20b' => $this->dl_number_20b,
            'dl_number_21b' => $this->dl_number_21b,
            'fssai_number' => $this->fssai_number,
            // Financial
            'bank_name' => $this->bank_name,
            'bank_account_holder' => $this->bank_account_holder,
            'bank_account_number' => $this->bank_account_number,
            'bank_ifsc' => $this->bank_ifsc,
            'bank_branch' => $this->bank_branch,
            'utr_number' => $this->utr_number,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'investment_amount' => $this->investment_amount,
            'ready_to_invest' => $this->ready_to_invest,
            // Documents
            'documents' => $this->documents,
            // Status
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->format('Y-m-d H:i'),
            'activated_at' => $this->activated_at?->format('Y-m-d H:i'),
            'deactivated_at' => $this->deactivated_at?->format('Y-m-d H:i'),
            // Relationships
            'state' => $this->whenLoaded('state', fn() => [
                'id' => $this->state->id,
                'name' => $this->state->name,
            ]),
            'district' => $this->whenLoaded('district', fn() => [
                'id' => $this->district->id,
                'name' => $this->district->name,
            ]),
            'city' => $this->whenLoaded('city', fn() => [
                'id' => $this->city->id,
                'name' => $this->city->name,
            ]),
            'district_head' => $this->whenLoaded('districtHead', fn() => [
                'id' => $this->districtHead->id,
                'name' => $this->districtHead->name,
            ]),
            'zone_head' => $this->whenLoaded('zoneHead', fn() => [
                'id' => $this->zoneHead->id,
                'name' => $this->zoneHead->name,
            ]),
            'state_head' => $this->whenLoaded('stateHead', fn() => [
                'id' => $this->stateHead->id,
                'name' => $this->stateHead->name,
            ]),
            'approved_by' => $this->whenLoaded('approvedBy', fn() => [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ]),
            'users_count' => $this->whenCounted('users'),
            'has_linked_user_account' => $this->when(
                isset($this->users_count) || $this->relationLoaded('users'),
                fn () => ($this->relationLoaded('users') ? $this->users->count() : (int) $this->users_count) > 0,
                false,
            ),
            'users' => $this->whenLoaded('users', fn() => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()),
            // Meta
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i'),
        ];
    }
}
