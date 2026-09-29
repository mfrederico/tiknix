/**
 * dt-server.js — turnkey server-side DataTables for tiknix.
 *
 * Pairs with the lib/DataTableResponse.php backend primitive. A view opts in
 * with zero JavaScript: tag a table `dt-server`, point it at an AJAX endpoint,
 * and describe columns via <th> data-attributes. This script auto-inits every
 * such table on the page, lazy-loading the DataTables Bootstrap5 assets only
 * when at least one is present.
 *
 *   <table class="dt-server table table-hover align-middle" id="leadsTable"
 *          data-dt-url="/leads/data"
 *          data-dt-order="3:desc"                     initial sort  col:dir[,col:dir]
 *          data-dt-page-length="25"
 *          data-dt-length-menu="10,25,50,100,-1"      -1 / "all" => All
 *          data-dt-search-placeholder="name, email…"
 *          style="width:100%">
 *     <thead><tr>
 *       <th>First Name</th>
 *       <th data-dt-noorder data-dt-nosearch data-dt-class="text-end">Actions</th>
 *     </tr></thead>
 *     <tbody></tbody>
 *   </table>
 *
 * Per-column filter controls (selects/inputs) bind declaratively:
 *   <select data-dt-filter-for="leadsTable" data-dt-col="1"> … </select>
 *
 * The DataTables API instance is exposed on the element as `el._dtApi`.
 */
(function () {
    'use strict';

    var DT_CSS = 'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css';
    var DT_JS = [
        'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
        'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js'
    ];

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function loadCss(href) {
        if (document.querySelector('link[data-dt-asset="' + href + '"]')) return;
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        l.setAttribute('data-dt-asset', href);
        document.head.appendChild(l);
    }

    // Load scripts sequentially so dataTables.bootstrap5 sees jquery.dataTables.
    function loadSeq(list, done) {
        if (!list.length) return done();
        var src = list[0];
        if (document.querySelector('script[data-dt-asset="' + src + '"]')) {
            return loadSeq(list.slice(1), done);
        }
        var s = document.createElement('script');
        s.src = src;
        s.setAttribute('data-dt-asset', src);
        s.onload = function () { loadSeq(list.slice(1), done); };
        s.onerror = function () { console.error('dt-server: asset failed to load:', src); };
        document.head.appendChild(s);
    }

    // "3:desc,0:asc" -> [[3,'desc'],[0,'asc']]. Defaults to first column asc.
    function parseOrder(str) {
        if (!str) return [[0, 'asc']];
        return str.split(',').map(function (p) {
            var b = p.split(':');
            var col = parseInt(b[0], 10) || 0;
            var dir = (b[1] || 'asc').toLowerCase() === 'desc' ? 'desc' : 'asc';
            return [col, dir];
        });
    }

    // "10,25,50,-1" -> [[10,25,50,-1],[10,25,50,'All']].
    function parseLengthMenu(str) {
        if (!str) return [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']];
        var vals = str.split(',').map(function (s) {
            s = s.trim();
            return (s === '-1' || s.toLowerCase() === 'all') ? -1 : parseInt(s, 10);
        });
        var labels = vals.map(function (v) { return v === -1 ? 'All' : v; });
        return [vals, labels];
    }

    function buildColumns(el) {
        var cols = [];
        var ths = el.querySelectorAll('thead th');
        for (var i = 0; i < ths.length; i++) {
            var th = ths[i];
            var def = { data: i };
            if (th.hasAttribute('data-dt-noorder')) def.orderable = false;
            if (th.hasAttribute('data-dt-nosearch')) def.searchable = false;
            var cls = th.getAttribute('data-dt-class');
            if (cls) def.className = cls;
            cols.push(def);
        }
        return cols;
    }

    function initOne($, el) {
        if ($(el).data('dt-server-init')) return;
        $(el).data('dt-server-init', true);

        var url = el.getAttribute('data-dt-url');
        if (!url) { console.error('dt-server: table missing data-dt-url', el); return; }

        var opts = {
            serverSide: true,   // SQL does the paging / search / sort
            processing: true,
            ajax: { url: url, type: el.getAttribute('data-dt-method') || 'GET' },
            pageLength: parseInt(el.getAttribute('data-dt-page-length'), 10) || 25,
            lengthMenu: parseLengthMenu(el.getAttribute('data-dt-length-menu')),
            order: parseOrder(el.getAttribute('data-dt-order')),
            // Rows arrive as ordered arrays with cells pre-rendered server-side,
            // so no client-side columns.render is needed.
            columns: buildColumns(el)
        };
        var ph = el.getAttribute('data-dt-search-placeholder');
        if (ph) opts.language = { search: 'Search:', searchPlaceholder: ph };

        var table = $(el).DataTable(opts);
        el._dtApi = table;

        // Declarative per-column filters targeting this table by id.
        if (el.id) {
            $('[data-dt-filter-for="' + el.id + '"]').each(function () {
                var col = parseInt(this.getAttribute('data-dt-col'), 10);
                if (isNaN(col)) return;
                var ev = this.tagName === 'SELECT' ? 'change' : 'keyup change';
                $(this).on(ev, function () { table.column(col).search(this.value).draw(); });
            });
        }
    }

    function initAll() {
        var tables = document.querySelectorAll('table.dt-server');
        if (!tables.length) return;   // nothing on this page — don't load the CDN

        loadCss(DT_CSS);

        // jQuery is loaded near the end of <body> (after most views); wait for it,
        // then load DataTables in order and initialise every opted-in table.
        (function waitJQ(n) {
            if (window.jQuery) {
                loadSeq(DT_JS, function () {
                    var $ = window.jQuery;
                    if (!$.fn || !$.fn.DataTable) return;   // graceful no-op if CDN unreachable
                    Array.prototype.forEach.call(tables, function (el) { initOne($, el); });
                });
            } else if (n < 200) {
                setTimeout(function () { waitJQ(n + 1); }, 50);   // ~10s cap
            }
        })(0);
    }

    ready(initAll);
})();
