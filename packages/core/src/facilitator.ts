import type {
  PaymentAttempt,
  PaymentPendingReason,
  PaymentRecord,
  PaymentRejection
} from "./types.js";

export interface PaymentVerificationInput {
  readonly attempt: PaymentAttempt;
  readonly fingerprint: string;
}

export type PaymentVerificationResult =
  | {
      readonly kind: "verified";
      readonly payer?: string;
      readonly transactionId?: string;
    }
  | {
      readonly kind: "pending";
      readonly reason: Extract<
        PaymentPendingReason,
        "verification_pending" | "settlement_pending"
      >;
    }
  | {
      readonly kind: "rejected";
      readonly rejection: PaymentRejection;
    };

export interface PaymentSettlementInput {
  readonly record: PaymentRecord;
}

export type PaymentSettlementResult =
  | {
      readonly kind: "settled";
      readonly transactionId?: string;
      readonly payer?: string;
    }
  | {
      readonly kind: "pending";
      readonly reason: Extract<PaymentPendingReason, "settlement_pending">;
    }
  | {
      readonly kind: "rejected";
      readonly rejection: PaymentRejection;
    };

export interface PaymentFacilitator {
  /**
   * TODO: Tighten the presented-payment contract once Access402 finalizes its
   * normalized x402 payload shape.
   */
  verifyPayment(
    input: PaymentVerificationInput
  ): Promise<PaymentVerificationResult>;
  settlePayment(input: PaymentSettlementInput): Promise<PaymentSettlementResult>;
}
