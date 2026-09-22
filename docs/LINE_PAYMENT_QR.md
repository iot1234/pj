# LINE Payment QR — PHP/MySQL

## Scope and parity

Uses `newap/services/billLineMessages.js` as a read-only reference for a rich bill card, a signed payment image and a portal action. `newap` is unchanged. PHP remains single-dorm/single-primary-OA; it does not inherit newap's public bill-detail tokens or manual paid actions.

Both admin single/bulk bill delivery and the resident `บิล` command now send a Flex card with an inline PNG QR. It includes bill reference, room snapshot, billing period, due date, original principal, exact reserved transfer amount, recipient display name, a portal button and slip fallback guidance. Only authenticated portal users can see bill details in the browser.

## Data and safety

- `LineBillService` uses the existing transactional `TransferInstructionService`; no extra amount allocator or schema migration. LINE-first or web-first produces the same locked amount. Existing 99-slot/global-uniqueness rules still apply.
- QR works independently of slip verifier configuration. Generation never marks a bill paid or contacts a bank/provider.
- PNG is rendered locally via the pinned MIT encoder under `src/Support/Vendor`, with GD. No remote QR service, floating point amount conversion or new runtime package manager.
- Signed image capabilities expire after 30 days and bind bill ID, transfer satang amount, resident/auth version, occupancy, OA, binding ID and an HMAC of the LINE recipient. APP_KEY rotation invalidates them. Tokens grant image access only, not bill/session access.
- GET validates HMAC/expiry before private queries, checks current bill/payment/receiver/binding state and does not write transfer instructions. Responses disable caching, carry `no-referrer`, and permit cross-origin image display only on this token-guarded route. Cookie/session login is not required by the image fetcher.
- Apache omits query and Referer from QR access logs. Operators must apply equivalent redaction in ingress/CDN/WAF logs. Tokens must not be copied to public issues or logs.
- The worker validates the persisted card layout, recipient, signed image URL and current state before provider delivery. JSON object key order is canonicalized because MySQL reorders object keys; scalar types, list order, text, URLs and layout remain strict. Arbitrary image/footer URLs are rejected.
- After any provider attempt, retry retains the exact stored message bytes, URL, recipient and X-Line-Retry-Key. A failed/uncertain attempt is not silently upgraded or regenerated. A terminal preflight rejection on the very first claim, before any provider call, is recorded as unattempted and may be rebuilt by an explicit enqueue action. That proof is preserved across database retries; a timeout, crash, old claim or commit error is never proof that LINE did not receive the request. Existing text-only pending jobs remain compatible.
- Changing or clearing PromptPay is rejected while reserved instructions belong to another target. The settings lock fences concurrent allocations, reports affected bill references/periods and preserves the old configuration atomically. Restoring an incorrectly changed target is allowed only when it matches every remaining reserved instruction; amounts are never reallocated.
- Current LINE delivery status counts only authorized current recipients. Revoked binding failures remain in the outbox history and are displayed separately; they do not override successful current deliveries or inflate worker failure counts.
- The webhook first prepares text/identity, then creates QR cards under the final resident binding fence. Lock contention is bounded to a one-second InnoDB wait without the usual transaction retries; near-deadline requests fall back to text. No DB transaction is held during the reply transport.
- Paid bills or pending/verified evidence are excluded from new QR replies and pushes. Revoked bindings, changed phones, inactive OA and changed receivers invalidate image fetches. No new transfer is encouraged when outcome is uncertain.

## Important limitations

LINE can cache an image and users can save/screenshot it. No server can revoke pixels already downloaded. The card therefore warns to check the current bill and never transfer again after payment. The endpoint prevents new image fetches in unsafe states; it is not a bank-side QR cancellation mechanism.

Sending a slip in chat is still manual review guidance. It does not ingest the image into the PHP payment store or automatically mark the invoice paid.

Image events and the portal's prefilled `แจ้งชำระ …` text enter that guidance handler, with signature/destination validation, command throttling and event deduplication. Ordinary chat still receives no bot response. For recovery after configuration changes, see [RECOVERY_FIXES_2026-09-22.md](RECOVERY_FIXES_2026-09-22.md).

HTTPS must be publicly reachable with a valid certificate, GD must be enabled, and PromptPay/OA/binding/worker must be configured. HTTP-only local deployments receive a safe text fallback. The bot `บิล` command can provide a fresh image link after expiry while reusing the original amount. Already-sent notifications are not automatically resent.

## Verification

Local sign-off after the recovery fixes on 22 September 2026: PHP unit/contract **127 passed**, JavaScript **219 passed**, MySQL **18 suites / 253 groups passed** (including **23** LINE QR groups), PHP lint **96 files**, LINE bot **8**, monthly CLI **7**, worker contract and canonical install.sql check passed. Schema audit: **54 passed**, two expected fixture configuration warnings. Operations/payment browser suites and the room → booking → check-in → meter recovery → bill issuance journey passed; final MySQL/browser logs contained no PHP warning/fatal/deprecation. The unchanged PNG renderer was independently decoded during the preceding QR implementation verification. New tests are wired into CI; no new remote CI run, production deployment or real-provider send was performed in this task.

- PHP unit suite includes PNG signature/size, opaque background, quiet zone, deterministic generation and invalid input rejection.
- `tests/line_payment_qr_mysql.php` is added to the CI isolated-MySQL suites. It covers provider-off generation, cross-channel equality, distinct same-principal bills, tampered/expired/cross-bill tokens, HTTP image access, no cookies, immutable push retries, receiver changes, recipient/URL substitution, binding revocation/blocking, disabled OA, phone changes, bot isolation, signed webhook/redelivery, pending/verified/paid states, HTTP fallback and bounded webhook lock contention.
- Tests use restricted runtime database grants and offline LINE transports. No LINE messages or bank transfers are sent to real users.
- Local independent decode with `jsQR@1.4.0` + `pngjs@7.0.0` confirms that the generated 410×410 PNG encodes exactly the expected PromptPay payload, including receiver, locked amount and CRC. These packages are verification-only, outside the repository.
- Existing browser regression checks for admin operations and payment QR remain in scope; actual LINE app layout/public TLS/provider delivery still requires staging verification with the owner's accounts.

LINE requirements checked against official [Flex image/message documentation](https://developers.line.biz/en/reference/messaging-api/nojs/#f-image) and [Flex message elements](https://developers.line.biz/en/docs/messaging-api/flex-message-elements/): HTTPS image URL, PNG/JPEG, at most 1024×1024 pixels, and an altText fallback. The generated PNG is well below the size limits.
