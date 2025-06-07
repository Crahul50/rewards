<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopify\Clients\Graphql;
use App\Models\Session;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\HttpResponse;

class DeleteCustomerMetafieldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $shop;
    protected $customerId;

    private const CUSTOMER_METAFIELD_QUERY = <<<'QUERY'
    query ($customerId: ID!) {
        customer(id: $customerId) {
            id
            metafield(namespace: "customer_membership_tiers", key: "tier") {
                id
            }
        }
    }
    QUERY;

    private const DELETE_METAFIELD_MUTATION = <<<'QUERY'
    mutation metafieldDelete($input: MetafieldDeleteInput!) {
        metafieldDelete(input: $input) {
            deletedId
            userErrors {
                field
                message
            }
        }
    }
    QUERY;

    /**
     * Create a new job instance.
     */
    public function __construct(string $shop, string $customerId)
    {
        $this->shop = $shop;
        $this->customerId = $customerId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Get Shopify session
            $session = Session::where('shop', $this->shop)->first();
            if (!$session) {
                Log::debug("No session found for shop: {$this->shop} in DeleteCustomerMetafieldJob");
                throw new \Exception("No session found for shop: {$this->shop}");
            }

            $client = new Graphql($this->shop, $session->access_token);

            // Fetch the customer's metafield
            $response = $client->query([
                'query' => self::CUSTOMER_METAFIELD_QUERY,
                'variables' => [
                    'customerId' => "gid://shopify/Customer/{$this->customerId}",
                ],
            ]);

            $body = HttpResponse::fromResponse($response)->getDecodedBody();

            if ($response->getStatusCode() !== 200 || isset($body['errors'])) {
                Log::debug("Error fetching customer metafield in DeleteCustomerMetafieldJob", [
                    'customer_id' => $this->customerId,
                    'shop' => $this->shop,
                    'errors' => $body['errors'] ?? 'No specific error details',
                ]);
                throw new \Exception($body['errors'] ?? 'Failed to fetch customer metafield');
            }

            $customer = $body['data']['customer'] ?? null;
            if (!$customer) {
                Log::warning("Customer not found in DeleteCustomerMetafieldJob", [
                    'customer_id' => $this->customerId,
                    'shop' => $this->shop,
                ]);
                return; // Exit if the customer doesn't exist
            }

            $metafield = $customer['metafield'] ?? null;

            if ($metafield && isset($metafield['id'])) {
                $metafieldId = $metafield['id'];
                $this->deleteMetafield($client, $metafieldId);
            } else {
                Log::info("No metafield found to delete for customer", [
                    'customer_id' => $this->customerId,
                    'shop' => $this->shop,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Error in DeleteCustomerMetafieldJob for customer {$this->customerId} in shop {$this->shop}: {$e->getMessage()}", [
                'exception' => [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
            throw $e;
        }
    }

    /**
     * Delete a single metafield.
     */
    private function deleteMetafield(Graphql $client, string $metafieldId): void
    {
        try {
            $mutationInput = [
                'input' => [
                    'id' => $metafieldId,
                ],
            ];

            $response = $client->query([
                'query' => self::DELETE_METAFIELD_MUTATION,
                'variables' => $mutationInput,
            ]);

            $body = HttpResponse::fromResponse($response)->getDecodedBody();

            if ($response->getStatusCode() !== 200 || isset($body['errors']) || !empty($body['data']['metafieldDelete']['userErrors'])) {
                Log::debug("Error in GraphQL response for metafield deletion", [
                    'customer_id' => $this->customerId,
                    'metafield_id' => $metafieldId,
                    'errors' => $body['errors'] ?? $body['data']['metafieldDelete']['userErrors'] ?? 'No specific error details',
                ]);
                $errors = $body['errors'] ?? $body['data']['metafieldDelete']['userErrors'] ?? 'Failed to delete customer metafield';
                throw new \Exception(json_encode($errors));
            }

            Log::info("Successfully deleted customer metafield", [
                'customer_id' => $this->customerId,
                'metafield_id' => $metafieldId,
                'shop' => $this->shop,
            ]);

        } catch (\Exception $e) {
            Log::error("Error deleting customer metafield for customer {$this->customerId} in shop {$this->shop}: {$e->getMessage()}", [
                'metafield_id' => $metafieldId,
                'exception' => [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
            throw $e;
        }
    }
}
