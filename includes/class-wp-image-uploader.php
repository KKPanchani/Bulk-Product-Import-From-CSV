<?php
if (!class_exists('WP_Image_Uploader')) {

    class WP_Image_Uploader {

        public static function fetch_csv($url) {
            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                error_log('CSV fetch error: ' . $response->get_error_message());
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/' . date('Y') . '/product-data/data.csv';

            if (file_put_contents($file_path, $body) === false) {
                error_log('Failed to write CSV file.');
                return false;
            }

            // Create a WordPress attachment for the CSV file
            $attachment = array(
                'guid'           => $upload_dir['basedir'] . '/'.date('Y').'/product-data/data.csv',
                'post_mime_type' => 'text/csv',
                'post_title'     => 'Products',
                'post_content'   => '',
                'post_status'    => 'inherit'
            );

            $attachment_id = wp_insert_attachment( $attachment, $file_path );

            require_once( ABSPATH . 'wp-admin/includes/image.php' );

            $attachment_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
            wp_update_attachment_metadata( $attachment_id, $attachment_data );

            return $file_path;
        }

        public static function upload_image($image_url) {
            // Image upload code from your original script
            $upload_dir = wp_upload_dir();
        
        // Get the image contents
        $image_data = file_get_contents($image_url);
        if ($image_data === false) {
            return new WP_Error('image_download_error', 'Failed to download image.');
        }
        
        // Generate a unique file name
        $file_name = basename($image_url);
        $unique_file_name = wp_unique_filename($upload_dir['path'], $file_name);
        $file_path = $upload_dir['path'] . '/' . $unique_file_name;
        
        // Save the image to the upload directory
        if (!file_put_contents($file_path, $image_data)) {
            return new WP_Error('image_save_error', 'Failed to save image to upload directory.');
        }
        
        // Get the file type and mime type
        $file_type = wp_check_filetype($file_name, null);
        
        // Create an attachment post
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . basename($file_path),
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_file_name($file_name),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        
        // Insert the attachment post into the database
        $attachment_id = wp_insert_attachment($attachment, $file_path);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        
        // Include the image.php file to use the wp_generate_attachment_metadata() function
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Generate the attachment metadata
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        
        // Update the attachment metadata in the database
        wp_update_attachment_metadata($attachment_id, $attachment_metadata);
        
        return $attachment_id;
        }
    }
}
