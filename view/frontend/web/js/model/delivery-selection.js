/**
 * Shared singleton holding the customer's current delivery selection on the
 * Luma checkout. The delivery-picker component writes to it; the
 * set-shipping-information mixin reads from it at submit time. A single
 * source of truth avoids the timing/serialization fragility of stamping
 * extension attributes straight onto the KO quote address.
 */
define(['ko'], function (ko) {
    'use strict';

    return {
        date: ko.observable(''),
        intervalId: ko.observable(''),
        comment: ko.observable('')
    };
});