# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/), đánh số theo [SemVer](https://semver.org/lang/vi/).

## [0.1.0] — 2026-10-27

Bản đầu tiên, theo hợp đồng `einvoice-api-v1` của Dịch vụ hoá đơn điện tử VJU.

### Thêm

- `Einvoice::createDocument()` (bí danh `upsert()`), `getDocument($key, refresh: true)`, `cancelDocument()`,
  `changes()`; ký mọi lời gọi bằng `Vju\SatelliteContracts\Signing\RequestSignature`.
- Builder bất biến `DocumentRequest`, `Buyer`, `Item` (vatRate bắt buộc, hằng số trong `VatRate`); DTO chỉ đọc
  `Document`, `DocumentPage`. Số hoá đơn giữ dạng chuỗi.
- Exception có kiểu theo envelope lỗi: `AuthenticationException`, `IssuerNotAllowedException`,
  `NotFoundException`, `DocumentLockedException` (con của `ConflictException`), `ValidationException` (kèm
  `fields`), `InvalidPayloadException`, gốc chung `EinvoiceException`.
- Chỉ thử lại GET khi lỗi kết nối; mỗi lần thử ký lại với nonce mới.
- Route webhook `POST /einvoice/webhook` (đổi được), kiểm chữ ký `X-Vju-Key: einvoice`, cửa sổ ±300 giây,
  chống phát lại nonce bằng bảng `einvoice_client_nonces`, nhận nhiều khoá khi xoay khoá.
- Event `DocumentDrafted`, `DocumentIssued`, `DocumentFailed`, `DocumentCancelled`; chống trùng theo
  `key` + trạng thái trong bảng `einvoice_client_events`, ghi dòng chống trùng và chạy listener trong cùng một
  transaction. Gửi lại chứng từ (cùng key) mở lại vòng đời để lần nháp/lỗi mới vẫn bắn event.
- Lệnh `einvoice:pull` kéo nguồn thay đổi `/documents` theo `change_id`, dùng chung bảng chống trùng với
  webhook; tuỳ chọn tự đăng ký lịch 5 phút.
- `Einvoice::fake()` với `assertDocumentCreated()`, `assertDocumentNotCreated()`, `assertDocumentCreatedTimes()`,
  `assertDocumentCancelled()`, `assertNothingSent()`, `simulateDrafted()`, `simulateIssued()`, `simulateFailed()`,
  `simulateCancelled()`, `failNext()`.
