<?php

namespace Vju\EinvoiceClient\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Con trỏ của einvoice:pull (change_id cuối đã xử lý).
 *
 * @internal
 */
class CursorStore
{
    public const TABLE = 'einvoice_client_cursors';

    public const DOCUMENTS = 'documents';

    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection = null,
    ) {}

    public function get(string $name = self::DOCUMENTS): int
    {
        return (int) ($this->connection()->table(self::TABLE)->where('name', $name)->value('cursor') ?? 0);
    }

    /**
     * Chỉ tiến, không lùi — hai lần kéo chồng nhau không kéo con trỏ ngược lại.
     */
    public function advance(int $cursor, string $name = self::DOCUMENTS): void
    {
        $this->ensure($name);

        $this->connection()->table(self::TABLE)
            ->where('name', $name)
            ->where('cursor', '<', $cursor)
            ->update(['cursor' => $cursor, 'updated_at' => now()]);
    }

    /**
     * Đặt thẳng (kể cả lùi) — dùng cho einvoice:pull --cursor.
     */
    public function put(int $cursor, string $name = self::DOCUMENTS): void
    {
        $this->ensure($name);

        $this->connection()->table(self::TABLE)
            ->where('name', $name)
            ->update(['cursor' => max(0, $cursor), 'updated_at' => now()]);
    }

    protected function ensure(string $name): void
    {
        $this->connection()->table(self::TABLE)->insertOrIgnore([
            'name' => $name,
            'cursor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
