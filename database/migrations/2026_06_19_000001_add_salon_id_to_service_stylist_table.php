<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defense-in-depth: the service_stylist pivot now feeds the booking engine,
     * so carry a redundant salon_id (always = the service's salon). This lets
     * any direct pivot query be tenant-scoped and prevents crossing tenants.
     */
    public function up(): void
    {
        Schema::table('service_stylist', function (Blueprint $table) {
            $table->foreignId('salon_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Backfill from the related service.
        DB::table('service_stylist')->update([
            'salon_id' => DB::raw('(select salon_id from services where services.id = service_stylist.service_id)'),
        ]);

        Schema::table('service_stylist', function (Blueprint $table) {
            $table->index(['salon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('service_stylist', function (Blueprint $table) {
            $table->dropIndex(['salon_id', 'user_id']);
            $table->dropConstrainedForeignId('salon_id');
        });
    }
};
