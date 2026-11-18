/**
 * Corporate Book print shop: cart (kept in this browser), cart page, delivery address, checkout.
 * The server prices everything; this file only sends book IDs, quantities and the address.
 */
(function () {
  'use strict';
  var cfg = window.corporateBookShop;
  if (!cfg) {
    return;
  }
  var KEY = 'cbCart';

  function read() {
    try {
      var items = JSON.parse(localStorage.getItem(KEY) || '[]');
      return Array.isArray(items) ? items.filter(function (i) { return i && i.id > 0 && i.qty > 0; }) : [];
    } catch (e) {
      return [];
    }
  }
  function write(items) {
    try {
      localStorage.setItem(KEY, JSON.stringify(items));
    } catch (e) { /* private window: the cart lasts for this page only */ }
    pill();
    badges();
  }
  function count() {
    return read().reduce(function (sum, i) { return sum + i.qty; }, 0);
  }

  function post(data) {
    var form = new FormData();
    data._ajax_nonce = cfg.nonce;
    Object.keys(data).forEach(function (key) {
      form.append(key, data[key]);
    });
    return fetch(cfg.url, { method: 'POST', body: form, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { throw { message: cfg.failed }; })
      .then(function (answer) {
        if (!answer || answer.success !== true) {
          var d = answer && answer.data;
          throw typeof d === 'string' ? { message: d } : (d || { message: cfg.failed });
        }
        return answer.data || {};
      });
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  // Floating "Cart (n)" link on every page except the cart itself.
  function pill() {
    var head = document.querySelector('.cb-head-count');
    if (head) {
      head.textContent = count();
      head.hidden = !count();
    }
    if (head || document.querySelector('.cb-cart')) {
      return;
    }
    var link = document.querySelector('.cb-cart-pill');
    var n = count();
    if (!link && n) {
      link = el('a', 'cb-cart-pill');
      link.href = cfg.cart;
      document.body.appendChild(link);
    }
    if (link) {
      link.textContent = cfg.cartName + ' (' + n + ')';
      link.hidden = !n;
    }
  }

  // "2 in your cart" under every Add to cart button for a book already in the cart.
  function badges() {
    var items = read();
    document.querySelectorAll('.cb-add-cart').forEach(function (button) {
      var id = parseInt(button.getAttribute('data-book'), 10);
      var line = items.filter(function (i) { return i.id === id; })[0];
      var badge = button.nextElementSibling;
      if (!badge || !badge.classList.contains('cb-in-cart')) {
        badge = el('a', 'cb-in-cart');
        badge.href = cfg.cart;
        button.parentNode.insertBefore(badge, button.nextSibling);
      }
      badge.textContent = line ? cfg.inCart.replace('%d', line.qty) + ' · ' + cfg.view : '';
      badge.hidden = !line;
    });
  }
  badges();
  pill();

  function toast(text) {
    var t = document.querySelector('.cb-toast') || document.body.appendChild(el('div', 'cb-toast'));
    t.setAttribute('role', 'status');
    t.textContent = text + ' · ';
    var a = el('a', '', cfg.view);
    a.href = cfg.cart;
    t.appendChild(a);
    t.classList.add('cb-show');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(function () { t.classList.remove('cb-show'); }, 4000);
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.cb-add-cart');
    if (!button) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    var id = parseInt(button.getAttribute('data-book'), 10);
    var items = read();
    var line = items.filter(function (i) { return i.id === id; })[0];
    if (line) {
      line.qty = Math.min(10, line.qty + 1);
    } else {
      items.push({ id: id, qty: 1 });
    }
    write(items);
    toast(cfg.added);
  }, true);

  // ---- Cart page ---------------------------------------------------------------------------

  var cart = document.querySelector('.cb-cart');
  var form = cart && cart.querySelector('.cb-checkout');

  function pin() {
    var field = form && form.querySelector('[name="pin"]');
    var value = field ? field.value.replace(/\s+/g, '') : '';
    return /^[1-9][0-9]{5}$/.test(value) ? value : '';
  }

  function render(q) {
    var box = cart.querySelector('.cb-cart-items');
    box.textContent = '';
    // The server may drop books that are no longer for sale or lower quantities to the stock.
    write(q.lines.map(function (l) { return { id: l.id, qty: l.qty }; }));
    if (q.errors && q.errors.length) {
      var errors = el('ul', 'cb-cart-errors');
      q.errors.forEach(function (e) { errors.appendChild(el('li', '', e)); });
      box.appendChild(errors);
    }
    if (!q.lines.length) {
      box.appendChild(el('p', 'cb-cart-empty', cfg.empty));
      if (form) { form.hidden = true; }
      return;
    }
    if (form) { form.hidden = false; }
    var table = el('table', 'cb-cart-table');
    q.lines.forEach(function (l) {
      var row = table.insertRow();
      row.setAttribute('data-book', l.id);
      var c0 = row.insertCell();
      if (l.thumb) {
        var img = el('img');
        img.src = l.thumb;
        img.alt = '';
        c0.appendChild(img);
      }
      var c1 = row.insertCell();
      c1.appendChild(el('strong', '', l.title));
      c1.appendChild(el('div', 'cb-cart-price', l.price));
      var c2 = row.insertCell();
      var minus = el('button', 'cb-qty-minus', '−');
      var plus = el('button', 'cb-qty-plus', '+');
      minus.type = plus.type = 'button';
      minus.setAttribute('aria-label', '−1');
      plus.setAttribute('aria-label', '+1');
      minus.disabled = l.qty <= 1;
      plus.disabled = l.qty >= l.max;
      c2.className = 'cb-qty';
      c2.appendChild(minus);
      c2.appendChild(el('span', 'cb-qty-value', String(l.qty)));
      c2.appendChild(plus);
      row.insertCell().textContent = l.total;
      var c4 = row.insertCell();
      var remove = el('button', 'cb-cart-remove', cfg.remove);
      remove.type = 'button';
      c4.appendChild(remove);
    });
    box.appendChild(table);
    if (form) {
      // Price Details: price, shipping, discount, total, what the reader saves, delivery time, trust line.
      var totals = form.querySelector('.cb-cart-totals');
      totals.textContent = '';
      totals.appendChild(el('h3', 'cb-price-title', cfg.priceDetails));
      var box = totals.appendChild(el('div', 'cb-price-box'));
      var rows = box.appendChild(el('div', 'cb-price-rows'));
      var add = function (label, value, cls, was) {
        var row = el('div', 'cb-total-row ' + (cls || ''));
        row.appendChild(el('span', '', label));
        var amount = el('strong');
        if (was) { amount.appendChild(el('s', '', was)); }
        amount.appendChild(document.createTextNode(value));
        row.appendChild(amount);
        rows.appendChild(row);
      };
      var copies = q.copies || 0;
      add(copies === 1 ? cfg.priceOne : cfg.priceMany.replace('%d', copies), q.subtotal);
      var freeShip = q.shipping !== null && q.shippingWas !== undefined && !/\d/.test(q.shipping);
      add(cfg.shippingLabel || 'Shipping', q.shipping === null ? (cfg.enterPin || 'Enter your PIN code') : (freeShip ? cfg.freeLabel : q.shipping), freeShip ? 'cb-free' : '', freeShip ? q.shippingWas : '');
      if (q.discount) {
        rows.appendChild(el('div', 'cb-price-sep'));
        add(cfg.couponLabel + (q.coupon ? ' (' + q.coupon + ')' : ''), q.discount, 'cb-discount');
      }
      rows.appendChild(el('div', 'cb-price-sep cb-solid'));
      add(cfg.totalLabel || 'Total', q.total, 'cb-grand-total');
      if (q.saved) {
        var save = box.appendChild(el('div', 'cb-save-banner'));
        save.appendChild(el('span', 'cb-save-badge', '%'));
        var parts = cfg.youSave.split('%s');
        var text = save.appendChild(el('span'));
        text.appendChild(document.createTextNode(parts[0]));
        text.appendChild(el('b', '', q.saved));
        text.appendChild(document.createTextNode(parts[1] || ''));
      }
      if (q.days) {
        totals.appendChild(el('p', 'cb-delivery-days', q.days));
      }
      var trust = totals.appendChild(el('p', 'cb-trust'));
      trust.innerHTML = '<svg width="26" height="30" viewBox="0 0 26 30" aria-hidden="true"><path d="M13 1 2 5v8c0 7.2 4.7 13.6 11 16 6.3-2.4 11-8.8 11-16V5L13 1z" fill="#9aa3b5"/><path d="m8 15 3.5 3.5L18.5 11" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      trust.appendChild(el('span', '', cfg.trust));
      if (cfg.agree) {
        // Built and escaped on the server: links to the Terms of Use and Privacy Policy pages.
        totals.appendChild(el('p', 'cb-agree')).innerHTML = cfg.agree;
      }
    }
  }

  var asked = 0;
  function refresh() {
    var coupon = form && form.querySelector('[name="coupon"]');
    // Only the newest answer counts: an older one arriving late must not undo a new PIN or coupon.
    var mine = ++asked;
    return post({ action: 'corporate_book_cart_quote', items: JSON.stringify(read()), pin: pin(), coupon: coupon ? coupon.value : '' }).then(function (q) {
      if (mine === asked) { render(q); }
    }, function (e) {
      if (mine === asked) { cart.querySelector('.cb-cart-items').textContent = e.message; }
    });
  }

  function say(text) {
    var m = cart.querySelector('.cb-cart-message');
    if (m) { m.textContent = text || ''; }
  }

  function fieldErrors(scope, fields) {
    scope.querySelectorAll('.cb-field-error').forEach(function (e) {
      e.textContent = (fields && fields[e.getAttribute('data-for')]) || '';
    });
  }

  function loadCheckout() {
    if (window.Razorpay) {
      return Promise.resolve();
    }
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://checkout.razorpay.com/v1/checkout.js';
      s.onload = resolve;
      s.onerror = function () { reject({ message: cfg.failed }); };
      document.head.appendChild(s);
    });
  }

  function pay(order) {
    return loadCheckout().then(function () {
      return new Promise(function (resolve, reject) {
        new window.Razorpay({
          key: order.key,
          amount: order.amount,
          currency: order.currency,
          name: order.name,
          description: order.description,
          order_id: order.order_id,
          prefill: order.prefill,
          handler: resolve,
          modal: { ondismiss: function () { reject({ message: cfg.cancel }); } }
        }).open();
      });
    }).then(function (paid) {
      return post({ action: 'corporate_book_print_verify', order: order.order, payment_id: paid.razorpay_payment_id, signature: paid.razorpay_signature });
    });
  }

  function done(result) {
    write([]);
    cart.textContent = '';
    var box = el('div', 'cb-cart-done');
    box.appendChild(el('h3', '', '✓'));
    box.appendChild(el('p', '', result.message));
    var a = el('a', 'cb-plan-signin', cfg.myAccount || 'My account');
    a.href = cfg.account;
    box.appendChild(a);
    cart.appendChild(box);
    cart.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  if (cart) {
    refresh();
    cart.addEventListener('click', function (event) {
      var row = event.target.closest('tr[data-book]');
      if (!row) {
        return;
      }
      var id = parseInt(row.getAttribute('data-book'), 10);
      var items = read();
      items.forEach(function (i) {
        if (i.id === id) {
          if (event.target.closest('.cb-qty-plus')) { i.qty = Math.min(10, i.qty + 1); }
          if (event.target.closest('.cb-qty-minus')) { i.qty = Math.max(1, i.qty - 1); }
        }
      });
      if (event.target.closest('.cb-cart-remove')) {
        items = items.filter(function (i) { return i.id !== id; });
      }
      write(items);
      refresh();
    });
    if (form) {
      var last = pin();
      form.addEventListener('input', function (event) {
        if (event.target.name === 'pin' && pin() !== last) {
          last = pin();
          refresh();
        }
      });
      form.addEventListener('change', function (event) {
        if (event.target.name === 'gift') {
          form.querySelector('.cb-gift-message').hidden = !event.target.checked;
        }
      });
      form.querySelector('.cb-apply-coupon').addEventListener('click', refresh);
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var button = form.querySelector('.cb-place-order');
        var data = { action: 'corporate_book_checkout', items: JSON.stringify(read()) };
        Array.prototype.forEach.call(form.elements, function (f) {
          if (f.name && ((f.type !== 'radio' && f.type !== 'checkbox') || f.checked)) {
            data[f.name] = f.value;
          }
        });
        button.disabled = true;
        say('');
        fieldErrors(form, null);
        post(data).then(function (order) {
          if (order.done) {
            return order;
          }
          say(cfg.paying);
          return pay(order);
        }).then(done, function (e) {
          button.disabled = false;
          fieldErrors(form, e.fields);
          say(e.message || cfg.failed);
          if (e.quote) {
            render(e.quote);
          }
        });
      });
    }
  }

  // ---- My account: cancel an order ---------------------------------------------------------

  document.addEventListener('click', function (event) {
    var b = event.target.closest('.cb-cancel-order');
    if (!b || !window.confirm(b.getAttribute('data-confirm'))) {
      return;
    }
    b.disabled = true;
    post({ action: 'corporate_book_cancel_order', order: b.getAttribute('data-order') }).then(function (r) {
      b.parentNode.textContent = r.message;
      setTimeout(function () { window.location.reload(); }, 2500);
    }, function (e) {
      b.disabled = false;
      window.alert(e.message || cfg.failed);
    });
  });

  // ---- My account: return a delivered order -----------------------------------------------

  document.addEventListener('click', function (event) {
    var b = event.target.closest('.cb-return-order');
    if (!b) {
      return;
    }
    var reason = window.prompt(b.getAttribute('data-ask'));
    if (!reason || !reason.trim()) {
      return;
    }
    b.disabled = true;
    post({ action: 'corporate_book_return_order', order: b.getAttribute('data-order'), reason: reason }).then(function (r) {
      b.parentNode.textContent = r.message;
      setTimeout(function () { window.location.reload(); }, 2500);
    }, function (e) {
      b.disabled = false;
      window.alert(e.message || cfg.failed);
    });
  });

  // ---- My account: delivery address --------------------------------------------------------

  document.addEventListener('submit', function (event) {
    var f = event.target.closest('.cb-address-form');
    if (!f) {
      return;
    }
    event.preventDefault();
    var data = { action: 'corporate_book_save_address' };
    Array.prototype.forEach.call(f.elements, function (x) {
      if (x.name) { data[x.name] = x.value; }
    });
    var message = f.parentNode.querySelector('.cb-account-message');
    fieldErrors(f, null);
    post(data).then(function (r) {
      message.textContent = r.message;
    }, function (e) {
      fieldErrors(f, e.fields);
      message.textContent = e.message || '';
    });
  });

  pill();
})();
