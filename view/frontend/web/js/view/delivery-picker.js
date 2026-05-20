/**
 * Luma Knockout component — Delivery Date Picker.
 *
 * Reads the calendar data from window.checkoutConfig.etechflowDeliveryDate
 * (populated server-side by ETechFlow\DeliveryDate\Model\Checkout\ConfigProvider).
 *
 * Hooks into Magento's shipping-information action so the picked date is
 * sent to the server as extension_attributes on the standard shipping
 * payload. The capture plugin (ShippingInformationManagementPlugin)
 * persists it onto the quote address; the observer copies it to the order
 * at submit. The whole back-end pipeline was proven in v0.2.
 *
 * Feature parity with the Hyvä Alpine calendar (intentional — single
 * source of truth on data + UX):
 *   - 4-week grid + month nav
 *   - "Earliest" + "Today" badges
 *   - Keyboard navigation (arrows + Enter/Space)
 *   - "Get it as soon as possible" quick-pick button
 *   - 1000-char notes-to-driver comment field
 *
 * Hyvä is still the wedge — this is the fallback. Don't try to bring
 * Tailwind utility classes; Luma has its own LESS theme.
 */
define([
    'uiComponent',
    'ko',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/set-shipping-information',
    'mage/translate'
], function (Component, ko, quote, setShippingInformationAction, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'ETechFlow_DeliveryDate/delivery-picker'
        },

        initialize: function () {
            this._super();

            var config = (window.checkoutConfig && window.checkoutConfig.etechflowDeliveryDate)
                ? window.checkoutConfig.etechflowDeliveryDate
                : { enabled: false };

            this.enabled = config.enabled === true;

            if (!this.enabled) {
                // Component still loads (KO needs the binding context) but
                // the template renders nothing — keeps Luma's lifecycle
                // happy without paint cost.
                return this;
            }

            this.isRequired       = !!config.isRequired;
            this.fieldNote        = config.fieldNote || '';
            this.dateFormat       = config.dateFormat || 'yyyy-mm-dd';
            this.availableDates   = config.availableDates || [];
            this.earliestDate     = config.earliestDate || null;
            this.latestDate       = config.latestDate || null;
            this.deliveryComment  = config.deliveryComment || '';
            this.commentStyle     = config.commentStyle || 'magento_notice';
            this.availableIntervals = config.availableIntervals || [];

            // Hot-path lookup
            this._availableSet = {};
            for (var i = 0; i < this.availableDates.length; i++) {
                this._availableSet[this.availableDates[i]] = true;
            }

            // Reactive state (KO observables)
            this.selected       = ko.observable(config.defaultDate || '');
            this.focused        = ko.observable(config.defaultDate || config.earliestDate || '');
            this.viewMonth      = ko.observable((config.earliestDate || new Date().toISOString().slice(0, 10)).slice(0, 7));
            this.comment        = ko.observable('');
            this.timeIntervalId = ko.observable('');

            // Computed observables — KO re-renders cells when these change
            this.monthCells     = ko.computed(this._buildMonthCells, this);
            this.formattedDate  = ko.computed(this._formatSelected, this);
            this.canGoBack      = ko.computed(this._canGoBack, this);
            this.canGoForward   = ko.computed(this._canGoForward, this);

            // Subscribe shipping-information action — attach extension_attributes
            // before Magento POSTs. Pattern lifted from Magento_Checkout's own
            // sample but using the documented set-shipping-information override
            // surface so we don't fight Magento internals.
            this._registerSubmitHook();

            return this;
        },

        // -----------------------------------------------------------------
        // Calendar geometry
        // -----------------------------------------------------------------

        _buildMonthCells: function () {
            if (!this.viewMonth()) return [];
            var parts = this.viewMonth().split('-');
            var year = parseInt(parts[0], 10);
            var month = parseInt(parts[1], 10);
            var firstOfMonth = new Date(Date.UTC(year, month - 1, 1));
            var daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
            var leadingBlanks = firstOfMonth.getUTCDay();
            var todayIso = new Date().toISOString().slice(0, 10);

            var cells = [];
            for (var b = 0; b < leadingBlanks; b++) {
                cells.push({ empty: true });
            }
            for (var d = 1; d <= daysInMonth; d++) {
                var iso = year + '-' + this._pad(month) + '-' + this._pad(d);
                var available = !!this._availableSet[iso];
                var badge = '';
                if (available) {
                    if (iso === this.earliestDate) badge = $t('Earliest');
                    else if (iso === todayIso) badge = $t('Today');
                }
                cells.push({
                    empty: false,
                    iso: iso,
                    day: d,
                    available: available,
                    badge: badge
                });
            }
            return cells;
        },

        _pad: function (n) { return n < 10 ? '0' + n : '' + n; },

        weekdayLabels: function () {
            return [$t('Sun'), $t('Mon'), $t('Tue'), $t('Wed'), $t('Thu'), $t('Fri'), $t('Sat')];
        },

        cellClass: function (cell) {
            if (cell.empty) return 'etechflow-dd-cell etechflow-dd-cell--empty';
            var cls = 'etechflow-dd-cell';
            if (!cell.available) cls += ' etechflow-dd-cell--blocked';
            else if (cell.iso === this.selected()) cls += ' etechflow-dd-cell--selected';
            else if (cell.iso === this.focused()) cls += ' etechflow-dd-cell--focused';
            else cls += ' etechflow-dd-cell--available';
            return cls;
        },

        ariaLabel: function (cell) {
            if (cell.empty) return '';
            return cell.day + (cell.available
                ? ', ' + $t('available')
                : ', ' + $t('unavailable'))
                + (cell.iso === this.selected() ? ', ' + $t('selected') : '');
        },

        // -----------------------------------------------------------------
        // Selection
        // -----------------------------------------------------------------

        select: function (cell) {
            if (!cell || cell.empty || !cell.available) return;
            this.selected(cell.iso);
            this.focused(cell.iso);
        },

        selectEarliest: function () {
            if (!this.earliestDate) return;
            this.viewMonth(this.earliestDate.slice(0, 7));
            this.selected(this.earliestDate);
            this.focused(this.earliestDate);
        },

        // -----------------------------------------------------------------
        // Keyboard navigation
        // -----------------------------------------------------------------

        handleKey: function (cell, event) {
            var delta = 0;
            switch (event.keyCode) {
                case 37: delta = -1; break;   // left
                case 39: delta =  1; break;   // right
                case 38: delta = -7; break;   // up
                case 40: delta =  7; break;   // down
                case 13: case 32:             // enter / space
                    this.select(cell);
                    event.preventDefault();
                    return false;
                default: return true;
            }
            event.preventDefault();
            this._moveFocus(delta);
            return false;
        },

        _moveFocus: function (delta) {
            if (!this.focused()) {
                this.focused(this.earliestDate || '');
                return;
            }
            var dt = new Date(this.focused() + 'T00:00:00Z');
            dt.setUTCDate(dt.getUTCDate() + delta);
            var newIso = dt.toISOString().slice(0, 10);
            this.focused(newIso);
            if (newIso.slice(0, 7) !== this.viewMonth()) {
                this.viewMonth(newIso.slice(0, 7));
            }
        },

        // -----------------------------------------------------------------
        // Month navigation
        // -----------------------------------------------------------------

        formatMonth: function () {
            if (!this.viewMonth()) return '';
            var parts = this.viewMonth().split('-');
            var y = parseInt(parts[0], 10);
            var m = parseInt(parts[1], 10);
            var names = [$t('January'), $t('February'), $t('March'), $t('April'),
                $t('May'), $t('June'), $t('July'), $t('August'),
                $t('September'), $t('October'), $t('November'), $t('December')];
            return names[m - 1] + ' ' + y;
        },

        _canGoBack: function () {
            if (!this.earliestDate || !this.viewMonth()) return false;
            return this.viewMonth() > this.earliestDate.slice(0, 7);
        },

        _canGoForward: function () {
            if (!this.latestDate || !this.viewMonth()) return false;
            return this.viewMonth() < this.latestDate.slice(0, 7);
        },

        prevMonth: function () {
            if (!this.canGoBack()) return;
            var parts = this.viewMonth().split('-');
            var y = parseInt(parts[0], 10);
            var m = parseInt(parts[1], 10);
            var prev = new Date(Date.UTC(y, m - 2, 1));
            this.viewMonth(prev.toISOString().slice(0, 7));
        },

        nextMonth: function () {
            if (!this.canGoForward()) return;
            var parts = this.viewMonth().split('-');
            var y = parseInt(parts[0], 10);
            var m = parseInt(parts[1], 10);
            var next = new Date(Date.UTC(y, m, 1));
            this.viewMonth(next.toISOString().slice(0, 7));
        },

        // -----------------------------------------------------------------
        // Date format display
        // -----------------------------------------------------------------

        _formatSelected: function () {
            if (!this.selected()) return '';
            var dt = new Date(this.selected() + 'T00:00:00Z');
            var d = this._pad(dt.getUTCDate());
            var m = this._pad(dt.getUTCMonth() + 1);
            var y = dt.getUTCFullYear();
            var fmt = this.dateFormat;
            if (fmt === 'dd-mm-yyyy' || fmt === 'dd-mm-yy') return d + '-' + m + '-' + y;
            if (fmt === 'mm/dd/yyyy' || fmt === 'mm/dd/yy') return m + '/' + d + '/' + y;
            if (fmt === 'dd/mm/yyyy' || fmt === 'dd/mm/yy') return d + '/' + m + '/' + y;
            return y + '-' + m + '-' + d;
        },

        // -----------------------------------------------------------------
        // Magento shipping-info wire-up
        // -----------------------------------------------------------------

        _registerSubmitHook: function () {
            var self = this;
            var originalAction = setShippingInformationAction.registerPaymentsAccessor
                || setShippingInformationAction;  // older Magento API surface

            // The documented hook is to attach extension_attributes to the
            // quote's shipping-address before the shipping-information call
            // packages the payload. Magento's quote model exposes
            // extensionAttributes() — we set our three keys there.
            quote.shippingAddress.subscribe(function (address) {
                if (!address || !self.selected) return;
                var extension = address.customAttributes || {};
                extension['etechflow_delivery_date']                = self.selected();
                extension['etechflow_delivery_time_interval_id']    = self.timeIntervalId();
                extension['etechflow_delivery_comment']             = self.comment();
                address.customAttributes = extension;
            });

            // Also write into the shipping-step's standard extensionAttributes
            // bag the first time the user touches the picker — covers the
            // case where the shippingAddress subscription has already fired.
            this.selected.subscribe(function () { self._stampExtensionAttributes(); });
            this.comment.subscribe(function () { self._stampExtensionAttributes(); });
            this.timeIntervalId.subscribe(function () { self._stampExtensionAttributes(); });
        },

        _stampExtensionAttributes: function () {
            var address = quote.shippingAddress();
            if (!address) return;
            var ext = address.extensionAttributes || {};
            ext.etechflow_delivery_date             = this.selected();
            ext.etechflow_delivery_time_interval_id = this.timeIntervalId();
            ext.etechflow_delivery_comment          = this.comment();
            address.extensionAttributes = ext;
        }
    });
});
