/**
 * store.js — client-side storefront reassembler for the /shop front controller.
 *
 * window.__STORE__ carries { view, dataBase, catBase, shopBase } plus a sku/category:
 *   view 'plp'      -> dataBase/index.json                    (all products)
 *   view 'pdp'      -> dataBase/<sku>.json                    (product page)
 *   view 'category' -> catBase/<slug>.json + dataBase/index.json  (one catalog)
 *   view 'catalogs' -> catBase/index.json                     (all catalogs)
 *
 * Data paths are absolute (/products/…, /categories/…) so they resolve on any
 * domain; navigation links point back into the front controller (/shop/…).
 */
(function () {
  var app = document.getElementById('app');
  var S = window.__STORE__ || {};
  var DATA = S.dataBase || '/shop/product/';
  var CATB = S.catBase || '/shop/catalog/';
  var SHOP = S.shopBase || '/shop/';

  var money = function (p, c) { return (c || 'usd').toUpperCase() + ' ' + Number(p || 0).toFixed(2); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); };
  var productHref = function (sku) { return SHOP + 'product/' + encodeURIComponent(sku) + '/'; };
  var catalogHref = function (slug) { return SHOP + 'catalog/' + encodeURIComponent(slug) + '/'; };

  function head(title, backHref, backText) {
    return '<div class="store-head"><h1>' + esc(title) + '</h1>' +
      (backHref ? '<a href="' + backHref + '">' + esc(backText) + '</a>' : '') + '</div>';
  }

  function card(p) {
    return '<a class="card" href="' + productHref(p.sku) + '">' +
      (p.image ? '<img src="' + DATA + esc(p.image) + '" alt="' + esc(p.title) + '" loading="lazy">' : '<div class="ph">◇</div>') +
      '<div class="meta">' +
      (p.category ? '<div class="cat">' + esc(p.category) + '</div>' : '') +
      '<div class="name">' + esc(p.title) + '</div>' +
      '<div class="price">' + money(p.price, p.currency) +
      (p.serialized ? ' <span class="badge">Unique</span>' : '') + '</div>' +
      '</div></a>';
  }

  function grid(title, backHref, backText, products) {
    var html = '<div class="wrap">' + head(title, backHref, backText);
    if (!products.length) { app.innerHTML = html + '<div class="empty">No products here yet.</div></div>'; return; }
    app.innerHTML = html + '<div class="grid">' + products.map(card).join('') + '</div></div>';
  }

  function renderPLP(data) {
    grid('Shop', null, null, (data.products || []).filter(function (p) { return p.active !== false; }));
  }

  function renderCategory(cat, manifest) {
    var bySku = {}; (manifest.products || []).forEach(function (p) { bySku[p.sku] = p; });
    var products = (cat.products || []).map(function (s) { return bySku[s]; })
      .filter(function (p) { return p && p.active !== false; });
    grid(cat.title || 'Catalog', SHOP + 'product/', '← All products', products);
    document.title = (cat.title || 'Catalog') + ' — Shop';
  }

  function renderCatalogs(data) {
    var cats = data.categories || [];
    var html = '<div class="wrap">' + head('Catalogs', SHOP + 'product/', '← All products');
    if (!cats.length) { app.innerHTML = html + '<div class="empty">No catalogs yet.</div></div>'; return; }
    html += '<div class="grid">' + cats.map(function (c) {
      return '<a class="card" href="' + catalogHref(c.slug) + '"><div class="ph">▤</div>' +
        '<div class="meta"><div class="name">' + esc(c.title) + '</div>' +
        '<div class="price">' + (c.count || 0) + ' product' + (c.count === 1 ? '' : 's') + '</div></div></a>';
    }).join('') + '</div></div>';
    app.innerHTML = html;
  }

  function renderPDP(p) {
    var imgs = (p.images || []).map(function (i) { return DATA + i; });
    var available = p.serialized ? (p.units || []).length : (p.stock || 0);
    var stock = p.serialized
      ? '<span class="badge">Unique item</span> ' + available + ' available · held ' + (p.holdMinutes || 0) + ' min in your cart'
      : (available > 0 ? '<span class="badge">In stock</span> · ' + available + ' available' : '<span class="badge">Out of stock</span>');
    var gallery = imgs.length
      ? '<img class="main" id="main" src="' + esc(imgs[0]) + '" alt="' + esc(p.title) + '">' +
        (imgs.length > 1 ? '<div class="thumbs">' + imgs.map(function (i) {
          return '<img src="' + esc(i) + '" alt="" onclick="document.getElementById(\'main\').src=this.src">'; }).join('') + '</div>' : '')
      : '<div class="main ph" style="display:flex;align-items:center;justify-content:center;font-size:3rem;color:var(--muted)">◇</div>';
    var cat = p.category
      ? '<a class="cat" href="' + catalogHref(p.category) + '" style="text-decoration:none">' + esc(p.category) + '</a>'
      : '';
    app.innerHTML = '<div class="wrap">' + head('Shop', SHOP + 'product/', '← All products') +
      '<div class="pdp"><div class="gallery">' + gallery + '</div>' +
      '<div class="detail">' + cat +
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

  // Never let a crafted sku/slug build a fetch path (traversal / cross-origin).
  // Server already normalizes to this shape; this is defense-in-depth on the client.
  var SAFE = /^[a-z0-9][a-z0-9-]*$/;

  if (S.view === 'pdp') {
    if (!SAFE.test(S.sku || '')) { fail('Product not found.'); return; }
    getJSON(DATA + S.sku + '.json').then(renderPDP).catch(function () { fail('Product not found.'); });
  } else if (S.view === 'category') {
    if (!SAFE.test(S.category || '')) { fail('Catalog not found.'); return; }
    Promise.all([getJSON(CATB + S.category + '.json'), getJSON(DATA + 'index.json')])
      .then(function (r) { renderCategory(r[0], r[1]); }).catch(function () { fail('Catalog not found.'); });
  } else if (S.view === 'catalogs') {
    getJSON(CATB + 'index.json').then(renderCatalogs).catch(function () { fail('No catalogs yet.'); });
  } else {
    getJSON(DATA + 'index.json').then(renderPLP).catch(function () { fail('Store is empty.'); });
  }
})();
