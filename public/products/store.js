/**
 * store.js — client-side storefront reassembler.
 *
 * Renders the PLP (product grid) and PDP (product detail) purely from the JSON
 * files that live alongside this script under /products/. All asset/data paths are
 * relative to the document <base href="/products/">, so the same files work on any
 * domain (tiknix.com now, your own domain after you publish the repo).
 *
 *   /products/            -> PLP, reads index.json
 *   /products/<sku>/      -> PDP, reads <sku>.json   (window.__STORE__.sku)
 */
(function () {
  var app = document.getElementById('app');
  var STORE = window.__STORE__ || { sku: null };
  var money = function (p, c) { return (c || 'usd').toUpperCase() + ' ' + Number(p || 0).toFixed(2); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); };
  var el = function (html) { var d = document.createElement('div'); d.innerHTML = html; return d.firstElementChild; };

  function head(title, backHref, backText) {
    return '<div class="store-head"><h1>' + esc(title) + '</h1>' +
      (backHref ? '<a href="' + backHref + '">' + esc(backText) + '</a>' : '') + '</div>';
  }

  function renderPLP(data) {
    var products = (data.products || []).filter(function (p) { return p.active !== false; });
    var html = '<div class="wrap">' + head('Shop', null, null);
    if (!products.length) { app.innerHTML = html + '<div class="empty">No products yet.</div></div>'; return; }
    html += '<div class="grid">';
    products.forEach(function (p) {
      html += '<a class="card" href="' + esc(p.sku) + '/">' +
        (p.image ? '<img src="' + esc(p.image) + '" alt="' + esc(p.title) + '" loading="lazy">' : '<div class="ph">◇</div>') +
        '<div class="meta">' +
        (p.category ? '<div class="cat">' + esc(p.category) + '</div>' : '') +
        '<div class="name">' + esc(p.title) + '</div>' +
        '<div class="price">' + money(p.price, p.currency) +
        (p.serialized ? ' <span class="badge">Unique</span>' : '') + '</div>' +
        '</div></a>';
    });
    app.innerHTML = html + '</div></div>';
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

  if (STORE.sku) {
    getJSON(STORE.sku + '.json').then(renderPDP).catch(function () { fail('Product not found.'); });
  } else {
    getJSON('index.json').then(renderPLP).catch(function () { fail('Store is empty.'); });
  }
})();
