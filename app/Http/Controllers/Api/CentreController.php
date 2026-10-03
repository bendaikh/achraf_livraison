<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CentreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Page « Centre » — cards from config/centre.php with real counters. */
class CentreController extends Controller
{
    public function show(Request $request, CentreService $centre): JsonResponse
    {
        return response()->json([
            'cards' => $centre->cards($request->user()),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
