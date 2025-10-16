<?php
/**
 * Plugin Name: VPC Temporary Images Manager
 * Plugin URI:  https://example.com
 * Description: Companion admin tool to list/manage Visual Product Customizer temporary preview images (_is_vpc_temporary meta).
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: vpc-temp-manager
 */

if (!defined('ABSPATH')) exit;

class VPC_Temp_Images_Manager {
    const META_KEY = '_is_vpc_temporary';
    const MENU_SLUG = 'vpc-temp-images';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'maybe_handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        // WP-CLI support (optional)
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('vpc-temp-images', [$this, 'wpcli_list_temp_images']);
        }
    }

    public function register_admin_menu() {
        add_submenu_page(
            'upload.php',
            __('VPC Temp Images', 'vpc-temp-manager'),
            __('VPC Temp Images', 'vpc-temp-manager'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'media_page_' . self::MENU_SLUG) return;
        wp_enqueue_style('vpc-temp-admin', plugins_url('css/vpc-temp-admin.css', __FILE__));
    }

    public function maybe_handle_actions() {
        if (!isset($_REQUEST['page']) || $_REQUEST['page'] !== self::MENU_SLUG) return;
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['vpc_bulk_action']) && check_admin_referer('vpc_temp_bulk_action')) {
            $action = sanitize_text_field($_POST['vpc_bulk_action']);
            $ids    = array_map('intval', (array) ($_POST['attachment_ids'] ?? []));
            if ($action && $ids) {
                foreach ($ids as $id) {
                    if ($action === 'make_permanent') {
                        delete_post_meta($id, self::META_KEY);
                    } elseif ($action === 'delete') {
                        wp_delete_attachment($id, true);
                    }
                }
                add_action('admin_notices', function() use ($ids, $action) {
                    $count = count($ids);
                    $msg = $action === 'make_permanent'
                        ? sprintf(_n('Marked %d attachment permanent.', 'Marked %d attachments permanent.', $count, 'vpc-temp-manager'), $count)
                        : sprintf(_n('Deleted %d attachment.', 'Deleted %d attachments.', $count, 'vpc-temp-manager'), $count);
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
                });
            }
        }

        if (isset($_POST['vpc_single_action']) && check_admin_referer('vpc_temp_single_action')) {
            $action = sanitize_text_field($_POST['vpc_single_action']);
            $id     = intval($_POST['attachment_id'] ?? 0);
            if ($id) {
                if ($action === 'make_permanent') {
                    delete_post_meta($id, self::META_KEY);
                    wp_safe_redirect(add_query_arg('vpc_msg', 'made_permanent', remove_query_arg('_wpnonce')));
                    exit;
                } elseif ($action === 'delete') {
                    wp_delete_attachment($id, true);
                    wp_safe_redirect(add_query_arg('vpc_msg', 'deleted', remove_query_arg('_wpnonce')));
                    exit;
                }
            }
        }
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'vpc-temp-manager'));

        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 40;
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'meta_key'       => self::META_KEY,
            'orderby'        => 'post_date',
            'order'          => 'DESC',
        ];
        $query = new WP_Query($args);
        $attachments = $query->posts;
        $total = $query->found_posts;
        $pages = (int) ceil($total / $per_page);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('VPC Temporary Images', 'vpc-temp-manager'); ?></h1>
            <p class="description"><?php esc_html_e('Images created as temporary previews by the Visual Product Customizer are hidden from the standard Media Library. Use this tool to preview, delete, or make them permanent.', 'vpc-temp-manager'); ?></p>

            <?php if (empty($attachments)) : ?>
                <div class="notice notice-info"><p><?php esc_html_e('No temporary images found.', 'vpc-temp-manager'); ?></p></div>
            <?php else : ?>

            <form method="post">
                <?php wp_nonce_field('vpc_temp_bulk_action'); ?>
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">

                <div style="margin:12px 0;">
                    <select name="vpc_bulk_action" required>
                        <option value=""><?php esc_html_e('Bulk actions', 'vpc-temp-manager'); ?></option>
                        <option value="make_permanent"><?php esc_html_e('Make Permanent', 'vpc-temp-manager'); ?></option>
                        <option value="delete"><?php esc_html_e('Delete Permanently', 'vpc-temp-manager'); ?></option>
                    </select>
                    <button class="button" type="submit"><?php esc_html_e('Apply', 'vpc-temp-manager'); ?></button>
                </div>

                <table class="widefat fixed striped" cellspacing="0">
                    <thead>
                        <tr>
                            <th style="width:1%"><input type="checkbox" id="vpc-select-all"></th>
                            <th style="width:8%"><?php esc_html_e('Preview', 'vpc-temp-manager'); ?></th>
                            <th><?php esc_html_e('Title / ID', 'vpc-temp-manager'); ?></th>
                            <th style="width:18%"><?php esc_html_e('Date', 'vpc-temp-manager'); ?></th>
                            <th style="width:22%"><?php esc_html_e('Actions', 'vpc-temp-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($attachments as $att): 
                        $thumb = wp_get_attachment_image_src($att->ID, 'thumbnail'); 
                        $full  = wp_get_attachment_url($att->ID);
                        ?>
                        <tr>
                            <td><input type="checkbox" class="vpc-attach-checkbox" name="attachment_ids[]" value="<?php echo esc_attr($att->ID); ?>"></td>
                            <td><?php if ($thumb) echo '<img src="'.esc_url($thumb[0]).'" style="max-width:80px;height:auto;">'; ?></td>
                            <td>
                                <strong><?php echo esc_html($att->post_title ?: '(' . $att->ID . ')'); ?></strong><br>
                                <code><?php echo esc_html($att->ID); ?></code><br>
                                <a href="<?php echo esc_url($full); ?>" target="_blank"><?php esc_html_e('Open full image', 'vpc-temp-manager'); ?></a>
                            </td>
                            <td><?php echo esc_html($att->post_date); ?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('vpc_temp_single_action'); ?>
                                    <input type="hidden" name="attachment_id" value="<?php echo esc_attr($att->ID); ?>">
                                    <button class="button" name="vpc_single_action" value="make_permanent"><?php esc_html_e('Make Permanent', 'vpc-temp-manager'); ?></button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('<?php esc_attr_e('Delete permanently? This cannot be undone.', 'vpc-temp-manager'); ?>');">
                                    <?php wp_nonce_field('vpc_temp_single_action'); ?>
                                    <input type="hidden" name="attachment_id" value="<?php echo esc_attr($att->ID); ?>">
                                    <button class="button button-danger" name="vpc_single_action" value="delete"><?php esc_html_e('Delete', 'vpc-temp-manager'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php
                // pagination
                $base_url = add_query_arg('paged', '%#%');
                echo '<div style="margin-top:12px;">' . paginate_links([
                    'base' => $base_url,
                    'format' => '',
                    'current' => $paged,
                    'total' => $pages,
                    'prev_text' => '&laquo; ' . __('Prev'),
                    'next_text' => __('Next') . ' &raquo;'
                ]) . '</div>';
            ?>

            <?php endif; ?>

        </div>

        <script>
        (function(){
            document.getElementById('vpc-select-all')?.addEventListener('change', function(e){
                var checked = e.target.checked;
                document.querySelectorAll('.vpc-attach-checkbox').forEach(function(cb){ cb.checked = checked; });
            });
        })();
        </script>
        <?php
    }

    // Optional WP-CLI command to list temp attachments
    public function wpcli_list_temp_images($args, $assoc_args) {
        $posts = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'meta_key' => self::META_KEY,
            'fields' => 'ids',
        ]);
        if (empty($posts)) {
            \WP_CLI::log('No temporary VPC attachments found.');
            return;
        }
        foreach ($posts as $id) {
            $url = wp_get_attachment_url($id);
            \WP_CLI::log("{$id}  —  {$url}");
        }
    }
}

new VPC_Temp_Images_Manager();
