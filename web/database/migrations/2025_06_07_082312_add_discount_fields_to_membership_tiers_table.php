<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDiscountFieldsToMembershipTiersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->decimal('discount_value', 5, 2)->nullable()->after('minimum_spend');
            $table->string('discount_type')->nullable()->after('minimum_spend');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn(['discount_value', 'discount_type']);
        });
    }
}
