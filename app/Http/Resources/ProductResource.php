<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_name' => $this->product_name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'mrp' => number_format($this->mrp, 2),
            'ptr' => number_format($this->ptr, 2),
            'pts' => number_format($this->pts, 2),
            'packing' => $this->packing_desc,
            'company' => [
                'id' => $this->company_id,
                'name' => $this->company?->name,
            ],
            'category' => [
                'id' => $this->category_id,
                'name' => $this->category?->name,
            ],
            'salt' => [
                'id' => $this->salt_id,
                'name' => $this->salt?->name,
            ],
            'hsn' => [
                'id' => $this->hsn_id,
                'code' => $this->hsn?->hsn_code,
                'tax' => ($this->hsn?->cgst_percent + $this->hsn?->sgst_percent) . '%',
            ],
            'is_active' => (bool)$this->is_active,
            'is_banned' => (bool)$this->is_banned,
            'created_at' => $this->created_at->format('Y-m-d'),
        ];
    }
}
