/**
 * Corporate Book Global Settings: "Select Image" buttons open the Media Library
 * and put the chosen image's URL in the field next to them.
 */
jQuery(function ($) {
  'use strict';
  $(document).on('click', '.df-upload-media', function (event) {
    event.preventDefault();
    var input = $(this).closest('.df-upload').find('.df-upload-input');
    var frame = wp.media({ title: this.title || 'Select Image', library: { type: 'image' }, multiple: false });
    frame.on('select', function () {
      input.val(frame.state().get('selection').first().toJSON().url).trigger('change');
    });
    frame.open();
  });
});
