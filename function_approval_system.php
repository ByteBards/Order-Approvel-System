<?php


// Register status with proper count styling
// 1. Register the status (use this version)
add_action('init', function() {
    register_post_status('wc-waiting-signature', [
        'label'                     => "Pending Doctor's approval",
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop("Pending Doctor's approval <span class=\"count\">(%s)</span>", "Pending Doctor's approval <span class=\"count\">(%s)</span>"),
        'post_type'                 => ['shop_order'], // Explicitly for orders
    ]);
});

// 2. Add to status list at specific position
add_filter('wc_order_statuses', function($order_statuses) {
    $new_statuses = [];
    foreach ($order_statuses as $key => $label) {
        $new_statuses[$key] = $label;
        if ('wc-pending' === $key) {
            $new_statuses['wc-waiting-signature'] = _x("Pending Doctor's approval", 'Order status', 'woocommerce');
        }
    }
    return $new_statuses;
});





// 3. Force new order email when status changes to processing
add_action('woocommerce_order_status_waiting-signature_to_on-hold', 'trigger_new_order_email', 10, 2);
add_action('woocommerce_order_status_waiting-signature_to_processing', 'trigger_new_order_email', 10, 2);

function trigger_new_order_email($order_id, $order) {
    $wc_emails = WC()->mailer()->get_emails();

    if (isset($wc_emails['WC_Email_New_Order'])) {
        $wc_emails['WC_Email_New_Order']->trigger($order_id);
    }

    // Optional: Send processing email to customer
    if (isset($wc_emails['WC_Email_Customer_Processing_Order'])) {
        $wc_emails['WC_Email_Customer_Processing_Order']->trigger($order_id);
    }
}


// 4. Ensure status changes work properly
add_action('woocommerce_order_status_changed', 'handle_waiting_signature_transition', 10, 4);
function handle_waiting_signature_transition($order_id, $from_status, $to_status, $order) {
    // If order was somehow set to pending, force it to waiting-signature
    if ($to_status === 'pending' && $order->get_meta('need_doctor_approval') === 'yes') {
        $order->update_status('waiting-signature');
    }
};



// 1. Add both fields in one grouped section at the TOP
add_action('woocommerce_before_checkout_billing_form', 'add_doctor_approval_fields', 5);
function add_doctor_approval_fields() {
    echo '<div class="doctor-approval-fields" style="margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 20px;">';
    
    // Checkbox
    woocommerce_form_field('need_doctor_approval', array(
        'type'        => 'checkbox',
        'label'       => __('This order requires doctor approval'),
        'class'       => array('form-row-wide'),
        'required'    => false,
    ), WC()->checkout->get_value('need_doctor_approval'));
    
    // Doctor Email (hidden by default)
    echo '<div id="doctor-email-wrapper" style="display:none; margin-top: 15px;">';
    woocommerce_form_field('doctor_email', array(
        'type'        => 'email',
        'label'       => __('Doctor Email'),
        'placeholder' => 'doctor@example.com',
        'required'    => true,
        'class'       => array('form-row-wide'),
    ), WC()->checkout->get_value('doctor_email'));
    echo '</div>';
    
    echo '</div>';
}

// 2. Save fields
add_action('woocommerce_checkout_update_order_meta', 'save_doctor_fields');
function save_doctor_fields($order_id) {
    if (!empty($_POST['need_doctor_approval'])) {
        update_post_meta($order_id, 'need_doctor_approval', 'yes');
        update_post_meta($order_id, 'doctor_email', sanitize_email($_POST['doctor_email']));
    }
}

function enqueue_combined_doctor_checkout_script() {
    if (!is_checkout()) return;
    ?>
    <style>
        .woocommerce-invalid {
            border-bottom: 1px solid #b81c23;
        }
        #global-loader {
            display: none;
            position: fixed;
            z-index: 99999;
            background: rgba(255, 255, 255, 0.7);
            top: 0; left: 0; right: 0; bottom: 0;
        }
        #global-loader .spinner {
            border: 6px solid #ccc;
            border-top: 6px solid #0C2A42;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        button#send-to-doctor, button#confirm-ok {
            width: 100%;
            background-color: #0C2A42 !important;
            color: #fff !important;
            padding: 10px 20px !important;
            border: none !important;
            border-radius: 5px !important;
            cursor: pointer !important;
            font-weight: normal !important;
            transition: background-color 0.3s !important;
            line-height: 25px;
        }
        .global-loader {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .doctor-email-error, .inline-error-msg, .woocommerce-error-term {
            color: #b81c23;
            font-size: .75em;
            display: block;
            margin-top: 0;
            margin-bottom: 0;
        }
        p:has(.inline-error-msg) + .form-row
        {
            height: 105px;
        }
    </style>

    <div id="global-loader"><div class="global-loader"><div class="spinner"></div></div></div>

    <script type="text/javascript">
    jQuery(function($) {

        if ($('#send-doctor-modal').length === 0) {
            $('body').append(`
                <div id="send-doctor-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
                    <div style="background:white;padding:20px 30px;border-radius:8px;text-align:center;max-width:400px;margin:auto;position:relative;top:25%;">
                        <p style="margin-bottom: 20px;">Email has been sent to the doctor to complete the checkout process.</p>
                        <button id="confirm-ok" class="button alt">OK</button>
                    </div>
                </div>
            `);
        }

        function showLoader() { $('#global-loader').fadeIn(); }
        function hideLoader() { $('#global-loader').fadeOut(); }

        function toggleDoctorButton() {
            let isChecked = $('input[name="need_doctor_approval"]').is(':checked');
            if (isChecked) {
                $('#place_order').hide();
                if ($('#send-to-doctor').length === 0) {
                    $('#payment').append('<button type="button" class="button alt" id="send-to-doctor" style="margin-top: 20px;">Send to Doctor</button>');
                }
            } else {
                $('#place_order').show();
                $('#send-to-doctor').remove();
            }
        }

        function resetAllErrors() {
            $('.woocommerce-error, .woocommerce-message, .checkout-inline-error-message, .woocommerce-NoticeGroup-checkout').remove();
            $('.doctor-email-error, .inline-error-msg, .woocommerce-error-term').remove();
            $('form.checkout .woocommerce-invalid').removeClass('woocommerce-invalid woocommerce-invalid-required-field');
        }

        function validateDoctorEmail() {
            const isChecked = $('input[name="need_doctor_approval"]').is(':checked');
            const doctorEmail = $('input[name="doctor_email"]');
            const customerEmail = $('input[name="billing_email"]').val();
            const emailVal = doctorEmail.val();
            let message = '';

            $('.doctor-email-error').remove();
            doctorEmail.removeClass('woocommerce-invalid');

            if (isChecked) {
                if (!emailVal) {
                    // message = 'Doctor email is required.';
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                    message = 'Please enter a valid doctor email address.';
                } else if (emailVal === customerEmail) {
                    message = 'Doctor email cannot be the same as customer email.';
                }

                // if (message) {
                //     doctorEmail.addClass('woocommerce-invalid woocommerce-invalid-required-field')
                //         .after('<span class="doctor-email-error">' + message + '</span>');
                // }
            }

            return message;
        }

        $('input[name="need_doctor_approval"]').on('change', function () {
            showLoader();
            setTimeout(() => {
                $('#doctor-email-wrapper').toggle($(this).is(':checked'));
                $('#signature-content').toggle(!$(this).is(':checked'));
                toggleDoctorButton();

                // ✅ Clear all validation errors when toggling approval checkbox
                resetAllErrors();

                hideLoader();
            }, 500);
        }).trigger('change');

        toggleDoctorButton();

        $(document).on('click', '#send-to-doctor', function(e) {
            e.preventDefault();
            showLoader();

            resetAllErrors();

            let errors = [];

            const paymentMethods = $('input[name="payment_method"]');
            if (paymentMethods.length > 0 && !$('input[name="payment_method"]:checked').val()) {
                errors.push('<li><strong>Error:</strong> Please select a payment method before proceeding.</li>');
            }

            const doctorEmailError = validateDoctorEmail();
            if (doctorEmailError) {
                errors.push('<li><strong>Error:</strong> ' + doctorEmailError + '</li>');
            }

            $('form.checkout').find('input, select, textarea').each(function () {
                if ($(this).is(':visible')) {
                    $(this).trigger('validate').blur();
                }
            });

            setTimeout(function () {
                $('form.checkout').find('.woocommerce-invalid').each(function () {
                    const $input = $(this);
                    const $formRow = $input.closest('.form-row');
                    const fieldId = $input.attr('id');
                    let label = $formRow.find('label').first().text().trim().replace('*', '').trim();

                    if (!label && fieldId) {
                        label = fieldId.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    }

                    if (fieldId && label) {
                        errors.push(`<li data-id="${fieldId}"><a href="#${fieldId}"><strong>${label}</strong> is a required field.</a></li>`);
                        $formRow.find('.inline-error-msg').remove();
                        $formRow.append(`<p class="inline-error-msg">${label} is a required field.</p>`);
                    }
                });

                const $terms = $('#terms');
                $terms.closest('.form-row').find('.woocommerce-error-term').remove();
                if ($terms.length && !$terms.prop('checked')) {
                    errors.push('<li><strong>Error:</strong> You must accept the terms and conditions.</li>');
                    $terms.closest('.form-row').append('<p class="woocommerce-error-term">Please read and accept the terms and conditions to proceed with your order.</p>');
                }

                if (errors.length > 0) {
                    const errorHtml = `<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout" role="alert">
                        <ul class="woocommerce-error" tabindex="-1">${errors.join('')}</ul>
                    </div>`;

                    $('.woocommerce-notices-wrapper').first().append(errorHtml);
                    $('html, body').animate({ scrollTop: $(".woocommerce-notices-wrapper").first().offset().top - 100 }, 500);
                    hideLoader();
                    return;
                }

                const formData = $('form.checkout').serialize();

                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'send_checkout_to_doctor',
                        form_data: formData,
                    },
                    success: function (response) {
                        hideLoader();
                        if (response.success) {
                            $('#send-doctor-modal').fadeIn();
                            let autoRedirect = setTimeout(() => {
                                window.location.href = '<?php echo esc_url(home_url()); ?>?clear_cart=1';
                            }, 10000);
                            $('#confirm-ok').on('click', function () {
                                clearTimeout(autoRedirect);
                                window.location.href = '<?php echo esc_url(home_url()); ?>?clear_cart=1';
                            });
                        } else {
                            $('.woocommerce-notices-wrapper').first().append(`
                                <ul class="woocommerce-error" role="alert">
                                    <li>${response.data.message || 'Failed to send email.'}</li>
                                </ul>
                            `);
                        }
                    },
                    error: function () {
                        hideLoader();
                        $('.woocommerce-notices-wrapper').first().append(`
                            <ul class="woocommerce-error" role="alert">
                                <li>Something went wrong while sending to the doctor.</li>
                            </ul>
                        `);
                    }
                });
            }, 300);
        });
    });
    </script>
    <?php
}
add_action('wp_footer', 'enqueue_combined_doctor_checkout_script');









// Register AJAX handler for logged-in and guest users
add_action('wp_ajax_send_checkout_to_doctor', 'handle_send_checkout_to_doctor');
add_action('wp_ajax_nopriv_send_checkout_to_doctor', 'handle_send_checkout_to_doctor');

function handle_send_checkout_to_doctor() {
    if (!isset($_POST['form_data'])) {
        wp_send_json_error(['message' => 'Form data missing']);
    }

    parse_str($_POST['form_data'], $form_data);
error_log(print_r($form_data, true));

    // Basic email validation
    if (empty($form_data['doctor_email']) || !is_email($form_data['doctor_email'])) {
        wp_send_json_error(['message' => 'Invalid doctor email']);
    }

    // Create a new order
    // $order = wc_create_order();
    // $order->set_status('waiting-signature');
	$order = wc_create_order([
		'status' => 'waiting-signature'
	]);

	// Add payment method
	if (!empty($form_data['payment_method'])) {
		$order->set_payment_method($form_data['payment_method']);
		$gateway = wc_get_payment_gateway_by_order($order);
		if ($gateway) {
			$order->set_payment_method_title($gateway->get_title());
		}
	}

    // Add cart items to order
    foreach (WC()->cart->get_cart() as $cart_item) {
        $order->add_product($cart_item['data'], $cart_item['quantity']);
    }

    // Set billing address (simplified - copy fields as needed)
    $billing_fields = [
        'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'email', 'phone'
    ];

    $billing_data = [];
    foreach ($billing_fields as $field) {
        $key = "billing_$field";
        $billing_data[$field] = isset($form_data[$key]) ? sanitize_text_field($form_data[$key]) : '';
    }

    $order->set_address($billing_data, 'billing');

    // Optionally: Set shipping address (if needed)
    // if (!isset($form_data['ship_to_different_address']) || $form_data['ship_to_different_address'] !== '1') {
    //     $order->set_address($billing_data, 'shipping'); 
    // }
// Save prescriber details
if (!empty($form_data['prescriber_number'])) {
    $order->update_meta_data('_prescriber_number', sanitize_text_field($form_data['prescriber_number']));
}

if (!empty($form_data['prescriber_name'])) {
    $order->update_meta_data('_prescriber_name', sanitize_text_field($form_data['prescriber_name']));
}

    // ✅ Set payment method
    if (!empty($form_data['payment_method'])) {
        $order->set_payment_method($form_data['payment_method']);

        $gateways = WC()->payment_gateways->payment_gateways();
        if (isset($gateways[$form_data['payment_method']])) {
            $order->set_payment_method_title($gateways[$form_data['payment_method']]->get_title());
        }
    }
    $order->set_customer_id(get_current_user_id());
    // Save doctor approval metadata
    $order->update_meta_data('need_doctor_approval', 'yes');
    $order->update_meta_data('doctor_email', sanitize_email($form_data['doctor_email']));
	$order->calculate_totals();
    $order->save();

    // Generate doctor approval link
    $order_id = $order->get_id();
    $order_key = $order->get_order_key();
    $approval_link = home_url("/doctor-approval/?order_id={$order_id}&key={$order_key}");

    // Send email to doctor
    $to = sanitize_email($form_data['doctor_email']);
    $subject = 'Order Requires Your Approval';
    $message = "Hello Doctor,\n\nA Staff has submitted an order that requires your approval. Please review and complete the order by clicking the link below:\n\n$approval_link\n\nThank you.";
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    wp_mail($to, $subject, $message, $headers);

    // Clear the cart
    WC()->cart->empty_cart();

    wp_send_json_success(['message' => 'Email sent successfully']);
}
add_filter('wp_mail_from_name', 'custom_wp_mail_from_name');
function custom_wp_mail_from_name($name) {
    return get_bloginfo('name'); // This returns the site title
}


add_action('template_redirect', 'handle_doctor_approval_page');
function handle_doctor_approval_page() {
    if (!is_page('doctor-approval')) return;

    if (empty($_GET['order_id']) || empty($_GET['key'])) {
        wp_die('Missing required parameters.');
    }

    $order_id = absint($_GET['order_id']);
    $order_key = sanitize_text_field($_GET['key']);
    $order = wc_get_order($order_id);

    if (!$order || $order->get_order_key() !== $order_key) {
        wp_die('Invalid order access.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle Rejection
        if (isset($_POST['doctor_action']) && $_POST['doctor_action'] === 'reject_order') {
            $order->update_status('rejected-by-doctor');
            wp_redirect(home_url());
            exit;
        }

        // Handle Signature Submission
        if (!empty($_POST['signature'])) {
            $signature = $_POST['signature'];
            $image_data = str_replace('data:image/png;base64,', '', $signature);
            $image_data = base64_decode($image_data);
            $filename = 'signature_' . $order_id . '.png';

            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['path'] . '/' . $filename;
            file_put_contents($file_path, $image_data);

            if (file_exists($file_path)) {
                $editor = wp_get_image_editor($file_path);
                if (!is_wp_error($editor)) {
                    $editor->resize(200, 100, false);
                    $editor->save($file_path);
                }

                $attachment_id = wp_insert_attachment([
                    'guid' => $upload_dir['url'] . '/' . basename($file_path),
                    'post_mime_type' => 'image/png',
                    'post_title' => sanitize_file_name($filename),
                    'post_content' => '',
                    'post_status' => 'inherit',
                ], $file_path);

                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
                wp_update_attachment_metadata($attachment_id, $attach_data);
                update_post_meta($order_id, 'doctor_signature_image_id', $attachment_id);
            }

            $payment_method = $order->get_payment_method();
            if (!empty($payment_method) && $payment_method !== 'cod') {
                $order->update_status('on-hold');
            } else {
                $order->update_status('processing');
            }

            wc_add_notice('Order approved successfully.', 'success');
            // wp_redirect(add_query_arg('key', $order->get_order_key(), wc_get_endpoint_url('order-received', $order->get_id(), wc_get_checkout_url())));
            $order_key = $order->get_order_key();
            wp_redirect(home_url("/thank-you/?order_id={$order_id}&key={$order_key}"));
            exit;
        }
    }

    // Enqueue Bootstrap CSS
    add_action('wp_enqueue_scripts', function () {
        wp_enqueue_style('bootstrap-5', 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.7/css/bootstrap.min.css', [], null);
    });

    // JavaScript logic
    add_action('wp_footer', function () {
        ?>
        <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.5/dist/signature_pad.umd.min.js"></script>
        <script>
            const canvas = document.getElementById('signature-pad');
            const signaturePad = new SignaturePad(canvas);
            let signatureConfirmed = false;

            function showGlobalLoader() {
                document.getElementById('global-loader').style.display = 'block';
            }

            function hideGlobalLoader() {
                document.getElementById('global-loader').style.display = 'none';
            }

            document.getElementById('clear-signature').addEventListener('click', function(e) {
                e.preventDefault();
                signaturePad.clear();
                signatureConfirmed = false;
            });

            document.getElementById('submit-signature').addEventListener('click', function(e) {
                showGlobalLoader();

                if (signaturePad.isEmpty()) {
                    alert('Please sign before confirming.');
                    hideGlobalLoader();
                    return;
                }

                document.getElementById('signature-data').value = signaturePad.toDataURL();
                signatureConfirmed = true;
                hideGlobalLoader();
                alert('Signature confirmed.');
            });

            document.getElementById('place-order-btn').addEventListener('click', function(e) {
                showGlobalLoader();

                if (!signatureConfirmed || signaturePad.isEmpty()) {
                    alert('Please Confirm signature before placing order.');
                    hideGlobalLoader();
                    return;
                }

                document.getElementById('signature-data').value = signaturePad.toDataURL();
                document.getElementById('signature-form').submit();
            });

            document.getElementById('reject-order-btn').addEventListener('click', function(e) {
                e.preventDefault();
                const confirmReject = confirm("Are you sure you want to reject this order?");
                if (confirmReject) {
                    showGlobalLoader();
                    document.getElementById('reject-form').submit();
                }
            });
        </script>
        <?php
    });

    // HTML output
    add_filter('the_content', function ($content) use ($order, $order_id) {
        ob_start();
        $billing = $order->get_address('billing');
        $items = $order->get_items();
        $subtotal = $order->get_subtotal();
        $total = $order->get_total();
        $tax = $order->get_total_tax();
        $shipping_total = $order->get_shipping_total();
        $payment_method = $order->get_payment_method_title();

        $prescriber_name = $order->get_meta('_prescriber_name');
        $prescriber_number = $order->get_meta('_prescriber_number');
        ?>
        <style>
            .doctor-approval-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            .doctor-approval-table th, .doctor-approval-table td { padding: 10px; border: 1px solid #ccc; }
            #signature-content {
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
                margin-bottom: 20px;
            }
            main#content .page-header {
                display: none;
            }
            .page-content {
                padding-top: 120px;
            }
            #signature-pad {
                width: 100%;
                max-width: 300px;
                height: 200px;
                border: 1px solid black;
                margin-bottom: 10px;
            }
            #clear-signature, #submit-signature, #place-order-btn {
                width: 100%;
                max-width: 200px;
                margin-bottom: 5px;
            }
            #clear-signature, #submit-signature, #place-order-btn {
                background-color: #0C2A42;
                font-size: 16px !important;
                color: #fff;
                border: none;
                border-radius: 5px;
                padding: 10px 20px;
                margin: 5px;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            #global-loader {
                display: none;
                position: fixed;
                z-index: 99999;
                background: rgba(255, 255, 255, 0.7);
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
            }
            .global-loader {
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            #global-loader .spinner {
                border: 6px solid #ccc;
                border-top: 6px solid #0C2A42;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
            }
            .da-footer {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            #signature-content label {
                margin-bottom: 10px;
            }
            .row.doctor-approval-main {
                margin-top: 40px;
            }
            .row.doctor-approval-top h1 {
                text-align: center;
            }
        </style>

        <div class="container mt-5 mb-5 pt-5">
            <div class="row  doctor-approval-top"><div class="col-md-12"><h1>Doctor Approval for Order #<?php echo esc_html($order_id); ?></h1></div></div>
            <div class="row  doctor-approval-main">
                <div class="col-md-8">
                    <h3>Staff Information</h3>
                    <table class="doctor-approval-table">
                        <tr><th>Full Name</th><td><?php echo esc_html($billing['first_name'] . ' ' . $billing['last_name']); ?></td></tr>
                        <tr><th>Email</th><td><?php echo esc_html($billing['email']); ?></td></tr>
                        <tr><th>Prescriber Name</th><td><?php echo esc_html($prescriber_name); ?></td></tr>
                        <tr><th>Prescriber Number</th><td><?php echo esc_html($prescriber_number); ?></td></tr>
                        <tr><th>Phone</th><td><?php echo esc_html($billing['phone']); ?></td></tr>
                        <tr><th>Address</th><td><?php echo esc_html($billing['address_1'] . ' ' . $billing['address_2']); ?></td></tr>
                        <tr><th>City</th><td><?php echo esc_html($billing['city']); ?></td></tr>
                        <tr><th>Postcode</th><td><?php echo esc_html($billing['postcode']); ?></td></tr>
                        <tr><th>Country</th><td><?php echo esc_html($billing['country']); ?></td></tr>
                    </table>

                    <h3>Products in Order</h3>
                    <table class="doctor-approval-table">
                        <thead><tr><th>Product</th><th>Qty</th><th>Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item->get_name()); ?></td>
                                    <td><?php echo esc_html($item->get_quantity()); ?></td>
                                    <td><?php echo wc_price($item->get_total()); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="col-md-4">
                    <h3>Order Summary</h3>
                    <table class="doctor-approval-table">
                        <?php if($payment_method){ ?>
                        <tr><th>Payment Method</th><td><?php echo esc_html($payment_method); ?></td></tr>
                        <?php } ?>
                        <tr><th>Subtotal</th><td><?php echo wc_price($subtotal); ?></td></tr>
                        <tr><th>Shipping</th><td><?php echo wc_price($shipping_total); ?></td></tr>
                        <tr><th>Tax</th><td><?php echo wc_price($tax); ?></td></tr>
                        <tr><th>Total</th><td><strong><?php echo wc_price($total); ?></strong></td></tr>
                    </table>

                    <form method="POST" id="signature-form">
                        <div id="signature-content">
                            <label for="signature">Please sign below to approve the order: <span class="required" style="color: red;">*</span></label>
                            <canvas id="signature-pad" width="300" height="200" style="border: 1px solid black; touch-action: none;"></canvas>
                            <input type="hidden" name="signature" id="signature-data" value="">
                            <br>
                            <button type="button" id="clear-signature">Clear</button>
                            <button type="button" id="submit-signature">Confirm Signature</button>
                        </div>
                    </form>
                </div>
            </div>
			<div class="row doctor-approval-footer">
                <div class="col-md-8"></div>
				<div class="col-md-4">
					
                    <form method="POST" id="reject-form">
                        <input type="hidden" name="doctor_action" value="reject_order">
                    </form>

                    <div class="da-footer">
                        <button type="button" id="place-order-btn">Place Order</button>
                        <!-- <button type="button" id="reject-order-btn" class="btn btn-danger mt-2">Reject</button> -->
                    </div>

                    <div id="global-loader" style="display: none;"><div class="global-loader"><div class="spinner"></div></div></div>
				</div>
			</div>
        </div>
        <?php
        return $content . ob_get_clean();
    }, 20);
}
function doctor_order_thankyou_shortcode() {
    if (!isset($_GET['order_id'])) return '<p>Missing order.</p>';

    $order_id = absint($_GET['order_id']);
    $order_key = sanitize_text_field($_GET['key'] ?? '');

    $order = wc_get_order($order_id);
    if (!$order) return '<p>Order not found.</p>';

    if ($order->get_order_key() !== $order_key) {
        return '<p>Invalid order key.</p>';
    }

    // Fake login context (temporarily) to display full order data
    $customer_id = $order->get_customer_id();
    $original_user_id = get_current_user_id();

    if ($customer_id > 0 && $customer_id !== $original_user_id) {
        wp_set_current_user($customer_id);
    }

    ob_start();

    wc_get_template(
        'checkout/thankyou.php',
        array(
            'order_id' => $order_id,
            'order' => $order
        )
    );

    // Restore original user
    if ($customer_id > 0 && $customer_id !== $original_user_id) {
        wp_set_current_user($original_user_id);
    }

    return ob_get_clean();
}
add_shortcode('doctor_order_thankyou', 'doctor_order_thankyou_shortcode');



// add_action('init', function() {
//     register_post_status('wc-rejected-by-doctor', [
//         'label'                     => 'Rejected By Doctor',
//         'public'                    => true,
//         'exclude_from_search'       => false,
//         'show_in_admin_all_list'    => true,
//         'show_in_admin_status_list' => true,
//         'label_count'               => _n_noop('Rejected By Doctor <span class="count">(%s)</span>', 'Rejected By Doctor <span class="count">(%s)</span>'),
//         'post_type'                 => ['shop_order']
//     ]);
// });

// add_filter('wc_order_statuses', function($order_statuses) {
//     $new_statuses = [];

//     foreach ($order_statuses as $key => $label) {
//         $new_statuses[$key] = $label;

//         if ('wc-cancelled' === $key) {
//             $new_statuses['wc-rejected-by-doctor'] = _x('Rejected By Doctor', 'Order status', 'woocommerce');
//         }
//     }

//     return $new_statuses;
// });

// Add inline JavaScript
function add_email_verification_script() {
    if (is_wc_endpoint_url('order-received')) {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.woocommerce-verify-email').on('submit', function(e) {
                var email = $('#email').val().trim();
                
                if (!email) {
                    e.preventDefault();
                    
                    // Remove existing errors
                    $('.woocommerce-error').remove();
                    
                    // Add new error
                    $(this).prepend(
                        '<ul class="woocommerce-error" role="alert">' +
                        '<li>Please enter an email address to verify.</li>' +
                        '</ul>'
                    );
                    
                    $('#email').focus();
                }
            });
        });
        </script>
        <?php
    }
}
add_action('wp_footer', 'add_email_verification_script');
// Add this to your theme's functions.php file
add_action('template_redirect', function() {
    if (is_wc_endpoint_url('order-received') && 
        $_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['verify'])) {
        
        if (empty($_POST['email'])) {
            wc_add_notice('Please enter an email address to verify.', 'error');
        }
    }
});


// Step 1: Add "Status" column to My Account orders table
add_filter('woocommerce_my_account_my_orders_columns', 'custom_add_status_column');
function custom_add_status_column($columns) {
    // Insert the "Status" column before the "Actions" column
    $new_columns = [];

    foreach ($columns as $key => $label) {
        if ($key === 'order-total') {
            $new_columns['order-status'] = __('Status', 'woocommerce');
        }

        $new_columns[$key] = $label;
    }

    return $new_columns;
}

// Step 2: Display the status inside the new column
add_action('woocommerce_my_account_my_orders_column_order-status', 'custom_show_order_status_column');
function custom_show_order_status_column($order) {
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order);
    }

    $status = $order->get_status(); // e.g., 'completed'
    $status_label = wc_get_order_status_name($status); // e.g., 'Completed'
    
    echo '<span class="order-status-label order-status-' . esc_attr($status) . '">' . esc_html($status_label) . '</span>';
}
