/**
 * Corporate Book library: pick a year and a brand together; a book shows when it matches both.
 */
document.addEventListener('click', function (event) {
  'use strict';
  var button = event.target.closest('.cb-library .cb-filter');
  if (!button) {
    return;
  }
  var library = button.closest('.cb-library');
  var group = button.closest('.cb-library-years, .cb-library-filter') || library;
  Array.prototype.forEach.call(group.querySelectorAll('.cb-filter'), function (other) {
    other.classList.toggle('cb-active', other === button);
  });
  var picked = [];
  Array.prototype.forEach.call(library.querySelectorAll('.cb-filter.cb-active'), function (b) {
    var f = b.getAttribute('data-filter');
    if (f !== 'all' && f !== 'cat-all') {
      picked.push(f);
    }
  });
  Array.prototype.forEach.call(library.querySelectorAll('.cb-library-year'), function (section) {
    var shown = 0;
    Array.prototype.forEach.call(section.querySelectorAll('.cb-library-item'), function (item) {
      var tokens = ' ' + item.getAttribute('data-filters') + ' ';
      var match = picked.every(function (f) { return tokens.indexOf(' ' + f + ' ') !== -1; });
      item.hidden = !match;
      shown += match ? 1 : 0;
    });
    section.hidden = shown === 0;
  });
});
