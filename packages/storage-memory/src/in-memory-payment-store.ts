import type { PaymentRecord, PaymentStore } from "@access402/core";

export interface InMemoryPaymentStoreOptions {
  readonly seedRecords?: Iterable<PaymentRecord>;
}

export class InMemoryPaymentStore implements PaymentStore {
  private readonly recordsByFingerprint = new Map<string, PaymentRecord>();

  constructor(options: InMemoryPaymentStoreOptions = {}) {
    for (const record of options.seedRecords ?? []) {
      this.recordsByFingerprint.set(record.fingerprint, cloneRecord(record));
    }
  }

  async findByFingerprint(fingerprint: string): Promise<PaymentRecord | null> {
    const record = this.recordsByFingerprint.get(fingerprint);
    return record ? cloneRecord(record) : null;
  }

  async put(record: PaymentRecord): Promise<void> {
    this.recordsByFingerprint.set(record.fingerprint, cloneRecord(record));
  }

  async clear(): Promise<void> {
    this.recordsByFingerprint.clear();
  }

  async size(): Promise<number> {
    return this.recordsByFingerprint.size;
  }
}

function cloneRecord(record: PaymentRecord): PaymentRecord {
  return structuredClone(record);
}
