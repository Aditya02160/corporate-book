/**
 * Corporate Book library: the year buttons and the filter list show only the books
 * of one year or one category.
 */
document.addEventListener('click', function (event) {
  'use strict';
  var button = event.target.closest('.cb-library .cb-filter');
  if (!button) {
    return;
  }
  var library = button.closest('.cb-library');
  var filter = button.getAttribute('data-filter');
  Array.prototype.forEach.call(library.querySelectorAll('.cb-filter'), function (other) {
    other.classList.toggle('cb-active', other.getAttribute('data-filter') === filter);
  });
  Array.prototype.forEach.call(library.querySelectorAll('.cb-library-year'), function (section) {
    var shown = 0;
    Array.prototype.forEach.call(section.querySelectorAll('.cb-library-item'), function (item) {
      var match = filter === 'all' || (' ' + item.getAttribute('data-filters') + ' ').indexOf(' ' + filter + ' ') !== -1;
      item.hidden = !match;
      shown += match ? 1 : 0;
    });
    section.hidden = shown === 0;
  });
});
