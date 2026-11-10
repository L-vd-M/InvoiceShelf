<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class DocumentImmutableException extends RuntimeException
{
    public function __construct(private readonly string $action, private readonly string $number)
    {
        parent::__construct("Invoice {$number} is issued and cannot be {$action}. Issue a credit note to correct it.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://docs.tarcza-electronics.com/problems/document_immutable',
            'title' => 'Issued document is read-only',
            'status' => 409,
            'code' => 'document_immutable',
            'detail' => $this->getMessage(),
        ], 409, ['Content-Type' => 'application/problem+json']);
    }
}
