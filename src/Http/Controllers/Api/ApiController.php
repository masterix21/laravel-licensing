<?php

namespace LucaLongo\Licensing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

abstract class ApiController extends Controller
{
    protected function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    protected function error(string $code, string $message, int $status, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                ...$meta,
            ], fn ($value) => $value !== null),
        ], $status);
    }
}
