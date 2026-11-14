/**
 * Corporate Book 3D extras: spiral binding and calendar mode for the 3D viewer.
 * The viewer bundle ships empty hooks for both (initSpiral, refreshSpiral, isVertical);
 * this file fills them in.
 */
(function () {
  'use strict';
  var CB = window.CORPORATE_BOOK;
  if (!CB || !CB.FlipBook3D) {
    return;
  }
  var proto = CB.FlipBook3D.prototype;
  var isOn = function (value) {
    return value === true || value === 'true';
  };

  // Calendar mode: the book is bound at the top and pages flip upward.
  proto.isVertical = function () {
    return !!(this.app && this.app.options && isOn(this.app.options.calendarMode));
  };

  // Spiral binding: metal rings along the spine.
  proto.initSpiral = function () {
    this.hasSpiral = isOn(this.app.options.hasSpiral);
    if (!this.hasSpiral || !window.THREE || !this.bookWrapper) {
      return;
    }
    var material = new THREE.MeshPhongMaterial({ color: 0x8a8a8a, shininess: 90 });
    var ring = new THREE.TorusGeometry(1, 0.2, 8, 24);
    this.spiral = new THREE.Group();
    for (var i = 0; i < this.spiralCount; i++) {
      this.spiral.add(new THREE.Mesh(ring, material));
    }
    this.bookWrapper.add(this.spiral);
  };

  proto.refreshSpiral = function () {
    var size = this._defaultPageSize;
    if (!this.spiral || !size) {
      return;
    }
    var vertical = this.isVertical();
    var length = (vertical ? size.width : size.height) * 0.9;
    var radius = Math.max(this.pageOffset * 2.5, (vertical ? size.width : size.height) * 0.016);
    var rings = this.spiral.children;
    for (var i = 0; i < rings.length; i++) {
      var along = -length / 2 + length * i / (rings.length - 1);
      rings[i].scale.set(radius, radius, radius);
      // A torus lies flat by default; turn it so it wraps around the spine.
      if (vertical) {
        rings[i].position.set(along, 0, 0);
        rings[i].rotation.set(0, Math.PI / 2, 0);
      } else {
        rings[i].position.set(0, along, 0);
        rings[i].rotation.set(Math.PI / 2, 0, 0);
      }
    }
  };
})();

/**
 * Realistic book and reading desk.
 * - Opening: a book that starts on its cover opens by itself once it is on screen.
 * - Corner peel (3D): the top page lifts a little when the mouse nears its outer bottom corner.
 * - Reading desk: embedded books lie on a desk with a lamp the reader can switch on and off.
 */
(function ($) {
  'use strict';
  // Read when used: the settings are printed after this file on some pages.
  var G = function () {
    return window.corporateBookWPGlobal || {};
  };
  var CB = window.CORPORATE_BOOK;
  if (!$ || !CB) {
    return;
  }
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var SVG = {
    lamp: '<svg viewBox="0 0 200 260" aria-hidden="true"><ellipse cx="60" cy="248" rx="52" ry="10" fill="#1d1d1f"/><rect x="54" y="150" width="12" height="96" rx="6" fill="#2b2b2e"/><path d="M60 156 L140 70" stroke="#2b2b2e" stroke-width="11" stroke-linecap="round"/><circle cx="60" cy="156" r="10" fill="#3a3a3e"/><circle cx="140" cy="70" r="9" fill="#3a3a3e"/><path d="M118 40 L190 78 L170 118 L98 80 Z" fill="#1f4e46"/><path d="M98 80 L170 118" stroke="#0f2f2a" stroke-width="5"/><ellipse class="cb-bulb" cx="136" cy="102" rx="20" ry="8" fill="#fff4cf" transform="rotate(28 136 102)"/></svg>',
    cup: '<svg viewBox="0 0 160 170" aria-hidden="true"><g class="cb-steam" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="4" stroke-linecap="round"><path d="M55 50 C45 35 65 25 55 8"/><path d="M80 50 C70 33 92 24 80 4"/><path d="M105 50 C95 36 115 26 105 10"/></g><ellipse cx="80" cy="150" rx="70" ry="14" fill="#e9e4dc"/><ellipse cx="80" cy="147" rx="58" ry="10" fill="#d7d0c5"/><path d="M28 62 H132 L122 138 Q80 152 38 138 Z" fill="#f5f2ec"/><ellipse cx="80" cy="62" rx="52" ry="12" fill="#f5f2ec"/><ellipse cx="80" cy="63" rx="45" ry="9" fill="#4a2c1a"/><path d="M130 78 q28 4 22 28 q-6 20 -28 18" fill="none" stroke="#f5f2ec" stroke-width="9"/></svg>',
    pen: '<svg viewBox="0 0 240 40" aria-hidden="true"><rect x="20" y="12" width="170" height="16" rx="8" fill="#0b1f5c"/><rect x="150" y="10" width="52" height="20" rx="6" fill="#c9a227"/><path d="M20 12 L0 20 L20 28 Z" fill="#c9a227"/><rect x="60" y="6" width="80" height="5" rx="2" fill="#c9a227"/></svg>',
    glasses: '<svg viewBox="0 0 220 80" aria-hidden="true"><g fill="rgba(200,225,255,.18)" stroke="#2a1d14" stroke-width="7"><circle cx="55" cy="45" r="32"/><circle cx="165" cy="45" r="32"/></g><path d="M87 40 Q110 26 133 40" fill="none" stroke="#2a1d14" stroke-width="6"/><path d="M23 40 L2 20 M197 40 L218 20" stroke="#2a1d14" stroke-width="6" stroke-linecap="round"/></svg>'
  };

  function storedLight() {
    try {
      return localStorage.getItem('cbDeskLight') !== 'off';
    } catch (e) {
      return true;
    }
  }

  function addDesk(el) {
    if (!G().deskScene || el.closest('.cb-desk') || el.closest('.df-lightbox-wrapper, .df-lightbox')) {
      return;
    }
    var desk = $('<div class="cb-desk"><div class="cb-desk-glow"></div><div class="cb-desk-lamp">' + SVG.lamp + '</div><div class="cb-desk-cup">' + SVG.cup + '</div><div class="cb-desk-pen">' + SVG.pen + '</div><div class="cb-desk-glasses">' + SVG.glasses + '</div><div class="cb-desk-dim"></div><button type="button" class="cb-desk-switch"></button></div>');
    $(el).before(desk);
    desk.find('.cb-desk-dim').before(el);
    var setLight = function (on) {
      desk.toggleClass('cb-light-off', !on);
      desk.find('.cb-desk-switch').attr({ 'aria-pressed': on ? 'true' : 'false', title: on ? G().deskText.lightOff : G().deskText.lightOn, 'aria-label': on ? G().deskText.lightOff : G().deskText.lightOn });
    };
    setLight(storedLight());
    desk.find('.cb-desk-switch').on('click', function () {
      var on = desk.hasClass('cb-light-off');
      setLight(on);
      try {
        localStorage.setItem('cbDeskLight', on ? 'on' : 'off');
      } catch (e) { /* private window */ }
    });
  }

  // The top sheet on one side: right = lowest page number still on the right, left = highest on the left.
  function topSheet(viewer, right) {
    var best = null;
    (viewer.sheets || []).forEach(function (s) {
      var onRight = s.sheetAngle >= 90;
      if (onRight !== right || !s.frontPage) {
        return;
      }
      if (!best || (right ? s.frontPage.pageNumber < best.frontPage.pageNumber : s.frontPage.pageNumber > best.frontPage.pageNumber)) {
        best = s;
      }
    });
    return best;
  }

  function settled(viewer) {
    return (viewer.sheets || []).every(function (s) {
      return s.cbPeel || s.sheetAngle === 0 || s.sheetAngle === 180;
    });
  }

  function addPeel(app) {
    var viewer = app.viewer;
    var lifted = null;
    var frame = null;
    var animate = function (sheet, to, done) {
      cancelAnimationFrame(frame);
      var from = sheet.sheetAngle;
      var start = null;
      var step = function (t) {
        start = start || t;
        var k = Math.min(1, (t - start) / 220);
        sheet.sheetAngle = from + (to - from) * (1 - Math.pow(1 - k, 3));
        sheet.updateAngle();
        // Ask the viewer's render loop for a new frame.
        viewer.renderRequestStatus = 'pending';
        app.renderRequestStatus = 'pending';
        if (k < 1) {
          frame = requestAnimationFrame(step);
        } else if (done) {
          done();
        }
      };
      frame = requestAnimationFrame(step);
    };
    var drop = function () {
      if (!lifted) {
        return;
      }
      var sheet = lifted;
      lifted = null;
      animate(sheet, sheet.cbRest, function () {
        sheet.cbPeel = false;
      });
    };
    $(app.element).on('mousemove.cbpeel', function (e) {
      if (viewer.isBooklet || app.zoomValue > 1 || !settled(viewer)) {
        return;
      }
      var rect = app.element[0].getBoundingClientRect();
      var pad = (app.dimensions && app.dimensions.padding) || { top: 0, left: 0, right: 0, bottom: 0 };
      var cx = rect.left + pad.left + (rect.width - pad.left - pad.right) / 2;
      var cy = rect.top + pad.top + (rect.height - pad.top - pad.bottom) / 2;
      var pw = viewer.availablePageWidth(), ph = viewer.availablePageHeight();
      var low = e.clientY > cy + ph * 0.15 && e.clientY < cy + ph / 2 + 30;
      var side = low && e.clientX > cx + pw * 0.72 && e.clientX < cx + pw + 30 ? 'right' : (low && e.clientX < cx - pw * 0.72 && e.clientX > cx - pw - 30 ? 'left' : '');
      var sheet = side ? topSheet(viewer, side === 'right') : null;
      if (sheet === lifted) {
        return;
      }
      drop();
      if (sheet) {
        lifted = sheet;
        sheet.cbPeel = true;
        sheet.cbRest = sheet.sheetAngle;
        animate(sheet, side === 'right' ? 158 : 22);
      }
    }).on('mouseleave.cbpeel mousedown.cbpeel touchstart.cbpeel', function () {
      if (lifted) {
        // Back to rest at once so a click flips from the normal position.
        cancelAnimationFrame(frame);
        lifted.sheetAngle = lifted.cbRest;
        lifted.updateAngle();
        viewer.renderRequestStatus = 'pending';
        lifted.cbPeel = false;
        lifted = null;
      }
    });
  }

  function addOpening(app) {
    if (reduceMotion || app.currentPageNumber !== 1 || /corporate-book-|#.*\d/.test(window.location.hash)) {
      return;
    }
    var opened = false;
    var open = function () {
      if (!opened && app.currentPageNumber === 1) {
        opened = true;
        setTimeout(function () {
          app.next();
        }, 1600);
      }
    };
    if (!('IntersectionObserver' in window)) {
      open();
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) {
        io.disconnect();
        open();
      }
    }, { threshold: 0.6 });
    io.observe(app.element[0]);
  }

  function setup() {
    $('._df_book').each(function () {
      var el = this;
      addDesk(el);
      var app = $(el).data('df-app');
      if (!app || !app.viewer || !app.pageCount || $(el).data('cb-real')) {
        return;
      }
      $(el).data('cb-real', true);
      if (G().realistic) {
        addOpening(app);
        if (CB.FlipBook3D && app.viewer instanceof CB.FlipBook3D && window.matchMedia('(hover: hover)').matches) {
          addPeel(app);
        }
      }
    });
  }
  // Books start at different times (lazy loading), so check for new ones for a while.
  var tries = 0;
  var timer = setInterval(function () {
    setup();
    if (++tries > 120) {
      clearInterval(timer);
    }
  }, 500);
  $(setup);
})(window.jQuery);
