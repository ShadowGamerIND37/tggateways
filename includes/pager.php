<?php
declare(strict_types=1);
/**
 * Shared pagination.
 *
 * tickets.php loaded every ticket a merchant had ever opened, and invoices.php
 * every invoice: fine at ten rows, a problem at ten thousand, and the page had
 * no way to reach anything older than whatever fitted on screen. This renders
 * the same control on both.
 */

/**
 * HTML-escape helper.
 *
 * Guarded because `h()` is defined in both auth.php and orders.php, neither of
 * which every caller of this file loads. Requiring this file on its own used to
 * die with "Call to undefined function h()".
 */
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Resolve page state from the query string.
 *
 * @return array{page:int,perPage:int,offset:int,pages:int,total:int}
 */
function tg_paginate(int $total, int $defaultPerPage = 25, int $maxPerPage = 100): array {
    $perPage = (int)($_GET['per_page'] ?? $defaultPerPage);
    if ($perPage < 1 || $perPage > $maxPerPage) $perPage = $defaultPerPage;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, (int)($_GET['page'] ?? 1));
    if ($page > $pages) $page = $pages;
    return [
        'page' => $page,
        'perPage' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'pages' => $pages,
        'total' => $total,
    ];
}

/**
 * Render the pager.
 *
 * Returns an empty string for a single page, so short lists are not given a
 * control that cannot do anything.
 *
 * @param array{page:int,perPage:int,offset:int,pages:int,total:int} $state
 * @param array<string,string|int|null> $params Existing query parameters to carry.
 */
function tg_pager(array $state, array $params = []): string {
    if ($state['pages'] <= 1) return '';
    $base = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    $link = static function (int $page) use ($base, $params): string {
        $qs = array_filter(
            array_merge($params, ['page' => $page]),
            static fn ($v): bool => $v !== null && $v !== ''
        );
        return h($base.'?'.http_build_query($qs));
    };

    $first = max(1, $state['page'] - 2);
    $last = min($state['pages'], $first + 4);
    $first = max(1, $last - 4);

    $from = $state['offset'] + 1;
    $to = min($state['total'], $state['offset'] + $state['perPage']);

    $out = '<div class="pager"><span>Showing <strong>'.$from.'–'.$to.'</strong> of '
        .$state['total'].'</span><div class="pager-links">';
    if ($state['page'] > 1) {
        $out .= '<a href="'.$link($state['page'] - 1).'" rel="prev" aria-label="Previous page">←</a>';
    }
    if ($first > 1) {
        $out .= '<a href="'.$link(1).'">1</a>';
        if ($first > 2) $out .= '<span class="pager-gap">…</span>';
    }
    for ($p = $first; $p <= $last; $p++) {
        $out .= $p === $state['page']
            ? '<a href="'.$link($p).'" aria-current="page">'.$p.'</a>'
            : '<a href="'.$link($p).'">'.$p.'</a>';
    }
    if ($last < $state['pages']) {
        if ($last < $state['pages'] - 1) $out .= '<span class="pager-gap">…</span>';
        $out .= '<a href="'.$link($state['pages']).'">'.$state['pages'].'</a>';
    }
    if ($state['page'] < $state['pages']) {
        $out .= '<a href="'.$link($state['page'] + 1).'" rel="next" aria-label="Next page">→</a>';
    }
    return $out.'</div></div>';
}
