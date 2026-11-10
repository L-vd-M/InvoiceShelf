<?php

namespace App\Http\Resources;

use App\Services\Document\RevisionDiffService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_id' => $this->document_id,
            'revision_no' => $this->revision_no,
            'reason_code' => $this->reason_code,
            'note' => $this->note,
            'status' => $this->status,
            'totals_hash' => $this->totals_hash,
            'pdf_sha256' => $this->pdf_sha256,
            'has_pdf' => $this->pdf_sha256 !== null,
            'actor_user_id' => $this->actor_user_id,
            'actor_name' => $this->actor?->name,
            'actor_role' => $this->actor_role,
            'source' => $this->source,
            'created_at' => $this->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'diff_summary' => RevisionDiffService::summarise($this->diff_from_previous),
        ];

        if ($this->getAttribute('detail')) {
            $data['snapshot'] = $this->snapshot;
            $data['diff_from_previous'] = $this->diff_from_previous;
        }

        return $data;
    }
}
