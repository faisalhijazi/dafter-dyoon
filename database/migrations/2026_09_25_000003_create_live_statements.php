<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FEATURE = 'كشف حساب إلكتروني حي يُرسل للعميل';

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('live_statement')->default(true)->after('cloud_backup');
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Secret for the public statement link; null = link disabled.
            $table->string('statement_token', 64)->nullable()->unique()->after('last_activity_at');
            $table->timestamp('statement_viewed_at')->nullable()->after('statement_token');
        });

        Schema::create('statement_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('open'); // open | resolved
            $table->timestamp('resolved_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'account_id', 'status']);
        });

        // Advertise the feature on the existing plans.
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
        Schema::dropIfExists('statement_disputes');

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['statement_token']);
            $table->dropColumn(['statement_token', 'statement_viewed_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('live_statement');
        });

        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = array_values(array_diff(json_decode((string) $plan->features, true) ?: [], [self::FEATURE]));
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
