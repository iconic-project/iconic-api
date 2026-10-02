<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\HealthChecker;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $checks = [
            'db' => $this->runCheck($checker->database(...)),
            'redis' => $this->runCheck($checker->redis(...)),
            'queue' => $this->runCheck($checker->queue(...)),
        ];

        $failed = in_array('fail', $checks, true);

        return response()->json([
            'status' => $failed ? 'degraded' : 'ok',
            'app' => 'iconic-api',
            'time' => Iso::utc(now()),
            'checks' => $checks,
        ], $failed ? 503 : 200);
    }

    /**
     * @param  callable(): void  $check
     * @return 'ok'|'fail'
     */
    private function runCheck(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
