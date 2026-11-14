/**
 * Corporate Book reader extras: "Continue from page N", bookmarks (both kept in this browser),
 * Save for later, Email me when back in stock, and anonymous reading counts.
 */
(function () {
  'use strict';
  var cfg = window.corporateBookReader;
  if (!cfg) {
    return;
  }
  var store = {
    get: function (k, d) {
      try { var v = JSON.parse(localStorage.getItem(k)); return v === null ? d : v; } catch (e) { return d; }
    },
    set: function (k, v) {
      try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* private window */ }
    }
  };

  function post(data) {
    var form = new FormData();
    data._ajax_nonce = cfg.nonce;
    Object.keys(data).forEach(function (k) { form.append(k, data[k]); });
    return fetch(cfg.url, { method: 'POST', body: form, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  // Counts each event once per visit (per browser tab session).
  function track(book, event, page) {
    var key = 'cbT:' + book + ':' + event + ':' + (page || 0);
    try {
      if (sessionStorage.getItem(key)) { return; }
      sessionStorage.setItem(key, '1');
    } catch (e) { /* counted anyway */ }
    post({ action: 'corporate_book_track', book: book, event: event, page: page || 0 });
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    e.className = cls || '';
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  function setupBook(node, app) {
    // The viewer's element id is "df_" plus the book's post ID.
    var book = parseInt(String(node.id).replace(/\D+/g, ''), 10);
    if (!book) {
      return;
    }
    var lastKey = 'cbLast:' + book;
    var marksKey = 'cbMarks:' + book;
    var bar = el('div', 'cb-reader-bar');
    node.parentNode.insertBefore(bar, node.nextSibling);

    // Resume reading.
    var last = store.get(lastKey, 0);
    if (last > 2 && app.currentPageNumber <= 3 && !/#/.test(window.location.hash)) {
      var ask = el('div', 'cb-resume');
      ask.appendChild(el('span', '', cfg.resume.replace('%d', last)));
      var yes = el('button', 'cb-resume-yes', cfg.yes);
      var no = el('button', 'cb-resume-no', cfg.no);
      yes.type = no.type = 'button';
      yes.onclick = function () { app.gotoPage(last); ask.remove(); };
      no.onclick = function () { ask.remove(); };
      ask.appendChild(yes);
      ask.appendChild(no);
      bar.appendChild(ask);
    }

    // Bookmarks.
    var marks = el('div', 'cb-marks');
    var add = el('button', 'cb-mark-add', '☆ ' + cfg.mark);
    add.type = 'button';
    function draw() {
      marks.textContent = '';
      marks.appendChild(add);
      store.get(marksKey, []).forEach(function (p) {
        var chip = el('span', 'cb-mark');
        var go = el('button', 'cb-mark-go', '★ ' + cfg.page.replace('%d', p));
        var x = el('button', 'cb-mark-x', '×');
        go.type = x.type = 'button';
        x.setAttribute('aria-label', '×');
        go.onclick = function () { app.gotoPage(p); };
        x.onclick = function () { store.set(marksKey, store.get(marksKey, []).filter(function (q) { return q !== p; })); draw(); };
        chip.appendChild(go);
        chip.appendChild(x);
        marks.appendChild(chip);
      });
    }
    add.onclick = function () {
      var list = store.get(marksKey, []);
      var p = app.currentPageNumber;
      if (list.indexOf(p) < 0) {
        list.push(p);
        list.sort(function (a, b) { return a - b; });
        store.set(marksKey, list.slice(-20));
      }
      draw();
    };
    draw();
    bar.appendChild(marks);

    // Remember the page and count how far readers get.
    track(book, 'open', 0);
    var furthest = 0;
    setInterval(function () {
      var p = app.currentPageNumber;
      if (p > 1) {
        store.set(lastKey, p);
      }
      if (p > furthest) {
        for (var i = furthest + 1; i <= p; i++) { track(book, 'page', i); }
        furthest = p;
      }
      var lock = node.querySelector('.cb-lock');
      if (lock && lock.offsetParent) {
        track(book, 'locked', 0);
      }
    }, 1500);
  }

  // Books start at different times, so look for new ones for a while.
  var tries = 0;
  var timer = setInterval(function () {
    if (window.jQuery) {
      window.jQuery('._df_book').each(function () {
        var app = window.jQuery(this).data('df-app');
        if (app && app.viewer && app.pageCount && !this.cbReader) {
          this.cbReader = true;
          setupBook(this, app);
        }
      });
    }
    if (++tries > 120) { clearInterval(timer); }
  }, 500);

  // Save for later and back-in-stock buttons.
  document.addEventListener('click', function (event) {
    var b = event.target.closest('.cb-wish, .cb-notify');
    if (!b) {
      return;
    }
    event.preventDefault();
    var wish = b.classList.contains('cb-wish');
    b.disabled = true;
    post({ action: wish ? 'corporate_book_wishlist' : 'corporate_book_notify', book: b.getAttribute('data-book') }).then(function (r) {
      if (!r || r.success !== true) {
        b.disabled = false;
        window.alert((r && r.data) || cfg.signin);
        return;
      }
      if (wish) {
        b.disabled = false;
        b.classList.toggle('cb-wished', r.data.saved);
        b.setAttribute('aria-pressed', r.data.saved ? 'true' : 'false');
        b.textContent = r.data.saved ? '♥ ' + cfg.saved : '♡ ' + cfg.save;
      } else {
        b.textContent = cfg.notified;
      }
    });
  });
})();
