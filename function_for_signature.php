<?php
function rudr_upload_file_by_url( $image_url ) {

	// it allows us to use download_url() and wp_handle_sideload() functions
	require_once( ABSPATH . 'wp-admin/includes/file.php' );

	// download to temp dir
	$temp_file = download_url( $image_url );

	if( is_wp_error( $temp_file ) ) {
		return false;
	}

	// move the temp file into the uploads directory
	$file = array(
		'name'     => basename( $image_url ),
		'type'     => mime_content_type( $temp_file ),
		'tmp_name' => $temp_file,
		'size'     => filesize( $temp_file ),
	);
	$sideload = wp_handle_sideload(
		$file,
		array(
			'test_form'   => false // no needs to check 'action' parameter
		)
	);

	if( ! empty( $sideload[ 'error' ] ) ) {
		// you may return error message if you want
		return false;
	}

	// it is time to add our uploaded image into WordPress media library
	$attachment_id = wp_insert_attachment(
		array(
			'guid'           => $sideload[ 'url' ],
			'post_mime_type' => $sideload[ 'type' ],
			'post_title'     => basename( $sideload[ 'file' ] ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$sideload[ 'file' ]
	);

	if( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		return false;
	}

	// update medatata, regenerate image sizes
	require_once( ABSPATH . 'wp-admin/includes/image.php' );

	wp_update_attachment_metadata(
		$attachment_id,
		wp_generate_attachment_metadata( $attachment_id, $sideload[ 'file' ] )
	);

	return $attachment_id;

}

function add_signature_field_to_checkout_widget() {
	// Return early (hide) if doctor_email exists
 if (isset($_POST['need_doctor_approval']) && $_POST['need_doctor_approval']) {
        return;
    }
    ?>
    <style>
        .prescriber-fields {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            margin-bottom: 20px;
        }

        .prescriber-fields input[type="text"] {
            width: 25%;
            padding-right: 15px;
        }

        .prescriber-fields label {
            width: 18%;
            text-align: left;
            margin-right: 0;
            padding-left: 15px;
        }

        #signature-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 20px; /* Added margin-bottom to create space */
        }

        #signature-pad {
            width: 100%;
            max-width: 300px;
            height: 200px;
            border: 1px solid black;
            margin-bottom: 10px;
        }

        #clear-signature,
        #confirm-signature {
            width: 100%;
            max-width: 200px;
            margin-bottom: 5px;
        }
    </style>
    <div id="signature-content">
        <label for="signature">Signature <span class="required" style="color: red;">*</span></label>
        <canvas id="signature-pad" width="300" height="200" style="border: 1px solid black; touch-action: none;"></canvas>
        <br>
        <button id="clear-signature">Clear</button>
        <button id="confirm-signature">Confirm Signature</button>
    </div>
    <input type="hidden" id="signature-data" name="signature" value="">
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var canvas = document.getElementById('signature-pad');
        var signaturePad = new SignaturePad(canvas);

        document.getElementById('clear-signature').addEventListener('click', function() {
            signaturePad.clear();
            document.getElementById('signature-data').value = '';
        });

        document.getElementById('confirm-signature').addEventListener('click', function(event) {
            // Prevent default form submission or any unintended behavior
            event.preventDefault();

            if (signaturePad.isEmpty()) {
                alert('Please sign before confirming.');
            } else {
                document.getElementById('signature-data').value = signaturePad.toDataURL();
                alert('Signature confirmed.');

                // Check if the specific WooCommerce error message exists and remove it
                var errorMessages = document.querySelectorAll('.woocommerce-error');
                errorMessages.forEach(function(errorMessage) {
                    if (errorMessage.textContent.includes("Please confirm your signature before placing the order.")) {
                        errorMessage.remove();
                    }
                });
            }
        });
    });
    </script>
    <?php
}
add_action('woocommerce_checkout_before_order_review', 'add_signature_field_to_checkout_widget');

/**
 * Validate Signature Field Before Placing Order
 */
function validate_signature_field() {
    if (isset($_POST['need_doctor_approval']) && $_POST['need_doctor_approval']) {
        return; // Skip validation if doctor approval is active
    }
    if (empty($_POST['signature'])) {
        wc_add_notice(__('Please confirm your signature before placing the order.', 'woocommerce'), 'error');
    }
}
add_action('woocommerce_checkout_process', 'validate_signature_field');

/**
 * Email Signature as Image File with Order ID (Updated with Resizing)
 */
function email_signature_with_order($order_id) {
    $order = wc_get_order($order_id);
    $signature_data = isset($_POST['signature']) ? $_POST['signature'] : '';

    if (!empty($signature_data)) {
        // Convert signature data to image
        $image_data = str_replace('data:image/png;base64,', '', $signature_data);
        $image_data = base64_decode($image_data);

        // Generate unique file name for the signature image
        $filename = 'signature_' . $order_id . '.png';

        // Get upload directory
        $upload_dir = wp_upload_dir();

        // Path to save the image
        $file = $upload_dir['path'] . '/' . $filename;

        // Save image data to file
        file_put_contents($file, $image_data);

        // Check if file exists
        if (file_exists($file)) {
            // Resize the signature image (NEW ADDITION)
            $editor = wp_get_image_editor($file);
            if (!is_wp_error($editor)) {
                $editor->resize(200, 100, false); // Width, Height, Crop
                $editor->save($file);
            }

            // Set up the attachment array
            $attachment = array(
                'guid' => $upload_dir['url'] . '/' . basename($file),
                'post_mime_type' => 'image/png',
                'post_title' => sanitize_file_name($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );

            // Insert the attachment
            $attach_id = wp_insert_attachment($attachment, $file);

            // Generate attachment metadata
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file);
            wp_update_attachment_metadata($attach_id, $attach_data);

            // Save attachment ID as post meta
            update_post_meta($order_id, 'signature_image_attachment_id', $attach_id);

            // Get PDF invoice if available
            $invoice_id = $order->get_id();
            $invoice_path = ''; // Initialize invoice path
            if (class_exists('WC_PDF_Invoices')) {
                $invoice = new WC_PDF_Invoices();
                $invoice_pdf = $invoice->get_invoice($order_id);
                if ($invoice_pdf) {
                    $upload_dir = wp_upload_dir();
                    $upload_path = $upload_dir['path'] . '/';
                    $invoice_path = $upload_path . 'invoice_' . $order_id . '.pdf';
                    $invoice_pdf->Output($invoice_path, 'F');
                }
            }

            // Email details
            $to = '';
            $subject = 'Signature and Invoice for Order #' . $order_id;
            $message = 'Please find the signature and invoice for Order #' . $order_id . ' attached.';
            $headers = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>' . "\r\n";

            // Attach signature image to email
            $attachments = array($file);

            // Attach PDF invoice to email if available
            if (!empty($invoice_path)) {
                $attachments[] = $invoice_path;
            }

            // Send email with signature image, PDF invoice, and prescriber details
            wp_mail($to, $subject, $message, $headers, $attachments);

            // Remove invoice file after sending email
            if (!empty($invoice_path)) {
                unlink($invoice_path);
            }
        } else {
            // Log error if file not found
            error_log('Error saving signature image: File not found');
        }
    }
}
add_action('woocommerce_checkout_update_order_meta', 'email_signature_with_order');