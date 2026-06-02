/**
 * Mixin on Magento_Checkout/js/action/set-shipping-information.
 *
 * Injects the customer's delivery selection (date / time-interval / comment)
 * into the shipping address's extension_attributes (snake_case) at the exact
 * moment the shipping-information REST payload is built. This is the reliable
 * way to send custom data with the shipping step — far more robust than
 * stamping observables onto the KO address and hoping serialization picks
 * them up.
 *
 * The backend ETechFlow\DeliveryDate\Plugin\ShippingInformationManagementPlugin
 * reads exactly these three extension-attribute keys.
 */
define([
    'Magento_Checkout/js/model/quote',
    'ETechFlow_DeliveryDate/js/model/delivery-selection'
], function (quote, deliverySelection) {
    'use strict';

    return function (originalAction) {
        return function (messageContainer) {
            var address = quote.shippingAddress();

            if (address) {
                if (!address['extension_attributes']) {
                    address['extension_attributes'] = {};
                }
                if (deliverySelection.date()) {
                    address['extension_attributes']['etechflow_delivery_date'] = deliverySelection.date();
                }
                if (deliverySelection.intervalId()) {
                    address['extension_attributes']['etechflow_delivery_time_interval_id'] = deliverySelection.intervalId();
                }
                if (deliverySelection.comment()) {
                    address['extension_attributes']['etechflow_delivery_comment'] = deliverySelection.comment();
                }
            }

            return originalAction(messageContainer);
        };
    };
});