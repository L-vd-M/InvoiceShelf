<?php

namespace App\Http\Controllers\Company\Address;

use App\Http\Controllers\Controller;
use App\Services\Address\AddressSuggestionService;
use App\Services\Address\SuggestionsUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressSuggestController extends Controller
{
    public function __construct(
        private readonly AddressSuggestionService $addresses,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'cc' => ['nullable', 'string', 'size:2', 'alpha'],
        ]);

        try {
            $suggestions = $this->addresses->suggest($validated['q'], $validated['cc'] ?? null);
        } catch (SuggestionsUnavailableException) {
            return response()->json(['suggestions' => [], 'error' => 'unavailable']);
        }

        return response()->json(['suggestions' => $suggestions]);
    }
}
