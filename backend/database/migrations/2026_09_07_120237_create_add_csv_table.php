<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work-order rows imported from the source CSV.
     *
     * Column types follow the supplied field spec. Where a field was described
     * as holding "both number and text", it is stored as VARCHAR — a mixed
     * column cannot be numeric, and coercing it would drop the text values.
     */
    public function up(): void
    {
        Schema::create('add_csv', function (Blueprint $table) {
            $table->id();

            // Purely numeric per the spec.
            $table->unsignedBigInteger('wonum')->nullable();

            // Free text, can be long.
            $table->text('description')->nullable();

            // Mixed number/text.
            $table->string('assetnum', 100)->nullable();

            $table->string('asset_type', 255)->nullable();

            // Mixed number/text.
            $table->string('location', 100)->nullable();
            $table->string('workshop', 100)->nullable();

            $table->string('status', 50)->nullable();

            $table->dateTime('reportdate')->nullable();

            // Mixed number/text.
            $table->string('woeq8', 100)->nullable();

            $table->dateTime('actstart')->nullable();
            $table->dateTime('actfinish')->nullable();

            // Mixed number/text — see the note in the class docblock about
            // aggregating this column.
            $table->string('total', 50)->nullable();

            $table->timestamps();

            // Indexes for the dimensions and date ranges the dashboard groups by.
            $table->index('wonum');
            $table->index('status');
            $table->index('location');
            $table->index('workshop');
            $table->index('reportdate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('add_csv');
    }
};
