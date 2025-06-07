// @ts-check
import { DiscountApplicationStrategy } from "../generated/api";

/**
 * @typedef {import("../generated/api").RunInput} RunInput
 * @typedef {import("../generated/api").FunctionRunResult} FunctionRunResult
 */

/**
 * @type {FunctionRunResult}
 */
const EMPTY_DISCOUNT = {
  discountApplicationStrategy: DiscountApplicationStrategy.First,
  discounts: [],
};

/**
 * @param {RunInput} input
 * @returns {FunctionRunResult}
 */
export function run(input) {
  // Parse configuration from discountNode metafield
  let tiersConfig = {};
  try {
    const configValue = input?.discountNode?.metafield?.value;
    if (!configValue) {
      console.log("No function-configuration metafield found");
      return EMPTY_DISCOUNT;
    }

    const parsedConfig = JSON.parse(configValue);
    console.log("Parsed metafield value:", JSON.stringify(parsedConfig));

    if (!parsedConfig?.tiers || !Array.isArray(parsedConfig.tiers)) {
      console.log("Metafield does not contain a valid 'tiers' array");
      return EMPTY_DISCOUNT;
    }

    // Convert the tiers array into an object mapping tier names to discount configurations
    tiersConfig = parsedConfig.tiers.reduce((acc, tier) => {
      if (tier.name && tier.discount_value !== null) {
        acc[tier.name] = {
          discount_value: parseFloat(tier.discount_value),
          discount_type: tier.discount_type || "percentage"
        };
      }
      return acc;
    }, {});
    console.log("Loaded discount rates from metafield:", JSON.stringify(tiersConfig));

    if (Object.keys(tiersConfig).length === 0) {
      console.log("No valid tiers found in metafield");
      return EMPTY_DISCOUNT;
    }
  } catch (error) {
    console.error(`Failed to parse function-configuration metafield: ${error.message}`);
    return EMPTY_DISCOUNT;
  }

  // Check if customer exists
  const customer = input.cart.buyerIdentity?.customer;

  console.log("Customer associated with checkout:", customer ? customer.id : "None");

  if (!customer) {
    console.log("No customer associated with this checkout");
    return EMPTY_DISCOUNT;
  }

  // Find the membership tier metafield
  const membershipMetafield = customer.metafield;

  if (!membershipMetafield || !membershipMetafield.value) {
    console.log("No membership tier metafield found for customer");
    return EMPTY_DISCOUNT;
  }

  const tier = membershipMetafield.value;
  const tierConfig = tiersConfig[tier];
  console.log("Customer membership tier:", JSON.stringify(tierConfig));
  if (!tierConfig || !tierConfig.discount_value) {
    console.log(`Unrecognized or invalid membership tier: ${tier}`);
    return EMPTY_DISCOUNT;
  }

  const discountRate = tierConfig.discount_value;
  const discountType = tierConfig.discount_type || "percentage";

  // Calculate total cart amount (excluding taxes and shipping)
  const cartTotal = input.cart.lines.reduce((total, line) => {
    return total + Number(line.cost.totalAmount.amount);
  }, 0);

  // Calculate discount amount based on discount type
  let discountAmount;
  if (discountType.toLowerCase() === "percentage") {
    discountAmount = cartTotal * (discountRate/100);
  } else if (discountType.toLowerCase() === "fixed") {
    discountAmount = discountRate;
  } else {
    console.log(`Unsupported discount type: ${discountType}`);
    return EMPTY_DISCOUNT;
  }

  // Ensure discount amount does not exceed cart total
  discountAmount = Math.min(discountAmount, cartTotal);
  console.log(`Calculated discount amount for ${tier} tier: $${discountAmount.toFixed(2)} (${discountType})`);
  // Return discount object
  return {
    discountApplicationStrategy: DiscountApplicationStrategy.First,
    discounts: [
      {
        value: {
          fixedAmount: {
            amount: discountAmount.toFixed(2),
          }
        },
        targets: [
          {
            orderSubtotal: {
              excludedVariantIds: []
            }
          }
        ],
        message: `${tier} Tier Discount (${discountType === "percentage" ? (discountRate * 100).toFixed(0) + "%" : "$" + discountRate.toFixed(2)})`
      }
    ]
  };
}
