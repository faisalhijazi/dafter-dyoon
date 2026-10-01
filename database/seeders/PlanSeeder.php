<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(['slug' => 'free'], [
            'name' => 'المجانية',
            'description' => 'للمحلات والأنشطة الفردية',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'max_accounts' => 30,
            'max_transactions' => 500,
            'max_staff' => 0,
            'multi_currency' => false,
            'free_currencies' => ['ILS', 'USD'],
            'debt_limits' => false,
            'cloud_backup' => false,
            'live_statement' => true,
            'features' => [
                'حتى 30 حساباً و 500 معاملة',
                'الشيكل والدولار مجاناً',
                'مشاركة المعاملات عبر واتساب',
                'كشف حساب إلكتروني حي يُرسل للعميل',
                'مساعد AI ذكي يفهم أوامرك بالعامية',
                'نسخ احتياطي يدوي (تنزيل ملف)',
            ],
            'sort_order' => 1,
        ]);

        Plan::updateOrCreate(['slug' => 'pro'], [
            'name' => 'الاحترافية',
            'description' => 'للشركات والمتاجر المتوسطة',
            'price_monthly' => 9,
            'price_yearly' => 99,
            'max_accounts' => null,
            'max_transactions' => null,
            'max_staff' => 10,
            'multi_currency' => true,
            'debt_limits' => true,
            'cloud_backup' => true,
            'live_statement' => true,
            'features' => [
                'حسابات ومعاملات غير محدودة',
                'كل العملات (دينار، ريال، جنيه…) وأسعار الصرف',
                'سقوف التنبيه والحد الائتماني',
                'نسخ احتياطي سحابي على Google Drive',
                'كشف حسابات عام وطباعة PDF',
                'كشف حساب إلكتروني حي يُرسل للعميل',
                'مساعد AI ذكي يفهم أوامرك بالعامية',
            ],
            'sort_order' => 2,
        ]);
    }
}
