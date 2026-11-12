/**
 * Corporate Book editor: "Create preview" builds the free preview PDF (the first
 * N% of pages) in the author's browser with pdf-lib and uploads it to the Media
 * Library, so readers without a subscription never receive the full PDF.
 */
(function ($) {
  'use strict';

  function buildPreview(bytes, percent) {
    return PDFLib.PDFDocument.load(bytes).then(function (full) {
      var total = full.getPageCount();
      var count = Math.max(1, Math.ceil(total * percent / 100));
      var indexes = [];
      for (var i = 0; i < count; i++) {
        indexes.push(i);
      }
      return PDFLib.PDFDocument.create().then(function (preview) {
        return preview.copyPages(full, indexes).then(function (pages) {
          pages.forEach(function (page) {
            preview.addPage(page);
          });
          return preview.save();
        }).then(function (data) {
          return { data: data, count: count, total: total };
        });
      });
    });
  }

  $(document).on('click', '#corporate_book_create_preview', function () {
    var button = $(this);
    var status = $('#corporate_book_preview_status');
    var source = $('#corporate_book_pdf_source').val();
    var percent = parseInt(button.data('percent'), 10) || 10;
    if (!source) {
      status.text('Select a PDF file first.');
      return;
    }
    button.prop('disabled', true);
    status.text('Creating preview...');
    fetch(source, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('could not load the PDF (HTTP ' + response.status + ')');
        }
        return response.arrayBuffer();
      })
      .then(function (bytes) {
        return buildPreview(bytes, percent);
      })
      .then(function (result) {
        var name = (source.split('/').pop().replace(/\.pdf$/i, '') || 'book') + '-preview.pdf';
        var form = new FormData();
        form.append('file', new Blob([result.data], { type: 'application/pdf' }), name);
        return wp.apiFetch({ path: '/wp/v2/media', method: 'POST', body: form }).then(function (media) {
          $('#corporate_book_preview_pdf').val(media.id);
          status.text('Preview created: ' + result.count + ' of ' + result.total + ' pages. Update the book to save it.');
        });
      })
      .catch(function (error) {
        status.text('Preview failed: ' + error.message);
      })
      .then(function () {
        button.prop('disabled', false);
      });
  });
})(jQuery);
