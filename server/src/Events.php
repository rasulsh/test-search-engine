<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * What shoppers do with a search's results (click, add to cart, order, a clicked
 * suggestion), keyed by the `search_uuid` /search returned. The id is a random
 * v4 UUID known only to the page that received the answer, so the endpoint needs
 * no secret; it still refuses unknown ids, odd actions and repeats of the same
 * event within a few seconds (a double click, a retried beacon).
 */
final class Events
{
    public const ACTIONS = ['click', 'cart', 'order', 'dym_click'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const REPEAT_SECONDS = 5;

    private PDO $pdo;
    private string $events;
    private string $logs;

    public function __construct(PDO $pdo, string $eventsTable, string $searchLogsTable)
    {
        $this->pdo = $pdo;
        $this->events = Identifier::quote($eventsTable);
        $this->logs = Identifier::quote($searchLogsTable);
    }

    /**
     * @param array<string, mixed> $input search_uuid, product_id, position, action (default click)
     * @return string 'ok', 'duplicate', 'invalid' or 'unknown_search'
     */
    public function record(array $input): string
    {
        $uuid = is_string($input['search_uuid'] ?? null) ? strtolower($input['search_uuid']) : '';
        $action = $input['action'] ?? 'click';
        $productId = $input['product_id'] ?? null;
        $position = $input['position'] ?? 0;
        if (
            preg_match(self::UUID, $uuid) !== 1
            || !is_string($action) || !in_array($action, self::ACTIONS, true)
            || !self::isCount($productId, 4294967295)
            // dym_click names no product; every other action names one.
            || ($action !== 'dym_click' && (int) $productId < 1)
            || !self::isCount($position, 65535)
        ) {
            return 'invalid';
        }

        $known = $this->pdo->prepare("SELECT 1 FROM {$this->logs} WHERE search_uuid = :uuid LIMIT 1");
        $known->execute(['uuid' => $uuid]);
        if ($known->fetchColumn() === false) {
            return 'unknown_search';
        }

        $row = ['uuid' => $uuid, 'product' => (int) $productId, 'position' => (int) $position, 'action' => $action];
        $repeat = $this->pdo->prepare(
            "SELECT 1 FROM {$this->events} WHERE search_uuid = :uuid AND product_id = :product AND action = :action"
            . ' AND ts >= NOW() - INTERVAL ' . self::REPEAT_SECONDS . ' SECOND LIMIT 1'
        );
        $repeat->execute(['uuid' => $uuid, 'product' => $row['product'], 'action' => $action]);
        if ($repeat->fetchColumn() !== false) {
            return 'duplicate';
        }

        $this->pdo->prepare(
            "INSERT INTO {$this->events} (search_uuid, product_id, position, action)"
            . ' VALUES (:uuid, :product, :position, :action)'
        )->execute($row);

        return 'ok';
    }

    private static function isCount(mixed $value, int $max): bool
    {
        $int = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : -1);

        return $int >= 0 && $int <= $max;
    }
}
