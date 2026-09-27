<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('violet');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 10);       // الاسم المختصر: USD / دولار
            $table->string('name');           // الاسم الكامل: دولار أمريكي
            // How many units of the base currency equal ONE unit of this currency.
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_base')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('phone_code', 8)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            // Per-account exception to the tenant's global debt limit.
            $table->boolean('has_custom_limit')->default(false);
            $table->decimal('debt_limit', 18, 2)->nullable(); // null + custom = open for this account
            $table->timestamp('last_activity_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'last_activity_at']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10);                 // credit = له | debit = عليه
            $table->decimal('amount', 18, 4);
            $table->decimal('exchange_rate', 18, 6);    // snapshot at time of entry
            $table->string('kind', 20)->default('entry'); // entry | exchange | transfer | split
            $table->uuid('group_uuid')->nullable();     // links the legs of exchange/transfer/split
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->dateTime('occurred_at');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'account_id', 'occurred_at']);
            $table->index('group_uuid');
        });

        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 20);   // local | google
            $table->string('action', 20);     // backup | restore
            $table->string('file_name');
            $table->string('remote_id')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 20)->default('completed');
            $table->string('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('categories');
    }
};
