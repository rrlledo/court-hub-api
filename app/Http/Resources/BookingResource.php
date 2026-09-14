<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'court_id' => $this->court_id, 'starts_at' => $this->starts_at?->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String(), 'status' => $this->status, 'amount' => $this->amount, 'currency' => $this->currency, 'expires_at' => $this->expires_at?->toIso8601String(), 'notes' => $this->notes];
    }
}
