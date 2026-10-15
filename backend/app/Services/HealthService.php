<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class HealthService
{
    /**
     * @return array{status: string, database: string}
     */
    public function check(): array
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'error';
        }

        return [
            'status' => $database === 'ok' ? 'ok' : 'error',
            'database' => $database,
        ];
    }
}
