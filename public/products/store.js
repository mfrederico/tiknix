/**
 * store.js — client-side storefront reassembler.
 *
 * Renders three views purely from JSON, all paths relative to the document <base>:
 *   /products/            PLP   -> index.json                 (window.__STORE__ = {})
 *   /products/<sku>/      PDP   -> <sku>.json                 (window.__STORE__.sku)
 *   /categories/<slug>/   CAT   -> <slug>.json + products     (window.__STORE__.category
 *                                                              + productsBase="../products/")
 *
 * The same files work on any domain (tiknix.com now, your own domain on publish).
 */
(function () {
  var app = document.getElementById('app');
  var STORE = window.__STORE__ || {};
  var PBASE = STORE.productsBase || '';   // prefix to reach /products/ from the current page
  var money = function (p, c) { return (c || 'usd').toUpperCase() + ' ' + Number(p || 0).toFixed(2); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); };

  function head(title, backHref, backText) {
    return '<div class="store-head"><h1>' + esc(title) + '</h1>' +
      (backHref ? '<a href="' + backHref + '">' + esc(backText) + '</a>' : '') + '</div>';
  }

  // One product card. pbase reaches /products/ from wherever this grid is rendered.
  function card(p, pbase) {
    return '<a class="card" href="' + pbase + esc(p.sku) + '/">' +
      (p.image ? '<img src="' + pbase + esc(p.image) + '" alt="' + esc(p.title) + '" loading="lazy">' : '<div class="ph">◇</div>') +
      '<div class="meta">' +
      (p.category ? '<div class="cat">' + esc(p.category) + '</div>' : '') +
      '<div class="name">' + esc(p.title) + '</div>' +
      '<div class="price">' + money(p.price, p.currency) +
      (p.serialized ? ' <span class="badge">Unique</span>' : '') + '</div>' +
      '</div></a>';
  }

  function grid(title, backHref, backText, products, pbase) {
    var html = '<div class="wrap">' + head(title, backHref, backText);
    if (!products.length) { app.innerHTML = html + '<div class="empty">No products here yet.</div></div>'; return; }
    app.innerHTML = html + '<div class="grid">' + products.map(function (p) { return card(p, pbase); }).join('') + '</div></div>';
  }

  function renderPLP(data) {
    grid('Shop', null, null, (data.products || []).filter(function (p) { return p.active !== false; }), '');
  }

  function renderCategory(cat, manifest) {
    var bySku = {};
    (manifest.products || []).forEach(function (p) { bySku[p.sku] = p; });
    var products = (cat.products || []).map(function (s) { return bySku[s]; })
      .filter(function (p) { return p && p.active !== false; });
    grid(cat.title || 'Catalog', PBASE, '← All products', products, PBASE);
  }

  function renderPDP(p) {
    var imgs = p.images || [];
    var available = p.serialized ? (p.units || []).length : (p.stock || 0);
    var stock = p.serialized
      ? '<span class="badge">Unique item</span> ' + available + ' available · held ' + (p.holdMinutes || 0) + ' min in your cart'
      : (available > 0 ? '<span class="badge">In stock</span> · ' + available + ' available' : '<span class="badge">Out of stock</span>');
    var gallery = imgs.length
      ? '<img class="main" id="main" src="' + esc(imgs[0]) + '" alt="' + esc(p.title) + '">' +
        (imgs.length > 1 ? '<div class="thumbs">' + imgs.map(function (i) {
          return '<img src="' + esc(i) + '" alt="" onclick="document.getElementById(\'main\').src=this.src">'; }).join('') + '</div>' : '')
      : '<div class="main ph" style="display:flex;align-items:center;justify-content:center;font-size:3rem;color:var(--muted)">◇</div>';
    app.innerHTML = '<div class="wrap">' + head('Shop', './', '← All products') +
      '<div class="pdp"><div class="gallery">' + gallery + '</div>' +
      '<div class="detail">' +
      (p.category ? '<div class="cat">' + esc(p.category) + '</div>' : '') +
      '<h1>' + esc(p.title) + '</h1>' +
      '<div class="price">' + money(p.price, p.currency) + '</div>' +
      (p.description ? '<div class="desc">' + esc(p.description) + '</div>' : '') +
      '<div class="stock">' + stock + '</div>' +
      '<button class="btn" disabled title="Checkout arrives next">Add to cart</button>' +
      '<div class="sku">SKU ' + esc(p.sku) + '</div>' +
      '</div></div></div>';
    document.title = p.title + ' — Shop';
  }

  function fail(msg) { app.innerHTML = '<div class="wrap"><div class="err">' + esc(msg) + '</div></div>'; }
  function getJSON(url) {
    return fetch(url, { headers: { 'Accept': 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error(r.status); return r.json();
    });
  }

  if (STORE.category) {
    Promise.all([getJSON(STORE.category + '.json'), getJSON(PBASE + 'index.json')])
      .then(function (res) { renderCategory(res[0], res[1]); document.title = (res[0].title || 'Catalog') + ' — Shop'; })
      .catch(function () { fail('Catalog not found.'); });
  } else if (STORE.sku) {
    getJSON(STORE.sku + '.json').then(renderPDP).catch(function () { fail('Product not found.'); });
  } else {
    getJSON('index.json').then(renderPLP).catch(function () { fail('Store is empty.'); });
  }
})();
