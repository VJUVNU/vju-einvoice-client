<?php

namespace Vju\EinvoiceClient\Exceptions;

/**
 * 409 document_locked: chứng từ đã ký/phát hành, không sửa/huỷ được nữa (thay thế/điều chỉnh chưa có ở v1).
 */
class DocumentLockedException extends ConflictException
{
}
