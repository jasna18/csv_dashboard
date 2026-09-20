<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('add_csv', 'woeq1')) {
            Schema::table('add_csv', function (Blueprint $table) {
                $table->renameColumn('woeq1', 'asset_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('add_csv', 'asset_type')) {
            Schema::table('add_csv', function (Blueprint $table) {
                $table->renameColumn('asset_type', 'woeq1');
            });
        }
    }
};
