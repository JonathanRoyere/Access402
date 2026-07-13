import type {
  JsonObject,
  PaymentAttempt,
  PaymentGateResult,
  PaymentRecord,
  PaymentRejection,
  PresentedPayment,
  RoutePolicy
} from "@access402/core";

export interface Access402Context {
  readonly result: Extract<PaymentGateResult, { readonly kind: "accepted" }>;
  readonly record: PaymentRecord;
}

export interface Access402Request {
  readonly method?: string;
  readonly path?: string;
  readonly url?: string;
  readonly headers?: Record<string, string | string[] | undefined>;
  access402?: Access402Context;
  access402Payment?: PresentedPayment;
  [key: string]: unknown;
}

export interface Access402Response {
  status(code: number): this;
  json(body: unknown): this;
}

export type Access402Next = (error?: unknown) => void;

export interface PaymentGateLike {
  evaluate(attempt: PaymentAttempt): Promise<PaymentGateResult>;
}

export interface Access402MiddlewareOptions {
  readonly route: RoutePolicy;
  readonly gate: PaymentGateLike;
  readonly getPresentedPayment?: (
    request: Access402Request
  ) => PresentedPayment | undefined | Promise<PresentedPayment | undefined>;
  readonly getMetadata?: (
    request: Access402Request
  ) => JsonObject | undefined | Promise<JsonObject | undefined>;
}

export function createAccess402Middleware(
  options: Access402MiddlewareOptions
) {
  return async function access402Middleware(
    request: Access402Request,
    response: Access402Response,
    next: Access402Next
  ): Promise<void> {
    try {
      const payment =
        (await options.getPresentedPayment?.(request)) ??
        readPresentedPaymentFromRequest(request);
      const metadata = await options.getMetadata?.(request);

      const result = await options.gate.evaluate({
        route: options.route,
        request: {
          method: normalizeMethod(request.method, options.route.method),
          path: request.path ?? request.url ?? "/",
          requestId: readFirstHeader(request.headers, "x-request-id"),
          idempotencyKey: readFirstHeader(request.headers, "idempotency-key"),
          metadata
        },
        payment
      });

      if (result.kind === "accepted") {
        request.access402 = {
          result,
          record: result.record
        };
        next();
        return;
      }

      if (result.kind === "payment_required") {
        // TODO: Replace this JSON body with the finalized x402 HTTP challenge
        // transport once Access402 locks down its adapter wire contract.
        response.status(402).json({
          error: "payment_required",
          challenge: result.challenge
        });
        return;
      }

      if (result.kind === "pending") {
        response.status(409).json({
          error: "payment_pending",
          result
        });
        return;
      }

      if (result.kind === "rejected") {
        response.status(400).json({
          error: "payment_rejected",
          rejection: result.rejection,
          record: result.record ?? null
        });
        return;
      }

      response.status(503).json({
        error: "payment_unresolved",
        reason: result.reason,
        record: result.record
      });
    } catch (error) {
      if (error instanceof MalformedPresentedPaymentError) {
        response.status(400).json({
          error: "payment_rejected",
          rejection: createInvalidPaymentRejection(error.message),
          record: null
        });
        return;
      }

      next(error);
    }
  };
}

function normalizeMethod(
  method: string | undefined,
  fallback: PaymentAttempt["request"]["method"]
): PaymentAttempt["request"]["method"] {
  const normalized = (method ?? "GET").toUpperCase();

  if (
    normalized === "GET" ||
    normalized === "POST" ||
    normalized === "PUT" ||
    normalized === "PATCH" ||
    normalized === "DELETE" ||
    normalized === "OPTIONS" ||
    normalized === "HEAD"
  ) {
    return normalized;
  }

  return fallback;
}

function readPresentedPaymentFromRequest(
  request: Access402Request
): PresentedPayment | undefined {
  if (request.access402Payment) {
    return request.access402Payment;
  }

  const headerValue = readFirstHeader(request.headers, "x-access402-payment");

  if (!headerValue) {
    return undefined;
  }

  // TODO: Replace this custom header transport once Access402 commits to the
  // initial x402-compatible request format it wants adapters to accept.
  const parsed = parsePresentedPaymentHeader(headerValue);

  if (!parsed || typeof parsed !== "object") {
    return {
      raw: parsed
    };
  }

  const candidate = parsed as Record<string, unknown>;

  return {
    raw: parsed,
    scheme: readString(candidate.scheme),
    networkId: readString(candidate.networkId),
    assetId: readString(candidate.assetId),
    payTo: readString(candidate.payTo),
    transactionId: readString(candidate.transactionId),
    payer: readString(candidate.payer),
    signature: readString(candidate.signature),
    expiresAt: readString(candidate.expiresAt)
  };
}

function readFirstHeader(
  headers: Record<string, string | string[] | undefined> | undefined,
  name: string
): string | undefined {
  const directValue = headers?.[name];

  if (directValue !== undefined) {
    if (Array.isArray(directValue)) {
      return directValue[0];
    }

    return directValue;
  }

  const entry = Object.entries(headers ?? {}).find(
    ([headerName]) => headerName.toLowerCase() === name
  );

  if (!entry) {
    return undefined;
  }

  const [, value] = entry;

  if (Array.isArray(value)) {
    return value[0];
  }

  return value;
}

function readString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function parsePresentedPaymentHeader(headerValue: string): unknown {
  try {
    return JSON.parse(headerValue);
  } catch {
    throw new MalformedPresentedPaymentError(
      "Presented payment header is not valid JSON."
    );
  }
}

function createInvalidPaymentRejection(message: string): PaymentRejection {
  return {
    code: "invalid_payment",
    message,
    retryable: true
  };
}

class MalformedPresentedPaymentError extends Error {}
