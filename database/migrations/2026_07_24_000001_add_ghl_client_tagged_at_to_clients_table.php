<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the app confirmed the configured client tag is on this client's
     * GHL contact (applied by us, or observed in an inbound payload). Real
     * clients — people who BOOK, or who staff create/edit in the app — get
     * the tag exactly once; mere callers/leads never do. Nullable: existing
     * clients are tagged lazily on their next booking push or edit.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('ghl_client_tagged_at')->nullable()->after('ghl_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('ghl_client_tagged_at');
        });
    }
};
