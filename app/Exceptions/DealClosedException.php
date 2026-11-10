<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class DealClosedException extends RuntimeException
{
    public function __construct(string $number)
    {
        parent::__construct("Quote {$number} is accepted or invoiced, so the deal is won and cannot be marked lost.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://docs.tarcza-electronics.com/problems/deal_closed',
            'title' => 'Deal already won',
            'status' => 409,
            'code' => 'deal_closed',
            'detail' => $this->getMessage(),
        ], 409, ['Content-Type' => 'application/problem+json']);
    }
}
