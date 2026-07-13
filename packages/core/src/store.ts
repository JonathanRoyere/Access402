import type { PaymentRecord } from "./types.js";

export interface PaymentStore {
  findByFingerprint(fingerprint: string): Promise<PaymentRecord | null>;
  /**
   * TODO: Add conditional-write / lock semantics before production adapters use
   * this interface for concurrent payment flows.
   */
  put(record: PaymentRecord): Promise<void>;
}
