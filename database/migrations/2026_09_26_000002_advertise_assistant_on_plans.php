<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FEATURE = 'مساعد AI ذكي يفهم أوامرك بالعامية';

    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];

            if (! in_array(self::FEATURE, $features, true)) {
                $features[] = self::FEATURE;
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = array_values(array_diff(json_decode((string) $plan->features, true) ?: [], [self::FEATURE]));
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
