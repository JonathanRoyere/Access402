import test from "node:test";
import assert from "node:assert/strict";

import { PaymentGate } from "../../packages/core/dist/index.js";
import { createAccess402Middleware } from "../../packages/express/dist/index.js";
import { InMemoryPaymentStore } from "../../packages/storage-memory/dist/index.js";

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
    payTo: "0xabc123"
  }
};

class MockPaymentFacilitator {
  nextVerificationResult = {
    kind: "verified",
    payer: "0xverified-payer",
    transactionId: "0xverified_tx"
  };
  nextSettlementResult = {
    kind: "settled",
    payer: "0xsettled-payer",
    transactionId: "0xsettled_tx"
  };

  async verifyPayment() {
    return this.nextVerificationResult;
  }

  async settlePayment() {
    return this.nextSettlementResult;
  }
}

function createResponse() {
  return {
    statusCode: undefined,
    jsonBody: undefined,
    status(code) {
      this.statusCode = code;
      return this;
    },
    json(body) {
      this.jsonBody = body;
      return this;
    }
  };
}

test("createAccess402Middleware returns a 402 challenge when payment is missing", async () => {
  const gate = new PaymentGate({
    facilitator: new MockPaymentFacilitator(),
    store: new InMemoryPaymentStore(),
    now: () => "2026-07-13T12:00:00.000Z",
    createRecordId: () => "payment_record_001",
    createReceiptId: () => "receipt_001"
  });
  const middleware = createAccess402Middleware({
    route,
    gate
  });
  const request = {
    method: "GET",
    path: "/reports/42",
    headers: {}
  };
  const response = createResponse();
  let nextCalled = false;

  await middleware(request, response, () => {
    nextCalled = true;
  });

  assert.equal(nextCalled, false);
  assert.equal(response.statusCode, 402);
  assert.equal(response.jsonBody.error, "payment_required");
});

test("createAccess402Middleware advances accepted payments and attaches access402 context", async () => {
  const gate = new PaymentGate({
    facilitator: new MockPaymentFacilitator(),
    store: new InMemoryPaymentStore(),
    now: () => "2026-07-13T12:00:00.000Z",
    createRecordId: () => "payment_record_001",
    createReceiptId: () => "receipt_001"
  });
  const middleware = createAccess402Middleware({
    route,
    gate
  });
  const request = {
    method: "GET",
    path: "/reports/42",
    headers: {
      "x-access402-payment": JSON.stringify({
        scheme: "exact",
        networkId: route.payment.amount.asset.networkId,
        assetId: route.payment.amount.asset.assetId,
        transactionId: "0xtx_123"
      })
    }
  };
  const response = createResponse();
  let nextCalled = false;

  await middleware(request, response, () => {
    nextCalled = true;
  });

  assert.equal(nextCalled, true);
  assert.equal(request.access402.result.kind, "accepted");
  assert.equal(request.access402.record.state, "settled");
  assert.equal(response.statusCode, undefined);
});

test("createAccess402Middleware maps payment rejections to HTTP 400", async () => {
  const facilitator = new MockPaymentFacilitator();
  facilitator.nextVerificationResult = {
    kind: "rejected",
    rejection: {
      code: "verification_failed",
      message: "Signature could not be verified.",
      retryable: false
    }
  };
  const gate = new PaymentGate({
    facilitator,
    store: new InMemoryPaymentStore(),
    now: () => "2026-07-13T12:00:00.000Z",
    createRecordId: () => "payment_record_001",
    createReceiptId: () => "receipt_001"
  });
  const middleware = createAccess402Middleware({
    route,
    gate
  });
  const request = {
    method: "GET",
    path: "/reports/42",
    headers: {
      "x-access402-payment": JSON.stringify({
        scheme: "exact",
        networkId: route.payment.amount.asset.networkId,
        assetId: route.payment.amount.asset.assetId,
        transactionId: "0xtx_123"
      })
    }
  };
  const response = createResponse();
  let nextCalled = false;

  await middleware(request, response, () => {
    nextCalled = true;
  });

  assert.equal(nextCalled, false);
  assert.equal(response.statusCode, 400);
  assert.equal(response.jsonBody.error, "payment_rejected");
  assert.equal(response.jsonBody.rejection.code, "verification_failed");
});

test("createAccess402Middleware reads payment headers case-insensitively", async () => {
  const gate = new PaymentGate({
    facilitator: new MockPaymentFacilitator(),
    store: new InMemoryPaymentStore(),
    now: () => "2026-07-13T12:00:00.000Z",
    createRecordId: () => "payment_record_001",
    createReceiptId: () => "receipt_001"
  });
  const middleware = createAccess402Middleware({
    route,
    gate
  });
  const request = {
    method: "GET",
    path: "/reports/42",
    headers: {
      "X-Access402-Payment": JSON.stringify({
        scheme: "exact",
        networkId: route.payment.amount.asset.networkId,
        assetId: route.payment.amount.asset.assetId,
        transactionId: "0xtx_case_test"
      })
    }
  };
  const response = createResponse();
  let nextCalled = false;

  await middleware(request, response, () => {
    nextCalled = true;
  });

  assert.equal(nextCalled, true);
  assert.equal(request.access402.result.kind, "accepted");
});

test("createAccess402Middleware rejects malformed payment headers with HTTP 400", async () => {
  const gate = new PaymentGate({
    facilitator: new MockPaymentFacilitator(),
    store: new InMemoryPaymentStore(),
    now: () => "2026-07-13T12:00:00.000Z",
    createRecordId: () => "payment_record_001",
    createReceiptId: () => "receipt_001"
  });
  const middleware = createAccess402Middleware({
    route,
    gate
  });
  const request = {
    method: "GET",
    path: "/reports/42",
    headers: {
      "x-access402-payment": "{not-json"
    }
  };
  const response = createResponse();
  let nextError;

  await middleware(request, response, (error) => {
    nextError = error;
  });

  assert.equal(nextError, undefined);
  assert.equal(response.statusCode, 400);
  assert.equal(response.jsonBody.error, "payment_rejected");
  assert.equal(response.jsonBody.rejection.code, "invalid_payment");
});
