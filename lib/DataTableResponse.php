<?php
/**
 * DataTableResponse — reusable server-side backend for DataTables (1.13).
 *
 * Speaks the DataTables server-side processing protocol so any admin list can be
 * paginated / searched / sorted in SQL instead of shipping every row to the
 * browser. A controller supplies a column spec, an optional always-on base
 * filter, and a row renderer; this class does the counting, global + per-column
 * searching, ordering, and page slicing, then returns the exact JSON shape
 * DataTables expects: { draw, recordsTotal, recordsFiltered, data }.
 *
 * Security model: every SQL *identifier* (table + column names) comes from the
 * server-defined $columns / $opts spec — never from the request — and is
 * additionally validated against a strict identifier pattern. Sort direction is
 * clamped to ASC|DESC. All user *values* are bound as parameters. The client can
 * only choose *which* whitelisted column to sort/filter (by numeric index) and
 * what value to match — never the SQL text itself.
 *
 * Controller usage:
 *
 *   $columns = [
 *       ['db' => 'item_name',  'search' => 'like'],   // 0
 *       ['db' => 'item_type',  'search' => 'exact'],  // 1
 *       ['db' => null],                               // 2  non-data col (e.g. Actions)
 *   ];
 *   $resp = DataTableResponse::build('catalogitem', $columns, $this->getParams(), [
 *       'baseWhere'  => 'active IS NOT NULL',
 *       'baseParams' => [],
 *       'globalCols' => ['item_name', 'botanical_name', 'uom'],   // hit by the search box
 *       'row'        => fn(array $r) => [ h($r['item_name']), h($r['item_type']) ],  // ordered cells
 *   ]);
 *   Flight::json($resp);
 *
 * Rows are returned as ordered arrays (array-sourced DataTables), so all cell
 * rendering — badges, links, formatting, escaping — lives in the controller's
 * `row` callback and stays server-side.
 */

namespace app;

use \RedBeanPHP\R as R;

class DataTableResponse {

    /** SQL identifiers we will interpolate must match this (defence in depth). */
    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param string $table    Table name (validated).
     * @param array  $columns  Ordered specs aligned to the client columns. Each:
     *                         ['db' => '<col>|null', 'search' => 'like'|'exact'|null, 'orderable' => bool]
     * @param array  $request  DataTables request params (draw/start/length/search/order/columns).
     * @param array  $opts     baseWhere, baseParams, globalCols[], row(callable).
     * @return array { draw, recordsTotal, recordsFiltered, data }
     */
    public static function build(string $table, array $columns, array $request, array $opts = []): array {
        $table = self::ident($table);

        $draw   = (int)($request['draw'] ?? 0);
        $start  = max(0, (int)($request['start'] ?? 0));
        $length = (int)($request['length'] ?? 25);

        $baseWhere  = trim((string)($opts['baseWhere'] ?? ''));
        $baseParams = array_values((array)($opts['baseParams'] ?? []));
        $globalCols = (array)($opts['globalCols'] ?? []);
        $rowFn      = $opts['row'] ?? null;

        $where  = [];
        $params = [];
        if ($baseWhere !== '') { $where[] = '(' . $baseWhere . ')'; $params = $baseParams; }

        // Unfiltered total (base filter only) — DataTables' recordsTotal.
        $recordsTotal = (int)R::getCell(
            'SELECT COUNT(*) FROM `' . $table . '`' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params
        );

        // Global search box: OR of LIKE across the declared global columns.
        $globalVal = trim((string)($request['search']['value'] ?? ''));
        if ($globalVal !== '' && $globalCols) {
            $ors = [];
            foreach ($globalCols as $col) {
                $ors[]    = '`' . self::ident($col) . '` LIKE ?';
                $params[] = '%' . $globalVal . '%';
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        // Per-column search (the filter dropdowns). The numeric index selects a
        // whitelisted column from the server-defined spec.
        foreach ((array)($request['columns'] ?? []) as $i => $rc) {
            $val = trim((string)($rc['search']['value'] ?? ''));
            if ($val === '') continue;
            $spec = $columns[$i] ?? null;
            if (!$spec || empty($spec['db'])) continue;
            $mode = $spec['search'] ?? 'like';
            if ($mode === null) continue;   // column explicitly not searchable
            if ($mode === 'exact') {
                $where[]  = '`' . self::ident($spec['db']) . '` = ?';
                $params[] = $val;
            } else {
                $where[]  = '`' . self::ident($spec['db']) . '` LIKE ?';
                $params[] = '%' . $val . '%';
            }
        }

        $whereSql        = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $recordsFiltered = (int)R::getCell('SELECT COUNT(*) FROM `' . $table . '`' . $whereSql, $params);

        // ORDER BY — index into the whitelisted spec; direction clamped.
        $orderSql = '';
        $order    = $request['order'][0] ?? null;
        if ($order) {
            $ci   = (int)($order['column'] ?? -1);
            $dir  = strtolower((string)($order['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $spec = $columns[$ci] ?? null;
            if ($spec && !empty($spec['db']) && ($spec['orderable'] ?? true)) {
                $orderSql = ' ORDER BY `' . self::ident($spec['db']) . '` ' . $dir;
            }
        }

        // Page slice (length = -1 means "all").
        $limitSql = $length >= 0 ? ' LIMIT ' . (int)$length . ' OFFSET ' . (int)$start : '';

        $rows = R::getAll('SELECT * FROM `' . $table . '`' . $whereSql . $orderSql . $limitSql, $params);

        $data = [];
        foreach ($rows as $r) {
            $data[] = $rowFn ? array_values($rowFn($r)) : array_values($r);
        }

        return [
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ];
    }

    /** Validate a server-supplied SQL identifier or fail loudly (never user input). */
    private static function ident(string $name): string {
        if (!preg_match(self::IDENT, $name)) {
            throw new \InvalidArgumentException('Unsafe SQL identifier: ' . $name);
        }
        return $name;
    }
}
