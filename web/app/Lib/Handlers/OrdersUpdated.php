<?php

declare(strict_types=1);

namespace App\Lib\Handlers;

use App\Jobs\DeleteCustomerMetafieldJob;
use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;
use Shopify\Clients\Graphql;
use App\Models\Session;
use Shopify\Clients\HttpResponse;
use Illuminate\Support\Facades\DB;

class OrdersUpdated implements Handler
{
    private const CUSTOMER_ORDERS_QUERY = <<<'QUERY'
    query ($customerId: ID!) {
        customer(id: $customerId) {
            orders(first: 100) {
                edges {
                    node {
                        id
                        totalPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                    }
                }
            }
        }
    }
    QUERY;

    private const SET_CUSTOMER_METAFIELD_MUTATION = <<<'QUERY'
    mutation customerUpdate($input: CustomerInput!) {
        customerUpdate(input: $input) {
            customer {
                id
                metafield(namespace: "customer_membership_tiers", key: "tier") {
                    id
                    value
                }
            }
            userErrors {
                field
                message
            }
        }
    }
    QUERY;

    public function handle(string $topic, string $shop, array $body): void
    {
        try {
            $orderStats = $this->getCustomerOrderStats($shop, $body['customer']['id']);
            $membershipTier = $this->determineMembershipTier($orderStats['total']);

            $customerData = [
                'shopify_customer_id' => $body['customer']['id'],
                'shop' => $shop,
                'first_name' => $body['customer']['first_name'],
                'last_name' => $body['customer']['last_name'],
                'email' => $body['customer']['email'],
                'phone' => $body['customer']['phone'] ?? null,
                'membership_tier' => $membershipTier,
                'points' => 0,
                'orders_count' => $orderStats['count'],
                'total_spent' => $orderStats['total'],
            ];
            \DB::table('customers')->updateOrInsert(
                [
                    'shopify_customer_id' => $body['customer']['id'],
                    'shop' => $shop
                ],
                $customerData
            );

            $this->setCustomerMembershipMetafield($shop, $body['customer']['id'], $membershipTier);

            // DeleteCustomerMetafieldJob::dispatch($shop, $body['customer']['id'])
            //         ->delay(now()->addMinutes(1));

        } catch (\Exception $e) {
            Log::error("Error processing order update for shop {$shop}: {$e->getMessage()}", [
                'customer_id' => $body['customer']['id'] ?? null,
                'exception' => [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            ]);
            throw new \Exception($e->getMessage());
        }
    }

    private function getCustomerOrderStats(string $shop, int $customerId): array
    {
        try {
            // Get Shopify session
            $session = Session::where('shop', $shop)->first();
            if (!$session) {
                Log::debug("No session found for shop: {$shop}");
                throw new \Exception("No session found for shop: {$shop}");
            }

            $client = new Graphql($shop, $session->access_token);

            $response = $client->query([
                'query' => self::CUSTOMER_ORDERS_QUERY,
                'variables' => [
                    'customerId' => "gid://shopify/Customer/{$customerId}"
                ]
            ]);

            $body = HttpResponse::fromResponse($response)->getDecodedBody();


            if ($response->getStatusCode() !== 200 || isset($body['errors'])) {
                Log::debug("Error in GraphQL response for customer orders", [
                    'customer_id' => $customerId,
                    'errors' => $body['errors'] ?? 'No specific error details'
                ]);
                throw new \Exception($body['errors'] ?? 'Failed to fetch customer orders');
            }

            $orders = $body['data']['customer']['orders']['edges'] ?? [];

            $orderCount = count($orders);
            $totalSpent = array_reduce($orders, function($carry, $order) {
                $amount = floatval($order['node']['totalPriceSet']['shopMoney']['amount']);
                return $carry + $amount;
            }, 0.0);

            return [
                'count' => $orderCount,
                'total' => $totalSpent
            ];

        } catch (\Exception $e) {
            Log::error("Error fetching order stats for customer {$customerId}: {$e->getMessage()}", [
                'shop' => $shop,
                'exception' => [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            ]);
            throw $e;
        }
    }

    private function determineMembershipTier(float $totalSpent): string
    {
        $tiers = DB::table('membership_tiers')
            ->where('is_active', true)
            ->orderBy('minimum_spend', 'desc')
            ->get();

        $defaultTier = '';

        foreach ($tiers as $tier) {
            if ($totalSpent >= $tier->minimum_spend) {
                return $tier->name;
            }
        }

        return $defaultTier;
    }

    private function setCustomerMembershipMetafield(string $shop, int $customerId, string $membershipTier): void
    {
        try {
            // Get Shopify session
            $session = Session::where('shop', $shop)->first();
            if (!$session) {
                Log::debug("No session found for shop: {$shop}");
                throw new \Exception("No session found for shop: {$shop}");
            }

            $client = new Graphql($shop, $session->access_token);

            $mutationInput = [
                'input' => [
                    'id' => "gid://shopify/Customer/{$customerId}",
                    'metafields' => [
                        [
                            'namespace' => 'customer_membership_tiers',
                            'key' => 'tier',
                            'value' => $membershipTier,
                            'type' => 'string'
                        ]
                    ]
                ]
            ];


            $response = $client->query([
                'query' => self::SET_CUSTOMER_METAFIELD_MUTATION,
                'variables' => $mutationInput
            ]);

            $body = HttpResponse::fromResponse($response)->getDecodedBody();


            if ($response->getStatusCode() !== 200 || isset($body['errors']) || !empty($body['data']['customerUpdate']['userErrors'])) {
                Log::debug("Error in GraphQL response for metafield update", [
                    'customer_id' => $customerId,
                    'errors' => $body['errors'] ?? $body['data']['customerUpdate']['userErrors'] ?? 'No specific error details'
                ]);
                $errors = $body['errors'] ?? $body['data']['customerUpdate']['userErrors'] ?? 'Failed to set customer metafield';
                throw new \Exception(json_encode($errors));
            }

        } catch (\Exception $e) {
            Log::error("Error setting customer metafield for customer {$customerId} in shop {$shop}: {$e->getMessage()}", [
                'exception' => [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            ]);
            throw $e;
        }
    }
}
