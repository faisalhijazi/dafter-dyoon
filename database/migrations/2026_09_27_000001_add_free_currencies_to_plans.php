<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free plan: shekel + dollar are free; every other currency needs a plan with multi_currency.
 *
 * Currencies get a stable ISO code (tenants may rename "دولار" freely) and plans a list of
 * ISO codes usable without multi_currency.
 */
return new class extends Migration
{
    /** ISO code => words that identify it in a currency's code / name. */
    private const ISO = [
        'ILS' => ['شيكل', 'شيقل', 'ils', 'nis'],
        'USD' => ['دولار', 'usd'],
        'JOD' => ['دينار', 'jod', 'jd'],
        'SAR' => ['سعودي', 'sar'],
        'EGP' => ['مصري', 'جنيه', 'egp'],
        'YER' => ['يمني', 'yer'],
        'EUR' => ['يورو', 'eur'],
        'AED' => ['درهم', 'اماراتي', 'aed'],
    ];

    private const FEATURE_OLD_FREE = 'عملة رئيسية واحدة';

    private const FEATURE_NEW_FREE = 'الشيكل والدولار مجاناً';

    private const FEATURE_OLD_PRO = 'دعم العملات المتعددة وأسعار الصرف';

    private const FEATURE_NEW_PRO = 'كل العملات (دينار، ريال، جنيه…) وأسعار الصرف';

    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->string('iso_code', 3)->nullable()->after('code');
        });

        Schema::table('plans', function (Blueprint $table) {
            // ISO codes usable on this plan even without multi_currency; null = base currency only.
            $table->json('free_currencies')->nullable()->after('multi_currency');
        });

        foreach (DB::table('currencies')->get(['id', 'code', 'name']) as $currency) {
            $text = mb_strtolower($currency->code.' '.$currency->name);
            $iso = collect(self::ISO)->search(fn ($words) => collect($words)->contains(fn ($w) => str_contains($text, $w)));

            // "ريال" alone means Saudi unless it says Yemeni.
            if (! $iso && str_contains($text, 'ريال')) {
                $iso = str_contains($text, 'يمني') ? 'YER' : 'SAR';
            }

            DB::table('currencies')->where('id', $currency->id)->update(['iso_code' => $iso ?: null]);
        }

        DB::table('plans')->where('multi_currency', false)->update(['free_currencies' => json_encode(['ILS', 'USD'])]);
        $this->replaceFeature(self::FEATURE_OLD_FREE, self::FEATURE_NEW_FREE);
        $this->replaceFeature(self::FEATURE_OLD_PRO, self::FEATURE_NEW_PRO);
    }

    public function down(): void
    {
        $this->replaceFeature(self::FEATURE_NEW_FREE, self::FEATURE_OLD_FREE);
        $this->replaceFeature(self::FEATURE_NEW_PRO, self::FEATURE_OLD_PRO);

        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('free_currencies'));
        Schema::table('currencies', fn (Blueprint $table) => $table->dropColumn('iso_code'));
    }

    private function replaceFeature(string $from, string $to): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];

            if (in_array($from, $features, true)) {
                $features = array_map(fn ($f) => $f === $from ? $to : $f, $features);
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
