{{-- Pure content fragment: Blueprint injects this inside its own admin wrapper.
     Do NOT add @extends / @section here (it causes a double render). --}}
{{-- All variables come from the controller (index()) — the view never queries the DB itself. --}}

<style>
    .es-th { position: relative; white-space: nowrap; }
    .es-sort-btn, .es-filter-btn { text-decoration: none; margin-left: 4px; }
    .es-filter-wrap { position: relative; display: inline-block; }
    .es-filter-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        z-index: 1000;
        background: #fff;
        color: #333;
        border: 1px solid #ccc;
        border-radius: 4px;
        padding: 8px;
        width: 220px;
        box-shadow: 0 2px 8px rgba(0,0,0,.2);
        font-weight: 400;
        white-space: normal;
    }
    .es-filter-wrap.open .es-filter-menu { display: block; }
    .es-filter-options { max-height: 180px; overflow: auto; margin: 6px 0; }
    .es-filter-opt { display: block; font-weight: 400; margin: 0; padding: 2px 0; }
    .es-filter-actions a { cursor: pointer; }
</style>

<div class="row">
    <div class="col-xs-12">
        <div class="box box-warning">
            <div class="box-body">
                <p style="margin:0">
                    Egg IDs <strong>differ</strong> from one panel to another: always pick the egg from
                    the list below instead of copying an ID noted elsewhere.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Add or update an allowed egg</h3></div>
            <div class="box-body">
                <input type="hidden" id="es-edit-id" value="">
                <div class="form-group">
                    <label>Egg</label>
                    <select id="es-egg-id" class="form-control">
                        <option value="">— select an egg —</option>
                        @foreach ($eggsByNest as $nestName => $eggsInNest)
                            <optgroup label="{{ $nestName }}">
                                @foreach ($eggsInNest as $e)
                                    <option value="{{ $e->id }}" data-name="{{ $e->name }}" data-description="{{ $e->description }}">
                                        #{{ $e->id }} — {{ $e->name }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="text-muted small" style="margin-top:4px">Picking an egg fills in the label and description below; you can edit them afterwards.</p>
                </div>
                <div class="row">
                    <div class="col-md-12 form-group">
                        <label>Label</label>
                        <input type="text" id="es-label" class="form-control" placeholder="Paper">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="es-description" class="form-control" placeholder="High-performance Spigot fork.">
                </div>
                <div class="form-group">
                    <label style="font-weight:400;margin-right:16px">
                        <input type="checkbox" id="es-self-service"> Self-service (server page)
                    </label>
                    <label style="font-weight:400">
                        <input type="checkbox" id="es-for-addon"> For other addons
                    </label>
                </div>
                <button id="es-save" class="btn btn-success btn-sm">Save</button>
                <button id="es-reset" class="btn btn-default btn-sm">Clear form</button>
                <span id="es-msg" style="margin-left:12px"></span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Allowed eggs ({{ $entries->count() }})</h3></div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover" id="es-table">
                    <thead>
                    <tr>
                        @foreach ([[0, 'num', 'Egg ID'], [1, 'text', 'Real name (egg)'], [2, 'text', 'Nest'], [3, 'text', 'Label (display name)'], [4, 'text', 'Description'], [5, 'text', 'Self-service'], [6, 'text', 'For other addons']] as [$col, $type, $label])
                            <th class="es-th" data-col="{{ $col }}" data-type="{{ $type }}">
                                {{ $label }}
                                <a href="#" class="es-sort-btn" title="Sort">⇅</a>
                                <span class="es-filter-wrap" data-col="{{ $col }}">
                                    <a href="#" class="es-filter-btn" title="Filter">▾</a>
                                    <div class="es-filter-menu">
                                        <input type="text" class="form-control input-sm es-filter-search" placeholder="Search…">
                                        <div class="es-filter-actions"><a href="#" class="es-filter-all">All</a> · <a href="#" class="es-filter-none">None</a></div>
                                        <div class="es-filter-options"></div>
                                        <button type="button" class="btn btn-primary btn-xs es-filter-apply" style="margin-top:6px;width:100%">Apply</button>
                                    </div>
                                </span>
                            </th>
                        @endforeach
                        <th style="width:140px"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($entries as $entry)
                        <tr data-entry-row="{{ $entry->id }}">
                            <td>{{ $entry->egg_id }}</td>
                            <td>{{ $eggNames[$entry->egg_id] ?? '— egg not found on this panel —' }}</td>
                            <td>{{ $eggNests[$entry->egg_id] ?? '—' }}</td>
                            <td>{{ $entry->label }}</td>
                            <td>{{ $entry->description }}</td>
                            <td>{{ $entry->self_service ? 'yes' : 'no' }}</td>
                            <td>{{ $entry->for_addon ? 'yes' : 'no' }}</td>
                            <td>
                                <button class="btn btn-primary btn-xs es-edit"
                                    data-id="{{ $entry->id }}"
                                    data-egg-id="{{ $entry->egg_id }}"
                                    data-label="{{ $entry->label }}"
                                    data-description="{{ $entry->description }}"
                                    data-self-service="{{ $entry->self_service ? 1 : 0 }}"
                                    data-for-addon="{{ $entry->for_addon ? 1 : 0 }}">Edit</button>
                                <button class="btn btn-danger btn-xs es-delete" data-id="{{ $entry->id }}" data-label="{{ $entry->label }}">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No allowed eggs yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.jQuery === 'undefined') { console.error('[eggswitch] jQuery not available'); return; }
    var $ = window.jQuery;
    var ROOT = @json($root);
    var TOKEN = $('meta[name="_token"]').attr('content');

    function req(method, url, data) {
        return $.ajax({
            method: method, url: url, data: data,
            headers: { 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
        });
    }
    function msg(t, ok) {
        $('#es-msg').text(t).css('color', ok ? '#00a65a' : '#dd4b39');
    }
    function resetForm() {
        $('#es-edit-id').val('');
        $('#es-egg-id').val('').prop('disabled', false);
        $('#es-label').val('');
        $('#es-description').val('');
        $('#es-self-service').prop('checked', false);
        $('#es-for-addon').prop('checked', false);
    }

    $(document).on('click', '#es-reset', resetForm);

    $(document).on('change', '#es-egg-id', function () {
        var opt = $(this).find('option:selected');
        if (!opt.val()) return;
        // attr(), not data(): data() converts values ("123" becomes a number, "null" null).
        $('#es-label').val(opt.attr('data-name'));
        $('#es-description').val(opt.attr('data-description') || '');
    });

    $(document).on('click', '.es-edit', function () {
        var $btn = $(this);
        $('#es-edit-id').val($btn.attr('data-id'));
        $('#es-egg-id').val($btn.attr('data-egg-id')).prop('disabled', true); // egg_id is the identity key, not editable once created
        $('#es-label').val($btn.attr('data-label'));
        $('#es-description').val($btn.attr('data-description'));
        $('#es-self-service').prop('checked', $btn.attr('data-self-service') === '1');
        $('#es-for-addon').prop('checked', $btn.attr('data-for-addon') === '1');
        $('html, body').animate({ scrollTop: 0 }, 200);
    });

    $(document).on('click', '#es-save', function () {
        var editId = $('#es-edit-id').val();
        var payload = {
            egg_id: $('#es-egg-id').val(),
            label: $('#es-label').val(),
            description: $('#es-description').val(),
            // 1/0, not true/false: jQuery serializes a JS boolean as the literal
            // string "true"/"false", which Laravel's `boolean` rule rejects
            // (it only accepts true, false, 0, 1, '0', '1' via strict in_array).
            self_service: $('#es-self-service').is(':checked') ? 1 : 0,
            for_addon: $('#es-for-addon').is(':checked') ? 1 : 0,
        };
        var call = editId
            ? req('PATCH', ROOT, Object.assign({ allowed_egg_id: editId }, payload))
            : req('POST', ROOT, payload);
        call.done(function () { location.reload(); })
            .fail(function (x) { msg((x.responseJSON && (x.responseJSON.error || (x.responseJSON.errors && x.responseJSON.errors[0] && x.responseJSON.errors[0].detail))) || 'Save failed.', false); });
    });

    $(document).on('click', '.es-delete', function () {
        var id = $(this).attr('data-id');
        if (!confirm('Delete the allowed egg "' + $(this).attr('data-label') + '"?')) return;
        req('DELETE', ROOT + '/egg/' + id)
            .done(function () { location.reload(); })
            .fail(function () { msg('Delete failed.', false); });
    });

    // --- Column sorting + Excel-style filters (100% client-side, the table is already
    // fully loaded at once, no server round trip needed) ---
    var $table = $('#es-table');
    var $tbody = $table.find('tbody');
    var sortState = { col: null, dir: 1 };
    var activeFilters = {}; // { col: Set(allowed values) } — absent = no filter on this column
    var columnValues = {}; // { col: [sorted distinct values] }, computed once at load

    function cellText($tr, col) {
        return $tr.children('td').eq(col).text().trim();
    }

    function computeColumnValues() {
        $table.find('.es-th').each(function () {
            var col = parseInt($(this).data('col'), 10);
            var seen = {};
            $tbody.find('tr[data-entry-row]').each(function () {
                seen[cellText($(this), col)] = true;
            });
            columnValues[col] = Object.keys(seen).sort(function (a, b) {
                return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
            });
        });
    }
    computeColumnValues();

    function applyFilters() {
        var anyVisible = false;
        $tbody.find('tr[data-entry-row]').each(function () {
            var $tr = $(this);
            var visible = true;
            Object.keys(activeFilters).forEach(function (col) {
                if (!visible) return;
                if (!activeFilters[col].has(cellText($tr, col))) visible = false;
            });
            $tr.toggle(visible);
            if (visible) anyVisible = true;
        });
        $tbody.find('.es-no-match-row').remove();
        if (!anyVisible && $tbody.find('tr[data-entry-row]').length) {
            $tbody.append('<tr class="es-no-match-row"><td colspan="8" class="text-center text-muted">No entries match the active filters.</td></tr>');
        }
    }

    function updateFilterIcon($wrap, active) {
        $wrap.find('.es-filter-btn').css({ color: active ? '#00a65a' : '', fontWeight: active ? '700' : '400' });
    }

    // Sorting — click a column, click again to reverse the direction.
    $(document).on('click', '.es-sort-btn', function (e) {
        e.preventDefault();
        var $th = $(this).closest('th');
        var col = parseInt($th.data('col'), 10);
        var type = $th.data('type');

        sortState.dir = (sortState.col === col) ? sortState.dir * -1 : 1;
        sortState.col = col;

        var rows = $tbody.find('tr[data-entry-row]').get();
        rows.sort(function (a, b) {
            var av = cellText($(a), col), bv = cellText($(b), col);
            var cmp = type === 'num'
                ? (parseFloat(av) || 0) - (parseFloat(bv) || 0)
                : av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
            return cmp * sortState.dir;
        });
        rows.forEach(function (row) { $tbody.append(row); });

        $table.find('.es-sort-btn').text('⇅');
        $(this).text(sortState.dir === 1 ? '▲' : '▼');
    });

    // Filter — open/close the menu (only one open at a time).
    $(document).on('click', '.es-filter-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $wrap = $(this).closest('.es-filter-wrap');
        var wasOpen = $wrap.hasClass('open');
        $('.es-filter-wrap').removeClass('open');
        if (wasOpen) return;
        $wrap.addClass('open');

        var col = parseInt($wrap.data('col'), 10);
        var active = activeFilters[col];
        var $options = $wrap.find('.es-filter-options').empty();
        (columnValues[col] || []).forEach(function (v) {
            var checked = !active || active.has(v);
            var $cb = $('<input type="checkbox">').val(v).prop('checked', checked);
            var $label = $('<label class="es-filter-opt">').append($cb)
                .append(document.createTextNode(' ' + (v === '' ? '(empty)' : v)));
            $options.append($label);
        });
        $wrap.find('.es-filter-search').val('');
    });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.es-filter-wrap').length) $('.es-filter-wrap').removeClass('open');
    });

    // Search within the open filter's value list (doesn't touch the table yet).
    $(document).on('input', '.es-filter-search', function () {
        var q = $(this).val().toLowerCase();
        $(this).closest('.es-filter-menu').find('.es-filter-opt').each(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(q) !== -1);
        });
    });
    $(document).on('click', '.es-filter-all', function (e) {
        e.preventDefault();
        $(this).closest('.es-filter-menu').find('.es-filter-opt:visible input[type=checkbox]').prop('checked', true);
    });
    $(document).on('click', '.es-filter-none', function (e) {
        e.preventDefault();
        $(this).closest('.es-filter-menu').find('.es-filter-opt:visible input[type=checkbox]').prop('checked', false);
    });
    $(document).on('click', '.es-filter-apply', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.es-filter-wrap');
        var col = parseInt($wrap.data('col'), 10);
        var all = columnValues[col] || [];
        var checked = $wrap.find('.es-filter-options input[type=checkbox]:checked').map(function () { return $(this).val(); }).get();
        if (checked.length === all.length) {
            delete activeFilters[col];
        } else {
            activeFilters[col] = new Set(checked);
        }
        $wrap.removeClass('open');
        updateFilterIcon($wrap, activeFilters.hasOwnProperty(col));
        applyFilters();
    });
});
</script>
