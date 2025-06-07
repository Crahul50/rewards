<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_order_id')->unique();
            $table->string('shop');
            $table->unsignedBigInteger('customer_id');
            $table->string('order_number');
            $table->decimal('total_price', 10, 2);
            $table->string('currency')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->string('financial_status')->nullable();
            $table->integer('points_earned')->default(0);
            $table->unsignedBigInteger('tier_membership_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')
                  ->references('id')
                  ->on('customers')
                  ->onDelete('cascade');
            $table->index('shopify_order_id');
            $table->index('order_number');
            $table->index('shop');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
