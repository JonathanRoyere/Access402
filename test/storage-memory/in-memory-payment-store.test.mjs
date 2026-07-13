import test from "node:test";
import assert from "node:assert/strict";

import { InMemoryPaymentStore } from "../../packages/storage-memory/dist/index.js";

const record = {
  id: "payment_record_001",
  routeId: "route.paid-report",
  fingerprint: "tx:route.paid-report:0xtx_123",
  state: "verification_pending",
  requirement: {
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
  },
  createdAt: "2026-07-13T12:00:00.000Z",
  updatedAt: "2026-07-13T12:00:00.000Z"
};

test("InMemoryPaymentStore stores and retrieves payment records by fingerprint", async () => {
  const store = new InMemoryPaymentStore();

  await store.put(record);
  const stored = await store.findByFingerprint(record.fingerprint);

  assert.deepEqual(stored, record);
  assert.equal(await store.size(), 1);
});

test("InMemoryPaymentStore returns cloned records so callers cannot mutate internal state", async () => {
  const store = new InMemoryPaymentStore();

  await store.put(record);
  const stored = await store.findByFingerprint(record.fingerprint);
  stored.state = "rejected";

  const reread = await store.findByFingerprint(record.fingerprint);

  assert.equal(reread.state, "verification_pending");
});

test("InMemoryPaymentStore can seed and clear records", async () => {
  const store = new InMemoryPaymentStore({
    seedRecords: [record]
  });

  assert.equal(await store.size(), 1);
  await store.clear();
  assert.equal(await store.size(), 0);
});
