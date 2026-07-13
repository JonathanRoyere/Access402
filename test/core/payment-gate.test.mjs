import test from "node:test";
import assert from "node:assert/strict";

import { PaymentGate } from "../../packages/core/dist/index.js";

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

class MemoryPaymentStore {
  recordsByFingerprint = new Map();
  putCalls = 0;

  async findByFingerprint(fingerprint) {
    return this.recordsByFingerprint.get(fingerprint) ?? null;
  }

  async put(record) {
    this.putCalls += 1;
    this.recordsByFingerprint.set(record.fingerprint, record);
  }
}

class MockPaymentFacilitator {
  verifyCalls = [];
  nextVerificationResult = {
    kind: "pending",
    reason: "verification_pending"
  };
  settleCalls = [];
  nextSettlementResult = {
    kind: "pending",
    reason: "settlement_pending"
  };

  async verifyPayment(input) {
    this.verifyCalls.push(input);
    return this.nextVerificationResult;
  }

  async settlePayment(input) {
    this.settleCalls.push(input);
    return this.nextSettlementResult;
  }
}

function createGate(
  store,
  facilitator = new MockPaymentFacilitator()
) {
  return new PaymentGate({
    facilitator,
    store,
    now: () => "2026-07-13T12:00:00.000Z",
    createRecordId: () => "payment_record_001",
    createReceiptId: () => "receipt_001"
  });
}

function createBaseAttempt() {
  return {
    route,
    request: {
      method: "GET",
      path: "/reports/42",
      requestId: "req_123",
      operationId: "order_42"
    }
  };
}

test("PaymentGate returns payment_required when payment is missing", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate(createBaseAttempt());

  assert.equal(result.kind, "payment_required");
  assert.equal(result.challenge.reason, "payment_missing");
  assert.equal(store.putCalls, 0);
  assert.equal(facilitator.verifyCalls.length, 0);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate rejects obviously wrong network payments before facilitator verification", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_123",
      networkId: "eip155:1",
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "rejected");
  assert.equal(result.rejection.code, "wrong_network");
  assert.equal(store.putCalls, 0);
  assert.equal(facilitator.verifyCalls.length, 0);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate rejects wrong recipient payments before facilitator verification", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_124",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId,
      payTo: "0xnot-the-seller"
    }
  });

  assert.equal(result.kind, "rejected");
  assert.equal(result.rejection.code, "wrong_recipient");
  assert.equal(store.putCalls, 0);
  assert.equal(facilitator.verifyCalls.length, 0);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate rejects malformed expiration timestamps before facilitator verification", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_125",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId,
      expiresAt: "not-a-real-date"
    }
  });

  assert.equal(result.kind, "rejected");
  assert.equal(result.rejection.code, "invalid_payment");
  assert.equal(store.putCalls, 0);
  assert.equal(facilitator.verifyCalls.length, 0);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate stores a verification_pending record when facilitator leaves verification pending", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  facilitator.nextVerificationResult = {
    kind: "pending",
    reason: "verification_pending"
  };
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_126",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId,
      payer: "0xpayer"
    }
  });

  assert.equal(result.kind, "pending");
  assert.equal(result.reason, "verification_pending");
  assert.equal(result.record.state, "verification_pending");
  assert.equal(result.record.fingerprint, "tx:route.paid-report:0xtx_126");
  assert.equal(result.record.id, "payment_record_001");
  assert.equal(store.putCalls, 1);
  assert.equal(facilitator.verifyCalls.length, 1);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate stores a settlement_pending record when settlement remains pending", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  facilitator.nextVerificationResult = {
    kind: "verified",
    payer: "0xverified-payer",
    transactionId: "0xverified_tx"
  };
  facilitator.nextSettlementResult = {
    kind: "pending",
    reason: "settlement_pending"
  };
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_127",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "pending");
  assert.equal(result.reason, "settlement_pending");
  assert.equal(result.record.state, "settlement_pending");
  assert.equal(result.record.payerId, "0xverified-payer");
  assert.equal(result.record.transactionId, "0xverified_tx");
  assert.equal(store.putCalls, 1);
  assert.equal(facilitator.verifyCalls.length, 1);
  assert.equal(facilitator.settleCalls.length, 1);
});

test("PaymentGate stores a settled record and receipt when settlement succeeds", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  facilitator.nextVerificationResult = {
    kind: "verified",
    payer: "0xverified-payer",
    transactionId: "0xverified_tx"
  };
  facilitator.nextSettlementResult = {
    kind: "settled",
    payer: "0xsettled-payer",
    transactionId: "0xsettled_tx"
  };
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_128",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "accepted");
  assert.equal(result.record.state, "settled");
  assert.equal(result.record.transactionId, "0xsettled_tx");
  assert.equal(result.record.payerId, "0xsettled-payer");
  assert.equal(result.record.receipt.id, "receipt_001");
  assert.equal(result.record.receipt.transactionId, "0xsettled_tx");
  assert.equal(result.record.receipt.payer, "0xsettled-payer");
  assert.equal(store.putCalls, 1);
  assert.equal(facilitator.verifyCalls.length, 1);
  assert.equal(facilitator.settleCalls.length, 1);
});

test("PaymentGate stores and returns settlement rejections", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  facilitator.nextVerificationResult = {
    kind: "verified",
    payer: "0xverified-payer",
    transactionId: "0xverified_tx"
  };
  facilitator.nextSettlementResult = {
    kind: "rejected",
    rejection: {
      code: "settlement_failed",
      message: "Settlement could not be finalized.",
      retryable: true
    }
  };
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_129",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "rejected");
  assert.equal(result.rejection.code, "settlement_failed");
  assert.equal(result.record.state, "rejected");
  assert.equal(result.record.rejection.code, "settlement_failed");
  assert.equal(store.putCalls, 1);
  assert.equal(facilitator.verifyCalls.length, 1);
  assert.equal(facilitator.settleCalls.length, 1);
});

test("PaymentGate stores and returns facilitator verification rejections without settling", async () => {
  const store = new MemoryPaymentStore();
  const facilitator = new MockPaymentFacilitator();
  facilitator.nextVerificationResult = {
    kind: "rejected",
    rejection: {
      code: "verification_failed",
      message: "Signature could not be verified.",
      retryable: false
    }
  };
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_130",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "rejected");
  assert.equal(result.rejection.code, "verification_failed");
  assert.equal(result.record.state, "rejected");
  assert.equal(result.record.rejection.code, "verification_failed");
  assert.equal(store.putCalls, 1);
  assert.equal(facilitator.verifyCalls.length, 1);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate returns duplicate_in_flight for an existing in-progress record without re-verifying", async () => {
  const store = new MemoryPaymentStore();
  store.recordsByFingerprint.set("tx:route.paid-report:0xtx_131", {
    id: "payment_record_existing",
    routeId: route.id,
    fingerprint: "tx:route.paid-report:0xtx_131",
    state: "verification_pending",
    requirement: route.payment,
    createdAt: "2026-07-13T10:00:00.000Z",
    updatedAt: "2026-07-13T10:05:00.000Z"
  });
  const facilitator = new MockPaymentFacilitator();
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_131",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "pending");
  assert.equal(result.reason, "duplicate_in_flight");
  assert.equal(result.record.id, "payment_record_existing");
  assert.equal(store.putCalls, 0);
  assert.equal(facilitator.verifyCalls.length, 0);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate returns accepted for an existing settled record without re-verifying", async () => {
  const store = new MemoryPaymentStore();
  store.recordsByFingerprint.set("tx:route.paid-report:0xtx_999", {
    id: "payment_record_settled",
    routeId: route.id,
    fingerprint: "tx:route.paid-report:0xtx_999",
    state: "settled",
    requirement: route.payment,
    createdAt: "2026-07-13T10:00:00.000Z",
    updatedAt: "2026-07-13T10:05:00.000Z",
    receipt: {
      id: "receipt_999",
      routeId: route.id,
      fingerprint: "tx:route.paid-report:0xtx_999",
      requirement: route.payment,
      settledAt: "2026-07-13T10:05:00.000Z"
    }
  });
  const facilitator = new MockPaymentFacilitator();
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_999",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "accepted");
  assert.equal(result.record.id, "payment_record_settled");
  assert.equal(store.putCalls, 0);
  assert.equal(facilitator.verifyCalls.length, 0);
  assert.equal(facilitator.settleCalls.length, 0);
});

test("PaymentGate returns unresolved for an existing unresolved record without re-verifying", async () => {
  const store = new MemoryPaymentStore();
  store.recordsByFingerprint.set("tx:route.paid-report:0xtx_404", {
    id: "payment_record_unresolved",
    routeId: route.id,
    fingerprint: "tx:route.paid-report:0xtx_404",
    state: "unresolved",
    requirement: route.payment,
    createdAt: "2026-07-13T10:00:00.000Z",
    updatedAt: "2026-07-13T10:05:00.000Z"
  });
  const facilitator = new MockPaymentFacilitator();
  const gate = createGate(store, facilitator);

  const result = await gate.evaluate({
    ...createBaseAttempt(),
    payment: {
      raw: { signed: true },
      scheme: "exact",
      transactionId: "0xtx_404",
      networkId: route.payment.amount.asset.networkId,
      assetId: route.payment.amount.asset.assetId
    }
  });

  assert.equal(result.kind, "unresolved");
  assert.equal(result.record.id, "payment_record_unresolved");
  assert.match(result.reason, /requires recovery/i);
  assert.equal(store.putCalls, 0);
  assert.equal(facilitator.verifyCalls.length, 0);
  assert.equal(facilitator.settleCalls.length, 0);
});
