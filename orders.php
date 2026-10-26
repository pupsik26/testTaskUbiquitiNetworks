<?php

declare(strict_types=1);

enum OrderStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
}

final class Order
{
    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly int $amountCents,
        public readonly OrderStatus $status,
    ) {
    }
}

final class SkippedRecord
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly int $position,
        public readonly ?int $id,
        public readonly array $reasons,
        public readonly ?int $amountCents,
    ) {
    }
}

final class InvalidOrderException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(
        public readonly array $problems,
        public readonly ?int $id = null,
        public readonly ?int $amountCents = null,
    ) {
        parent::__construct(implode('; ', $problems));
    }
}

const REQUIRED_FIELDS = ['id', 'customer', 'amount', 'status'];

function readOrdersFile(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("файл не найден или не читается: $path");
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("не удалось прочитать файл: $path");
    }
    return $content;
}

/** @return list<mixed> */
function decodeOrders(string $json): array
{
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('не удалось декодировать JSON: ' . $e->getMessage(), 0, $e);
    }
    // Пустой объект {} при assoc=true даёт [] и выглядел бы как пустой список,
    // поэтому корень дополнительно проверяется по первому символу.
    if (!is_array($data) || !array_is_list($data) || !str_starts_with(ltrim($json), '[')) {
        throw new RuntimeException('ожидается JSON-массив заказов');
    }
    return $data;
}

/** @return array{0: ?int, 1: ?string} [id, ошибка] */
function parseId(mixed $value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '' || !ctype_digit($trimmed) || strlen($trimmed) > 18) {
            return [null, "id не является целым числом (\"$value\")"];
        }
        $value = (int) $trimmed;
    }
    if (!is_int($value)) {
        return [null, 'id не является целым числом'];
    }
    if ($value <= 0) {
        return [null, "id должен быть положительным ($value)"];
    }
    return [$value, null];
}

/** @return array{0: ?int, 1: ?string, 2: ?string} [копейки, ошибка, предупреждение] */
function parseAmount(mixed $value): array
{
    if (is_string($value)) {
        if (!is_numeric($value)) {
            return [null, "сумма не является числом (\"$value\")", null];
        }
        $value = $value + 0; // int или float; (float)/(int) к мусору не применяются
    }
    if (!is_int($value) && !is_float($value)) {
        return [null, 'сумма должна быть числом', null];
    }
    if (is_float($value) && !is_finite($value)) {
        return [null, 'сумма не является конечным числом', null];
    }
    if ($value < 0) {
        return [null, "сумма отрицательная ($value)", null];
    }
    $exact = $value * 100;
    if ($exact >= (float) PHP_INT_MAX) {
        return [null, 'сумма слишком велика', null];
    }
    $cents = (int) round($exact);
    $warning = null;
    if (is_float($value) && abs($exact - $cents) > 1e-6) {
        $warning = "сумма $value округлена до " . formatMoney($cents);
    }
    return [$cents, null, $warning];
}

/**
 * @return array{0: Order, 1: list<string>} заказ и предупреждения
 * @throws InvalidOrderException
 */
function validateOrderWithWarnings(mixed $raw): array
{
    if (!is_array($raw) || array_is_list($raw)) {
        throw new InvalidOrderException(['запись не является объектом']);
    }

    $problems = [];
    $present = [];
    foreach (REQUIRED_FIELDS as $field) {
        if (!array_key_exists($field, $raw)) {
            $problems[] = "отсутствует обязательное поле \"$field\"";
        } elseif ($raw[$field] === null) {
            $problems[] = "поле \"$field\" равно null";
        } else {
            $present[$field] = $raw[$field];
        }
    }

    $id = $customer = $cents = $status = null;
    $warnings = [];

    if (isset($present['id'])) {
        [$id, $err] = parseId($present['id']);
        if ($err !== null) {
            $problems[] = $err;
        }
    }
    if (isset($present['customer'])) {
        if (!is_string($present['customer'])) {
            $problems[] = 'customer не является строкой';
        } elseif (trim($present['customer']) === '') {
            $problems[] = 'customer пустой';
        } else {
            $customer = $present['customer'];
        }
    }
    if (isset($present['amount'])) {
        [$cents, $err, $warn] = parseAmount($present['amount']);
        if ($err !== null) {
            $problems[] = $err;
        }
        if ($warn !== null) {
            $warnings[] = $warn;
        }
    }
    if (isset($present['status'])) {
        if (!is_string($present['status'])) {
            $problems[] = 'status не является строкой';
        } else {
            $status = OrderStatus::tryFrom($present['status']);
            if ($status === null) {
                $problems[] = "неизвестный статус \"{$present['status']}\"";
            }
        }
    }

    if ($problems !== []) {
        throw new InvalidOrderException($problems, $id, $cents);
    }
    return [new Order($id, $customer, $cents, $status), $warnings];
}

/** @throws InvalidOrderException */
function validateOrder(mixed $raw): Order
{
    return validateOrderWithWarnings($raw)[0];
}

/**
 * @param list<mixed> $rawList
 * @return array{orders: list<Order>, skipped: list<SkippedRecord>, warnings: list<string>}
 */
function parseOrders(array $rawList): array
{
    $orders = [];
    $skipped = [];
    $warnings = [];
    $firstSeen = [];

    foreach ($rawList as $index => $raw) {
        $position = $index + 1;
        try {
            [$order, $recordWarnings] = validateOrderWithWarnings($raw);
        } catch (InvalidOrderException $e) {
            $skipped[] = new SkippedRecord($position, $e->id, $e->problems, $e->amountCents);
            continue;
        }
        if (isset($firstSeen[$order->id])) {
            $skipped[] = new SkippedRecord(
                $position,
                $order->id,
                ["дубликат номера заказа {$order->id} (первое вхождение — запись {$firstSeen[$order->id]})"],
                $order->amountCents,
            );
            continue;
        }
        $firstSeen[$order->id] = $position;
        $orders[] = $order;
        foreach ($recordWarnings as $warning) {
            $warnings[] = "запись $position (id {$order->id}): $warning";
        }
    }

    return ['orders' => $orders, 'skipped' => $skipped, 'warnings' => $warnings];
}

/**
 * @param list<Order> $orders
 * @return list<Order>
 */
function filterPaid(array $orders): array
{
    return array_values(array_filter($orders, static fn (Order $o): bool => $o->status === OrderStatus::Paid));
}

/** @param list<Order> $orders */
function sumAmount(array $orders): int
{
    return array_sum(array_map(static fn (Order $o): int => $o->amountCents, $orders));
}

/** @param list<Order> $orders */
function findMostExpensive(array $orders): ?Order
{
    $best = null;
    foreach ($orders as $order) {
        if ($best === null || $order->amountCents > $best->amountCents) {
            $best = $order;
        }
    }
    return $best;
}

/**
 * @param list<Order> $orders
 * @return array<string, int>
 */
function groupByStatus(array $orders): array
{
    $groups = [];
    foreach (OrderStatus::cases() as $status) {
        $groups[$status->value] = 0;
    }
    foreach ($orders as $order) {
        $groups[$order->status->value]++;
    }
    return $groups;
}

function formatMoney(int $cents): string
{
    if ($cents % 100 === 0) {
        return (string) intdiv($cents, 100);
    }
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

/** @param list<string> $argv */
function main(array $argv): int
{
    try {
        $path = $argv[1] ?? __DIR__ . '/orders.json';
        $parsed = parseOrders(decodeOrders(readOrdersFile($path)));
    } catch (Throwable $e) {
        fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }

    ['orders' => $orders, 'skipped' => $skipped, 'warnings' => $warnings] = $parsed;

    foreach ($warnings as $warning) {
        fwrite(STDERR, "Предупреждение: $warning" . PHP_EOL);
    }
    foreach ($skipped as $s) {
        $id = $s->id ?? '—';
        fwrite(STDERR, "Пропущена запись {$s->position} (id $id): " . implode('; ', $s->reasons) . PHP_EOL);
    }

    $paid = filterPaid($orders);
    $top = findMostExpensive($paid);

    echo 'Количество оплаченных заказов: ' . count($paid) . PHP_EOL;
    echo 'Общая сумма оплаченных заказов: ' . formatMoney(sumAmount($paid)) . PHP_EOL;
    echo 'Самый дорогой оплаченный заказ: '
        . ($top === null ? 'оплаченных заказов нет' : "№ {$top->id} на сумму " . formatMoney($top->amountCents))
        . PHP_EOL;
    echo PHP_EOL . 'Заказы по статусам:' . PHP_EOL;
    foreach (groupByStatus($orders) as $status => $count) {
        echo "  $status: $count" . PHP_EOL;
    }
    echo PHP_EOL;

    if ($skipped === []) {
        echo 'Пропущено записей: 0' . PHP_EOL;
        return 0;
    }
    $sum = array_sum(array_map(static fn (SkippedRecord $s): int => $s->amountCents ?? 0, $skipped));
    echo 'Пропущено записей: ' . count($skipped)
        . ' (распознанная сумма в них: ' . formatMoney($sum) . '; подробности — в STDERR)' . PHP_EOL;
    return 2;
}

exit(main($argv));
