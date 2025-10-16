<?php
/**
 * Plugin Name: Custom Fit Polished (Inline Fields)
 * Description: Custom-size measurements with an inline form instead of a modal, plus a context-aware system for global and product-specific fields.
 * Version: 3.5.4
 * Author: Gemini Pro
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * =================================================================
 * Core Class: Handles front-end display, logic, and cart/order processing.
 * =================================================================
 */
class CFP_Core {
    private $meta_key = 'csvp_custom_measurements';
    private $fallback_fields = [
        'chest'       => 'Chest',
        'waist'       => 'Waist',
        'back_length' => 'Back Length',
    ];

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'print_trigger_zone' ], 5 );

        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'save_cart_item_data' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
        add_filter( 'woocommerce_cart_item_name', [ $this, 'append_measurements_to_cart_item_name' ], 20, 3 );

        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_item_meta' ], 10, 4 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'save_user_meta_from_order' ], 10, 3 );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 3 );

        add_action( 'before_woocommerce_init', function() {
            if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            }
        } );
    }

    private function get_settings( $product_id = 0 ) {
        $opt = get_option( 'woocommerce_csvp_settings', [] );

        $settings = [
            'unit'                           => in_array($opt['unit'] ?? 'cm', ['cm','in'], true) ? $opt['unit'] : 'cm',
            'attribute_slug'                 => !empty($opt['attribute_slug']) ? sanitize_title($opt['attribute_slug']) : 'pa_size',
            'custom_label_text'              => isset($opt['custom_label_text']) ? strtolower(trim($opt['custom_label_text'])) : 'custom',
            'instructional_image_url'        => isset($opt['instructional_image_url']) ? esc_url_raw($opt['instructional_image_url']) : '',
            'validation_error_message'       => isset($opt['validation_error_message']) && $opt['validation_error_message'] !== '' ? $opt['validation_error_message'] : 'Please ensure all fields are filled and values are within the acceptable range.',
            'show_status_indicator'          => ($opt['show_status_indicator'] ?? '') === 'yes' ? 'yes' : 'no',
            'required_order_unit'            => in_array($opt['required_order_unit'] ?? 'cm', ['cm','in'], true) ? $opt['required_order_unit'] : 'cm',
            'admin_customer_link'            => ($opt['admin_customer_link'] ?? '') === 'yes' ? 'yes' : 'no',
            'feature_visibility'             => $opt['feature_visibility'] ?? 'all',
            'selected_categories'            => $opt['selected_categories'] ?? [],
            'selected_products'              => $opt['selected_products'] ?? '',
            'enable_product_specific_fields' => ($opt['enable_product_specific_fields'] ?? '') === 'yes' ? 'yes' : 'no',
        ];

        if ( ! $product_id && is_product() ) {
            global $product;
            if ( $product && is_a( $product, 'WC_Product' ) ) {
                $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            }
        }

        $master_fields_list = !empty($opt['master_fields']) && is_array($opt['master_fields']) ? $opt['master_fields'] : [];
        $master_fields_assoc = [];
        foreach($master_fields_list as $field) {
            if (!empty($field['key']) && !empty($field['label'])) {
                $master_fields_assoc[sanitize_key($field['key'])] = sanitize_text_field($field['label']);
            }
        }

        $fields_to_use = [];
        $minmax_to_use = [];

        if ( $settings['enable_product_specific_fields'] === 'yes' && $product_id ) {
            $product_config = get_post_meta( $product_id, '_csvp_product_config', true );
            if ( !empty($product_config) && is_array($product_config) ) {
                foreach($product_config as $key => $config) {
                    if (isset($master_fields_assoc[$key])) {
                        $fields_to_use[$key] = $master_fields_assoc[$key];
                        $minmax_to_use[$key] = [
                            'min' => $config['min'] ?? null,
                            'max' => $config['max'] ?? null
                        ];
                    }
                }
            }
        }

        if ( empty($fields_to_use) ) {
            $global_defaults = $this->json_to_array( $opt['fields'] ?? '' );
            if (!empty($global_defaults) && is_array($global_defaults)) {
                $global_minmax = $this->json_to_array( $opt['fields_min_max'] ?? '' );
                foreach($global_defaults as $key => $label) {
                    if (isset($master_fields_assoc[$key])) {
                        $fields_to_use[$key] = $label;
                        $minmax_to_use[$key] = $global_minmax[$key] ?? null;
                    }
                }
            } else {
                 $fields_to_use = $this->fallback_fields;
            }
        }

        $settings['fields'] = $fields_to_use;
        $settings['fields_min_max'] = $minmax_to_use;

        return $settings;
    }

    private function json_to_array( $json ) {
        $arr = json_decode( (string) $json, true );
        return is_array($arr) ? $arr : [];
    }

    private function is_feature_visible() {
        if ( ! is_product() ) return false;

        $product_to_check = wc_get_product( get_the_ID() );
        if ( ! $product_to_check ) return false;

        $settings = $this->get_settings( $product_to_check->get_id() );
        $visibility = $settings['feature_visibility'];

        switch ( $visibility ) {
            case 'disabled': return false;
            case 'all': return true;
            case 'categories':
                $selected_categories = $settings['selected_categories'];
                if ( empty( $selected_categories ) ) return false;
                $product_categories = wp_get_post_terms( $product_to_check->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
                return !empty( array_intersect( $selected_categories, $product_categories ) );
            case 'products':
                $selected_products = array_filter( array_map( 'trim', explode( ',', $settings['selected_products'] ) ) );
                if ( empty( $selected_products ) ) return false;
                return in_array( (string) $product_to_check->get_id(), $selected_products, true );
            default: return true;
        }
    }

    public function enqueue_assets() {
        if ( ! is_product() || ! $this->is_feature_visible() ) return;

        $s = $this->get_settings();
        $ver = '3.5.4'; // Version bump for cache busting

        wp_register_style( 'cfp-inline', false, [], $ver );

        $css = '
        /* Styles for the inline container */
        #csvp-inline-fields-container{display:none; border:1px solid #dfe3e8; border-radius:12px; padding:20px; margin:20px 0; background:#fdfdfd;}
        #csvp-inline-fields-container h3{margin:0 0 8px;font-size:20px;letter-spacing:.2px}
        #csvp-inline-fields-container .csvp-note{font-size:13px;color:#4b5563;margin:0 0 16px}

        /* Re-usable styles from the old modal */
        .csvp-grid{display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px}
        .csvp-field{margin-bottom:0}
        .csvp-field label{font-weight:600;margin-bottom:8px;display:block;font-size:14px;color:#374151}
        .csvp-input-wrapper{display:flex;align-items:center;gap:12px}
        .csvp-input{flex:1;position:relative}
        .csvp-input input[type=number]{width:100%;padding:12px 16px;border:1px solid #dfe3e8;border-radius:8px;font-size:14px;transition:all .15s ease;background:#fff}
        .csvp-input input[type=number]:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .csvp-input input[type=number]:hover{border-color:#9ca3af}
        .csvp-unit{font-size:13px;font-weight:500;color:#4b5563;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;min-width:40px;text-align:center;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.05)}
        .csvp-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;margin-bottom:0;padding-top:16px;border-top:1px solid #f1f5f9}
        .csvp-btn{background:#2563eb;color:#fff;border:none;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;transition:all .15s ease}
        .csvp-btn:hover{background:#1d4ed8;transform:translateY(-1px)}
        #csvp_error{display:none;color:#dc2626;margin-top:12px;font-size:13px;padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px}

        /* Trigger zone elements */
        .csvp-trigger-zone{margin:12px 0 16px;}
        .csvp-badge{display:none;align-items:center;gap:8px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:8px 12px;border-radius:20px;font-size:13px;transition:all .15s ease; width: fit-content; margin-top: 10px;}
        .csvp-badge .dot{width:8px;height:8px;background:#10b981;border-radius:50%;animation:pulse 2s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
        .csvp-summary{display:none;font-size:13px;color:#374151;line-height:1.4; margin-top: 10px;}
        .csvp-summary .sep{color:#9ca3af;margin:0 8px}
        .csvp-success-message{background:#ecfdf5;color:#065f46;padding:10px 16px;border-left: 4px solid #10b981; border-radius:4px;margin-bottom:12px;font-size:14px}
        ';
        wp_add_inline_style( 'cfp-inline', $css );
        wp_enqueue_style( 'cfp-inline' );

        $user_id = get_current_user_id();
        $prefill = [];
        if ( $user_id && is_array($s['fields']) ) {
            foreach ( array_keys($s['fields']) as $key ) {
                $raw = get_user_meta( $user_id, 'csvp_um_'.sanitize_key($key), true );
                if ( is_array($raw) && isset($raw['value']) ) $prefill[$key] = $raw;
            }
        }

        $cfg = [
            'fieldKeys'         => is_array($s['fields']) ? array_keys($s['fields']) : [],
            'fieldLabels'       => $s['fields'],
            'minmax'            => $s['fields_min_max'],
            'customLabel'       => $s['custom_label_text'],
            'defaultUnit'       => $s['unit'],
            'attributeSlug'     => $s['attribute_slug'],
            'showStatus'        => $s['show_status_indicator'],
            'validationMessage' => $s['validation_error_message'],
            'prefill'           => $prefill,
        ];
        $cfg_json = wp_json_encode( $cfg );

        $js = <<<JS
        jQuery(function($){
            const cfg = {$cfg_json};
            const fieldsContainer = $('#csvp-inline-fields-container');
            const hidden = $('#csvp_custom_measurements_input');
            const badge = $('#csvp_saved_badge');
            const summary = $('#csvp_inline_summary');

            const fieldKeys = cfg.fieldKeys||[];
            const labels = cfg.fieldLabels||{};
            const minmax = cfg.minmax||{};
            const customLabel = (cfg.customLabel||'custom').toLowerCase();
            const defaultUnit = cfg.defaultUnit||'cm';
            const attrSlug = cfg.attributeSlug||'pa_size';
            const showStatus = (cfg.showStatus==='yes');
            const validationMsg = cfg.validationMessage||'Please ensure all fields are filled and values are within the acceptable range.';
            let currentUnit = defaultUnit;

            function eqCustom(v,t){
                v=(v||'').toString().toLowerCase().trim();
                t=(t||'').toString().toLowerCase().trim();
                return v===customLabel||t.indexOf(customLabel)!==-1||v==='custom'||t.indexOf('custom')!==-1;
            }

            function isCustomSelected(){
                let f=false;
                const form=$('form.variations_form, form.cart');
                form.find('select[name^="attribute_"]').each(function(){
                    const name=$(this).attr('name')||'';
                    const val=$(this).val();
                    const txt=$(this).find('option:selected').text();
                    if (name.indexOf(attrSlug)!==-1||attrSlug===''){ if (eqCustom(val,txt)) f=true; }
                    else { if (eqCustom(val,txt)) f=true; }
                });
                form.find('input[name^="attribute_"]').each(function(){
                    const type=$(this).attr('type');
                    const name=$(this).attr('name')||'';
                    if (type==='radio'&&$(this).is(':checked')){
                        const val=$(this).val(); const txt=$(this).closest('label').text();
                        if (name.indexOf(attrSlug)!==-1||attrSlug===''){ if (eqCustom(val,txt)) f=true; }
                        else { if (eqCustom(val,txt)) f=true; }
                    }
                    if (type==='hidden'){ const val=$(this).val(); if (eqCustom(val,val)) f=true; }
                });
                return f;
            }

            function showFields(){
                if (hidden.val()===''){
                    const pre = cfg.prefill||{};
                    for (let k of fieldKeys){
                        $('#csvp_'+k).val(pre[k]&&pre[k].value?pre[k].value:'');
                    }
                    if (pre && pre.unit) currentUnit = pre.unit;
                }
                fieldsContainer.slideDown();
            }
            function hideFields(){ fieldsContainer.slideUp(); }

            function renderInlineSummary(data){
                if (!data || !data.measurements) return;
                const parts=[];
                for (let k of fieldKeys){
                    if (data.measurements[k] && data.measurements[k].value){
                        const v = data.measurements[k].value;
                        const u = data.measurements[k].unit || data.unit || defaultUnit;
                        const lab = labels[k] || k;
                        parts.push(lab+' '+v+' '+u);
                    }
                }
                if (parts.length){
                    summary.html(parts.map((p)=>'<span>'+p+'</span>').join('<span class="sep">•</span>'));
                    summary.show();
                    if (showStatus) badge.show();
                }
            }
            function clearInlineSummary(){
                summary.hide().empty();
                if (showStatus) badge.hide();
            }

            function checkVariationSelection() {
                if (isCustomSelected()){
                    showFields();
                    if (hidden.val() === '') {
                        clearInlineSummary();
                    }
                } else {
                    hidden.val('');
                    hideFields();
                    clearInlineSummary();
                }
            }

            $('.variations_form').on('show_variation found_variation', function() {
                checkVariationSelection();
            }).on('hide_variation', function() {
                hidden.val('');
                hideFields();
                clearInlineSummary();
            });
            $(document).on('change', '.variations_form select[name^="attribute_"], .variations_form input[name^="attribute_"]', checkVariationSelection);

            $(document).on('submit','form.cart',function(e){
                if (isCustomSelected() && (hidden.val()==='' || hidden.val()===null)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    showFields();
                    $('html, body').animate({
                        scrollTop: fieldsContainer.offset().top - 100
                    }, 500);
                    alert('Please save your custom measurements before adding to cart.');
                    return false;
                }
            });

            /*******************************************************
             * !! BUG FIX: Robust Type Parsing !!
             * This function now correctly handles string/null values from PHP
             * by using !isNaN() to ensure a value is a valid number before comparing.
             *******************************************************/
            function inRange(k, f){
                const m = minmax[k];

                if (isNaN(f) || f <= 0) return false;
                if (!m || (m.min === null && m.max === null)) return true;

                const mn = (m.min !== null && !isNaN(parseFloat(m.min))) ? parseFloat(m.min) : null;
                const mx = (m.max !== null && !isNaN(parseFloat(m.max))) ? parseFloat(m.max) : null;

                if (mn !== null && f < mn) return false;
                if (mx !== null && f > mx) return false;

                return true;
            }

            $('#csvp_save_btn').on('click', function(e){
                e.preventDefault();
                let ok = true;
                let data = { unit: currentUnit, measurements: {} };
                for (let k of fieldKeys){
                    const val = $('#csvp_'+k).val();
                    const f = parseFloat(val);
                    if (!val || !inRange(k,f)){
                        ok=false;
                        $('#csvp_'+k).css({'border':'1px solid #dc2626','background':'#fef2f2'});
                    } else {
                        $('#csvp_'+k).css({'border':'1px solid #dfe3e8','background':'#fff'});
                        data.measurements[k]={value:f.toString(),unit:currentUnit};
                    }
                }
                if (!ok){ $('#csvp_error').text(validationMsg).show(); return; }

                $('#csvp_error').hide();
                hidden.val(JSON.stringify(data));
                hideFields();
                renderInlineSummary(data);

                $('.csvp-success-message').remove();
                $('<div class="csvp-success-message">✓ Measurements saved! You can now add the product to cart.</div>')
                    .insertBefore('.csvp-trigger-zone')
                    .delay(4000)
                    .fadeOut(function(){ $(this).remove(); });
            });

            if ($('form.cart').not('.variations_form').length > 0 || isCustomSelected()) {
                 checkVariationSelection();
            }
        });
JS;
        wp_add_inline_script( 'jquery-core', $js, 'after' );
    }

    public function print_trigger_zone() {
        if ( ! $this->is_feature_visible() ) return;

        $s = $this->get_settings();
        ?>
        <div class="csvp-trigger-zone">
            <span id="csvp_saved_badge" class="csvp-badge"><span class="dot"></span>Measurements Saved</span>
            <div id="csvp_inline_summary" class="csvp-summary"></div>
        </div>

        <div id="csvp-inline-fields-container">
            <h3>Enter Custom Measurements</h3>
            <p class="csvp-note">Please enter your measurements for a perfect fit. Values must be positive and within allowed range.</p>

            <?php if ( $s['instructional_image_url'] ) : ?>
                <div style="margin:6px 0 16px">
                    <img src="<?php echo esc_url($s['instructional_image_url']); ?>" alt="Measurement Guide" style="max-width:100%;height:auto;border:1px solid #eef0f3;border-radius:10px;">
                </div>
            <?php endif; ?>

            <div class="csvp-grid">
            <?php if ( is_array( $s['fields'] ) ): foreach ( $s['fields'] as $key => $label ): ?>
                <div class="csvp-field">
                    <label for="csvp_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                    <div class="csvp-input-wrapper">
                        <div class="csvp-input">
                            <input class="csvp-control" type="number" id="csvp_<?php echo esc_attr($key); ?>" step="0.1" min="0.1" inputmode="decimal" />
                        </div>
                        <div class="csvp-unit"><?php echo esc_html($s['unit']); ?></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
            </div>

            <div id="csvp_error" style="display:none;"><?php echo esc_html($s['validation_error_message']); ?></div>

            <div class="csvp-actions">
                <button id="csvp_save_btn" class="csvp-btn" type="button">Save Measurements</button>
            </div>
        </div>

        <input type="hidden" name="<?php echo esc_attr($this->meta_key); ?>" id="<?php echo esc_attr($this->meta_key); ?>_input" value="">
        <?php
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        $s = $this->get_settings( $product_id );
        $is_custom = false;

        foreach ( $_REQUEST as $k => $v ) {
            if ( strpos($k, 'attribute_') === 0 && is_string($v) ) {
                $vv = strtolower($v);
                if ( $vv === $s['custom_label_text'] || stripos($vv, $s['custom_label_text']) !== false || $vv === 'custom' ) {
                    $is_custom = true;
                    break;
                }
            }
        }
        if ( $is_custom && empty($_REQUEST[ $this->meta_key ]) ) {
            wc_add_notice( 'Please enter your measurements before adding this item to cart.', 'error' );
            return false;
        }
        return $passed;
    }

    public function save_cart_item_data( $cart_item_data, $product_id, $variation_id = 0 ) {
        if ( empty($_POST[ $this->meta_key ]) ) return $cart_item_data;
        $payload = json_decode( wp_unslash($_POST[ $this->meta_key ]), true );
        if ( ! is_array($payload) || ! isset($payload['measurements']) ) return $cart_item_data;

        $s = $this->get_settings( $product_id );
        $allowed = is_array($s['fields']) ? array_keys($s['fields']) : [];
        $clean = ['unit'=> in_array($payload['unit']??'cm',['cm','in'],true)?$payload['unit']:'cm','measurements'=>[]];

        foreach ( $payload['measurements'] as $k => $m ) {
            if ( ! in_array($k, $allowed, true) ) continue;
            $value = is_array($m) && isset($m['value']) ? sanitize_text_field($m['value']) : sanitize_text_field((string)$m);
            $unit  = is_array($m) && isset($m['unit']) ? sanitize_text_field($m['unit']) : $clean['unit'];
            if ( $value !== '' ) $clean['measurements'][$k] = ['value'=>$value,'unit'=>$unit];
        }
        if ( ! empty($clean['measurements']) ) {
            $cart_item_data[ $this->meta_key ] = $clean;
            $cart_item_data[ $this->meta_key . '_unique' ] = md5( wp_json_encode($clean) . microtime(true) );
        }
        return $cart_item_data;
    }

    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( ! isset($cart_item[ $this->meta_key ]['measurements']) ) return $item_data;

        $product_id = $cart_item['product_id'];
        $s = $this->get_settings( $product_id );

        foreach($item_data as $key => $data) {
            if (isset($data['key']) && strtolower($data['key']) === 'size' && strtolower($data['value']) === 'custom') {
                unset($item_data[$key]);
            }
        }

        foreach ( $cart_item[ $this->meta_key ]['measurements'] as $k=>$m ) {
            $label = $s['fields'][$k] ?? ucwords(str_replace('_',' ',$k));
            $val   = is_array($m)&&isset($m['value']) ? $m['value'].' '.($m['unit']??'') : (string)$m;
            $item_data[] = ['name'=>$label,'value'=>$val];
        }
        return $item_data;
    }

    public function append_measurements_to_cart_item_name( $name, $cart_item, $cart_item_key ) {
        if ( ! isset($cart_item[ $this->meta_key ]['measurements']) ) return $name;

        $product_id = $cart_item['product_id'];
        $s = $this->get_settings( $product_id );

        $parts=[];
        foreach ( $cart_item[ $this->meta_key ]['measurements'] as $k=>$m ) {
            $label = $s['fields'][$k] ?? ucwords(str_replace('_',' ',$k));
            $val   = is_array($m)&&isset($m['value']) ? $m['value'].' '.($m['unit']??'') : (string)$m;
            $parts[] = esc_html($label.': '.$val);
        }
        return $name.'<div class="csvp-measurements" style="font-size:13px;color:#555;margin-top:6px;">'.implode('<br>',$parts).'</div>';
    }

    public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( ! isset($values[ $this->meta_key ]) ) return;
        if ( ! is_a( $item, 'WC_Order_Item' ) ) return;

        $product_id = $item->get_product_id();
        $s = $this->get_settings( $product_id );
        $required_unit = $s['required_order_unit'];

        $data = $values[ $this->meta_key ];
        $summary = [];
        foreach ( $data['measurements'] as $k=>$m ) {
            $label = $s['fields'][$k] ?? ucwords(str_replace('_',' ',$k));
            $value = is_array($m)&&isset($m['value']) ? (float)$m['value'] : (float)$m;
            $unit  = is_array($m)&&isset($m['unit']) ? $m['unit'] : $data['unit'];
            if ( $unit !== $required_unit ) {
                if ( $unit==='cm' && $required_unit==='in' ) $value = round($value/2.54,2);
                elseif ( $unit==='in' && $required_unit==='cm' ) $value = round($value*2.54,1);
                $unit = $required_unit;
            }
            $display = $value.' '.$unit;
            $item->add_meta_data('_csvp_'.$k, $display, true);
            $item->add_meta_data($label, $display, false);
            $summary[] = $label.': '.$display;
        }
        if ( $summary ) $item->add_meta_data('_csvp_measurements_summary', implode("\n",$summary), true);
    }

    public function save_user_meta_from_order( $order_id, $posted_data, $order ) {
        if ( ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) return;
        }

        $uid = $order->get_user_id();
        if ( ! $uid ) return;

        foreach ( $order->get_items() as $item ) {
            if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;

            $summary = $item->get_meta('_csvp_measurements_summary', true);
            if ( $summary ) {
                $s = $this->get_settings( $item->get_product_id() );
                if( is_array($s['fields']) ) {
                    foreach ( array_keys($s['fields']) as $k ) {
                        $raw = $item->get_meta('_csvp_'.$k, true);
                        if ( $raw ) {
                            $parts = preg_split('/\s+/', $raw);
                            update_user_meta( $uid, 'csvp_um_'.sanitize_key($k), ['value'=>$parts[0]??'','unit'=>$parts[1]??'cm'] );
                        }
                    }
                }
                break;
            }
        }
    }
}


/**
 * =================================================================
 * Admin Class: Handles settings page, product meta boxes, and admin scripts.
 * =================================================================
 */
class CFP_Admin {
    private $menu_slug = 'csvp-custom-fit-settings';
    private $option_key = 'woocommerce_csvp_settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 50 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        add_action( 'add_meta_boxes', [ $this, 'add_product_meta_box' ] );
        add_action( 'save_post_product', [ $this, 'save_product_meta_box' ], 10, 1 );
    }

    public function add_menu(){
        add_submenu_page('woocommerce','Custom Fit','Custom Fit','manage_options',$this->menu_slug,[$this,'render_page']);
    }

    public function register_settings(){
        register_setting('csvp_settings_group', $this->option_key, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $new_input = [];
        if (empty($input) || !is_array($input)) {
            return $new_input;
        }

        foreach ($input as $key => $value) {
            if ($key === 'master_fields') {
                $new_input[$key] = [];
                if (is_array($value)) {
                    foreach($value as $field) {
                        if (!empty($field['key']) && !empty($field['label'])) {
                            $new_input[$key][] = [
                                'key' => sanitize_key($field['key']),
                                'label' => sanitize_text_field($field['label'])
                            ];
                        }
                    }
                }
            } elseif (is_array($value)) {
                $new_input[$key] = $value;
            } else {
                $new_input[$key] = sanitize_text_field($value);
            }
        }
        return $new_input;
    }

    public function enqueue_admin_scripts( $hook ) {
        $screen = get_current_screen();
        if ( 'woocommerce_page_' . $this->menu_slug !== $hook && (is_null($screen) || 'product' !== $screen->post_type) ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui-sortable');
        wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true );
        wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0' );

        $js_option_key = esc_js($this->option_key);
        $inline_js = "
        jQuery(document).ready(function($) {
            if ($('#csvp_categories_select').length) {
                $('#csvp_categories_select').select2({
                    placeholder: 'Select categories...',
                    allowClear: true
                });
            }

            $('input[name=\"{$js_option_key}[feature_visibility]\"]').change(function() {
                var selectedValue = $(this).val();
                $('.csvp-selection-list').hide();
                if (selectedValue === 'categories') {
                    $('#csvp-categories-section').show();
                } else if (selectedValue === 'products') {
                    $('#csvp-products-section').show();
                }
            }).trigger('change');

            const updateRowIndexes = function() {
                $('#csvp-master-fields-tbody tr').each(function(index) {
                    $(this).find('input').each(function() {
                        const name = $(this).attr('name');
                        if (name) {
                            $(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                        }
                    });
                });
            };

            $('#csvp_add_master_field').on('click', function(e) {
                e.preventDefault();
                const tbody = $('#csvp-master-fields-tbody');
                const newRow = tbody.find('tr:first-child').clone();
                newRow.find('input').val('');
                tbody.append(newRow);
                updateRowIndexes();
            });

            $('#csvp-master-fields-tbody').on('click', '.csvp-remove-field', function(e) {
                e.preventDefault();
                if ($('#csvp-master-fields-tbody tr').length > 1) {
                    $(this).closest('tr').remove();
                    updateRowIndexes();
                } else {
                    alert('You must have at least one field.');
                }
            });

            $('#csvp-master-fields-tbody').sortable({
                handle: '.csvp-sort-handle',
                axis: 'y',
                update: function() {
                    updateRowIndexes();
                }
            }).disableSelection();

            $('#csvp_product_fields_config').on('change', '.csvp-enable-field', function() {
                const isChecked = $(this).is(':checked');
                $(this).closest('tr').find('.csvp-limit-input').prop('disabled', !isChecked);
            });

            $('.csvp-enable-field').trigger('change');
        });";
        wp_add_inline_script( 'jquery-core', $inline_js );
    }

    public function add_product_meta_box() {
        $s = get_option($this->option_key, []);
        if ( ($s['enable_product_specific_fields'] ?? '') === 'yes' ) {
            add_meta_box(
                'csvp_custom_fields_meta',
                'Custom Fit Measurements Configuration',
                [ $this, 'render_product_meta_box' ],
                'product', 'normal', 'high'
            );
        }
    }

    public function render_product_meta_box( $post ) {
        wp_nonce_field( 'csvp_save_product_data', 'csvp_product_meta_nonce' );

        $global_settings = get_option($this->option_key, []);
        $master_fields_list = $global_settings['master_fields'] ?? [];
        $product_config = get_post_meta($post->ID, '_csvp_product_config', true);
        if (!is_array($product_config)) {
            $product_config = [];
        }
        ?>
        <p class="description">
            Enable the required measurements for THIS product. These settings will <strong>override</strong> the global defaults. Leave all fields unchecked to use global settings.
        </p>
        <style>
            #csvp_product_fields_config { border-collapse: collapse; width: 100%; }
            #csvp_product_fields_config th, #csvp_product_fields_config td { text-align: left; padding: 8px 10px; border: 1px solid #ddd; }
            #csvp_product_fields_config th { background-color: #f9f9f9; }
            #csvp_product_fields_config input[type="number"] { width: 100px; }
        </style>
        <table id="csvp_product_fields_config">
            <thead>
                <tr>
                    <th>Enable</th>
                    <th>Measurement Field</th>
                    <th>Min Value</th>
                    <th>Max Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($master_fields_list)): foreach ($master_fields_list as $field): ?>
                    <?php
                        $key = sanitize_key($field['key']);
                        $label = esc_html($field['label']);
                        $is_enabled = isset($product_config[$key]);
                        $min_val = $is_enabled ? ($product_config[$key]['min'] ?? '') : '';
                        $max_val = $is_enabled ? ($product_config[$key]['max'] ?? '') : '';
                    ?>
                    <tr>
                        <td>
                            <input
                                class="csvp-enable-field"
                                type="checkbox"
                                name="_csvp_enabled_fields[]"
                                value="<?php echo esc_attr($key); ?>"
                                <?php checked($is_enabled, true); ?>
                            />
                        </td>
                        <td>
                            <label><?php echo $label; ?> (<code><?php echo $key; ?></code>)</label>
                        </td>
                        <td>
                            <input
                                type="number"
                                class="short csvp-limit-input"
                                name="_csvp_limits[<?php echo esc_attr($key); ?>][min]"
                                value="<?php echo esc_attr($min_val); ?>"
                                step="any"
                                placeholder="e.g. 50"
                                <?php disabled(!$is_enabled); ?>
                            />
                        </td>
                        <td>
                            <input
                                type="number"
                                class="short csvp-limit-input"
                                name="_csvp_limits[<?php echo esc_attr($key); ?>][max]"
                                value="<?php echo esc_attr($max_val); ?>"
                                step="any"
                                placeholder="e.g. 80"
                                <?php disabled(!$is_enabled); ?>
                            />
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="4">No global fields defined. Please configure them in the <a href="<?php echo esc_url(admin_url('admin.php?page='.$this->menu_slug)); ?>">Custom Fit settings page</a>.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function save_product_meta_box( $post_id ) {
        if ( ! isset( $_POST['csvp_product_meta_nonce'] ) || ! wp_verify_nonce( $_POST['csvp_product_meta_nonce'], 'csvp_save_product_data' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;

        $enabled_fields = isset($_POST['_csvp_enabled_fields']) && is_array($_POST['_csvp_enabled_fields']) ? array_map('sanitize_key', $_POST['_csvp_enabled_fields']) : [];
        $limits = isset($_POST['_csvp_limits']) && is_array($_POST['_csvp_limits']) ? $_POST['_csvp_limits'] : [];

        $new_config = [];

        if ( !empty($enabled_fields) ) {
            foreach($enabled_fields as $key) {
                $min = isset($limits[$key]['min']) ? sanitize_text_field($limits[$key]['min']) : '';
                $max = isset($limits[$key]['max']) ? sanitize_text_field($limits[$key]['max']) : '';
                $new_config[$key] = [
                    'min' => ($min !== '') ? (float)$min : null,
                    'max' => ($max !== '') ? (float)$max : null,
                ];
            }
        }

        if (!empty($new_config)) {
            update_post_meta($post_id, '_csvp_product_config', $new_config);
        } else {
            delete_post_meta($post_id, '_csvp_product_config');
        }
    }

    public function render_page(){
        $s = get_option($this->option_key, []);
        $categories = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => false] );
        $master_fields = $s['master_fields'] ?? [['key' => 'chest', 'label' => 'Chest']];
        ?>
        <div class="wrap">
            <h1>Custom Fit Settings</h1>
            <style>
                .csvp-repeater-table { border-collapse: collapse; width: 100%; margin-top: 10px; }
                .csvp-repeater-table th, .csvp-repeater-table td { text-align: left; padding: 8px; border: 1px solid #ddd; }
                .csvp-repeater-table th { background-color: #f9f9f9; }
                .csvp-repeater-table input[type="text"] { width: 98%; }
                .csvp-repeater-table .actions { width: 80px; text-align: center; }
                .csvp-repeater-table .button { margin: 0 2px; }
                .csvp-sort-handle { cursor: move; vertical-align: middle; margin-right: 10px; color: #888; }
            </style>
            <form method="post" action="options.php">
                <?php
                settings_fields('csvp_settings_group');
                submit_button();
                ?>
                <table class="form-table" role="presentation">

                    <tr><th colspan="2"><h2>Global Configuration</h2></th></tr>
                    <tr>
                        <th>Enable Product-Specific Overrides</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[enable_product_specific_fields]" value="yes" <?php checked($s['enable_product_specific_fields']??'', 'yes'); ?>>
                                When checked, allows overriding global field settings on a per-product basis.
                            </label>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2>Master Measurement Fields</h2><p class="description">Define all possible measurement fields available for your store here. You will select from this list on each product page.</p></th></tr>
                    <tr>
                        <td colspan="2">
                            <table class="csvp-repeater-table">
                                <thead>
                                    <tr>
                                        <th style="width: 20px;"></th>
                                        <th>Field Key (unique, no spaces, e.g., <code>sleeve_length</code>)</th>
                                        <th>Field Label (e.g., <code>Sleeve Length</code>)</th>
                                        <th class="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="csvp-master-fields-tbody">
                                    <?php foreach ($master_fields as $i => $field): ?>
                                    <tr>
                                        <td><span class="dashicons dashicons-menu csvp-sort-handle"></span></td>
                                        <td><input type="text" name="<?php echo esc_attr($this->option_key); ?>[master_fields][<?php echo $i; ?>][key]" value="<?php echo esc_attr($field['key'] ?? ''); ?>" required /></td>
                                        <td><input type="text" name="<?php echo esc_attr($this->option_key); ?>[master_fields][<?php echo $i; ?>][label]" value="<?php echo esc_attr($field['label'] ?? ''); ?>" required /></td>
                                        <td class="actions"><button type="button" class="button button-small csvp-remove-field">Remove</button></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p style="margin-top:10px;"><button type="button" class="button" id="csvp_add_master_field">Add Field</button></p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2>Global Default Fields (Fallback)</h2><p class="description">These fields will be used if a product does not have its own specific configuration. These are legacy settings and it is recommended to configure fields per-product.</p></th></tr>
                    <tr>
                        <th>Fields JSON</th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->option_key); ?>[fields]" rows="5" cols="80" style="font-family:monospace"><?php echo esc_textarea($s['fields']??'{"chest":"Chest","waist":"Waist"}'); ?></textarea>
                            <p class="description">Format: {"field_key":"Field Label"}. Keys must match a key from the Master Fields list above.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Field Min/Max JSON</th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->option_key); ?>[fields_min_max]" rows="5" cols="80" style="font-family:monospace"><?php echo esc_textarea($s['fields_min_max']??'{"chest":{"min":60,"max":150}}'); ?></textarea>
                                <p class="description">Format: {"field_key":{"min":10, "max":100}}</p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2>Feature Visibility</h2></th></tr>
                    <tr>
                        <th>Enable Feature On</th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="<?php echo esc_attr($this->option_key); ?>[feature_visibility]" value="all" <?php checked($s['feature_visibility'] ?? 'all', 'all'); ?>> All Products</label><br>
                                <label><input type="radio" name="<?php echo esc_attr($this->option_key); ?>[feature_visibility]" value="categories" <?php checked($s['feature_visibility'] ?? '', 'categories'); ?>> Selected Categories</label><br>
                                <label><input type="radio" name="<?php echo esc_attr($this->option_key); ?>[feature_visibility]" value="products" <?php checked($s['feature_visibility'] ?? '', 'products'); ?>> Selected Products</label><br>
                                <label><input type="radio" name="<?php echo esc_attr($this->option_key); ?>[feature_visibility]" value="disabled" <?php checked($s['feature_visibility'] ?? '', 'disabled'); ?>> Disabled Globally</label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th>Selection List</th>
                        <td>
                            <div id="csvp-categories-section" class="csvp-selection-list" style="display:none;">
                                <select id="csvp_categories_select" name="<?php echo esc_attr($this->option_key); ?>[selected_categories][]" multiple="multiple" style="width: 100%; max-width: 500px;">
                                    <?php
                                    $selected_categories = $s['selected_categories'] ?? [];
                                    if ( !empty( $categories ) && ! is_wp_error( $categories ) ):
                                        foreach ( $categories as $category ) : ?>
                                            <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo in_array($category->term_id, $selected_categories, true) ? 'selected="selected"' : ''; ?>>
                                                <?php echo esc_html($category->name); ?>
                                            </option>
                                        <?php endforeach;
                                    endif; ?>
                                </select>
                            </div>
                            <div id="csvp-products-section" class="csvp-selection-list" style="display:none;">
                                <input type="text" name="<?php echo esc_attr($this->option_key); ?>[selected_products]" value="<?php echo esc_attr($s['selected_products'] ?? ''); ?>" class="regular-text" placeholder="e.g. 123, 456, 789"/>
                                <p class="description">Enter comma-separated Product IDs.</p>
                            </div>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2>General Settings</h2></th></tr>
                    <tr><th>Default Unit</th><td><select name="<?php echo esc_attr($this->option_key); ?>[unit]"><option value="cm" <?php selected($s['unit']??'cm', 'cm'); ?>>cm</option><option value="in" <?php selected($s['unit']??'', 'in'); ?>>in</option></select></td></tr>
                    <tr><th>Size Attribute Slug</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[attribute_slug]" value="<?php echo esc_attr($s['attribute_slug']??'pa_size'); ?>"><p class="description">The slug of the attribute used for sizing (e.g., 'pa_size').</p></td></tr>
                    <tr><th>'Custom' Label Text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[custom_label_text]" value="<?php echo esc_attr($s['custom_label_text']??'custom'); ?>"><p class="description">The text in the size attribute that triggers this feature. Case-insensitive.</p></td></tr>
                    <tr><th>Instructional Image URL</th><td><input type="url" class="large-text" name="<?php echo esc_attr($this->option_key); ?>[instructional_image_url]" value="<?php echo esc_url($s['instructional_image_url']??''); ?>"></td></tr>
                    <tr><th>Validation Error Message</th><td><textarea name="<?php echo esc_attr($this->option_key); ?>[validation_error_message]" rows="2" class="large-text"><?php echo esc_textarea($s['validation_error_message']??'Please ensure all fields are filled and values are within the acceptable range.'); ?></textarea></td></tr>
                    <tr><th>Show "Saved" Status Badge</th><td><label><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[show_status_indicator]" value="yes" <?php checked($s['show_status_indicator']??'', 'yes'); ?>> Enable</label></td></tr>

                    <tr><th colspan="2"><h2>Order Processing</h2></th></tr>
                    <tr><th>Required Order Unit</th><td><select name="<?php echo esc_attr($this->option_key); ?>[required_order_unit]"><option value="cm" <?php selected($s['required_order_unit']??'cm', 'cm'); ?>>cm</option><option value="in" <?php selected($s['required_order_unit']??'', 'in'); ?>>in</option></select><p class="description">Automatically convert all measurements to this unit in the final order.</p></td></tr>
                    <tr><th>Show Customer Profile Link in Orders</th><td><label><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[admin_customer_link]" value="yes" <?php checked($s['admin_customer_link']??'', 'yes'); ?>> Enable</label></td></tr>

                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}


/**
 * =================================================================
 * Plugin Bootstrappers: Initializes the core and admin classes.
 * =================================================================
 */
new CFP_Core();
new CFP_Admin();