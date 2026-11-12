/**
 * Corporate Book subscription lock: on the last free preview page, shows the
 * "Subscribe" panel over the viewer. The preview file itself holds only the free
 * pages, so this panel is the reader's way forward, not the protection.
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
    // shortcut: shows one page early in single-page mode; read the viewer's page mode if that matters.
    var atEnd = app.currentPageNumber >= app.pageCount - 1;
    var panel = app.container.find('.cb-lock');
    if (atEnd && !panel.length) {
      panel = $('<div class="cb-lock"><p></p></div>').css({
        position: 'absolute', left: 0, right: 0, bottom: 0, zIndex: 100,
        padding: '16px', background: 'rgba(255,255,255,.96)', color: '#222', textAlign: 'center'
      });
      panel.find('p').text(lock.text);
      if (/^https?:\/\//i.test(lock.url || '')) {
        $('<a class="cb-button"></a>').attr('href', lock.url).text(lock.button).appendTo(panel);
      }
      app.container.append(panel);
    }
    panel.toggle(atEnd);
  }

  // shortcut: polls because this viewer build fires no page-change callback; use one if the viewer gains it.
  setInterval(function () {
    $.each(apps(), function (i, app) {
      var lock = app.options && app.options.cbLock;
      if (lock && app.pageCount && app.container) {
        showLock(app, lock);
      }
    });
  }, 500);
})(jQuery);
