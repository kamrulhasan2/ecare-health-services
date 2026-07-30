/**
 * E-Care Health Services - Frontend & Admin JavaScript
 * Pure jQuery - No frameworks
 */
(function($) {
    'use strict';

    // ================================================================
    // CAREGIVER BOOKING MODULE
    // ================================================================

    function loadCaregivers() {
        var type = $('#ecare-filter-type').val();
        var pkg = $('#ecare-filter-package').val();

        $('#ecare-caregiver-grid').html('<p>Loading caregivers...</p>');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_caregivers',
            nonce: ecare_ajax.nonce,
            provider_type: type,
            package_type: pkg
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-grid').html(response.data.html);
            }
        });
    }

    // Filter caregivers on change
    $(document).on('change', '#ecare-filter-type, #ecare-filter-package', function() {
        loadCaregivers();
    });

    // Load caregivers on page load
    if ($('#ecare-caregiver-grid').length) {
        loadCaregivers();
    }

    // View caregiver details
    $(document).on('click', '.ecare-view-details', function(e) {
        e.preventDefault();
        var id = $(this).data('id');

        $('#ecare-caregiver-detail-content').html('<p>Loading details...</p>');
        $('#ecare-caregiver-detail-modal').fadeIn(200);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_caregiver_details',
            nonce: ecare_ajax.nonce,
            caregiver_id: id
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-detail-content').html(response.data.html);
            } else {
                $('#ecare-caregiver-detail-content').html('<p class="error">Failed to load details.</p>');
            }
        });
    });

    // Close modal
    $(document).on('click', '.ecare-modal-close', function() {
        $('#ecare-caregiver-detail-modal').fadeOut(200);
    });

    $(document).on('click', '#ecare-caregiver-detail-modal', function(e) {
        if ($(e.target).is('#ecare-caregiver-detail-modal')) {
            $(this).fadeOut(200);
        }
    });

    // Update price display on package select
    $(document).on('change', 'input[name="package_type"]', function() {
        var price = $(this).data('price');
        $('#ecare-price-display').text(price ? parseFloat(price).toLocaleString() : '0');
    });

    // Submit caregiver booking form
    $(document).on('submit', '#ecare-booking-form', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_booking');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#ecare-booking-form').html('<div class="ecare-form-response success">' + response.data.message + '</div>');
                } else {
                    alert(response.data.message);
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Confirm Booking');
            }
        });
    });

    // ================================================================
    // CAREGIVER REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-caregiver-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                var $resp = $form.find('.ecare-form-response');
                if (response.success) {
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success');
                    $form[0].reset();
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Submit Registration');
            }
        });
    });

    // ================================================================
    // LAB TEST MODULE
    // ================================================================

    // Load divisions on page load
    if ($('#ecare-lab-division').length) {
        loadLocations('division', 0, '#ecare-lab-division');
    }

    function loadLocations(type, parentId, targetSelector) {
        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_locations',
            nonce: ecare_ajax.nonce,
            location_type: type,
            parent_id: parentId
        }, function(response) {
            if (response.success) {
                var $select = $(targetSelector);
                $select.find('option:not(:first)').remove();
                $.each(response.data.locations, function(i, loc) {
                    $select.append('<option value="' + loc.id + '" data-name="' + loc.name.replace(/"/g, '&quot;') + '">' + loc.name + '</option>');
                });
                $select.prop('disabled', false);
            }
        });
    }

    // Cascade location selects
    $(document).on('change', '#ecare-lab-division', function() {
        var val = $(this).val();
        var name = $(this).find('option:selected').data('name') || '';
        var $dist = $('#ecare-lab-district');
        var $area = $('#ecare-lab-area');
        var $prov = $('#ecare-lab-provider');
        $dist.html('<option value="">All Districts</option>').prop('disabled', true);
        $area.html('<option value="">All Areas</option>').prop('disabled', true);
        $prov.html('<option value="">All Providers</option>').prop('disabled', true);
        if (val) {
            loadLocations('district', val, '#ecare-lab-district');
        }
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-district', function() {
        var val = $(this).val();
        var $area = $('#ecare-lab-area');
        var $prov = $('#ecare-lab-provider');
        $area.html('<option value="">All Areas</option>').prop('disabled', true);
        $prov.html('<option value="">All Providers</option>').prop('disabled', true);
        if (val) {
            loadLocations('area', val, '#ecare-lab-area');
        }
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-area', function() {
        var val = $(this).val();
        var $prov = $('#ecare-lab-provider');
        $prov.html('<option value="">All Providers</option>').prop('disabled', true);
        if (val) {
            loadLocations('lab_provider', val, '#ecare-lab-provider');
        }
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-provider', function() {
        loadLabTests();
    });

    $(document).on('keyup', '#ecare-lab-search', function() {
        loadLabTests();
    });

    function getSelectedText(selectId) {
        var $sel = $(selectId);
        if ($sel.val()) {
            return $sel.find('option:selected').data('name') || '';
        }
        return '';
    }

    function loadLabTests() {
        $('#ecare-lab-grid').html('<p>Loading lab tests...</p>');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_lab_tests',
            nonce: ecare_ajax.nonce,
            division: getSelectedText('#ecare-lab-division'),
            district: getSelectedText('#ecare-lab-district'),
            area: getSelectedText('#ecare-lab-area'),
            lab_provider: getSelectedText('#ecare-lab-provider'),
            search: $('#ecare-lab-search').val()
        }, function(response) {
            if (response.success) {
                $('#ecare-lab-grid').html(response.data.html);
            }
        });
    }

    // Add lab test to cart
    $(document).on('click', '.ecare-add-lab-cart', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var $btn = $(this);
        $btn.text('Adding...').prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_add_lab_test_to_cart',
            nonce: ecare_ajax.nonce,
            test_id: id
        }, function(response) {
            if (response.success) {
                $btn.text('Added!');
                if (response.data.cart_url) {
                    var notice = $('<div class="ecare-form-response success" style="margin-top:10px;">Added to cart! <a href="' + response.data.cart_url + '">View Cart</a></div>');
                    $btn.closest('.ecare-lab-test-card').append(notice);
                }
            } else {
                alert(response.data.message);
                $btn.text('Add to Cart').prop('disabled', false);
            }
        });
    });

    // ================================================================
    // AMBULANCE MODULE
    // ================================================================

    // Update price summary
    $(document).on('change', '#ecare-ambulance-type', function() {
        var val = $(this).val();
        var prices = { 'Standard': 1500, 'ICU': 3000, 'Freezer': 5000 };
        var price = prices[val] || 0;
        $('#ecare-summary-type').text(val || '-');
        $('#ecare-summary-price').text(price.toLocaleString());
    });

    // Submit ambulance request
    $(document).on('submit', '#ecare-ambulance-form', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Submitting...');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_submit_ambulance_request',
            nonce: ecare_ajax.nonce,
            ambulance_type: $form.find('[name="ambulance_type"]').val(),
            pickup_address: $form.find('[name="pickup_address"]').val(),
            destination: $form.find('[name="destination"]').val(),
            schedule_time: $form.find('[name="schedule_time"]').val(),
            contact_phone: $form.find('[name="contact_phone"]').val(),
            priority_level: $form.find('[name="priority_level"]').val(),
            notes: $form.find('[name="notes"]').val()
        }, function(response) {
            if (response.success) {
                $form.html('<div class="ecare-form-response success">' + response.data.message + '</div>');
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).text('Confirm Request');
            }
        });
    });

    // ================================================================
    // AMBULANCE REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-ambulance-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_ambulance_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                var $resp = $form.find('.ecare-form-response');
                if (response.success) {
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success');
                    $form[0].reset();
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Submit Registration');
            }
        });
    });

    // ================================================================
    // ADMIN ACTIONS
    // ================================================================

    // Update booking status
    $(document).on('change', '.ecare-status-select', function() {
        var $select = $(this);
        var bookingId = $select.data('booking-id');
        var status = $select.val();

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_booking_status',
            nonce: ecare_ajax.nonce,
            booking_id: bookingId,
            status: status
        }, function(response) {
            if (response.success) {
                // Update the badge if present
                var $row = $select.closest('tr');
                $row.find('.ecare-status-badge')
                    .removeClass()
                    .addClass('ecare-status-badge ecare-status-' + status)
                    .text(status.charAt(0).toUpperCase() + status.slice(1));
            }
        });
    });

    // Approve/Reject provider from admin list
    $(document).on('click', '.ecare-approve-provider, .ecare-reject-provider', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var status = $btn.hasClass('ecare-approve-provider') ? 'approved' : 'rejected';

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_provider_status',
            nonce: ecare_ajax.nonce,
            provider_id: id,
            status: status
        }, function(response) {
            if (response.success) {
                var $row = $btn.closest('tr');
                $row.find('.ecare-status-badge')
                    .removeClass()
                    .addClass('ecare-status-badge ecare-status-' + status)
                    .text(status.charAt(0).toUpperCase() + status.slice(1));
                $btn.closest('td').find('.button').hide();
            }
        });
    });

})(jQuery);
