<?php

namespace App\Services\Health;

/**
 * Tri-state outcome of one health check: Pass (working), Warn (degraded or
 * worth attention — nothing broken), Fail (broken; needs fixing).
 */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
