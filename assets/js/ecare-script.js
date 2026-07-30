/**
 * E-Care Health Services - Frontend & Admin JavaScript
 * Pure jQuery - Shukhee/Meditaj UI
 */
(function($) {
    'use strict';

    // ================================================================
    // 1. CAREGIVER BOOKING MODULE – Tab-style filters
    // ================================================================

    function loadCaregivers() {
        var type = $('#ecare-filter-type .ecare-type-tab.active').data('type') || '';
        var pkg  = $('#ecare-filter-package .ecare-package-tab.active').data('package') || '';

        $('#ecare-caregiver-grid').html('<div style="grid-column:1/-1;text-align:center;padding:40px;"><p>Loading caregivers...</p></div>');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_caregivers',
            nonce: ecare_ajax.nonce,
            provider_type: type,
            package_type: pkg
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-grid').html(response.data.html);
                
                // Update badge and count text if element exists
                $('.ecare-results-count').text(response.data.count + ' caregivers available');
                
                var badgesHtml = '';
                if (type) {
                    badgesHtml += '<span class="ecare-filter-badge">' + type + '</span>';
                }
                if (pkg) {
                    var pkgLabel = $('#ecare-filter-package .ecare-package-tab.active .ecare-pkg-label').text();
                    badgesHtml += '<span class="ecare-filter-badge">' + pkgLabel + '</span>';
                }
                $('.ecare-filter-badge-row').html(badgesHtml);
            }
        });
    }

    // Type tabs click
    $(document).on('click', '#ecare-filter-type .ecare-type-tab', function() {
        $('#ecare-filter-type .ecare-type-tab').removeClass('active');
        $(this).addClass('active');
        loadCaregivers();
    });

    // Package tabs click
    $(document).on('click', '#ecare-filter-package .ecare-package-tab', function() {
        $('#ecare-filter-package .ecare-package-tab').removeClass('active');
        $(this).addClass('active');
        loadCaregivers();
    });

    // Load initial caregiver list on page load
    if ($('#ecare-caregiver-grid').length) {
        loadCaregivers();
    }

    // Open caregiver details modal
    $(document).on('click', '.ecare-view-details, .ecare-cg-card-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');

        // Create backdrop modal if not exists
        if (!$('#ecare-caregiver-detail-modal').length) {
            $('body').append(
                '<div id="ecare-caregiver-detail-modal" class="ecare-modal-backdrop" style="display:none;">' +
                '  <div class="ecare-modal-container">' +
                '    <div class="ecare-modal-header">' +
                '      <h3>Caregiver Profile & Booking</h3>' +
                '      <button class="ecare-modal-close-btn">&times;</button>' +
                '    </div>' +
                '    <div id="ecare-caregiver-detail-content" class="ecare-modal-body"></div>' +
                '  </div>' +
                '</div>'
            );
        }

        $('#ecare-caregiver-detail-content').html('<p style="text-align:center;padding:40px;">Loading details...</p>');
        $('#ecare-caregiver-detail-modal').fadeIn(200);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_caregiver_details',
            nonce: ecare_ajax.nonce,
            caregiver_id: id
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-detail-content').html(response.data.html);
                
                // Trigger click on active filter package in the modal details if available
                var preselectedPkg = $('#ecare-filter-package .ecare-package-tab.active').data('package');
                if (preselectedPkg) {
                    var $matchedRadioTab = $('.ecare-detail-main .ecare-package-tab[data-package="' + preselectedPkg + '"]');
                    if ($matchedRadioTab.length) {
                        $matchedRadioTab.trigger('click');
                    }
                }
            } else {
                $('#ecare-caregiver-detail-content').html('<p class="error" style="color:#b91c1c;text-align:center;padding:40px;">Failed to load details.</p>');
            }
        });
    });

    // Close modal
    $(document).on('click', '.ecare-modal-close-btn', function() {
        $('#ecare-caregiver-detail-modal').fadeOut(200);
    });
    $(document).on('click', '#ecare-caregiver-detail-modal', function(e) {
        if ($(e.target).is('#ecare-caregiver-detail-modal')) {
            $(this).fadeOut(200);
        }
    });

    // Package radio selection in detail view
    $(document).on('click', '.ecare-detail-main .ecare-package-tab', function() {
        var $radio = $(this).find('input[type="radio"]');
        if ($radio.length) {
            $radio.prop('checked', true);
            var price = $radio.data('price');
            $('#ecare-price-display').text(price ? parseFloat(price).toLocaleString() : '0');
            $(this).closest('.ecare-package-tabs').find('.ecare-package-tab').removeClass('active');
            $(this).addClass('active');
        }
    });

    // Interactive family member selection toggler
    $(document).on('click', '.ecare-change-family-link', function(e) {
        e.preventDefault();
        var $list = $('.ecare-family-select-list');
        if ($list.is(':visible')) {
            $list.slideUp(200);
            $(this).text('Change Family Member');
        } else {
            $list.slideDown(200);
            $(this).text('Hide Family Members List');
        }
    });

    $(document).on('click', '.ecare-family-option-row', function() {
        var $row = $(this);
        $row.siblings().removeClass('selected');
        $row.addClass('selected');
        
        var name = $row.data('name');
        var relation = $row.data('relation');
        var phone = $row.data('phone');
        var email = $row.data('email');
        var gender = $row.data('gender');
        var age = $row.data('age');

        // Update selected box details
        $('.ecare-family-name').text(name);
        $('.ecare-family-badge').text(relation);
        
        // Update meta items
        $('.ecare-family-phone-val').text(phone);
        $('.ecare-family-email-val').text(email);
        $('.ecare-family-gender-age-val').text(gender + ' | ' + age);

        // Hide select list
        $('.ecare-family-select-list').slideUp(200);
        $('.ecare-change-family-link').text('Change Family Member');
    });

    // Submit caregiver booking form
    $(document).on('submit', '#ecare-booking-form', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_booking');
        formData.append('nonce', ecare_ajax.nonce);
        
        // Append selected family member name & relation
        formData.append('family_member_name', $('.ecare-family-name').text());
        formData.append('family_member_relation', $('.ecare-family-badge').text());

        var $btn = $(this).find('button[type="submit"]');
        var origText = $btn.text();
        $btn.prop('disabled', true).text('Booking in progress...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#ecare-booking-form').html(
                        '<div class="ecare-form-response success" style="margin-top:20px;">' + 
                        '  <strong>Success!</strong> ' + response.data.message + 
                        '</div>'
                    );
                    
                    // Redirect back to list after 3 seconds as required by specification
                    setTimeout(function() {
                        $('#ecare-caregiver-detail-modal').fadeOut(200, function() {
                            loadCaregivers();
                        });
                    }, 3000);
                } else {
                    alert(response.data.message);
                    $btn.prop('disabled', false).text(origText);
                }
            },
            error: function() {
                alert('Something went wrong. Please try again.');
                $btn.prop('disabled', false).text(origText);
            }
        });
    });

    // ================================================================
    // 2. CAREGIVER REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-caregiver-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.text();
        $btn.prop('disabled', true).text('Registering...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                var $resp = $form.find('.ecare-form-response');
                if (response.success) {
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success').removeClass('error');
                    $form[0].reset();
                    // Scroll to top of form
                    $('html, body').animate({ scrollTop: $form.offset().top - 40 }, 300);
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error').removeClass('success');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text(origText);
            }
        });
    });

    // Active Packages Inputs in caregiver registration based on selected caregiver type
    $(document).on('change', 'select[name="provider_type"]', function() {
        var val = $(this).val();
        var $pkgBox = $('.ecare-package-prices-section');
        if (val) {
            $pkgBox.slideDown(200);
        } else {
            $pkgBox.slideUp(200);
        }
    });

    // Toggle Bank Info fields in registration
    $(document).on('click', '.ecare-bank-type-btn', function() {
        $(this).siblings().removeClass('active');
        $(this).addClass('active');
        var target = $(this).data('target');
        if (target === 'mobile') {
            $('.bank-field-lbl').text('Mobile Wallet Operator (e.g. bKash, Rocket)');
            $('.bank-acc-lbl').text('Wallet Account Number');
        } else {
            $('.bank-field-lbl').text('Bank Name & Branch');
            $('.bank-acc-lbl').text('Account Number');
        }
    });

    // ================================================================
    // 3. LAB TEST MODULE
    // ================================================================

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

    $(document).on('change', '#ecare-lab-division', function() {
        var val = $(this).val();
        $('#ecare-lab-district').html('<option value="">Select District</option>').prop('disabled', true);
        $('#ecare-lab-area').html('<option value="">Select Area</option>').prop('disabled', true);
        $('#ecare-lab-provider').html('<option value="">Select Lab Provider</option>').prop('disabled', true);
        if (val) loadLocations('district', val, '#ecare-lab-district');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-district', function() {
        var val = $(this).val();
        $('#ecare-lab-area').html('<option value="">Select Area</option>').prop('disabled', true);
        $('#ecare-lab-provider').html('<option value="">Select Lab Provider</option>').prop('disabled', true);
        if (val) loadLocations('area', val, '#ecare-lab-area');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-area', function() {
        var val = $(this).val();
        $('#ecare-lab-provider').html('<option value="">Select Lab Provider</option>').prop('disabled', true);
        if (val) loadLocations('lab_provider', val, '#ecare-lab-provider');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-provider', function() { loadLabTests(); });
    $(document).on('keyup', '#ecare-lab-search', function() { loadLabTests(); });

    function getSelectedText(selectId) {
        var $sel = $(selectId);
        if ($sel.val()) return $sel.find('option:selected').data('name') || '';
        return '';
    }

    function loadLabTests() {
        var division = getSelectedText('#ecare-lab-division');
        var district = getSelectedText('#ecare-lab-district');
        var area = getSelectedText('#ecare-lab-area');
        var provider = getSelectedText('#ecare-lab-provider');

        // Only search/load if location is selected or at least division is selected
        if (!division) {
            $('#ecare-lab-grid').html(
                '<div class="ecare-empty-lab-view" style="grid-column:1/-1;">' +
                '  <span style="font-size:48px;display:block;margin-bottom:12px;">🏥</span>' +
                '  <p>Select a location and provider to view available tests.</p>' +
                '</div>'
            );
            $('.ecare-test-count').text('Showing 0 tests');
            return;
        }

        $('#ecare-lab-grid').html('<div style="grid-column:1/-1;text-align:center;padding:40px;"><p>Loading lab tests...</p></div>');
        
        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_lab_tests',
            nonce: ecare_ajax.nonce,
            division: division,
            district: district,
            area: area,
            lab_provider: provider,
            search: $('#ecare-lab-search').val()
        }, function(response) {
            if (response.success) {
                $('#ecare-lab-grid').html(response.data.html);
                $('.ecare-test-count').text('Showing ' + response.data.count + ' tests');
            }
        });
    }

    // Add lab test to cart via plus (+) button
    $(document).on('click', '.ecare-add-to-cart-plus-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var $btn = $(this);
        
        $btn.html('⏳').prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_add_lab_test_to_cart',
            nonce: ecare_ajax.nonce,
            test_id: id
        }, function(response) {
            if (response.success) {
                $btn.html('✓').css('background-color', '#0E9F6E');
                
                // Show floating success notice
                var $card = $btn.closest('.ecare-lab-test-card');
                var noticeHtml = '<div class="ecare-form-response success" style="margin-top:12px;font-size:12px;padding:6px 10px;">Added! <a href="' + response.data.cart_url + '" style="font-weight:700;color:#166534;text-decoration:underline;">Checkout</a></div>';
                
                // Remove previous notices in this card
                $card.find('.ecare-form-response').remove();
                $card.append(noticeHtml);

                // Update cart badge if exists
                if ($('.ecare-cart-badge').length && response.data.cart_count) {
                    $('.ecare-cart-badge').html('🛒 Cart (' + response.data.cart_count + ')');
                }
            } else {
                alert(response.data.message);
                $btn.html('+').prop('disabled', false);
            }
        });
    });

    // ================================================================
    // 4. AMBULANCE MODULE – Card-style type select
    // ================================================================

    // Ambulance type card click
    $(document).on('click', '#ecare-ambulance-type .ecare-amb-type-card', function() {
        $('#ecare-ambulance-type .ecare-amb-type-card').removeClass('active');
        $(this).addClass('active');
        
        var type = $(this).data('type');
        var label = $(this).find('.ecare-amb-type-title').text();
        var price = $(this).data('price');
        
        $('input[name="ambulance_type"]').val(type);
        $('#ecare-summary-type').text(label);
        $('#ecare-summary-price').text(Number(price).toLocaleString());
    });

    // Priority select update
    $(document).on('change', 'select[name="priority_level"]', function() {
        $('#ecare-summary-priority').text($(this).find('option:selected').text());
    });

    // Submit ambulance request form
    $(document).on('submit', '#ecare-ambulance-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $('.ecare-confirm-btn, .ecare-confirm-amb-btn');
        
        if (!$('#agree-terms').is(':checked')) {
            alert('Please agree to the terms and privacy policy.');
            return;
        }

        var origText = $btn.text();
        $btn.prop('disabled', true).text('Confirming request...');

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
                // Check if WooCommerce payment redirect URL is returned
                if (response.data.checkout_url) {
                    $form.html('<div class="ecare-form-response success">' + response.data.message + '<br>Redirecting to checkout...</div>');
                    setTimeout(function() {
                        window.location.href = response.data.checkout_url;
                    }, 2000);
                } else {
                    $form.html('<div class="ecare-form-response success">' + response.data.message + '</div>');
                }
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).text(origText);
            }
        }).fail(function() {
            alert('Error submitting request. Please try again.');
            $btn.prop('disabled', false).text(origText);
        });
    });

    // ================================================================
    // 5. AMBULANCE REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-ambulance-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_ambulance_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.text();
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
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success').removeClass('error');
                    $form[0].reset();
                    $('html, body').animate({ scrollTop: $form.offset().top - 40 }, 300);
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error').removeClass('success');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text(origText);
            }
        });
    });

    // ================================================================
    // 6. ADMIN DASHBOARD ACTIONS (Meditaj / Shukhee Style)
    // ================================================================

    // Update booking status instantly via AJAX select
    $(document).on('change', '.ecare-status-select', function() {
        var $select = $(this);
        var bookingId = $select.data('booking-id');
        var status = $select.val();

        $select.prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_booking_status',
            nonce: ecare_ajax.nonce,
            booking_id: bookingId,
            status: status
        }, function(response) {
            $select.prop('disabled', false);
            if (response.success) {
                var $row = $select.closest('tr');
                var $pill = $row.find('.ecare-status-pill');
                
                // Remove existing status classes
                $pill.removeClass('pending approved completed cancelled dispatched assigned emergency');
                $pill.addClass(status);
                
                var label = status.charAt(0).toUpperCase() + status.slice(1);
                $pill.text(label);
            } else {
                alert(response.data.message);
            }
        });
    });

    // Approve/Reject provider actions from admin table
    $(document).on('click', '.ecare-approve-provider, .ecare-reject-provider', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var status = $btn.hasClass('ecare-approve-provider') ? 'approved' : 'rejected';

        $btn.prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_provider_status',
            nonce: ecare_ajax.nonce,
            provider_id: id,
            status: status
        }, function(response) {
            $btn.prop('disabled', false);
            if (response.success) {
                var $row = $btn.closest('tr');
                var $pill = $row.find('.ecare-status-pill');
                
                $pill.removeClass('pending approved rejected');
                $pill.addClass(status);
                $pill.text(status.charAt(0).toUpperCase() + status.slice(1));
                
                // Hide actions column buttons as status resolved
                $btn.closest('td').find('.ecare-approve-provider, .ecare-reject-provider').hide();
            } else {
                alert(response.data.message);
            }
        });
    });

})(jQuery);
