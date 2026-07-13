import type {
  JsonObject,
  PaymentAttempt,
  PaymentChallenge,
  PaymentChallengeReason,
  PaymentGateResult,
  RoutePolicy
} from "./types.js";

export function createPaymentChallenge(
  route: RoutePolicy,
  reason: PaymentChallengeReason = "payment_missing",
  details?: JsonObject
): PaymentChallenge {
  return {
    routeId: route.id,
    requirement: route.payment,
    reason,
    details
  };
}

export function createPaymentRequiredResult(
  attempt: PaymentAttempt,
  reason: PaymentChallengeReason = "payment_missing",
  details?: JsonObject
): Extract<PaymentGateResult, { readonly kind: "payment_required" }> {
  return {
    kind: "payment_required",
    challenge: createPaymentChallenge(attempt.route, reason, details)
  };
}
