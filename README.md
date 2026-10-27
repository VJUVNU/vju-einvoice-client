# vju/einvoice-client

Gói Laravel để app VJU dùng **Dịch vụ hoá đơn điện tử VJU** (`vju-einvoice`) theo hợp đồng
[`einvoice-api-v1`](https://github.com/VJUVNU/vju-einvoice/blob/main/docs/api/einvoice-api-v1.md):
lập chứng từ (hoá đơn/biên lai), theo dõi trạng thái qua webhook đã ký, kéo nguồn thay đổi dự phòng, và bản giả
để kiểm thử.

> **Gói không chứa khoá VNPT nào.** Tài khoản nhà cung cấp, mẫu số, ký hiệu nằm ở dịch vụ (theo đơn vị phát
> hành). App chỉ giữ hai khoá của chính nó với dịch vụ (`EINVOICE_SECRET`, `EINVOICE_WEBHOOK_SECRET`), trong `.env`.

Dịch vụ **chỉ lập nháp** lên nhà cung cấp; kiểm soát viên ký/phát hành tay trên portal VNPT bằng USB token. Vì vậy
một chứng từ đi `queued → drafted → issued`, và `DocumentIssued` có thể tới sau nhiều giờ.

## Cài đặt

Gói và phụ thuộc `vju/satellite-contracts` là repo **private** — khai cả hai trong `composer.json` của app:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/VJUVNU/vju-einvoice-client" },
    { "type": "vcs", "url": "https://github.com/VJUVNU/satellite-contracts" }
]
```

```bash
composer require vju/einvoice-client:^0.1
```

Composer cần token GitHub đọc được hai repo đó:

- **Máy dev**: `composer config --global github-oauth.github.com <token>`, hoặc
  `export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(gh auth token)\"}}"` trước lệnh composer.
  Đừng ghi token vào repo.
- **GitHub Actions** (OASS deploy bằng Actions): tạo secret `COMPOSER_AUTH` của repo app với giá trị
  `{"github-oauth":{"github.com":"<token>"}}` rồi đặt `env: COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}` cho job.

Service provider và facade `Einvoice` tự đăng ký qua package discovery.

### `.env`

```dotenv
EINVOICE_URL=https://einvoice.vju.ac.vn      # gốc dịch vụ, KHÔNG kèm /api/v1
EINVOICE_KEY=enrollment                      # client_apps.code
EINVOICE_SECRET=...                          # khoá API của app
EINVOICE_WEBHOOK_SECRET=...                  # client_apps.webhook_secret (xoay khoá: "moi,cu")
EINVOICE_ISSUER=vju-hocphi                   # tuỳ chọn: đơn vị phát hành mặc định

# Tuỳ chọn
EINVOICE_TIMEOUT=15
EINVOICE_RETRIES=2                           # chỉ GET, chỉ khi lỗi kết nối
EINVOICE_WEBHOOK_PATH=einvoice/webhook
EINVOICE_WEBHOOK_ENABLED=true
EINVOICE_PULL_SCHEDULE=false                 # true: gói tự đặt lịch einvoice:pull
```

### Cấu hình và migration

```bash
php artisan vendor:publish --tag=einvoice-config       # tuỳ chọn
php artisan vendor:publish --tag=einvoice-migrations   # tuỳ chọn
php artisan migrate
```

Ba bảng: `einvoice_client_events` (chống trùng), `einvoice_client_cursors` (con trỏ `einvoice:pull`),
`einvoice_client_nonces` (nonce webhook đã dùng). Không publish thì gói tự nạp migration; tắt bằng
`run_migrations => false`.

### Đăng ký webhook với quản trị dịch vụ

Gửi cho quản trị Dịch vụ hoá đơn điện tử URL `https://<app>/einvoice/webhook` (→ `client_apps.webhook_url`) và
nhận lại `webhook_secret` (→ `EINVOICE_WEBHOOK_SECRET`). Route không nằm trong nhóm `web` (không CSRF, không phiên).

## Dùng

```php
use Vju\EinvoiceClient\Data\{Buyer, DocumentRequest, Item, VatRate};
use Vju\EinvoiceClient\Facades\Einvoice;

$chungTu = Einvoice::createDocument(
    DocumentRequest::make('HD-'.$e->ref, issuer: 'vju-hocphi')
        ->buyer(Buyer::make($sinhVien->ho_ten)
            ->code($sinhVien->ma_sv)
            ->idNumber($sinhVien->cccd)
            ->email($sinhVien->vju_email))
        ->item(Item::make('Học phí học kỳ 1 năm học 2026-2027', unitPrice: $e->amount, vatRate: VatRate::KCT)
            ->unit('Khoản'))
        ->reference(['payment_ref' => $e->ref, 'payment_id' => $e->transactionId])
        ->sendEmail(),
);
// $chungTu->status === 'queued'; số hoá đơn tới sau qua DocumentIssued
```

- App gửi **đủ nội dung**: người mua, các dòng **đã lọc khoản thu hộ**, tên khoản chính thức, thuế suất.
  `vatRate` **bắt buộc**, không có mặc định — gói không đoán dòng nào chịu thuế. Giá trị: `VatRate::KCT`,
  `KKKNT`, `ZERO`, `FIVE`, `EIGHT`, `TEN`.
- `key` do app đặt (≤ 40 ký tự `[A-Za-z0-9._-]`); gửi lại cùng `key` không bao giờ lập hai hoá đơn.
- Builder bất biến: mỗi hàm trả bản mới. Tổ chức: `Buyer::make(...)->legalName(...)->taxCode(...)`.
- `->receipt()` cho biên lai (v1: dịch vụ trả 422 nếu đơn vị phát hành chưa khai mẫu biên lai);
  `->paymentMethod('TM' | 'CK' | 'TM/CK')` (mặc định `CK`); `->issuedOn(...)` (mặc định hôm nay).
- Lỗi lập trình (thiếu dòng, `quantity` ≤ 0…) ném `InvalidArgumentException` trước khi gửi.

```php
Einvoice::getDocument('HD-HP1234');                 // Document
Einvoice::getDocument('HD-HP1234', refresh: true);  // hỏi lại nhà cung cấp (chậm)
Einvoice::cancelDocument('HD-HP1234');              // chỉ khi chưa ký
Einvoice::changes(cursor: 800, limit: 100);         // DocumentPage
```

Muốn tiêm phụ thuộc thì type-hint `Vju\EinvoiceClient\Contracts\EinvoiceService` để `fake()` thay được.

### Lỗi

| Exception | Khi |
|---|---|
| `AuthenticationException` | 401 `unknown_client` · `signature_expired` · `nonce_missing` · `signature_invalid` · `replay_detected`; 403 `client_disabled` · `ip_not_allowed` |
| `IssuerNotAllowedException` | 403 `issuer_not_allowed` |
| `NotFoundException` | 404 `document_not_found` |
| `DocumentLockedException` (con của `ConflictException`) | 409 `document_locked` — đã ký, không sửa/huỷ được (thay thế/điều chỉnh chưa có ở v1) |
| `ValidationException` | 422 `validation_failed` (`$e->fields`, `messagesFor('items.0.vat_rate')`) |
| `InvalidPayloadException` | phản hồi không phải JSON / thiếu trường bắt buộc |
| `EinvoiceException` | gốc của tất cả; `connection_failed`, `not_configured` |

Chỉ **GET** được thử lại khi lỗi kết nối. `createDocument` gặp `connection_failed` thì app tự gọi lại (idempotent
theo `key`).

## Listener

```php
use Vju\EinvoiceClient\Events\DocumentIssued;

public function handle(DocumentIssued $e): void
{
    // $e->key, $e->number (chuỗi, giữ số 0 đầu), $e->serial, $e->pattern, $e->viewUrl, $e->document, $e->source
}
```

| Event | Khi | Trường riêng |
|---|---|---|
| `DocumentDrafted` | đã lập nháp ở VNPT, chờ ký | `viewUrl` |
| `DocumentIssued` | kiểm soát viên đã ký, có số | `number`, `serial`, `pattern`, `viewUrl` |
| `DocumentFailed` | nhà cung cấp từ chối / hết lượt thử | `error` |
| `DocumentCancelled` | app đã huỷ | |

`$e->status` là trạng thái event báo; trạng thái lúc dịch vụ gửi nằm ở `$e->document->status`.

### Ngữ nghĩa chống trùng — đọc kỹ

- Khoá chống trùng là **`key` + trạng thái** (hợp đồng: idempotent theo `data.key` + `data.status`). Mỗi cặp bắn
  **đúng một lần**, dù tới bằng webhook, `einvoice:pull` hay cả hai.
- **Gửi lại chứng từ mở lại vòng đời.** Khi `createDocument()` cùng `key` trả về chứng từ chưa `issued`, gói xoá
  dòng chống trùng `drafted`/`failed`/`cancelled` của `key` đó, để nháp mới / lỗi mới vẫn bắn event. `issued`
  không bao giờ bị xoá. Hệ quả: dịch vụ lập lại nháp mỗi lần nhận POST, nên **đừng gọi `createDocument()` mỗi lần
  mở trang** — chỉ gọi khi thật sự cần lập/sửa.
- Dòng chống trùng và **listener đồng bộ chạy trong cùng một transaction**: listener ném lỗi → dòng mất, webhook
  trả **500** → dịch vụ gửi lại (1′, 5′, 30′, 2h, 6h, 24h tới 3 ngày), lần `einvoice:pull` sau cũng lấy lại.
  Listener chỉ ghi CSDL trên cùng kết nối thì việc ghi và dòng chống trùng cùng thành hoặc cùng mất.
- Listener làm việc ngoài CSDL (gửi mail, gọi API) rồi mới ném lỗi thì việc đó chạy lại ở lần giao sau → phải tự
  idempotent. Listener `ShouldQueue` đặt `public $afterCommit = true;`.
- Để `EINVOICE_DB_CONNECTION` trống để listener ghi cùng transaction.

## Kéo dự phòng: `einvoice:pull`

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('einvoice:pull')->everyFiveMinutes()->withoutOverlapping();
```

(hoặc `EINVOICE_PULL_SCHEDULE=true`). Cần `schedule:work`/cron chạy thật.

- Kéo `/documents?cursor={change_id}`, bắn event theo **trạng thái hiện tại** của từng chứng từ, tiến con trỏ
  (chỉ tiến). `queued` không có event.
- Nguồn thay đổi chỉ cho trạng thái mới nhất: webhook rơi cả `drafted` lẫn `issued` thì lần kéo chỉ bắn
  `DocumentIssued` — listener đừng giả định đã nhận `DocumentDrafted` trước.
- Listener lỗi ở một chứng từ → con trỏ dừng ngay trước nó, lệnh thoát mã lỗi. Dịch vụ dựng lại CSDL làm
  `change_id` đánh lại từ đầu → chạy `php artisan einvoice:pull --cursor=0`.

## Kiểm thử trong app: `Einvoice::fake()`

```php
use Vju\EinvoiceClient\Data\DocumentRequest;
use Vju\EinvoiceClient\Facades\Einvoice;

public function test_thu_xong_thi_lap_hoa_don(): void   // cần RefreshDatabase: simulate* ghi bảng chống trùng
{
    $fake = Einvoice::fake();

    // ... luồng của app gọi Einvoice::createDocument(...)

    $fake->assertDocumentCreated(fn (DocumentRequest $r) => $r->key === 'HD-HP42' && $r->items[0]->vatRate === 'KCT');

    $fake->simulateDrafted('HD-HP42');
    $fake->simulateIssued('HD-HP42', number: '0000042');   // bắn DocumentIssued qua đúng đường chống trùng
    $this->assertDatabaseHas('hoa_don', ['id' => 42, 'so_hoa_don' => '0000042']);
}
```

Bản giả làm theo luật hợp đồng: tạo mới → `queued`; gửi lại khi `queued`/`drafted`/`failed` → `queued` lại; gửi
lại chứng từ `issued` cùng nội dung → trả nguyên, khác nội dung → `DocumentLockedException`; huỷ chứng từ
`issued` → `DocumentLockedException`; key lạ → `NotFoundException`. Tính sẵn `amount`, `subtotal`, `vat_amount`,
`total`. `cancelDocument()` **không** tự bắn `DocumentCancelled` (dịch vụ thật báo qua webhook sau) — gọi
`simulateCancelled()` nếu cần.

| Hàm | Làm gì |
|---|---|
| `assertDocumentCreated(fn\|key = null)` · `assertDocumentNotCreated(...)` · `assertDocumentCreatedTimes(n, ...)` | |
| `assertDocumentCancelled(key)` · `assertNothingSent()` | |
| `simulateDrafted(key)` · `simulateIssued(key, number)` · `simulateFailed(key, error)` · `simulateCancelled(key)` | đổi trạng thái, bắn event; `einvoice:pull` trên bản giả cũng thấy |
| `failNext(new IssuerNotAllowedException(...))` | lời gọi kế tiếp ném lỗi đó |
| `createdDocuments()` · `documents()` | xem dữ liệu đã ghi |

## Tương thích

| | PHP | Đã chạy bộ kiểm |
|---|---|---|
| Laravel 13 | 8.3 – 8.4 | 13.38 · testbench 11 · PHPUnit 12 |
| Laravel 12 (Cổng sinh viên) | 8.2 – 8.4 | 12.70 · testbench 10 · PHPUnit 12 |
| Laravel 11 (OASS 11.45) | 8.2 – 8.4 | 11.45.3 và 11.57 · testbench 9 · PHPUnit 11 — chạy tay, không có trong CI |

Ghi chú Laravel 11: Composer chặn cài Laravel 11.x vì một cảnh báo bảo mật, nên CI chỉ chạy 12 và 13; bộ kiểm trên
11 được chạy trong bản sao tạm với `audit.block-insecure=false`. Laravel **11.45** còn vỡ với `symfony/console`
7.4 (lỗi của framework — mọi lệnh artisan đều hỏng); app 11.45 cần giữ `symfony/console` ≤ 7.3 như lock hiện có.

## Những điểm hợp đồng chưa nói rõ, gói đã chọn

- **Đường dẫn ký** luôn là `/api/v1/...`; phần đường dẫn con trong `EINVOICE_URL` không vào chuỗi ký.
- **Đường dẫn kiểm webhook**: chấp nhận cả đường dẫn đầy đủ lẫn đường dẫn tương đối với app.
- **Phản hồi Document** nhận cả dạng trần lẫn bọc `{"data": …}`.
- **Trạng thái của webhook** lấy theo tên event (`document.issued` → `issued`), không theo `data.status`.
- `error` của Document nhận chuỗi hoặc đối tượng (`message`). `number` luôn là chuỗi.
- `POST /documents/{key}/cancel` gửi thân rỗng (ký `sha256("")`).
- Event webhook lạ (vd. `document.queued`) → 200 `ignored`; thân hỏng → 422; listener lỗi → 500.
