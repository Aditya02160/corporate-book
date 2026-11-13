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
    if (source && button.data('protected') && source.indexOf(button.data('protected')) === 0) {
      source = button.data('endpoint'); // a protected file is only readable through the protected link
    }
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

  // "Create thumbnail": renders page 1 of the PDF with PDF.js and uploads it as the book thumbnail.
  $(document).on('click', '#corporate_book_create_thumb', function () {
    var button = $(this);
    var preview = $('#corporate_book_create_preview');
    var status = $('#corporate_book_thumb_status');
    var source = $('#corporate_book_pdf_source').val();
    if (!source) {
      status.text('Select a PDF file first.');
      return;
    }
    if (preview.data('protected') && source.indexOf(preview.data('protected')) === 0) {
      source = preview.data('endpoint');
    }
    button.prop('disabled', true);
    status.text('Creating thumbnail...');
    pdfjsLib.GlobalWorkerOptions.workerSrc = button.data('worker');
    pdfjsLib.getDocument({ url: source, withCredentials: true }).promise
      .then(function (pdf) {
        return pdf.getPage(1);
      })
      .then(function (page) {
        var viewport = page.getViewport({ scale: 400 / page.getViewport({ scale: 1 }).width });
        var canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise.then(function () {
          return new Promise(function (resolve) {
            canvas.toBlob(resolve, 'image/png');
          });
        });
      })
      .then(function (blob) {
        var form = new FormData();
        form.append('file', blob, (source.split('/').pop().split('?')[0].replace(/\.pdf$/i, '') || 'book') + '-thumb.png');
        return wp.apiFetch({ path: '/wp/v2/media', method: 'POST', body: form });
      })
      .then(function (media) {
        $('#corporate_book_pdf_thumb').val(media.source_url).trigger('change');
        status.text('Thumbnail created. Update the book to save it.');
      })
      .catch(function (error) {
        status.text('Thumbnail failed: ' + error.message);
      })
      .then(function () {
        button.prop('disabled', false);
      });
  });

  // The preview and thumbnail make themselves: a new PDF gets a new preview, and anything missing is filled in.
  function fillIn(pdfChanged) {
    if (!$('#corporate_book_pdf_source').val()) {
      return;
    }
    if (pdfChanged || !Number($('#corporate_book_preview_pdf').val())) {
      $('#corporate_book_create_preview').trigger('click');
    }
    if (!$('#corporate_book_pdf_thumb').val()) {
      $('#corporate_book_create_thumb').trigger('click');
    }
  }
  $(document).on('change', '#corporate_book_pdf_source', function () {
    fillIn(true);
  });
  $(function () {
    fillIn(false);
  });

  // Saving waits for a preview or thumbnail that is still being made, then saves with the same button.
  $(document).on('submit', '#post', function (event) {
    var busy = function () {
      return $('#corporate_book_create_preview').prop('disabled') || $('#corporate_book_create_thumb').prop('disabled');
    };
    if (!busy()) {
      return;
    }
    event.preventDefault();
    var button = $(document.activeElement).is(':submit') ? $(document.activeElement) : $('#publish');
    var waited = 0;
    var timer = setInterval(function () {
      waited += 300;
      // Never block saving for long: after 20 seconds save without the unfinished file.
      if (!busy() || waited > 20000) {
        clearInterval(timer);
        $('#corporate_book_create_preview, #corporate_book_create_thumb').prop('disabled', false);
        // WordPress marks its save buttons "disabled" on the first try and then ignores clicks on them.
        $('#submitpost').find(':button, :submit, a.submitdelete, #post-preview').removeClass('disabled');
        button.trigger('click');
      }
    }, 300);
  });
})(jQuery);
