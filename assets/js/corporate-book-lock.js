/**
 * Corporate Book subscription lock: when a reader tries to turn past the last free
 * preview page, shows the "Your free preview has ended" panel over the viewer.
 * The preview file itself holds only the free pages, so this panel is the reader's
 * way forward, not the protection.
 */
(function ($) {
  'use strict';

  function apps() {
    var list = [];
    $('._df_book').each(function () {
      var app = $(this).data('df-app');
      if (app) {
        list.push(app);
      }
    });
    var lightBox = window.CORPORATE_BOOK && window.CORPORATE_BOOK.activeLightBox;
    if (lightBox && lightBox.app) {
      list.push(lightBox.app);
    }
    return list;
  }

  function showLock(app, lock) {
    var panel = app.container.find('.cb-lock');
    if (!panel.length) {
      panel = $('<div class="cb-lock"><div class="cb-lock-title"></div><div class="cb-lock-box"><p class="cb-lock-text"></p><div class="cb-lock-account"></div></div></div>');
      panel.find('.cb-lock-title').text(lock.title);
      panel.find('.cb-lock-text').text(lock.text);
      // Sign-in and sign-up forms, plans or the subscribe link: HTML rendered and escaped by the server.
      panel.find('.cb-lock-account').html(lock.account);
      $('<button type="button" class="cb-lock-back"></button>').text(lock.back).on('click', function () {
        panel.hide();
      }).appendTo(panel.find('.cb-lock-box'));
      // Typing and pressing in the panel must not flip, drag or zoom the book underneath.
      panel.on('mousedown touchstart pointerdown keydown keyup wheel', function (event) {
        event.stopPropagation();
      });
      app.container.append(panel);
    }
    panel.toggleClass('cb-lock-full', app.container.width() < 700).show();
  }

  // The panel opens when "next" leaves the reader on the same page, i.e. the preview has no more pages.
  function watch(app, lock) {
    if (app.cbLockWatched) {
      return;
    }
    app.cbLockWatched = true;
    var next = app.next;
    app.next = function () {
      var before = app.currentPageNumber;
      next.apply(app, arguments);
      setTimeout(function () {
        if (app.currentPageNumber === before) {
          showLock(app, lock);
        }
      }, 50);
    };
  }

  // shortcut: polls for new viewers because this build fires no "created" callback; use one if the viewer gains it.
  setInterval(function () {
    $.each(apps(), function (i, app) {
      var lock = app.options && app.options.cbLock;
      if (lock && app.pageCount && app.container) {
        watch(app, lock);
      }
    });
  }, 500);
})(jQuery);
