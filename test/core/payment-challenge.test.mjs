import test from "node:test";
import assert from "node:assert/strict";

import {
  createPaymentChallenge,
  createPaymentRequiredResult
} from "../../packages/core/dist/index.js";

const route = {
  id: "route.paid-report",
  method: "GET",
  pathPattern: "/reports/:id",
  payment: {
    scheme: "exact",
    amount: {
      asset: {
        networkId: "eip155:8453",
        assetId: "eip155:8453/erc20:0x833589fCD6EDb6E08f4c7C32D4f71b54bdA02913",
        symbol: "USDC",
        decimals: 6,
        kind: "token"
      },
      atomicAmount: "1000"
    },
    payTo: "0xabc123",
    metadata: {
      priceTier: "standard"
    }
  }
};

test("createPaymentChallenge uses the route payment requirement and default reason", () => {
  const challenge = createPaymentChallenge(route);

  assert.deepEqual(challenge, {
    routeId: route.id,
    requirement: route.payment,
    reason: "payment_missing",
    details: undefined
  });
});

test("createPaymentRequiredResult returns a payment_required gate result", () => {
  const result = createPaymentRequiredResult({
    route,
    request: {
      method: "GET",
      path: "/reports/42",
      requestId: "req_123"
    }
  });

  assert.equal(result.kind, "payment_required");
  assert.equal(result.challenge.reason, "payment_missing");
  assert.deepEqual(result.challenge.requirement, route.payment);
});

test("createPaymentRequiredResult preserves the retry reason and challenge details", () => {
  const result = createPaymentRequiredResult(
    {
      route,
      request: {
        method: "GET",
        path: "/reports/42",
        requestId: "req_456"
      }
    },
    "payment_retry_required",
    {
      retryAfterMs: 2500,
      message: "Retry with a fresh payment signature."
    }
  );

  assert.equal(result.kind, "payment_required");
  assert.equal(result.challenge.reason, "payment_retry_required");
  assert.deepEqual(result.challenge.details, {
    retryAfterMs: 2500,
    message: "Retry with a fresh payment signature."
  });
});
