<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique()->after('subscription_ends_at');
            $table->string('stripe_subscription_id')->nullable()->index()->after('stripe_customer_id');
            $table->string('subscription_status', 30)->nullable()->after('stripe_subscription_id');
            $table->string('subscription_interval', 10)->nullable()->after('subscription_status');
            $table->boolean('subscription_cancels')->default(false)->after('subscription_interval');
        });

        // Each paid plan maps to one Stripe product, created on first checkout.
        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable()->after('sort_order');
        });

        // One row per Stripe invoice (paid or failed), for the billing history.
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->unsignedInteger('amount'); // smallest currency unit (cents)
            $table->string('currency', 3);
            $table->string('status', 20); // paid | failed
            $table->string('hosted_invoice_url', 1000)->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
        });

        // Processed webhook events: Stripe retries deliveries, each one is applied once.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('subscription_payments');

        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('stripe_product_id'));

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id', 'subscription_status', 'subscription_interval', 'subscription_cancels']);
        });
    }
};
