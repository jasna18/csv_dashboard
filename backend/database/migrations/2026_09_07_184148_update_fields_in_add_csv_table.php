<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('add_csv', function (Blueprint $table) {
            $table->dropIndex(['wonum']);
            $table->dropIndex(['reportdate']);
        });

        Schema::table('add_csv', function (Blueprint $table) {
            $table->renameColumn('wonum', 'work_order');
            $table->renameColumn('description', 'fault_description');
            $table->renameColumn('assetnum', 'asset_number');
            $table->renameColumn('reportdate', 'report_date');
            $table->renameColumn('woeq8', 'fault_type');
            $table->renameColumn('actstart', 'act_start');
            $table->renameColumn('actfinish', 'act_finish');
            $table->renameColumn('total', 'total_downtime');
        });

        Schema::table('add_csv', function (Blueprint $table) {
            $table->text('work_type')->nullable()->after('fault_type');
            $table->text('fault_cause')->nullable()->after('total_downtime');
            $table->index('work_order');
            $table->index('report_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('add_csv', function (Blueprint $table) {
            $table->dropIndex(['work_order']);
            $table->dropIndex(['report_date']);
            $table->dropColumn(['work_type', 'fault_cause']);
        });

        Schema::table('add_csv', function (Blueprint $table) {
            $table->renameColumn('work_order', 'wonum');
            $table->renameColumn('fault_description', 'description');
            $table->renameColumn('asset_number', 'assetnum');
            $table->renameColumn('report_date', 'reportdate');
            $table->renameColumn('fault_type', 'woeq8');
            $table->renameColumn('act_start', 'actstart');
            $table->renameColumn('act_finish', 'actfinish');
            $table->renameColumn('total_downtime', 'total');
        });

        Schema::table('add_csv', function (Blueprint $table) {
            $table->index('wonum');
            $table->index('reportdate');
        });
    }
};
