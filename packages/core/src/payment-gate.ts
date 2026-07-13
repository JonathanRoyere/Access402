import { randomUUID } from "node:crypto";
import type {
  PaymentFacilitator,
  PaymentSettlementResult,
  PaymentVerificationResult
} from "./facilitator.js";
import { createPaymentRequiredResult } from "./payment-challenge.js";
import type { PaymentStore } from "./store.js";
import type {
  IsoTimestamp,
  PaymentAttempt,
  PaymentGateResult,
  PaymentPendingReason,
  PaymentRecord,
  PaymentRecordState,
  PaymentRejection
} from "./types.js";

export interface PaymentGateOptions {
  readonly facilitator: PaymentFacilitator;
  readonly store: PaymentStore;
  readonly now?: () => IsoTimestamp;
  readonly createRecordId?: () => string;
  readonly createReceiptId?: () => string;
}

export class PaymentGate {
  private readonly facilitator: PaymentFacilitator;
  private readonly store: PaymentStore;
  private readonly now: () => IsoTimestamp;
  private readonly createRecordId: () => string;
  private readonly createReceiptId: () => string;

  constructor(options: PaymentGateOptions) {
    this.facilitator = options.facilitator;
    this.store = options.store;
    this.now = options.now ?? (() => new Date().toISOString());
    this.createRecordId =
      options.createRecordId ?? (() => `payment_${randomUUID()}`);
    this.createReceiptId =
      options.createReceiptId ?? (() => `receipt_${randomUUID()}`);
  }

  async evaluate(attempt: PaymentAttempt): Promise<PaymentGateResult> {
    if (!attempt.payment) {
      return createPaymentRequiredResult(attempt);
    }

    const rejection = this.getObviousRejection(attempt);

    if (rejection) {
      return {
        kind: "rejected",
        rejection
      };
    }

    const fingerprint = derivePaymentFingerprint(attempt);

    if (!fingerprint) {
      return {
        kind: "rejected",
        rejection: {
          code: "invalid_payment",
          message:
            "Payment attempt is missing a stable identifier for replay protection.",
          retryable: true
        }
      };
    }

    const existingRecord = await this.store.findByFingerprint(fingerprint);

    if (existingRecord) {
      return mapExistingRecordToGateResult(existingRecord);
    }

    const verification = await this.facilitator.verifyPayment({
      attempt,
      fingerprint
    });

    return this.createVerificationResult(attempt, fingerprint, verification);
  }

  private getObviousRejection(
    attempt: PaymentAttempt
  ): PaymentRejection | null {
    const payment = attempt.payment;

    if (!payment) {
      return null;
    }

    const requirement = attempt.route.payment;
    const expectedAsset = requirement.amount.asset;

    if (payment.scheme && payment.scheme !== requirement.scheme) {
      return {
        code: "invalid_payment",
        message: `Expected payment scheme ${requirement.scheme} but received ${payment.scheme}.`,
        retryable: true
      };
    }

    if (payment.networkId && payment.networkId !== expectedAsset.networkId) {
      return {
        code: "wrong_network",
        message: `Expected network ${expectedAsset.networkId} but received ${payment.networkId}.`,
        retryable: true
      };
    }

    if (payment.assetId && payment.assetId !== expectedAsset.assetId) {
      return {
        code: "wrong_asset",
        message: `Expected asset ${expectedAsset.assetId} but received ${payment.assetId}.`,
        retryable: true
      };
    }

    if (payment.payTo && payment.payTo !== requirement.payTo) {
      return {
        code: "wrong_recipient",
        message: `Expected recipient ${requirement.payTo} but received ${payment.payTo}.`,
        retryable: true
      };
    }

    if (payment.expiresAt) {
      const expirationStatus = getExpirationStatus(payment.expiresAt, this.now());

      if (expirationStatus === "invalid") {
        return {
          code: "invalid_payment",
          message: "Presented payment expiration timestamp is invalid.",
          retryable: true
        };
      }

      if (expirationStatus === "expired") {
        return {
          code: "payment_expired",
          message: "Presented payment has expired and must be refreshed.",
          retryable: true
        };
      }
    }

    return null;
  }

  private async createVerificationResult(
    attempt: PaymentAttempt,
    fingerprint: string,
    verification: PaymentVerificationResult
  ): Promise<PaymentGateResult> {
    if (verification.kind === "verified") {
      const authorizedRecord = this.createRecord(attempt, fingerprint, {
        state: "authorized",
        payerId:
          attempt.request.payerId ??
          verification.payer ??
          attempt.payment?.payer,
        transactionId:
          verification.transactionId ?? attempt.payment?.transactionId
      });

      const settlement = await this.facilitator.settlePayment({
        record: authorizedRecord
      });

      return this.createSettlementResult(authorizedRecord, settlement);
    }

    if (verification.kind === "pending") {
      const state =
        verification.reason === "settlement_pending"
          ? "settlement_pending"
          : "verification_pending";
      const record = this.createRecord(attempt, fingerprint, {
        state
      });

      await this.store.put(record);

      return {
        kind: "pending",
        record,
        reason: verification.reason
      };
    }

    const record = this.createRecord(attempt, fingerprint, {
      state: "rejected",
      rejection: verification.rejection
    });

    await this.store.put(record);

    return {
      kind: "rejected",
      record,
      rejection: verification.rejection
    };
  }

  private async createSettlementResult(
    authorizedRecord: PaymentRecord,
    settlement: PaymentSettlementResult
  ): Promise<PaymentGateResult> {
    if (settlement.kind === "settled") {
      const timestamp = this.now();
      const payerId = settlement.payer ?? authorizedRecord.payerId;
      const transactionId =
        settlement.transactionId ?? authorizedRecord.transactionId;
      const receipt = {
        id: this.createReceiptId(),
        routeId: authorizedRecord.routeId,
        fingerprint: authorizedRecord.fingerprint,
        requirement: authorizedRecord.requirement,
        settledAt: timestamp,
        transactionId,
        payer: payerId
      };
      const record: PaymentRecord = {
        ...authorizedRecord,
        state: "settled",
        payerId,
        transactionId,
        updatedAt: timestamp,
        receipt,
        rejection: undefined
      };

      await this.store.put(record);

      return {
        kind: "accepted",
        record
      };
    }

    if (settlement.kind === "pending") {
      const record: PaymentRecord = {
        ...authorizedRecord,
        state: "settlement_pending",
        updatedAt: this.now()
      };

      await this.store.put(record);

      return {
        kind: "pending",
        record,
        reason: settlement.reason
      };
    }

    const record: PaymentRecord = {
      ...authorizedRecord,
      state: "rejected",
      updatedAt: this.now(),
      rejection: settlement.rejection
    };

    await this.store.put(record);

    return {
      kind: "rejected",
      record,
      rejection: settlement.rejection
    };
  }

  private createRecord(
    attempt: PaymentAttempt,
    fingerprint: string,
    overrides: {
      readonly state: PaymentRecordState;
      readonly payerId?: string;
      readonly transactionId?: string;
      readonly rejection?: PaymentRejection;
    }
  ): PaymentRecord {
    const timestamp = this.now();

    return {
      id: this.createRecordId(),
      routeId: attempt.route.id,
      fingerprint,
      state: overrides.state,
      requirement: attempt.route.payment,
      requestId: attempt.request.requestId,
      idempotencyKey: attempt.request.idempotencyKey,
      operationId: attempt.request.operationId,
      payerId:
        overrides.payerId ?? attempt.request.payerId ?? attempt.payment?.payer,
      transactionId:
        overrides.transactionId ?? attempt.payment?.transactionId,
      createdAt: timestamp,
      updatedAt: timestamp,
      rejection: overrides.rejection,
      metadata: attempt.request.metadata
    };
  }
}

function mapExistingRecordToGateResult(record: PaymentRecord): PaymentGateResult {
  if (record.state === "settled") {
    return {
      kind: "accepted",
      record
    };
  }

  if (record.state === "rejected") {
    return {
      kind: "rejected",
      record,
      rejection:
        record.rejection ??
        ({
          code: "invalid_payment",
          message: "Stored payment record is rejected without a rejection payload.",
          retryable: false
        } satisfies PaymentRejection)
    };
  }

  if (record.state === "unresolved") {
    return {
      kind: "unresolved",
      record,
      reason: "Stored payment record is unresolved and requires recovery."
    };
  }

  return {
    kind: "pending",
    record,
    reason: mapPendingReason(record.state)
  };
}

function mapPendingReason(state: PaymentRecordState): PaymentPendingReason {
  if (state === "settlement_pending" || state === "authorized") {
    return "settlement_pending";
  }

  return "duplicate_in_flight";
}

function derivePaymentFingerprint(attempt: PaymentAttempt): string | null {
  const routeId = attempt.route.id;

  if (attempt.payment?.transactionId) {
    return `tx:${routeId}:${attempt.payment.transactionId}`;
  }

  if (attempt.request.idempotencyKey) {
    return `idempotency:${routeId}:${attempt.request.idempotencyKey}`;
  }

  if (attempt.request.operationId) {
    return `operation:${routeId}:${attempt.request.operationId}`;
  }

  /**
   * TODO: Replace this fallback strategy with a normalized x402 payment
   * identifier once Access402 formalizes its replay-protection contract.
   */
  return null;
}

function getExpirationStatus(
  expiresAt: IsoTimestamp,
  now: IsoTimestamp
): "active" | "expired" | "invalid" {
  const expiresAtMs = Date.parse(expiresAt);
  const nowMs = Date.parse(now);

  if (Number.isNaN(expiresAtMs) || Number.isNaN(nowMs)) {
    return "invalid";
  }

  if (expiresAtMs <= nowMs) {
    return "expired";
  }

  return "active";
}
