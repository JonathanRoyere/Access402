export type IsoTimestamp = string;

export type JsonPrimitive = string | number | boolean | null;

export interface JsonObject {
  readonly [key: string]: JsonValue;
}

export interface JsonArray extends ReadonlyArray<JsonValue> {}

export type JsonValue = JsonPrimitive | JsonObject | JsonArray;

export type HttpMethod =
  | "GET"
  | "POST"
  | "PUT"
  | "PATCH"
  | "DELETE"
  | "OPTIONS"
  | "HEAD";

/**
 * Access402 persists its own record lifecycle.
 * This is intentionally separate from the higher-level x402 request flow.
 */
export type PaymentRecordState =
  | "verification_pending"
  | "authorized"
  | "settlement_pending"
  | "settled"
  | "rejected"
  | "unresolved";

export interface PaymentAsset {
  /**
   * TODO: Standardize on a canonical network identifier format across adapters.
   * CAIP-2 is a likely candidate, but we should confirm that before locking it in.
   */
  readonly networkId: string;
  /**
   * TODO: Standardize on a canonical asset identifier format for native assets
   * and tokens, for example a CAIP-19-like value or a chain-specific address.
   */
  readonly assetId: string;
  readonly symbol: string;
  readonly decimals: number;
  readonly kind: "native" | "token";
}

export interface AssetAmount {
  readonly asset: PaymentAsset;
  /**
   * Atomic units only. This avoids floating point mistakes and keeps values
   * exact across chains with different decimal precision.
   */
  readonly atomicAmount: string;
}

export interface PaymentRequirement {
  /**
   * TODO: Narrow this once Access402 commits to its initial supported x402
   * payment schemes.
   */
  readonly scheme: string;
  readonly amount: AssetAmount;
  readonly payTo: string;
  readonly metadata?: JsonObject;
}

export interface RoutePolicy {
  readonly id: string;
  readonly method: HttpMethod;
  /**
   * TODO: Standardize route pattern syntax and matching semantics across
   * adapters, for example Express-style "/reports/:id".
   */
  readonly pathPattern: string;
  readonly payment: PaymentRequirement;
}

export interface RequestContext {
  readonly method: HttpMethod;
  readonly path: string;
  readonly requestId?: string;
  readonly idempotencyKey?: string;
  /**
   * Application-level operation identifier, such as an order id or job id.
   * This helps Access402 tie payment attempts to a business action.
   */
  readonly operationId?: string;
  readonly payerId?: string;
  readonly metadata?: JsonObject;
}

export interface PresentedPayment {
  /**
   * TODO: Replace this with a typed normalized x402 payment payload once
   * Access402 finalizes the supported wire formats.
   */
  readonly raw: unknown;
  readonly scheme?: string;
  readonly networkId?: string;
  readonly assetId?: string;
  readonly payTo?: string;
  readonly transactionId?: string;
  readonly payer?: string;
  readonly signature?: string;
  readonly expiresAt?: IsoTimestamp;
}

export interface PaymentAttempt {
  readonly route: RoutePolicy;
  readonly request: RequestContext;
  readonly payment?: PresentedPayment;
  readonly receivedAt?: IsoTimestamp;
}

export type PaymentChallengeReason =
  | "payment_missing"
  | "payment_retry_required";

export interface PaymentChallenge {
  readonly routeId: string;
  readonly requirement: PaymentRequirement;
  readonly reason: PaymentChallengeReason;
  /**
   * TODO: Replace this with a typed adapter-facing challenge payload once the
   * HTTP wire contract is finalized.
   */
  readonly details?: JsonObject;
}

export type PaymentRejectionCode =
  | "invalid_payment"
  | "payment_expired"
  | "duplicate_payment"
  | "wrong_amount"
  | "wrong_asset"
  | "wrong_network"
  | "wrong_recipient"
  | "verification_failed"
  | "settlement_failed";

export interface PaymentRejection {
  readonly code: PaymentRejectionCode;
  readonly message: string;
  readonly retryable: boolean;
}

export interface PaymentReceipt {
  readonly id: string;
  readonly routeId: string;
  readonly fingerprint: string;
  readonly requirement: PaymentRequirement;
  readonly settledAt: IsoTimestamp;
  readonly transactionId?: string;
  readonly payer?: string;
  readonly metadata?: JsonObject;
}

export interface PaymentRecord {
  readonly id: string;
  readonly routeId: string;
  readonly fingerprint: string;
  readonly state: PaymentRecordState;
  readonly requirement: PaymentRequirement;
  readonly requestId?: string;
  readonly idempotencyKey?: string;
  readonly operationId?: string;
  readonly payerId?: string;
  readonly transactionId?: string;
  readonly createdAt: IsoTimestamp;
  readonly updatedAt: IsoTimestamp;
  readonly receipt?: PaymentReceipt;
  readonly rejection?: PaymentRejection;
  readonly metadata?: JsonObject;
}

export type PaymentPendingReason =
  | "verification_pending"
  | "settlement_pending"
  | "duplicate_in_flight";

export type PaymentGateResult =
  | { readonly kind: "payment_required"; readonly challenge: PaymentChallenge }
  | { readonly kind: "accepted"; readonly record: PaymentRecord }
  | {
      readonly kind: "pending";
      readonly record: PaymentRecord;
      readonly reason: PaymentPendingReason;
    }
  | {
      readonly kind: "rejected";
      readonly record?: PaymentRecord;
      readonly rejection: PaymentRejection;
    }
  | {
      readonly kind: "unresolved";
      readonly record: PaymentRecord;
      readonly reason: string;
    };
