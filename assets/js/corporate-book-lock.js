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

  function isWebLink(url) {
    return /^https?:\/\//i.test(url || '');
  }

  function showLock(app, lock) {
    var panel = app.container.find('.cb-lock');
    if (!panel.length) {
      panel = $('<div class="cb-lock"><div class="cb-lock-title"></div><div class="cb-lock-box"><p class="cb-lock-text"></p></div></div>');
      var box = panel.find('.cb-lock-box');
      panel.find('.cb-lock-title').text(lock.title);
      panel.find('.cb-lock-text').text(lock.text);
      if (isWebLink(lock.url)) {
        $('<a class="cb-lock-subscribe"></a>').attr('href', lock.url).text(lock.button).appendTo(box);
      }
      if (isWebLink(lock.signInUrl)) {
        if (isWebLink(lock.url)) {
          $('<div class="cb-lock-or"></div>').text(lock.or).appendTo(box);
        }
        $('<a class="cb-lock-signin"></a>').attr('href', lock.signInUrl).text(lock.signIn).appendTo(box);
      }
      if (lock.login) {
        // Output of the site's social login shortcode, set by an administrator.
        $('<div class="cb-lock-login"></div>').html(lock.login).appendTo(box);
      }
      $('<button type="button" class="cb-lock-back"></button>').text(lock.back).on('click', function () {
        panel.hide();
      }).appendTo(box);
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
