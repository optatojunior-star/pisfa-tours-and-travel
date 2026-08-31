<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\HealthCheck;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * The public health endpoint.
 *
 * Says only whether the site is up. No check names, no detail, no timings — a
 * public endpoint that reports "database: connection refused to
 * db1.example.com:3306" hands an attacker the topology, and one that lists
 * subsystems tells them what to aim at. The detailed report lives behind
 * authentication in the console.
 *
 * 503 rather than 500 on failure, because a monitoring system and a load
 * balancer both treat 503 as "not ready", which is what this means.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $status = $health->shallow();

        return response()->json(
            ['status' => $status->isDown() ? 'down' : 'ok'],
            $status->isDown() ? ResponseCode::HTTP_SERVICE_UNAVAILABLE : ResponseCode::HTTP_OK,
        );
    }
}
