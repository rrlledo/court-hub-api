<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'facility_id' => $this->facility_id, 'name' => $this->name, 'timezone' => $this->timezone, 'address' => $this->address, 'courts' => $this->whenLoaded('courts')];
    }
}
