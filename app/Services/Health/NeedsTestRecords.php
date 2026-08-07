<?php

namespace App\Services\Health;

/**
 * Marker for checks that require the disposable is_test records (and, for
 * the test booking, write through them). The scheduled monitor runs WITHOUT
 * test records and skips these — it must never create records, book on a
 * real stylist, or mutate anything. The manual page run includes them.
 */
interface NeedsTestRecords {}
