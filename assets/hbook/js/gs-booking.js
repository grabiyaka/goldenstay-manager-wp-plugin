/**
 * GoldenStay - HBook booking form minimal replacement:
 * - AJAX search (availability + calc-prices) via WP backend
 * - price breakdown show/hide toggle
 */
(function ($) {
  'use strict'

  // HBook originally toggles vertical/horizontal form classes in booking-form.js.
  // We don't load that file in the compat mode, so we re-implement the minimal part here
  // to keep Adomus theme booking form layout identical to the original.
  var HB_HORIZONTAL_FORM_MIN_WIDTH = 500
  var HB_DETAILS_FORM_STACK_WIDTH = 400

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

  function schedule(fn, delay) {
    var timer = null
    return function () {
      var scope = this
      var args = arguments
      if (timer) clearTimeout(timer)
      timer = setTimeout(function () {
        timer = null
        fn.apply(scope, args)
      }, delay)
    }
  }

  function resizeForms() {
    try {
      $('.hbook-wrapper[data-gs-hb-compat="1"] .hb-booking-search-form').each(function () {
        var $form = $(this)
        var formId = ($form.attr('id') || '').toString()
        var bodyClass = formId ? 'hb-' + formId + '-is-vertical' : ''
        var w = $form.width() || 0

        if (w < HB_HORIZONTAL_FORM_MIN_WIDTH) {
          $form.addClass('hb-vertical-search-form')
          $form.removeClass('hb-horizontal-search-form')
          if (bodyClass) $('body').addClass(bodyClass)
        } else {
          $form.removeClass('hb-vertical-search-form')
          $form.addClass('hb-horizontal-search-form')
          if (bodyClass) $('body').removeClass(bodyClass)
        }

        if (w < 400) $form.addClass('hb-narrow-search-form')
        else $form.removeClass('hb-narrow-search-form')
      })

      $('.hbook-wrapper[data-gs-hb-compat="1"] .hb-booking-details-form').each(function () {
        var $form = $(this)
        var w = $form.width() || 0
        if (w < HB_DETAILS_FORM_STACK_WIDTH) $form.addClass('hb-details-form-stacked')
        else $form.removeClass('hb-details-form-stacked')
      })
    } catch (e) {
      debugLog('[GS HB] resizeForms error:', e)
    }
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

  function showDetailsError($detailsForm, message) {
    if (!$detailsForm || !$detailsForm.length) {
      if (message) window.alert(message)
      return
    }

    var $error = $detailsForm.find('.hb-confirm-error').first()
    if ($error.length) {
      var text = (message || '').toString()
      if (!text) {
        $error.text('')
        $error.hide()
        return
      }
      $error.text(text)
      $error.show()
      return
    }

    if (message) window.alert(message)
  }

  function showPoliciesError($detailsForm, message) {
    if (!$detailsForm || !$detailsForm.length) {
      if (message) window.alert(message)
      return
    }

    var $error = $detailsForm.find('.hb-policies-error').first()
    if ($error.length) {
      var text = (message || '').toString()
      if (!text) {
        $error.text('')
        $error.hide()
        return
      }
      $error.text(text)
      $error.show()
      return
    }

    if (message) window.alert(message)
  }

  function clearDetailsErrors($detailsForm) {
    if (!$detailsForm || !$detailsForm.length) return
    showDetailsError($detailsForm, '')
    showPoliciesError($detailsForm, '')
  }

  function handleBookNow($wrapper) {
    if (!$wrapper || !$wrapper.length) return

    if (!window.gsHbBooking || !gsHbBooking.ajaxUrl || !gsHbBooking.nonce) {
      debugLog('[GS HB] book now: gsHbBooking config missing', window.gsHbBooking)
      window.alert('Booking configuration is missing. Please reload the page.')
      return
    }

    if ($wrapper.data('gsHbBookNowInProgress')) {
      return
    }

    var $detailsForm = $wrapper.find('form.gs-hb-details-step').first()
    if (!$detailsForm.length) return

    clearDetailsErrors($detailsForm)

    var accomId = parseInt($detailsForm.find('.hb-details-accom-ids').val(), 10) || 0
    var checkIn = ($detailsForm.find('.hb-details-check-in').val() || '').trim()
    var checkOut = ($detailsForm.find('.hb-details-check-out').val() || '').trim()
    var adults = parseInt($detailsForm.find('.hb-details-adults').val(), 10)
    var children = parseInt($detailsForm.find('.hb-details-children').val(), 10)

    if (!Number.isFinite(adults)) adults = 1
    if (!Number.isFinite(children)) children = 0

    var firstName = ($detailsForm.find('input[name="hb_first_name"]').val() || '').trim()
    var lastName = ($detailsForm.find('input[name="hb_last_name"]').val() || '').trim()
    var email = ($detailsForm.find('input[name="hb_email"]').val() || '').trim()
    var phone = ($detailsForm.find('input[name="hb_phone"]').val() || '').trim()
    var address1 = ($detailsForm.find('input[name="hb_address_1"]').val() || '').trim()
    var address2 = ($detailsForm.find('input[name="hb_address_2"]').val() || '').trim()
    var city = ($detailsForm.find('input[name="hb_city"]').val() || '').trim()
    var state = ($detailsForm.find('input[name="hb_state_province"]').val() || '').trim()
    var countryIso = ($detailsForm.find('select[name="hb_country_iso"]').val() || '').trim()
    var zipCode = ($detailsForm.find('input[name="hb_zip_code"]').val() || '').trim()

    var termsAccepted = $detailsForm.find('input[name="hb_terms_and_cond"]').is(':checked')
    var privacyAccepted = $detailsForm.find('input[name="hb_privacy_policy"]').is(':checked')

    if (!accomId) {
      showDetailsError($detailsForm, 'Accommodation is missing. Please reload the page.')
      return
    }
    if (!checkIn || !checkOut) {
      showDetailsError($detailsForm, 'Please select check-in and check-out dates.')
      return
    }
    if (!firstName || !lastName) {
      showDetailsError($detailsForm, 'Please enter your first and last name.')
      return
    }
    if (!email) {
      showDetailsError($detailsForm, 'Please enter your email address.')
      return
    }
    if (!termsAccepted || !privacyAccepted) {
      showPoliciesError($detailsForm, 'Please accept the terms and privacy policy.')
      return
    }

    var feeIds = ($detailsForm.find('.gs-hb-selected-fees').val() || '').trim()
    var $summary = $detailsForm.find('.gs-hb-summary-wrapper').first()
    var baseFeeIds = $summary.length ? String($summary.attr('data-base-fee-ids') || '') : ''
    var toggleFeeIds = $summary.length ? String($summary.attr('data-toggle-fee-ids') || '') : ''

    var payload = {
      action: 'goldenstay_hb_book_now',
      nonce: gsHbBooking.nonce,
      accom_id: accomId,
      check_in: checkIn,
      check_out: checkOut,
      adults: adults,
      children: children,
      fee_ids: feeIds,
      base_fee_ids: baseFeeIds,
      toggle_fee_ids: toggleFeeIds,

      first_name: firstName,
      last_name: lastName,
      email: email,
      phone: phone,
      address_1: address1,
      address_2: address2,
      city: city,
      state: state,
      country_iso: countryIso,
      zip_code: zipCode,
      terms: termsAccepted ? 1 : 0,
      privacy: privacyAccepted ? 1 : 0,
    }

    debugLog('[GS HB] book now payload:', payload)

    setFormLoading($detailsForm, true)
    $wrapper.data('gsHbBookNowInProgress', true)

    $.ajax({
      url: gsHbBooking.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: payload,
      success: function (response) {
        debugLog('[GS HB] book now response:', response)
        $wrapper.data('gsHbBookNowInProgress', false)
        setFormLoading($detailsForm, false)

        if (response && response.success && response.data && response.data.payment_url) {
          window.location.href = String(response.data.payment_url)
          return
        }

        var message =
          (response && response.data && response.data.message) ||
          'Booking failed. Please try again.'
        showDetailsError($detailsForm, message)
      },
      error: function (xhr, status, error) {
        debugLog('[GS HB] book now ajax error:', { status: status, error: error, xhr: xhr })
        $wrapper.data('gsHbBookNowInProgress', false)
        setFormLoading($detailsForm, false)
        showDetailsError($detailsForm, 'Connection error. Please try again.')
      },
    })
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
      $results.removeClass('gs-hb-results-ready')
      $results.html('<div class="gs-hb-loading">Zoeken ...</div>')
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
            // HBook CSS hides .hb-accom-list by default; show it for our injected result.
            $results.find('.hb-accom-list').show()
          }

          // The injected markup contains new step wrappers/forms; ensure responsive classes are correct.
          resizeForms()
          if ($results.length) {
            setTimeout(function () {
              $results.addClass('gs-hb-results-ready')
            }, 10)
          }

          // Mimic HBook UX: collapse form to summary + show "change search" button
          var $summary = $form.find('.hb-searched-summary')
          var $fields = $form.find('.hb-search-fields-and-submit')
          if ($summary.length && $fields.length) {
            $summary.find('.hb-chosen-check-in-date span').text(checkIn)
            $summary.find('.hb-chosen-check-out-date span').text(checkOut)
            $summary.find('.hb-chosen-adults span').text(String(adults))
            $summary.find('.hb-chosen-children span').text(String(children))
            $fields.hide()
            $summary.show()
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

  function formatNumberNl(amount) {
    var num = parseFloat(amount)
    if (!Number.isFinite(num)) num = 0
    var parts = num.toFixed(2).split('.')
    // thousands separator "." and decimal ","
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.')
    return parts[0] + ',' + parts[1]
  }

  function updateOptionsTotalPrice($wrapper) {
    if (!$wrapper || !$wrapper.length) return

    var sum = 0
    $wrapper.find('.gs-hb-fee-checkbox:checked').each(function () {
      var price = parseFloat($(this).data('price'))
      if (Number.isFinite(price)) sum += price
    })

    var $total = $wrapper.find('.hb-options-total-price').first()
    if (!$total.length) return

    if (Math.abs(sum) < 0.005) {
      $total.hide()
      return
    }

    var isNegative = sum < 0
    var abs = Math.abs(sum)
    $total.find('.hb-price-placeholder-minus').css('display', isNegative ? 'inline' : 'none')
    $total.find('.hb-price-placeholder').html(formatNumberNl(abs))
    $total.show()
  }

  function getSelectedFeeIds($wrapper) {
    var ids = []
    if (!$wrapper || !$wrapper.length) return ids
    $wrapper.find('.gs-hb-fee-checkbox:checked').each(function () {
      var id = parseInt($(this).data('fee-id'), 10)
      if (Number.isFinite(id) && id > 0) ids.push(id)
    })
    return ids
  }

  function setSelectedFeesHidden($wrapper, ids) {
    var $hidden = $wrapper.find('.gs-hb-selected-fees')
    if (!$hidden.length) return
    $hidden.val(Array.isArray(ids) ? ids.join(',') : '')
  }

  function readFeeMeta($wrapper) {
    if (!$wrapper || !$wrapper.length) return {}

    var raw =
      String($wrapper.find('.gs-hb-fees-form').attr('data-fee-meta') || '') ||
      String($wrapper.find('.gs-hb-summary-wrapper').attr('data-fee-meta') || '') ||
      '{}'

    try {
      var parsed = JSON.parse(raw)
      return parsed && typeof parsed === 'object' ? parsed : {}
    } catch (_) {
      return {}
    }
  }

  function updateDetailsSummary($wrapper, recalcData) {
    if (!$wrapper || !$wrapper.length) return

    var $details = $wrapper.find('.gs-hb-details-step').first()
    if (!$details.length) return

    var $summary = $details.find('.gs-hb-summary-wrapper').first()
    if (!$summary.length) return

    // Update selection info from data-* (set by PHP)
    var checkIn = String($summary.attr('data-check-in') || '')
    var checkOut = String($summary.attr('data-check-out') || '')
    var nights = parseInt($summary.attr('data-nights'), 10)
    var adults = parseInt($summary.attr('data-adults'), 10)
    var accomName = String($summary.attr('data-accom-name') || '')

    if (checkIn) $details.find('.gs-hb-summary-check-in').text(checkIn)
    if (checkOut) $details.find('.gs-hb-summary-check-out').text(checkOut)
    if (Number.isFinite(nights)) $details.find('.gs-hb-summary-nights').text(String(nights))
    if (Number.isFinite(adults)) $details.find('.gs-hb-summary-adults').text(String(adults))
    if (accomName) $details.find('.gs-hb-summary-accom').text(accomName)

    var data = recalcData || $wrapper.data('gsHbLastRecalc') || null
    if (!data) return

    if (data.total_formatted) {
      $details.find('.gs-hb-summary-total-value').text(String(data.total_formatted))
      $summary.attr('data-current-total', String(data.total))
    }

    var feeMeta = readFeeMeta($wrapper)
    var feeAmounts = data.fee_amounts || {}

    var items = []
    Object.keys(feeAmounts).forEach(function (feeIdStr) {
      var feeId = parseInt(feeIdStr, 10)
      if (!Number.isFinite(feeId) || feeId <= 0) return

      var entry = feeAmounts[feeIdStr]
      if (!entry) return

      var meta = feeMeta[String(feeId)] || feeMeta[feeId] || {}
      var name = meta && meta.name ? String(meta.name) : ''
      var order = meta && meta.order !== undefined ? parseInt(meta.order, 10) : 999999

      items.push({
        id: feeId,
        name: name || 'Fee',
        order: Number.isFinite(order) ? order : 999999,
        formatted: entry.formatted ? String(entry.formatted) : null,
      })
    })

    items.sort(function (a, b) {
      if (a.order !== b.order) return a.order - b.order
      return a.id - b.id
    })

    var $feesBox = $details.find('.gs-hb-summary-fees').first()
    if ($feesBox.length) {
      $feesBox.empty()
      items.forEach(function (it) {
        var text = it.name + ': ' + (it.formatted || '')
        $('<div>', { class: 'gs-hb-summary-line' }).text(text).appendTo($feesBox)
      })
    }
  }

  function recalcPricesWithFees($wrapper, selectedFeeIds) {
    if (!$wrapper || !$wrapper.length) return

    var $feesForm = $wrapper.find('.gs-hb-fees-form').first()
    if (!$feesForm.length) return

    if (!window.gsHbBooking || !gsHbBooking.ajaxUrl || !gsHbBooking.nonce) {
      debugLog('[GS HB] recalc-prices: gsHbBooking config missing', window.gsHbBooking)
      return
    }

    var accomId = parseInt($feesForm.data('accom-id'), 10) || 0
    var checkIn = String($feesForm.data('check-in') || '')
    var checkOut = String($feesForm.data('check-out') || '')
    var adults = parseInt($feesForm.data('adults'), 10)
    var children = parseInt($feesForm.data('children'), 10)
    var baseFeeIds = String($feesForm.attr('data-base-fee-ids') || '')
    var toggleFeeIds = String($feesForm.attr('data-toggle-fee-ids') || '')

    if (!Number.isFinite(adults)) adults = 1
    if (!Number.isFinite(children)) children = 0

    var payload = {
      action: 'goldenstay_hb_recalc_prices',
      nonce: gsHbBooking.nonce,
      accom_id: accomId,
      check_in: checkIn,
      check_out: checkOut,
      adults: adults,
      children: children,
      fee_ids: Array.isArray(selectedFeeIds) ? selectedFeeIds.join(',') : '',
      base_fee_ids: baseFeeIds,
      toggle_fee_ids: toggleFeeIds,
    }

    // Avoid double submits when user toggles quickly
    $feesForm.find('input, button').prop('disabled', true)

    $.ajax({
      url: gsHbBooking.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: payload,
      success: function (response) {
        debugLog('[GS HB] recalc-prices response:', response)
        $feesForm.find('input, button').prop('disabled', false)

        if (!response || !response.success || !response.data) {
          var message =
            (response && response.data && response.data.message) ||
            'Failed to recalculate price. Please try again.'
          var $searchForm = $wrapper.find('form.hb-booking-search-form').first()
          if ($searchForm.length) showFormError($searchForm, message)
          return
        }

        // Update total (step 2)
        if (response.data.total_formatted) {
          $wrapper.find('.gs-hb-total-price-value').text(String(response.data.total_formatted))
          // Also update the main price in step 1, so it stays consistent when going back.
          $wrapper.find('.hb-accom-price').first().text(String(response.data.total_formatted))

          // Track numeric total for later steps
          if (response.data.total !== undefined) {
            $feesForm.attr('data-current-total', String(response.data.total))
          }
        }

        // Update amounts for fees returned by API
        var feeAmounts = response.data.fee_amounts || {}
        Object.keys(feeAmounts).forEach(function (feeIdStr) {
          var feeId = parseInt(feeIdStr, 10)
          if (!Number.isFinite(feeId) || feeId <= 0) return

          var entry = feeAmounts[feeIdStr]
          if (!entry) return

          var amount = parseFloat(entry.amount)
          var formatted = entry.formatted ? String(entry.formatted) : null

          var $checkbox = $wrapper.find('.gs-hb-fee-checkbox[data-fee-id="' + feeId + '"]').first()
          if (!$checkbox.length) return

          if (Number.isFinite(amount)) {
            // keep data-price in sync for subtotal calc
            $checkbox.data('price', amount)
            $checkbox.attr('data-price', String(amount.toFixed(2)))
          }

          if (formatted) {
            var $price = $checkbox.closest('.gs-hb-fee-option').find('.gs-hb-fee-price').first()
            if ($price.length) $price.text('(' + formatted + ')')
          }
        })

        updateOptionsTotalPrice($wrapper)

        // Keep details-step summary in sync
        $wrapper.data('gsHbLastRecalc', response.data)
        updateDetailsSummary($wrapper, response.data)
      },
      error: function (xhr, status, error) {
        debugLog('[GS HB] recalc-prices ajax error:', { status: status, error: error, xhr: xhr })
        $feesForm.find('input, button').prop('disabled', false)
      },
    })
  }

  function showFeesStep($wrapper) {
    if (!$wrapper || !$wrapper.length) return false

    var $step1 = $wrapper.find('.hb-accom-step-wrapper').first()
    var $step2 = $wrapper.find('.hb-intermediate-step-wrapper.gs-hb-fees-step').first()
    var $step3 = $wrapper.find('.gs-hb-details-step').first()
    if (!$step2.length) return false

    // Original HBook JS may hide .hb-options-form / .hb-option; force-show our content.
    $step2.find('.gs-hb-fees-form').show()
    $step2.find('.gs-hb-fee-option').show()

    if ($step3.length) $step3.hide()
    $step1.slideUp(200, function () {
      $step2.slideDown(200)
    })

    var selectedFeeIds = getSelectedFeeIds($wrapper)
    setSelectedFeesHidden($wrapper, selectedFeeIds)
    updateOptionsTotalPrice($wrapper)
    recalcPricesWithFees($wrapper, selectedFeeIds)

    return true
  }

  function showPriceStep($wrapper) {
    if (!$wrapper || !$wrapper.length) return false

    var $step1 = $wrapper.find('.hb-accom-step-wrapper').first()
    var $step2 = $wrapper.find('.hb-intermediate-step-wrapper.gs-hb-fees-step').first()
    var $step3 = $wrapper.find('.gs-hb-details-step').first()
    if (!$step1.length) return false

    if ($step3.length) $step3.hide()
    if ($step2.length) {
      $step2.slideUp(200, function () {
        $step1.slideDown(200)
      })
    } else {
      $step1.slideDown(200)
    }

    return true
  }

  function showDetailsStep($wrapper) {
    if (!$wrapper || !$wrapper.length) return false

    var $step1 = $wrapper.find('.hb-accom-step-wrapper').first()
    var $step2 = $wrapper.find('.hb-intermediate-step-wrapper.gs-hb-fees-step').first()
    var $step3 = $wrapper.find('.gs-hb-details-step').first()
    if (!$step3.length) return false

    if ($step1.length) $step1.hide()
    if ($step2.length) $step2.hide()

    $step3.slideDown(200)

    var selectedFeeIds = getSelectedFeeIds($wrapper)
    setSelectedFeesHidden($wrapper, selectedFeeIds)
    updateDetailsSummary($wrapper)
    return true
  }

  // Step 1 -> fees step
  $(document).on(
    'click',
    '.hbook-wrapper[data-gs-hb-compat="1"] .hb-next-step-1 input',
    function (e) {
      e.preventDefault()
      e.stopPropagation()
      if (e.stopImmediatePropagation) e.stopImmediatePropagation()

      var $wrapper = $(this).closest('.hbook-wrapper[data-gs-hb-compat="1"]')
      if (!showFeesStep($wrapper)) {
        showDetailsStep($wrapper)
      }
      return false
    },
  )

  // Fees step -> back to step 1
  $(document).on(
    'click',
    '.hbook-wrapper[data-gs-hb-compat="1"] .hb-previous-step-1 input',
    function (e) {
      e.preventDefault()
      e.stopPropagation()
      if (e.stopImmediatePropagation) e.stopImmediatePropagation()

      var $wrapper = $(this).closest('.hbook-wrapper[data-gs-hb-compat="1"]')
      showPriceStep($wrapper)
      return false
    },
  )

  // Recalculate when fee selection changes (debounced)
  $(document).on(
    'change',
    '.hbook-wrapper[data-gs-hb-compat="1"] .gs-hb-fee-checkbox',
    function () {
      var $wrapper = $(this).closest('.hbook-wrapper[data-gs-hb-compat="1"]')
      var selectedFeeIds = getSelectedFeeIds($wrapper)
      setSelectedFeesHidden($wrapper, selectedFeeIds)
      updateOptionsTotalPrice($wrapper)

      var prevTimer = $wrapper.data('gsHbFeesRecalcTimer')
      if (prevTimer) clearTimeout(prevTimer)
      var timer = setTimeout(function () {
        recalcPricesWithFees($wrapper, selectedFeeIds)
      }, 250)
      $wrapper.data('gsHbFeesRecalcTimer', timer)
    },
  )

  // Let integrators hook the moment user confirms fees (step 2 "NEXT").
  $(document).on(
    'click',
    '.hbook-wrapper[data-gs-hb-compat="1"] .hb-next-step-2 input',
    function (e) {
      e.preventDefault()
      e.stopPropagation()
      if (e.stopImmediatePropagation) e.stopImmediatePropagation()

      var $wrapper = $(this).closest('.hbook-wrapper[data-gs-hb-compat="1"]')
      var selectedFeeIds = getSelectedFeeIds($wrapper)
      setSelectedFeesHidden($wrapper, selectedFeeIds)

      // Custom event for the next step implementation (booking details / reservation creation).
      try {
        $wrapper.trigger('gsHbFeesConfirmed', { feeIds: selectedFeeIds })
      } catch (_) {
        // ignore
      }

      showDetailsStep($wrapper)
      return false
    },
  )

  // Details step -> back
  $(document).on(
    'click',
    '.hbook-wrapper[data-gs-hb-compat="1"] .hb-previous-step-2 input',
    function (e) {
      e.preventDefault()
      e.stopPropagation()
      if (e.stopImmediatePropagation) e.stopImmediatePropagation()

      var $wrapper = $(this).closest('.hbook-wrapper[data-gs-hb-compat="1"]')
      if (!showFeesStep($wrapper)) {
        showPriceStep($wrapper)
      }

      return false
    },
  )

  // Book now (no-op)
  $(document).on(
    'click',
    '.hbook-wrapper[data-gs-hb-compat="1"] .gs-hb-book-now',
    function (e) {
      e.preventDefault()
      e.stopPropagation()
      if (e.stopImmediatePropagation) e.stopImmediatePropagation()
      var $wrapper = $(this).closest('.hbook-wrapper[data-gs-hb-compat="1"]')
      handleBookNow($wrapper)
      return false
    },
  )

  // "Change search" button: expand form again and clear results (matches original flow)
  $(document).on(
    'click',
    '.hbook-wrapper[data-gs-hb-compat="1"] .hb-change-search-wrapper input[type="submit"]',
    function (e) {
      e.preventDefault()
      e.stopPropagation()
      if (e.stopImmediatePropagation) e.stopImmediatePropagation()

      var $form = $(this).closest('form.hb-booking-search-form')
      var $wrapper = $form.closest('.hbook-wrapper[data-gs-hb-compat="1"]')

      debugLog('[GS HB] change search clicked')

      var $summary = $form.find('.hb-searched-summary')
      var $fields = $form.find('.hb-search-fields-and-submit')
      if ($summary.length) $summary.hide()
      if ($fields.length) $fields.show()

      var $results = $wrapper.find('.gs-hb-search-results')
      if ($results.length) {
        $results.removeClass('gs-hb-results-ready')
        $results.empty()
      }

      showFormError($form, '')

      return false
    },
  )

  // Ensure initial page load does not show any result blocks.
  $(function () {
    $('.hbook-wrapper[data-gs-hb-compat="1"]').each(function () {
      var $wrapper = $(this)
      $wrapper.find('.gs-hb-search-results').empty()
      var $form = $wrapper.find('form.hb-booking-search-form')
      if ($form.length) {
        $form.find('.hb-searched-summary').hide()
        // Keep fields visible by default
        $form.find('.hb-search-fields-and-submit').show()
        showFormError($form, '')
      }
    })

    // Restore original HBook responsive behaviour (vertical form in narrow containers).
    resizeForms()
    $(window).on('resize', schedule(resizeForms, 150))
  })

  // Capture-phase click interceptor:
  // Some themes/plugins include the original HBook booking-form.js which runs on `.hb-next-step-1`
  // and hides `.hb-options-form` / `.hb-option`. We intercept early and show our fees step.
  if (window.document && document.addEventListener) {
    document.addEventListener(
      'click',
      function (event) {
        try {
          var target = event.target
          if (!target || !target.closest) return

          // We only care about clicks within our compat wrappers.
          var wrapper = target.closest('.hbook-wrapper[data-gs-hb-compat="1"]')
          if (!wrapper) return

          // Only intercept when our fees step exists.
          if (!wrapper.querySelector || !wrapper.querySelector('.hb-intermediate-step-wrapper.gs-hb-fees-step')) {
            return
          }

          // Intercept step-1 NEXT clicks before HBook JS sees them.
          if (target.matches && target.matches('.hb-next-step-1 input')) {
            event.preventDefault()
            event.stopPropagation()
            if (event.stopImmediatePropagation) event.stopImmediatePropagation()

            if (!showFeesStep($(wrapper))) {
              showDetailsStep($(wrapper))
            }
          }

          // Intercept step-2 NEXT clicks before HBook JS sees them.
          if (target.matches && target.matches('.hb-next-step-2 input')) {
            event.preventDefault()
            event.stopPropagation()
            if (event.stopImmediatePropagation) event.stopImmediatePropagation()

            var $w = $(wrapper)
            var selectedFeeIds = getSelectedFeeIds($w)
            setSelectedFeesHidden($w, selectedFeeIds)
            try {
              $w.trigger('gsHbFeesConfirmed', { feeIds: selectedFeeIds })
            } catch (_) {
              // ignore
            }
            showDetailsStep($w)
          }

          // Intercept details PREVIOUS clicks before HBook JS sees them.
          if (target.matches && target.matches('.hb-previous-step-2 input')) {
            event.preventDefault()
            event.stopPropagation()
            if (event.stopImmediatePropagation) event.stopImmediatePropagation()

            if (!showFeesStep($(wrapper))) {
              showPriceStep($(wrapper))
            }
          }

          // Intercept Book Now clicks (no-op)
          if (target.matches && target.matches('.gs-hb-book-now')) {
            event.preventDefault()
            event.stopPropagation()
            if (event.stopImmediatePropagation) event.stopImmediatePropagation()
            handleBookNow($(wrapper))
          }
        } catch (e) {
          debugLog('[GS HB] click capture error:', e)
        }
      },
      true,
    )
  }

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


