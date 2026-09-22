# QR encoder provenance

- Upstream: https://github.com/kazuhikoarase/qrcode-generator
- Pinned revision: `83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8`
- Source: `php/qrcode.php`, MIT, Copyright (c) 2009 Kazuhiko Arase; license retained in `QRCode-LICENSE`.
- Local changes: namespace `Dormitory\Support\Vendor`, provenance comment, `createNullArray`, `createData`, and `createBytes` declared static, final closing PHP tag removed.
- Encoding algorithm, Reed–Solomon tables, mask selection and drawing logic are unchanged.
- Only `QrPng::render()` invokes this encoder, with bounded ASCII PromptPay payloads, error correction M, 10px integer modules and a four-module quiet zone. No general-purpose public encoder endpoint is exposed.
- No Composer/Node dependency or outbound image service is required in production.
