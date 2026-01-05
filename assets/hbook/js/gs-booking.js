/**
 * GoldenStay - HBook booking form minimal replacement:
 * - AJAX search (availability + calc-prices) via WP backend
 * - price breakdown show/hide toggle
 */
(function ($) {
  'use strict'

  function debugLog() {
    try {
      if (!window.console || !console.log) return
      console.log.apply(console, arguments)
    } catch (_) {
      // ignore
    }
  }

  function setFormLoading($form, isLoading) {
    $form.find('input[type="submit"], button[type="submit"]').prop('disabled', !!isLoading)
  }

  function showFormError($form, message) {
    var $error = $form.find('.hb-search-error')
    if ($error.length) {
      var text = (message || '').toString()
      // When clearing errors, hide the element completely to avoid "empty space".
      if (!text || text === '&nbsp;') {
        $error.html('&nbsp;')
        $error.hide()
        return
      }
      $error.html(text)
      $error.show()
      return
    }
    if (message) window.alert(message)
  }

  function handleSearch($form) {
    var $wrapper = $form.closest('.hbook-wrapper')
    var searchOnly = String($form.data('search-only') || '').toLowerCase()
    if (searchOnly === 'yes') {
      debugLog('[GS HB] submit ignored (search-only=yes)')
      return true
    }

    // If config is missing, fallback to normal POST.
    if (!window.gsHbBooking || !gsHbBooking.ajaxUrl || !gsHbBooking.nonce) {
      debugLog('[GS HB] gsHbBooking config missing, fallback to normal submit', window.gsHbBooking)
      return true
    }

    var accomId = parseInt($wrapper.data('page-accom-id'), 10) || 0
    if (!accomId) {
      showFormError($form, 'This booking form is not linked to an accommodation.')
      debugLog('[GS HB] missing data-page-accom-id on wrapper', $wrapper.get(0))
      return false
    }

    var checkIn = ($form.find('.hb-check-in-date').val() || '').trim()
    var checkOut = ($form.find('.hb-check-out-date').val() || '').trim()
    var adults = parseInt($form.find('select.hb-adults').val(), 10)
    var children = parseInt($form.find('select.hb-children').val(), 10)

    if (!checkIn || !checkOut) {
      showFormError($form, 'Please select check-in and check-out dates.')
      debugLog('[GS HB] missing dates', { checkIn: checkIn, checkOut: checkOut })
      return false
    }

    if (!Number.isFinite(adults)) adults = 1
    if (!Number.isFinite(children)) children = 0

    var payload = {
      action: 'goldenstay_hb_calc_prices',
      nonce: gsHbBooking.nonce,
      accom_id: accomId,
      check_in: checkIn,
      check_out: checkOut,
      adults: adults,
      children: children,
    }

    debugLog('[GS HB] calc-prices request payload:', payload)

    var $results = $wrapper.find('.gs-hb-search-results')
    if ($results.length) {
      $results.html('<div class="gs-hb-loading">Calculating...</div>')
    }

    setFormLoading($form, true)
    showFormError($form, '')

    $.ajax({
      url: gsHbBooking.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: payload,
      success: function (response) {
        debugLog('[GS HB] calc-prices response:', response)
        setFormLoading($form, false)

        if (response && response.success && response.data && response.data.mark_up !== undefined) {
          if ($results.length) {
            $results.html(response.data.mark_up || '')
          }
          return
        }

        var message =
          (response && response.data && response.data.message) ||
          'Failed to calculate price. Please try again.'
        showFormError($form, message)
      },
      error: function (xhr, status, error) {
        debugLog('[GS HB] calc-prices ajax error:', { status: status, error: error, xhr: xhr })
        setFormLoading($form, false)
        showFormError($form, 'Connection error. Please try again.')
      },
    })

    return false
  }

  // Toggle "price details" block (matches HBook UX)
  $(document).on('click', '.hb-view-price-breakdown', function (e) {
    e.preventDefault()
    var $self = $(this)
    $self.blur()

    var $accom = $self.closest('.hb-accom')
    var $bd = $accom.find('.hb-price-breakdown').first()
    if (!$bd.length) return false

    $bd.stop(true, true).slideToggle(200, function () {
      if ($bd.is(':visible')) {
        $self.find('.hb-price-bd-hide-text').show()
        $self.find('.hb-price-bd-show-text').hide()
      } else {
        $self.find('.hb-price-bd-hide-text').hide()
        $self.find('.hb-price-bd-show-text').show()
      }
    })

    return false
  })

  // Capture-phase submit interceptor:
  // Some themes/plugins include the original HBook booking-form.js which stops bubbling,
  // so delegated submit handlers won't fire. This capture handler ensures we still run.
  if (window.document && document.addEventListener) {
    document.addEventListener(
      'submit',
      function (event) {
        try {
          var form = event.target
          if (!form || !form.classList || !form.classList.contains('hb-booking-search-form')) return

          var wrapper = form.closest ? form.closest('.hbook-wrapper[data-gs-hb-compat="1"]') : null
          if (!wrapper) return

          debugLog('[GS HB] captured submit:', form)
          event.preventDefault()
          event.stopPropagation()
          if (event.stopImmediatePropagation) event.stopImmediatePropagation()

          // Use jQuery pipeline
          handleSearch($(form))
        } catch (e) {
          debugLog('[GS HB] submit capture error:', e)
        }
      },
      true,
    )
  }
})(jQuery)


