<?php

declare(strict_types=1);

// Bounded list pagination for the entity pages. The pager takes the exact
// ORDER BY contract of each page (deterministic because every ordering ties
// back to the row id), pages with OFFSET for simplicity, and always validates
// the incoming page number through the shared request validation.

function list_page_query(string $bodySql, string $orderSql, array $params = [], int $perPage = 50): array
{
    $perPage = max(10, min(200, $perPage));
    $page = max(1, (int)(input_id($_GET, 'page') ?? 1));
    $counter = db()->prepare('SELECT COUNT(*) FROM (' . $bodySql . ') AS paged_count');
    $counter->execute($params);
    $count = (int)$counter->fetchColumn();
    $pages = max(1, (int)ceil($count / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $stmt = db()->prepare($bodySql . ' ' . $orderSql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $count, 'per_page' => $perPage];
}

function pager_link(int $number): string
{
    $params = $_GET;
    $params['page'] = $number;
    unset($params['edit']);
    $query = http_build_query($params);
    return $query === '' ? '?' : '?' . $query;
}

function pager_numbers(array $list): array
{
    // At most five page numbers around the current page, so long histories
    // cannot flood a page with links.
    $start = max(1, $list['page'] - 2);
    $end = min($list['pages'], $start + 4);
    $start = max(1, $end - 4);
    return range($start, $end);
}

function render_pager(array $list): void
{
    if ($list['pages'] <= 1) {
        return;
    }
    $from = ($list['page'] - 1) * $list['per_page'] + 1;
    $to = min($list['total'], $list['page'] * $list['per_page']);
    $numbers = pager_numbers($list);
    ?>
    <nav class="pager" aria-label="Page navigation">
        <span class="pager-info"><?= (int)$from ?>&ndash;<?= (int)$to ?> van <?= (int)$list['total'] ?></span>
        <?php if ($list['page'] > 1): ?>
            <a href="<?= e(pager_link($list['page'] - 1)) ?>" rel="prev">&laquo; vorige</a>
        <?php endif; ?>
        <?php foreach ($numbers as $number): ?>
            <?php if ($number === $list['page']): ?>
                <strong><?= (int)$number ?></strong>
            <?php else: ?>
                <a href="<?= e(pager_link($number)) ?>"><?= (int)$number ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($list['page'] < $list['pages']): ?>
            <a href="<?= e(pager_link($list['page'] + 1)) ?>" rel="next">volgende &raquo;</a>
        <?php endif; ?>
    </nav>
    <?php
}
