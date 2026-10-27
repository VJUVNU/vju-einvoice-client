<?php

namespace Vju\EinvoiceClient\Exceptions;

/**
 * 422 validation_failed: thân sai; fields liệt kê từng trường. Cũng dùng cho type=receipt khi đơn vị
 * phát hành chưa khai mẫu biên lai.
 */
class ValidationException extends EinvoiceException
{
    /**
     * @return list<string>
     */
    public function messagesFor(string $field): array
    {
        return array_values((array) ($this->fields[$field] ?? []));
    }
}
