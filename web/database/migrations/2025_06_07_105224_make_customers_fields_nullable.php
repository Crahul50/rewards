<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeCustomersFieldsNullable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('shopify_customer_id')->nullable()->change();
            $table->string('shop')->nullable()->change();
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('phone')->nullable()->change();
            $table->integer('orders_count')->nullable()->default(0)->change();
            $table->decimal('total_spent', 10, 2)->nullable()->default(0)->change();
            $table->string('membership_tier')->nullable()->default('bronze')->change();
            $table->integer('points')->nullable()->default(0)->change();
            $table->json('tags')->nullable()->change();
            $table->json('metadata')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('shopify_customer_id')->nullable(false)->change();
            $table->string('shop')->nullable(false)->change();
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('phone')->nullable()->change();
            $table->integer('orders_count')->nullable(false)->default(0)->change();
            $table->decimal('total_spent', 10, 2)->nullable(false)->default(0)->change();
            $table->string('membership_tier')->nullable(false)->default('bronze')->change();
            $table->integer('points')->nullable(false)->default(0)->change();
            $table->json('tags')->nullable()->change();
            $table->json('metadata')->nullable()->change();
        });
    }
}
