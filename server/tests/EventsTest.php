<?php

declare(strict_types=1);

namespace App\Tests;

use App\Events;
use App\Logger;

final class EventsTest extends DatabaseTestCase
{
    private const UUID = '0b6a1d3e-5c52-4f7a-9d77-1c2f3a4b5c6d';

    private Events $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new Events($this->pdo, 'search_events', 'search_logs');
        (new Logger($this->pdo, 'search_logs'))->log(['raw_q' => 'mafia', 'search_uuid' => self::UUID]);
    }

    private function eventCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM search_events')->fetchColumn();
    }

    public function testRecordsAClick(): void
    {
        self::assertSame('ok', $this->events->record(
            ['search_uuid' => strtoupper(self::UUID), 'product_id' => 12, 'position' => 3, 'action' => 'click']
        ));

        $row = $this->pdo->query('SELECT * FROM search_events')->fetch();
        self::assertSame(self::UUID, $row['search_uuid']);
        self::assertSame([12, 3, 'click'], [(int) $row['product_id'], (int) $row['position'], $row['action']]);
    }

    public function testActionDefaultsToClickAndStringNumbersAreAccepted(): void
    {
        self::assertSame('ok', $this->events->record(
            ['search_uuid' => self::UUID, 'product_id' => '12', 'position' => '0']
        ));
        self::assertSame('click', $this->pdo->query('SELECT action FROM search_events')->fetchColumn());
    }

    public function testEveryActionIsAccepted(): void
    {
        self::assertSame('ok', $this->events->record(
            ['search_uuid' => self::UUID, 'product_id' => 7, 'position' => 1, 'action' => 'click']
        ));
        self::assertSame('ok', $this->events->record(
            ['search_uuid' => self::UUID, 'product_id' => 0, 'position' => 0, 'action' => 'dym_click']
        ));
        self::assertSame(2, $this->eventCount());
    }

    public function testCartAndOrderAreNoLongerAccepted(): void
    {
        foreach (['cart', 'order'] as $action) {
            self::assertSame('invalid', $this->events->record(
                ['search_uuid' => self::UUID, 'product_id' => 7, 'position' => 1, 'action' => $action]
            ), $action);
        }
        self::assertSame(0, $this->eventCount());
    }

    public function testRejectsWhatIsNotAnEvent(): void
    {
        $good = ['search_uuid' => self::UUID, 'product_id' => 5, 'position' => 1, 'action' => 'click'];
        foreach (
            [
            ['search_uuid' => 'nope'] + $good,
            ['search_uuid' => 12] + $good,
            ['action' => 'delete'] + $good,
            ['action' => ['click']] + $good,
            ['product_id' => 0] + $good,           // a click names a product
            ['product_id' => -1] + $good,
            ['product_id' => 'x'] + $good,
            ['product_id' => 1.5] + $good,
            ['position' => 70000] + $good,
            ['position' => -1] + $good,
            ] as $input
        ) {
            self::assertSame('invalid', $this->events->record($input), (string) json_encode($input));
        }
        self::assertSame(0, $this->eventCount());
    }

    public function testRefusesAnUnknownSearch(): void
    {
        self::assertSame('unknown_search', $this->events->record(
            ['search_uuid' => '11111111-1111-4111-8111-111111111111', 'product_id' => 5, 'position' => 1]
        ));
        self::assertSame(0, $this->eventCount());
    }

    public function testARepeatWithinSecondsIsDroppedButAnotherProductIsNot(): void
    {
        $click = ['search_uuid' => self::UUID, 'product_id' => 5, 'position' => 1];

        self::assertSame('ok', $this->events->record($click));
        self::assertSame('duplicate', $this->events->record($click));
        self::assertSame('ok', $this->events->record(['product_id' => 6] + $click));
        self::assertSame('ok', $this->events->record(['action' => 'dym_click', 'product_id' => 0] + $click));
        self::assertSame(3, $this->eventCount());
    }
}
