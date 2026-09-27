<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant dashboard round 2: collections (due dates, promises, payment reports), pins & tags,
 * staff permissions + activity log, customer confirmation, offline sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('last_activity_at');
            // Payment reminders: next due date and how it repeats after the customer pays.
            $table->date('due_date')->nullable()->after('pinned_at');
            $table->string('due_repeat', 10)->default('none')->after('due_date'); // none | weekly | monthly
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Offline entries carry a client-generated id so a retried sync never records twice.
            $table->uuid('client_uuid')->nullable()->after('group_uuid');
            // The customer confirmed this movement from their statement link.
            $table->timestamp('confirmed_at')->nullable()->after('occurred_at');
            $table->unique(['tenant_id', 'client_uuid']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Staff permissions (owners implicitly have all): delete, reports, settings.
            $table->json('permissions')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('permissions');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_staff')->default(0)->after('max_transactions'); // null = unlimited
        });
        DB::table('plans')->where('slug', 'pro')->update(['max_staff' => 10]);

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 20)->default('slate');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('account_tag', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['account_id', 'tag_id']);
        });

        Schema::create('payment_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 18, 4)->nullable();     // null = "will pay" without an amount
            $table->date('promised_on');
            $table->string('status', 10)->default('pending'); // pending | kept | broken | cancelled
            $table->timestamp('resolved_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'promised_on']);
        });

        Schema::create('payment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('method', 30);            // bank | wallet | cash | other
            $table->string('reference', 120)->nullable();
            $table->string('payer_name', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->timestamp('reviewed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);                 // e.g. transaction.created
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 500);
            $table->json('properties')->nullable();      // before / after values
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('payment_reports');
        Schema::dropIfExists('payment_promises');
        Schema::dropIfExists('account_tag');
        Schema::dropIfExists('tags');

        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('max_staff'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['permissions', 'is_active']));
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'client_uuid']);
            $table->dropColumn(['client_uuid', 'confirmed_at']);
        });
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['pinned_at', 'due_date', 'due_repeat']));
    }
};
