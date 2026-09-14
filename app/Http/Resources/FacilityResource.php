<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id, 'name' => $this->name, 'timezone' => $this->timezone, 'address' => $this->address, 'registration_open' => (bool) $this->registration_open, 'branches' => $this->whenLoaded('branches')];
    }
}
