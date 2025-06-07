<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MembershipTiersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $tiers = [
            [
                'name' => 'Bronze',
                'description' => '10% discount for customers with total order value of 1000 or more',
                'minimum_spend' => 1000.00,
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'is_active' => true,
            ],
            [
                'name' => 'Silver',
                'description' => '15% discount for customers with total order value of 5000 or more',
                'minimum_spend' => 5000.00,
                'discount_type' => 'percentage',
                'discount_value' => 15.00,
                'is_active' => true,
            ],
            [
                'name' => 'Gold',
                'description' => '25% discount for customers with total order value of 10000 or more',
                'minimum_spend' => 10000.00,
                'discount_type' => 'percentage',
                'discount_value' => 25.00,
                'is_active' => true,
            ],
        ];

        foreach ($tiers as $tier) {
            DB::table('membership_tiers')->updateOrInsert(
                ['name' => $tier['name']], // Unique constraint or identifier
                array_merge($tier, [       // Fields to update
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }
    }
}
