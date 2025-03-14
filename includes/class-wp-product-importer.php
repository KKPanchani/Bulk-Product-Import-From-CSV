<?php
if (!class_exists('WP_Product_Importer')) {

    class WP_Product_Importer {

        public static function init() {
            // Initialization logic if needed
            // Hook into WordPress for scheduling the cron
             // Add custom interval
        }

        private static $stop_flag = 'wp_product_importer_stop';

        public static function should_stop() {
            return get_option(self::$stop_flag, false) === true;
        }
        
        public static function set_stop_flag($value) {
            update_option(self::$stop_flag, $value);
        }
        
        public static function import_product() {
           
            // Get the current offset
            $current_offset = get_option('wp_csv_import_offset', 0);
            
            if($current_offset == 0){
                $options = get_option('wp_product_importer_options');
                $supplier_url = isset($options['wp_product_importer_supplier_url']) ? $options['wp_product_importer_supplier_url'] : '';
                if (empty($supplier_url)) {
                    error_log('Supplier URL not set.');
                    return false;
                }
                $csv_file = WP_Image_Uploader::fetch_csv($supplier_url);
                if (!$csv_file) {
                    return false;
                }
            }else{
                $upload_dir = wp_upload_dir();
                $csv_file = $upload_dir['basedir'] . '/' . date('Y') . '/product-data/data.csv';
            }
            $batch_size = 100; // Number of rows per batch
           
            $has_more_rows = self::create_product_from_csv($csv_file, $current_offset, $batch_size);  
        
            if ($has_more_rows) {
                // Update the offset for the next batch
                update_option('wp_csv_import_offset', $current_offset + $batch_size);
                return true; // More batches to process
            } else {
                // All rows processed, reset the offset
                update_option('wp_csv_import_offset',0);
                delete_option('wp_csv_import_offset');
                return true; // Completed
            }
        }

        public static function create_product_from_csv($csv_file_path, $offset = 0, $limit = 10) {
            $release_date = new DateTime('2022-01-01 00:00:00');
            
            if (!file_exists($csv_file_path) || !is_readable($csv_file_path)) {
                return false;
            }
           
            $header = null;
            $data = [];
            $current_row = 0;
            $total_rows = 0;
            
            if (($handle = fopen($csv_file_path, 'r')) !== false) {
                
                while (($row = fgetcsv($handle, 100000, ',')) !== false) {
                    $total_rows++;
                }
                rewind($handle);
                while (($row = fgetcsv($handle, 100000, ',')) !== false) {
                    if (!$header) {
                        $header = $row; // First row is the header
                        continue;
                    }
        
                    if ($current_row >= $offset && $current_row < $offset + $limit) {
                        if (count($row) === count($header)) {
                            $data[] = array_combine($header, $row);
                        }
                    }
        
                    $current_row++;
                    if ($current_row >= $offset + $limit) {
                        break; // Stop after processing the batch
                    }
                }
                fclose($handle);

                update_option('wp_product_importer_total_rows', $total_rows - 1); // Subtract 1 for header

            }
            foreach ($data as $product_data) {
                
                $product_id = wc_get_product_id_by_sku($product_data['sku']);
                if ($product_id){
                    // Product exists, update it
                    $product = wc_get_product($product_id);
            
                    if ($product) {
                        // Update product fields
                        if (isset($product_data['name'])) {
                            $product->set_name($product_data['name']);
                        }
                        // if (isset($product_data['wholesale_price'])) {
                            error_log('Debug info: update.');
                            error_log('Debug info:'.$product_data['sku']); 
                          
                            $wholesale_price = $product_data['wholesale_price'];
                            $margin = 0.60 ; // 40% 
                            $selling_price = ($wholesale_price / $margin);
                            $product->set_regular_price($selling_price);
                        // }
                        $product->set_stock_quantity($product_data['qty_avail']);
                        $product->save(); 
                    }
                }else
                {
                if(new DateTime($product_data['date_released']) > $release_date)
                {
                    error_log('Debug info: new product.');
                    error_log('Debug info:'.$product_data['sku']);
                    $product = new WC_Product();
                    $product->set_name( $product_data['name'] ); // Product name
                    $product->set_description( $product_data['description']);
                    $product->set_status( 'publish' );
                    $product->set_sku($product_data['sku']);
                    $categories = $product_data['cat.cat_0'].'>'.$product_data['cat.cat_1'].'>'.$product_data['cat.cat_2'].'>'.$product_data['cat.cat_3'].'>'.$product_data['cat.cat_4'].'>'.$product_data['cat.cat_5'].'>'.$product_data['cat.cat_6'].'>'.$product_data['cat.cat_7'].'>'.$product_data['cat.cat_8'].'>'.$product_data['cat.cat_9'].'>'.$product_data['cat.cat_10'].'>'.$product_data['cat.cat_11'];
                    $category_ids = [];
                    $categories_groups = explode( '>', $categories );
                    foreach ( $categories_groups as $categories_group ) {
                        $categories_array = explode( '|', $categories_group );
                        $parent_id = 0;
                        // print_r($categories_array);
                        foreach ( $categories_array as $category_name ) {
                            $category_name = trim( $category_name );

                            // Check if the category already exists
                            $existing_category = get_term_by( 'name', $category_name, 'product_cat' );
                            if ( $existing_category ) {
                                $category_ids[] = $existing_category->term_id;
                                $parent_id = $existing_category->term_id;
                            } else {
                                // Create the category
                                $new_category = wp_insert_term( $category_name, 'product_cat', [
                                    'parent' => $parent_id
                                ] );
                                // print_r($new_category);
                                if ( ! is_wp_error( $new_category ) ) {
                                    $category_ids[] = $new_category['term_id'];
                                    $parent_id = $new_category['term_id'];
                                }
                            }
                        }
                    }
                    
                    $product->set_category_ids( $category_ids );

                    $feature_image_url = $product_data['images.0.filename'];
                    $image_1 = isset($product_data['images.1.filename']) ? $product_data['images.1.filename'] : null;
                    $image_2 = isset($product_data['images.2.filename']) ? $product_data['images.2.filename'] : null;
                    $image_3 = isset($product_data['images.3.filename']) ? $product_data['images.3.filename'] : null;
                    $image_4 = isset($product_data['images.4.filename']) ? $product_data['images.4.filename'] : null;
                    $image_5 = isset($product_data['images.5.filename']) ? $product_data['images.5.filename'] : null;
                    $image_6 = isset($product_data['images.6.filename']) ? $product_data['images.6.filename'] : null;
                    $image_7 = isset($product_data['images.7.filename']) ? $product_data['images.7.filename'] : null;
                    $image_8 = isset($product_data['images.8.filename']) ? $product_data['images.8.filename'] : null;
                    $image_9 = isset($product_data['images.9.filename']) ? $product_data['images.9.filename'] : null;
                    $image_10 = isset($product_data['images.10.filename']) ? $product_data['images.10.filename'] : null;
                    $image_11 = isset($product_data['images.11.filename']) ? $product_data['images.11.filename'] : null;

                    $gallery_image_url = $image_1.','.$image_2.','.$image_3.','.$image_4.','.$image_5.','.$image_6.','.$image_7.','.$image_8.','.$image_9.','.$image_10.','.$image_11;
                    $gallery_image_urls = explode(',', $gallery_image_url);
                    $feature_image_id = WP_Image_Uploader::upload_image($feature_image_url);
                    // Set feature image
                    // $feature_image_id = upload_image_to_media_library($feature_image_url);
                    
                    if (is_wp_error($feature_image_id)) {
                        echo 'Error setting feature image: ' . $feature_image_id->get_error_message();
                    } else {
                        $product->set_image_id($feature_image_id);
                    }
                        $product->set_image_id($feature_image_id);
                    
                    // Set gallery images
                    $gallery_image_ids = [];
                    foreach ($gallery_image_urls as $image_url) {
                        if($image_url !== ""){
                            // $image_id = upload_image_to_media_library($image_url);
                            $image_id = WP_Image_Uploader::upload_image($image_url);
                        }
                        if (!is_wp_error($image_id)) {
                            $gallery_image_ids[] = $image_id;
                        }
                    }

                    $product->set_gallery_image_ids($gallery_image_ids);
                    $wholesale_price = $product_data['wholesale_price'];
                    $margin = 0.60 ; // 40% margin
                    $selling_price = ($wholesale_price / $margin);
                    $product->set_regular_price($selling_price);
                    // Set stock quantity
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($product_data['qty_avail']);
                    $manufacurer = $product_data['manufacturer'];
                    $color = $product_data['Color'];
                    $product_attributes = ['Manufacurer', 'Color'];
                    $attribute_value = [$manufacurer, $color];
                    $product_id = $product->save();


                    update_post_meta($product_id, 'upc', $product_data['upc']);
                    update_post_meta($product_id, 'special_feature', $product_data['special_features']);
                    update_post_meta($product_id, 'how_to_use', $product_data['how_to_use']);
                    update_post_meta($product_id, 'how_to_clean', $product_data['how_to_clean']);
                    update_post_meta($product_id, 'purpose', $product_data['purpose']);
                    update_post_meta($product_id, 'manufacturer', $product_data['manufacturer']);
                    update_post_meta($product_id, 'color', $product_data['Color']);
                    update_post_meta($product_id, 'power', $product_data['Power']);
                    update_post_meta($product_id, 'included', $product_data['Included']);
                    update_post_meta($product_id, 'feature', $product_data['Feature']);
                    update_post_meta($product_id, 'supplier_name', "Honey's Place");
                }
            }
            
            }
            return $current_row > $offset + $limit;   // Return true if there are more rows to process, false otherwise
        }
    }
}
