class LatepointPaymentsCcavenueFrontAddon {

    // Init
    constructor() {
        this.ready();
    }

    ready() {
        jQuery(document).ready(() => {

            // Check for success URL params
            const urlParams = new URLSearchParams(window.location.search);
            const latepoint_payment_status = urlParams.get('latepoint_payment_status');
            const booking_id = urlParams.get('booking_id');

            if (latepoint_payment_status === 'success' && booking_id) {
                // Remove params from URL to avoid re-triggering on refresh
                const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                window.history.replaceState({ path: newUrl }, '', newUrl);

                // Open the booking summary using LatePoint's internal route
                // We use the generic 'reload_booking_form_summary_panel' route which fetches the summary
                // We need to trigger this as if a booking flow just finished.
                // LatePoint doesn't have a simple public JS function to "open success modal" from scratch without context.
                // However, we can use the 'latepoint:openBookingSummary' or simulate a click if we can find a trigger.
                // Alternatively, we can make an ajax call to get the summary HTML and inject it.

                // Let's try to mimic the "Show Steps" action but pre-filled. 
                // Getting the summary HTML is the standard way.

                let data = {
                    action: 'latepoint_route_call',
                    route_name: latepoint_helper.reload_booking_form_summary_route,
                    booking_id: booking_id,
                    return_format: 'json'
                };

                jQuery.ajax({
                    type: "post",
                    dataType: "json",
                    url: latepoint_helper.ajaxurl,
                    data: data,
                    success: function (response) {
                        if (response.status === "success") {
                            // We need to inject this into the page. 
                            // Usually LatePoint uses a lightbox or side panel.
                            // Let's assume we can trigger the standard "Confirmation" step lightbox.
                            var $summary_wrapper = jQuery('.latepoint-summary-wrapper');
                            if (!$summary_wrapper.length) {
                                jQuery('body').append('<div class="latepoint-summary-wrapper latepoint-lightbox-w"><div class="latepoint-lightbox-i"></div><div class="latepoint-lightbox-bg"></div></div>');
                                $summary_wrapper = jQuery('.latepoint-summary-wrapper');
                            }
                            $summary_wrapper.find('.latepoint-lightbox-i').html(response.message);
                            $summary_wrapper.addClass('latepoint-lightbox-visible');

                            // Add close handler
                            $summary_wrapper.find('.latepoint-lightbox-bg, .latepoint-lightbox-close').on('click', function () {
                                $summary_wrapper.removeClass('latepoint-lightbox-visible');
                            });
                        }
                    }
                });
            }

            // Booking Form
            jQuery('body').on('latepoint:initPaymentMethod', '.latepoint-booking-form-element', (e, data) => {
                if (data.payment_method == 'ccavenue_checkout') {
                    latepoint_add_action(data.callbacks_list, async () => {
                        return this.initPaymentRedirect(jQuery(e.currentTarget));
                    });
                }
            });

            // Transaction/Invoice Form
            jQuery('body').on('latepoint:initOrderPaymentMethod', '.latepoint-transaction-payment-form', (e, data) => {
                const $transaction_intent_form = jQuery(e.currentTarget);

                if (data.payment_method == 'ccavenue_checkout') {
                    latepoint_add_action(data.callbacks_list, async () => {
                        return this.initOrderPaymentRedirect($transaction_intent_form);
                    });
                }
            });

        });
    }

    async initPaymentRedirect($booking_form_element) {

        let form_data = new FormData($booking_form_element.find('.latepoint-form')[0]);
        var data = {
            action: 'latepoint_route_call',
            route_name: latepoint_helper.ccavenue_payment_options_route,
            params: latepoint_formdata_to_url_encoded_string(form_data),
            layout: 'none',
            return_format: 'json'
        }
        try {
            let response = await jQuery.ajax({
                type: "post",
                dataType: "json",
                url: latepoint_timestamped_ajaxurl(),
                data: data
            });
            if (response.status == 'success') {

                if (response.amount > 0) {
                    $booking_form_element.find('.latepoint_order_intent_key').val(response.order_intent_key);

                    // Redirect Flow
                    this.createAndSubmitForm(response.action_url, response.access_code, response.encRequest);

                    // Return false/prevent default if needed, or just let it redirect.
                    // LatePoint expects promise resolution for next step, but we are redirecting away.
                    // We can return a promise that never resolves or just true to let existing loaders spin until unload.
                    return new Promise(() => { });

                } else {
                    // free booking
                    return true;
                }
            } else {
                throw new Error('Error: ' + response.message);
            }
        } catch (e) {
            throw e;
        }
    }


    async initOrderPaymentRedirect($transaction_intent_form) {

        let form_data = new FormData($transaction_intent_form[0]);

        let data = {
            action: 'latepoint_route_call',
            route_name: latepoint_helper.ccavenue_payment_options_route, // Reuse route if it handles both or use separate
            // Actually our controller has get_payment_options and get_order_payment_options
            // We need to check if latepoint_helper.ccavenue_payment_options_route points to generic or specific?
            // In main PHP file: $localized_vars['ccavenue_payment_options_route'] points to get_payment_options
            // We might need another route for orders if the logic is different.
            // But wait, the controller has get_order_payment_options but likely only one route variable was localized.
            // Let's manually construct route name for order if needed or assume the same controller handles it?

            // Correction: The controller provided earlier has get_order_payment_options
            // We should use that route name. In PHP we only registered one var.
            // Let's hardcode the route name construction logic as LatePoint does safely.
            route_name: 'payments_ccavenue__get_order_payment_options',

            params: latepoint_formdata_to_url_encoded_string(form_data),
            layout: 'none',
            return_format: 'json'
        }

        try {
            let response = await jQuery.ajax({
                type: "post",
                dataType: "json",
                url: latepoint_timestamped_ajaxurl(),
                data: data
            });
            if (response.status == 'success') {

                if (response.amount > 0) {
                    this.createAndSubmitForm(response.action_url, response.access_code, response.encRequest);
                    return new Promise(() => { });
                } else {
                    // free booking
                    return true;
                }
            } else {
                throw new Error('Error: ' + response.message);
            }
        } catch (e) {
            console.error(e.message);
            throw e;
        }
    }

    createAndSubmitForm(action_url, access_code, encRequest) {
        var form = document.createElement("form");
        form.setAttribute("method", "post");
        form.setAttribute("action", action_url);

        var accessCodeField = document.createElement("input");
        accessCodeField.setAttribute("type", "hidden");
        accessCodeField.setAttribute("name", "access_code");
        accessCodeField.setAttribute("value", access_code);
        form.appendChild(accessCodeField);

        var encRequestField = document.createElement("input");
        encRequestField.setAttribute("type", "hidden");
        encRequestField.setAttribute("name", "encRequest");
        encRequestField.setAttribute("value", encRequest);
        form.appendChild(encRequestField);

        document.body.appendChild(form);
        form.submit();
    }

}


// Init
if (latepoint_helper.is_ccavenue_active) {
    window.latepointPaymentsCcavenueFrontAddon = new LatepointPaymentsCcavenueFrontAddon();
}
