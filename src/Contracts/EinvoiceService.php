<?php

namespace Vju\EinvoiceClient\Contracts;

use Vju\EinvoiceClient\Data\Document;
use Vju\EinvoiceClient\Data\DocumentPage;
use Vju\EinvoiceClient\Data\DocumentRequest;

/**
 * Hợp đồng chung của client thật (EinvoiceClient) và bản giả (FakeEinvoice). Type-hint interface này
 * (hoặc dùng facade Einvoice) để Einvoice::fake() thay được trong kiểm thử.
 */
interface EinvoiceService
{
    /**
     * POST /documents — tạo hoặc cập nhật theo key. Gửi lại cùng key không bao giờ lập hai hoá đơn.
     */
    public function createDocument(DocumentRequest $request): Document;

    /**
     * Bí danh của createDocument().
     */
    public function upsert(DocumentRequest $request): Document;

    /**
     * GET /documents/{key}; $refresh = true hỏi lại nhà cung cấp trước khi trả (chậm).
     */
    public function getDocument(string $key, bool $refresh = false): Document;

    /**
     * POST /documents/{key}/cancel — chỉ chứng từ chưa ký.
     */
    public function cancelDocument(string $key): Document;

    /**
     * GET /documents?cursor=…&limit=… — nguồn thay đổi để kéo dự phòng.
     */
    public function changes(int $cursor = 0, ?int $limit = null): DocumentPage;
}
