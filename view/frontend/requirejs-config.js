/**
 * Register a mixin on Magento's set-shipping-information action so the
 * customer-picked delivery date/slot/comment are reliably injected into the
 * shipping-information REST payload's extension_attributes at submit time.
 *
 * The picker component (delivery-picker.js) writes the current selection into
 * the shared delivery-selection model; this mixin reads it the instant the
 * payload is built — guaranteeing capture regardless of KO timing.
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/action/set-shipping-information': {
                'ETechFlow_DeliveryDate/js/action/set-shipping-information-mixin': true
            }
        }
    }
};