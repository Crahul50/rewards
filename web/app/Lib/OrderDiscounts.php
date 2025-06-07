<?php

declare(strict_types=1);

namespace App\Lib;

use App\Models\MembershipTiers;
use App\Models\Session;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class OrderDiscounts
{
    public const FIND_FUNCTION_QUERY = <<<QUERY
    query {
      shopifyFunctions(first: 25) {
        nodes {
          app {
            title
          }
          apiType
          title
          id
        }
      }
    }
    QUERY;

    public const CREATE_ORDER_DISCOUNT_QUERY = <<<QUERY
    mutation(\$automaticAppDiscount: DiscountAutomaticAppInput!) {
      discountAutomaticAppCreate(automaticAppDiscount: \$automaticAppDiscount) {
         automaticAppDiscount {
          discountId
         }
         userErrors {
          field
          message
         }
      }
    }
    QUERY;

    public const GET_AUTOMATIC_DISCOUNTS = <<<QUERY
    query {
      discountNodes(first: 100, query: "status:active method:automatic") {
        edges {
          node {
            id
            discount {
              ... on DiscountAutomaticApp {
                title
                startsAt
                appDiscountType {
                    appKey
                    functionId
                    title
                }
              }
            }
          }
        }
      }
    }
    QUERY;

    public const DELETE_AUTOMATIC_DISCOUNT_QUERY = <<<QUERY
    mutation discountAutomaticDelete(\$id: ID!) {
      discountAutomaticDelete(id: \$id) {
        deletedAutomaticDiscountId
        userErrors {
          field
          message
        }
      }
    }
    QUERY;

    public $session;
    public $client;
    public $membershipTiers;
    public $last_message;
    public $last_json;
    public $shopData;
    public $shop;
    public $accessToken;

    public function __construct($session)
    {
        \Log::debug("Initializing OrderDiscounts", ['session' => $session]);
        $this->session = $session;

        if (method_exists($this->session, 'getShop')) {
            $this->shop = $this->session->getShop();
        } elseif (property_exists($this->session, 'shop')) {
            $this->shop = $this->session->shop;
        } elseif ($this->session instanceof \Illuminate\Database\Eloquent\Model) {
            $this->shop = $this->session->getAttribute('shop');
        } elseif (array_key_exists('shop', $this->session->toArray())) {
            $this->shop = $this->session->shop;
        } else {
            throw new \Exception("Unable to retrieve 'shop' from session.");
        }

        if (method_exists($this->session, 'getAccessToken')) {
            $this->accessToken = $this->session->getAccessToken();
        } elseif (property_exists($this->session, 'access_token')) {
            $this->accessToken = $this->session->access_token;
        } elseif ($this->session instanceof \Illuminate\Database\Eloquent\Model) {
            $this->accessToken = $this->session->getAttribute('access_token');
        } elseif (array_key_exists('access_token', $this->session->toArray())) {
            $this->accessToken = $this->session->access_token;
        } else {
            throw new \Exception("Unable to retrieve 'access_token' from session.");
        }

        $this->client = new Graphql($this->shop, $this->accessToken);
        $this->shopData = Session::where('shop', $this->shop)->first();
        $this->last_message = "";
    }

    public function run()
    {

        $dbTiers = \DB::table('membership_tiers')->select([
            'id',
            'name',
            'discount_value',
            'discount_type',
            'minimum_spend'
        ])
          ->where('is_active', true);

        $this->membershipTiers = $dbTiers->get()->map(function ($tier) {
            return (array) $tier;
        })->toArray();

        $tiers = $this->membershipTiers;
        $functionId = $this->getFunctionId();

        if (!$functionId) {
            $this->last_message = "Failed to retrieve function ID for order discounts.";
            \Log::error("Function ID retrieval failed", ['last_message' => $this->last_message]);
            return false;
        }

        $deletedAppDiscounts = $this->deleteAppAutomaticDiscounts();
        if (!$deletedAppDiscounts) {
            $this->last_message = config('env') === 'production' ?
                "We cannot create discount based on membership tiers. Code: 0004" :
                "Discount cleanup failed";
            \Log::error("Failed to delete existing discounts", ['last_message' => $this->last_message]);
            return false;
        }

        if (!empty($tiers)) {
            \Log::debug("Creating new automatic discount", ['tiers' => $tiers]);
            $createdAppDiscounts = $this->createAppAutomaticDiscount($functionId, $tiers);
            if (!$createdAppDiscounts) {
                $this->last_message = config('env') === 'production' ?
                    "We cannot create discount based on membership tiers. Code: 0005" :
                    "Discount creation failed";
                \Log::error("Failed to create new discount", ['last_message' => $this->last_message]);
                return false;
            }
        } else {
        }

        \Log::info("OrderDiscounts run completed successfully");
        return true;
    }

    public function getFunctionId()
    {
        try {
            $response = $this->client->query(self::FIND_FUNCTION_QUERY);
            $json = $response->getDecodedBody();

            $functionId = collect($json['data']['shopifyFunctions']['nodes'])
                ->firstWhere('apiType', 'order_discounts')['id'];
            if (!$functionId) {
                return false;
            }
            return $functionId;
        } catch (\Exception $e) {
            \Log::error($this->shop . ": failed to retrieve function ID, details:" . "\n" . $e->getMessage());
            return false;
        }
    }

    public function deleteAppAutomaticDiscounts()
    {
        $success = true;

        $response = $this->client->query(self::GET_AUTOMATIC_DISCOUNTS);
        $json = $response->getDecodedBody();

        $automaticAppDiscounts = $json['data']['discountNodes']['edges'];
        if (count($automaticAppDiscounts) > 0) {
            foreach ($automaticAppDiscounts as $discount) {
                $response = $this->client->query([
                    "query" => self::DELETE_AUTOMATIC_DISCOUNT_QUERY,
                    "variables" => [
                        "id" => $discount['node']['id']
                    ]
                ]);
                $json = $response->getDecodedBody();

                if (
                    !isset($json['data']['discountAutomaticDelete']['deletedAutomaticDiscountId']) ||
                    !$json['data']['discountAutomaticDelete']['deletedAutomaticDiscountId']
                ) {
                    \Log::error($this->shop . ": removing old automatic discount error, details:" .
                        "\n" . var_export($json, true));
                    $success = false;
                } else {
                    \Log::debug("Discount deleted successfully", ['deletedId' => $json['data']['discountAutomaticDelete']['deletedAutomaticDiscountId']]);
                }
            }
        } else {
            \Log::debug("No existing discounts to delete");
        }

        return $success;
    }

    public function createAppAutomaticDiscount($functionId, $tiers)
    {

        $discountTitle = count($tiers) > 1
            ? "Membership Tier Discount - " . $tiers[0]['name'] . " and " . (count($tiers) - 1) . " others"
            : "Membership Tier Discount - " . $tiers[0]['name'];

        $variables = [
            "automaticAppDiscount" => [
                "combinesWith" => [
                    "orderDiscounts" => true,
                    "productDiscounts" => true,
                    "shippingDiscounts" => true
                ],
                "functionId" => $functionId,
                "title" => $discountTitle,
                "startsAt" => date("c"),
                "metafields" => [
                    [
                        "namespace" => "default",
                        "key" => "function-configuration",
                        "type" => "json",
                        "value" => json_encode(['tiers' => array_map(function ($tier) {
                            return [
                                'name' => $tier['name'],
                                'discount_value' => $tier['discount_value'],
                                'discount_type' => $tier['discount_type'],
                                'minimum_spend' => $tier['minimum_spend']
                            ];
                        }, $tiers)])
                    ]
                ]
            ]
        ];

        $response = $this->client->query([
            "query" => self::CREATE_ORDER_DISCOUNT_QUERY,
            "variables" => $variables
        ]);
        $json = $response->getDecodedBody();

        if (
            $json['data']['discountAutomaticAppCreate']['automaticAppDiscount'] &&
            is_array($json['data']['discountAutomaticAppCreate']['automaticAppDiscount']) &&
            count($json['data']['discountAutomaticAppCreate']['automaticAppDiscount']) > 0
        ) {
            $this->last_message = "Order discount created successfully";
            $this->last_json = $json['data']['discountAutomaticAppCreate']['automaticAppDiscount'];
            \Log::info("Order discount created successfully", ['discountId' => $this->last_json['discountId']]);
            return true;
        } else {
            if (
                is_array($json['data']['discountAutomaticAppCreate']['userErrors']) &&
                count($json['data']['discountAutomaticAppCreate']['userErrors']) > 0
            ) {
                $this->last_message = implode(". ", array_map(function ($error) {
                    return $error["message"];
                }, $json['data']['discountAutomaticAppCreate']['userErrors']));
                \Log::error($this->shop . ": creating automatic discount error, details:" .
                    "\n" . var_export($json, true));
            } else {
                \Log::error($this->shop . ": unexpected error during discount creation", ['response' => $json]);
            }
            return false;
        }
    }
}
