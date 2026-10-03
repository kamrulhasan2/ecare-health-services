/*
 * A link from the site search lands here with ?ecare_caregiver=ID (open that
 * caregiver's profile and booking) or ?ecare_amb_type=ICU (choose that
 * ambulance type). Uses the page's own handlers in ecare-script.js.
 */
(function ($) {
    'use strict';
    var q = new URLSearchParams(window.location.search);
    $(function () {
        var id = parseInt(q.get('ecare_caregiver'), 10);
        if (id > 0) {
            // The details handler listens on document, so a stand-in button opens the same modal.
            $('<a class="ecare-view-details" href="#" hidden></a>').attr('data-id', id).appendTo('body').trigger('click').remove();
        }
        var type = q.get('ecare_amb_type');
        if (type) {
            var $card = $('#ecare-ambulance-type .ecare-amb-type-card').filter(function () { return $(this).data('type') === type; });
            if ($card.length) {
                $card.first().trigger('click');
                if ($card[0].scrollIntoView) { $card[0].scrollIntoView({ block: 'center' }); }
            }
        }
    });
})(jQuery);
