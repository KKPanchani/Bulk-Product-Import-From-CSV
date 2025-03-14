<?php
if (!class_exists('WP_Admin_Settings')) {

    class WP_Admin_Settings {

        public static function init() {
            add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
            add_action('admin_init', [__CLASS__, 'register_settings']);
            add_action('wp_ajax_import_products', [__CLASS__, 'handle_import_button_click']);
            // add_action('wp_ajax_import_products_cron', [__CLASS__, 'handle_cron_import_button_click']);
            add_action('wp_ajax_check_import_progress', [__CLASS__, 'check_import_progress']);
            add_action('wp_ajax_delete_unwanted_products', [__CLASS__, 'delete_unwanted_products']);
        }

        public static function add_admin_menu() {
            add_options_page(
                'Product Importer Settings',
                'Product Importer',
                'manage_options',
                'wp-product-importer',
                [__CLASS__, 'settings_page']
            );
        }

        public static function register_settings() {
            register_setting('wp_product_importer_settings', 'wp_product_importer_options');
            
            add_settings_section(
                'wp_product_importer_section',
                'Supplier Settings',
                null,
                'wp-product-importer'
            );

            add_settings_field(
                'wp_product_importer_supplier_url',
                'Supplier URL',
                [__CLASS__, 'supplier_url_field_callback'],
                'wp-product-importer',
                'wp_product_importer_section'
            );
        }

        public static function supplier_url_field_callback() {
            $options = get_option('wp_product_importer_options');
            ?>
            <input type="text" name="wp_product_importer_options[wp_product_importer_supplier_url]"
                   value="<?php echo isset($options['wp_product_importer_supplier_url']) ? esc_attr($options['wp_product_importer_supplier_url']) : ''; ?>"
                   class="regular-text">
            <?php
        }

        public static function settings_page() {
            ?>
            <div class="wrap">
                <h1>Product Importer Settings</h1>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('wp_product_importer_settings');
                    do_settings_sections('wp-product-importer');
                    submit_button();
                    ?>
                </form>
        
                <h2>Import Products</h2>
                <button id="import-products-button" class="button button-primary">Import Now</button>
                <div id="import-status"></div>
        
                <h3>Import Progress</h3>
                <div id="progress-bar" style="width: 100%; background: #f1f1f1; border: 1px solid #ccc;">
                    <div id="progress-bar-fill" style="width: 0%; height: 30px; background: #E43C85; text-align: center; color: #fff; line-height: 30px;">
                        0%
                    </div>
                </div>
            </div>
            <div class="wrap">
                <h2>Delete Unwanted Products</h2>
                <p>Click the button below to remove products that are not in the latest CSV import.</p>
                <button id="delete-products-btn" class="button button-primary">Delete Unwanted Products</button>
                <div id="delete-status"></div>
            </div>
        
            <script type="text/javascript">
                document.getElementById('import-products-button').addEventListener('click', function() {
                    document.getElementById('import-status').textContent = 'Importing...';

                    function importNextBatch() {
                        var data = {
                            action: 'import_products'
                        };

                        jQuery.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                console.log(response.data.message);
                                checkProgress();
                            } else {
                                console.error(response.data.message || 'An error occurred.');
                            }
                        }).fail(function(jqXHR, textStatus, errorThrown) {
                            console.error('AJAX error: ' + textStatus + ' - ' + errorThrown);
                        });
                    }


                    function checkProgress() {
                        jQuery.post(ajaxurl, { action: 'check_import_progress' }, function(response) {
                            if (response.success) {
                                var progress = response.data.progress;
                                var progressBarFill = document.getElementById('progress-bar-fill');
                                progressBarFill.style.width = progress + '%';
                                progressBarFill.textContent = progress + '%';

                                if (progress < 100) {
                                    importNextBatch();
                                } else {
                                    update_option('wp_csv_import_offset',0);
                                    document.getElementById('import-status').textContent = 'Import completed!';
                                }
                            }
                        });
                    }

                    importNextBatch();
                });

                document.getElementById('delete-products-btn').addEventListener('click', function($) {
                    document.getElementById('delete-status').textContent = 'Deleting...';

                    function DeleteProduct() {
                        var data = {
                            action: 'delete_unwanted_products'
                        };

                        jQuery.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                document.getElementById('delete-status').textContent = response.data.message;
                            } else {
                                document.getElementById('delete-status').textContent = response.data.message;
                            }
                        }).fail(function(jqXHR, textStatus, errorThrown) {
                            document.getElementById('delete-status').textContent = textStatus+'----0'+errorThrown; 
                        });
                    }
                    DeleteProduct();
                })
            </script>
            <?php
        }

        public static function handle_import_button_click() {
            try {
                if (!current_user_can('manage_options')) {
                    throw new Exception('Permission denied.');
                }
        
                // Reset offset if first run or continue from saved offset
                $current_offset = get_option('wp_csv_import_offset', 0);
        
                // Process batch
                $result = WP_Product_Importer::import_product($current_offset);
        
                // Increment offset and save progress
                $new_offset = $current_offset + 10; // Assuming batch size is 50
                update_option('wp_csv_import_offset', $new_offset);
        
                wp_send_json_success(['message' => 'Batch processed.', 'new_offset' => $new_offset]);
            } catch (Exception $e) {
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        }
        

        public static function check_import_progress() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Permission denied.']);
                return;
            }
        
            $current_offset = get_option('wp_csv_import_offset', 0);
            $total_rows = get_option('wp_product_importer_total_rows', 1000); // Default value if not set
            $progress = ($total_rows > 0) ? round(($current_offset / $total_rows) * 100, 2) : 0;
            wp_send_json_success(['progress' => $progress]);
        }

        public static function delete_unwanted_products() {

            $upload_dir = wp_upload_dir();
            $csv_file_path = $upload_dir['basedir'] . '/' . date('Y') . '/product-data/data.csv';

            if (!file_exists($csv_file_path)) {
                wp_send_json_error(['message' =>'CSV file does not exist: ' . $csv_file_path]);
                return;
            }
            // Read CSV and extract product SKUs
            $csv_data = array_map('str_getcsv', file($csv_file_path));
            $csv_headers = array_shift($csv_data); // Get headers
            $csv_skus = [];
           
            foreach ($csv_data as $row) {
                $row = array_map(fn($value) => mb_convert_encoding(trim($value), 'UTF-8', 'auto'), $row);

                if (count($row) !== count($csv_headers)) {
                    error_log("Skipping row due to mismatch: " . json_encode($row));
                    continue;
                }

                $row_data = array_combine($csv_headers, $row);
                if (!empty($row_data['sku'])) {
                    $csv_skus[] = $row_data['sku'];
                }
            }
        
            // Get all WooCommerce products
            $args = [
                'limit' => -1,
                'return' => 'ids',
            ];
            $products = wc_get_products($args);
        
            foreach ($products as $product_id) {
                $product = wc_get_product($product_id);
                $sku = $product->get_sku();
        
                if (!in_array($sku, $csv_skus)) {
                    wp_delete_post($product_id, true); // Permanently delete
                    error_log("Deleted product ID: $product_id (sku: $sku)");
                }
            }
        
            error_log("Unwanted products deleted.");
        }

        
    }
}
 