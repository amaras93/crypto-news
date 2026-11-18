<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_alerts', function (Blueprint $table): void {
            $table->string('target_price', 40)->change();
            $table->string('last_price', 40)->nullable()->change();
        });
        Schema::table('price_alert_events', function (Blueprint $table): void {
            $table->string('target_price', 40)->change();
            $table->string('previous_price', 40)->change();
            $table->string('observed_price', 40)->change();
        });
        foreach (['price_alerts' => ['target_price', 'last_price'], 'price_alert_events' => ['target_price', 'previous_price', 'observed_price']] as $table => $columns) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} === null) {
                            continue;
                        }
                        $value = (string) $row->{$column};
                        if (str_contains(strtolower($value), 'e')) {
                            $value = number_format((float) $value, 10, '.', '');
                        }
                        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
                        $values[$column] = (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 10, '0');
                    }
                    DB::table($table)->where('id', $row->id)->update($values);
                }
            });
        }
    }

    /** Exact decimal strings are deliberately retained on rollback. */
    public function down(): void {}
};
