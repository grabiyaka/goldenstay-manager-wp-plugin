/**
 * GoldenStay controls on the Accommodation list screen.
 */
(function($) {
  'use strict';

  // In-memory cache for properties list (can be large)
  let _gsPropertiesCache = null;
  let _gsPropertiesLoadedAt = 0;

  function requireConfig() {
    if (!window.goldenStayAccomAdmin || !goldenStayAccomAdmin.ajaxUrl || !goldenStayAccomAdmin.nonce) {
      alert('GoldenStay admin config missing.');
      return false;
    }
    return true;
  }

  function getAccomId($el) {
    const idAttr = $el.data('accom-id');
    if (idAttr) return Number(idAttr);
    const $cell = $el.closest('.gs-accom-cell');
    const fromCell = $cell.data('accom-id');
    return fromCell ? Number(fromCell) : 0;
  }

  function ajaxPost(data) {
    data = data || {};
    data.nonce = goldenStayAccomAdmin.nonce;
    return $.ajax({
      url: goldenStayAccomAdmin.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: data
    });
  }

  function buildModalOnce() {
    if ($('#gs-prop-picker-modal').length) return;

    const html = `
      <div id="gs-prop-picker-modal" class="gs-prop-modal" style="display:none;">
        <div class="gs-prop-modal__overlay"></div>
        <div class="gs-prop-modal__panel">
          <div class="gs-prop-modal__header">
            <strong>Select a GoldenStay property</strong>
            <a href="#" class="gs-prop-modal__close">×</a>
          </div>
          <div class="gs-prop-modal__body">
            <div class="gs-prop-modal__toolbar">
              <input type="text" class="gs-prop-modal__search" placeholder="Search by name or ID…" />
              <label style="display:flex;align-items:center;gap:6px;margin:0;">
                <input type="checkbox" class="gs-prop-modal__override" />
                <span>Override booking</span>
              </label>
              <a class="button button-secondary gs-prop-modal__open-properties" target="_blank" rel="noopener">Open GoldenStay → Properties</a>
              <button type="button" class="button gs-prop-modal__reload">Reload</button>
            </div>
            <div class="gs-prop-modal__status"></div>
            <div class="gs-prop-modal__list"></div>
            <div class="gs-prop-modal__footer">
              <button type="button" class="button gs-prop-modal__load-more" style="display:none;">Load more</button>
            </div>
          </div>
        </div>
      </div>
    `;
    $('body').append(html);

    const $m = $('#gs-prop-picker-modal');
    $m.find('.gs-prop-modal__open-properties').attr('href', goldenStayAccomAdmin.propertiesPageUrl || '#');

    // Basic styles (inline, minimal)
    const css = `
      <style id="gs-prop-picker-modal-css">
        .gs-prop-modal { position: fixed; inset: 0; z-index: 100000; }
        .gs-prop-modal__overlay { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
        .gs-prop-modal__panel { position: absolute; top: 8%; left: 50%; transform: translateX(-50%); width: min(920px, 92vw); max-height: 84vh; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 12px 40px rgba(0,0,0,.25); }
        .gs-prop-modal__header { display:flex; justify-content:space-between; align-items:center; padding: 12px 14px; border-bottom: 1px solid #e5e5e5; }
        .gs-prop-modal__close { text-decoration:none; font-size: 22px; line-height: 22px; padding: 2px 8px; }
        .gs-prop-modal__body { padding: 12px 14px; }
        .gs-prop-modal__toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom: 10px; }
        .gs-prop-modal__search { width: min(420px, 100%); padding: 6px 10px; }
        .gs-prop-modal__status { color:#666; margin: 8px 0; min-height: 18px; }
        .gs-prop-modal__list { border: 1px solid #e5e5e5; border-radius: 4px; overflow: auto; max-height: 56vh; }
        .gs-prop-row { display:flex; justify-content:space-between; gap: 12px; padding: 10px 10px; border-bottom: 1px solid #eee; }
        .gs-prop-row:last-child { border-bottom: none; }
        .gs-prop-row__title { font-weight: 600; }
        .gs-prop-row__meta { color:#666; font-size: 12px; margin-top: 4px; }
        .gs-prop-row__actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .gs-prop-modal__footer { margin-top: 10px; display:flex; justify-content:flex-end; }
      </style>
    `;
    if (!$('#gs-prop-picker-modal-css').length) $('head').append(css);

    function close() { $m.hide(); }
    $m.on('click', '.gs-prop-modal__overlay, .gs-prop-modal__close', function(e) { e.preventDefault(); close(); });
    $(document).on('keydown', function(e){ if ($m.is(':visible') && e.key === 'Escape') close(); });
  }

  function fetchProperties(force) {
    if (_gsPropertiesCache && !force) {
      return $.Deferred().resolve(_gsPropertiesCache).promise();
    }
    const action = (goldenStayAccomAdmin && goldenStayAccomAdmin.propertiesAction) ? goldenStayAccomAdmin.propertiesAction : 'goldenstay_get_properties';
    return ajaxPost({ action: action }).then(function(resp) {
      // Endpoint may proxy array directly OR old wrapped format.
      let props = null;
      if (Array.isArray(resp)) props = resp;
      else if (resp && resp.success && resp.data && Array.isArray(resp.data.properties)) props = resp.data.properties;

      if (!Array.isArray(props)) {
        const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to load properties';
        return $.Deferred().reject(new Error(msg)).promise();
      }
      _gsPropertiesCache = props;
      _gsPropertiesLoadedAt = Date.now();
      return props;
    });
  }

  function openPropertyPicker(opts) {
    buildModalOnce();
    const $m = $('#gs-prop-picker-modal');
    const $list = $m.find('.gs-prop-modal__list');
    const $status = $m.find('.gs-prop-modal__status');
    const $search = $m.find('.gs-prop-modal__search');
    const $override = $m.find('.gs-prop-modal__override');
    const $loadMore = $m.find('.gs-prop-modal__load-more');

    const mode = opts && opts.mode ? opts.mode : 'set'; // 'set' or 'create'
    const accomId = opts && opts.accomId ? Number(opts.accomId) : 0;

    let renderLimit = Number((goldenStayAccomAdmin && goldenStayAccomAdmin.initialRenderLimit) || 200);
    if (!renderLimit || renderLimit < 50) renderLimit = 200;

    function norm(s) { return String(s || '').toLowerCase(); }

    function render(properties, q) {
      q = norm(q).trim();
      let items = properties;
      if (q) {
        items = properties.filter(function(p){
          const id = p && p.id ? String(p.id) : '';
          const name = norm(p && (p.name || p.internal_name));
          return id.indexOf(q) !== -1 || name.indexOf(q) !== -1;
        });
      }

      const total = items.length;
      const shown = Math.min(total, renderLimit);
      const slice = items.slice(0, shown);
      $status.text(total ? (`Showing ${shown} of ${total}`) : 'No matches');

      $list.empty();
      slice.forEach(function(p){
        const pid = p && p.id ? Number(p.id) : 0;
        const title = p && (p.name || p.internal_name) ? (p.name || p.internal_name) : ('Property ' + pid);
        const address = [p && p.address, p && p.city, p && p.country].filter(Boolean).join(', ');
        const meta = [
          pid ? ('ID: ' + pid) : null,
          address ? address : null
        ].filter(Boolean).join(' • ');

        const $row = $('<div class="gs-prop-row"></div>');
        const $left = $('<div></div>');
        $left.append($('<div class="gs-prop-row__title"></div>').text(title));
        if (meta) $left.append($('<div class="gs-prop-row__meta"></div>').text(meta));

        const $actions = $('<div class="gs-prop-row__actions"></div>');
        if (mode === 'set') {
          $actions.append($('<a href="#" class="button button-primary button-small">Link</a>').data('property-id', pid));
        } else {
          $actions.append($('<a href="#" class="button button-primary button-small">Import</a>').data('property-id', pid));
        }

        $row.append($left, $actions);
        $list.append($row);
      });

      if (shown < total) $loadMore.show();
      else $loadMore.hide();
    }

    function load(force) {
      $m.show();
      $status.text('Loading properties…');
      $list.html('<div style="padding:10px;color:#666;">Loading…</div>');
      $loadMore.hide();
      renderLimit = Number((goldenStayAccomAdmin && goldenStayAccomAdmin.initialRenderLimit) || 200);
      // Default OFF each time (safer)
      $override.prop('checked', false);

      fetchProperties(!!force).done(function(props){
        render(props, $search.val());
      }).fail(function(err){
        $list.empty();
        const msg = err && err.message ? err.message : 'Request failed';
        $status.html(
          `<span style="color:#b32d2e;">${msg}</span>`
        );
        $list.html('<div style="padding:10px;color:#666;">Try opening GoldenStay → Properties and ensure you are logged in.</div>');
      });
    }

    // Wire events (re-bind cleanly)
    $m.off('input.gsProp').on('input.gsProp', '.gs-prop-modal__search', function(){
      if (_gsPropertiesCache) render(_gsPropertiesCache, $(this).val());
    });
    $m.off('click.gsPropReload').on('click.gsPropReload', '.gs-prop-modal__reload', function(){
      $search.val('');
      load(true);
    });
    $m.off('click.gsPropLoadMore').on('click.gsPropLoadMore', '.gs-prop-modal__load-more', function(){
      renderLimit += 200;
      if (_gsPropertiesCache) render(_gsPropertiesCache, $search.val());
    });

    $m.off('click.gsPropSelect').on('click.gsPropSelect', '.gs-prop-row__actions a.button', function(e){
      e.preventDefault();
      const propertyId = Number($(this).data('property-id'));
      if (!propertyId) return alert('Invalid property ID');

      if (mode === 'set') {
        if (!accomId) return alert('Accommodation ID not found');
        const override = $override.is(':checked');
        ajaxPost({
          action: 'goldenstay_update_accommodation_mapping',
          accom_id: accomId,
          property_id: propertyId,
          use_goldenstay_booking: override ? 1 : 0
        }).done(function(resp) {
          if (resp && resp.success) location.reload();
          else alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to save mapping');
        }).fail(function(xhr) {
          const preview = xhr && xhr.responseText ? String(xhr.responseText).trim().slice(0, 200) : '';
          alert('Request failed' + (preview ? (': ' + preview) : ''));
        });
      } else {
        ajaxPost({
          action: 'goldenstay_create_accommodation_from_property',
          property_id: propertyId,
          use_goldenstay_booking: $override.is(':checked') ? 1 : 0
        }).done(function(resp) {
          if (resp && resp.success && resp.data && resp.data.editUrl) {
            window.location.href = resp.data.editUrl;
          } else {
            alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to create accommodation');
          }
        }).fail(function(xhr) {
          const preview = xhr && xhr.responseText ? String(xhr.responseText).trim().slice(0, 200) : '';
          alert('Request failed' + (preview ? (': ' + preview) : ''));
        });
      }
    });

    load(false);
  }

  function unlinkMapping(accomId) {
    if (!confirm('Unlink this accommodation from GoldenStay?')) return;
    ajaxPost({
      action: 'goldenstay_unlink_accommodation_property',
      accom_id: accomId
    }).done(function(resp) {
      if (resp && resp.success) {
        location.reload();
      } else {
        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to unlink');
      }
    }).fail(function(xhr) {
      const preview = xhr && xhr.responseText ? String(xhr.responseText).trim().slice(0, 200) : '';
      alert('Request failed' + (preview ? (': ' + preview) : ''));
    });
  }

  $(document).on('click', 'a.gs-accom-set', function(e) {
    e.preventDefault();
    if (!requireConfig()) return;
    const accomId = getAccomId($(this));
    if (!accomId) return alert('Accommodation ID not found');
    openPropertyPicker({ mode: 'set', accomId: accomId });
  });

  $(document).on('click', 'a.gs-accom-unlink', function(e) {
    e.preventDefault();
    if (!requireConfig()) return;
    const accomId = getAccomId($(this));
    if (!accomId) return alert('Accommodation ID not found');
    unlinkMapping(accomId);
  });

  $(document).on('click', 'a.gs-add-from-goldenstay', function(e) {
    e.preventDefault();
    if (!requireConfig()) return;
    openPropertyPicker({ mode: 'create' });
  });
})(jQuery);

